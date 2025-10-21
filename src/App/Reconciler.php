<?php
declare(strict_types=1);

namespace Mirror\App;

use Mirror\Infra\BybitClient;

/**
 * Приводит локальные позиции к позициям модели с NOF1.
 * GUARDS:
 *  • startup_cooldown_sec — первые N секунд после запуска не торгуем
 *  • запрет перезахода в ту же сделку (по entry_oid), если вручную вышли
 *  • (опц.) перезаход только “лучше, чем entry” и пока сделка свежая
 *
 * Логирование:
 *  - debug  → болтливые статусы/тех.инфо (только консоль)
 *  - action → реальные действия: OPEN/REDUCE/FLIP/CLOSE (в файл + консоль)
 *  - warn   → важные пропуски/ограничения (консоль)
 *  - error  → ошибки (консоль)
 */
final class Reconciler
{
    public function __construct(
        private BybitClient $bybit,
        private array $cfg,
        private StateStore $state,
        private Logger $log
    ) {}

    public function syncSymbol(string $nof1Symbol, array $pos, array $symbolMap): void
    {
        $bybitSymbol = $symbolMap[$nof1Symbol] ?? null;
        if (!$bybitSymbol) return;

        $cat = $this->cfg['bybit']['account']['category'] ?? 'linear';

        // ===== 1) Исходные данные с NOF1 =====
        $nof1Qty   = (float)($pos['quantity'] ?? 0.0);      // знак qty определяет сторону
        $side      = Mapper::sideFromQty($nof1Qty);          // Buy / Sell
        $entryPx   = (float)($pos['entry_price'] ?? 0.0);
        $entryOid  = (string)($pos['entry_oid'] ?? '');      // id входа сделки на стороне NOF1
        $entryTime = (float)($pos['entry_time'] ?? 0.0);     // unix sec
        $tp        = $pos['exit_plan']['profit_target'] ?? null;
        $sl        = $pos['exit_plan']['stop_loss'] ?? null;

        // ===== 2) STARTUP COOLDOWN =====
        static $appStart = null;
        $appStart ??= time();
        $cooldown = (int)($this->cfg['guards']['startup_cooldown_sec'] ?? 0);
        if ($cooldown > 0 && (time() - $appStart) < $cooldown) {
            $this->log->debug("⏸️ cooldown {$bybitSymbol}… (".(time()-$appStart)."/{$cooldown}s)");
            return;
        }

        // ===== 3) Текущая биржевая позиция =====
        $p = $this->bybit->getPositions($cat, $bybitSymbol);
        $curQty   = 0.0;
        $curSide  = null;   // "Buy"/"Sell"
        $avgEntry = null;   // средняя цена входа на бирже
        if (($p['retCode'] ?? 1) === 0 && !empty($p['result']['list'][0])) {
            $row      = $p['result']['list'][0];
            $curQty   = (float)($row['size'] ?? 0.0);
            $curSide  = $row['side'] ?? null;
            $avgEntry = isset($row['avgPrice']) ? (float)$row['avgPrice'] : null;
        }

        // ===== 4) GUARD: Не перезаходить в ту же сделку (по entry_oid) =====
        $guardCfg   = $this->cfg['guards'] ?? [];
        $lastOid    = (string)$this->state->get($bybitSymbol, 'last_entry_oid', '');
        $joined     = (bool)$this->state->get($bybitSymbol, 'joined', false);

        // видим новый entry → запомним и сбросим joined
        if ($entryOid !== '' && $entryOid !== $lastOid) {
            $this->state->set($bybitSymbol, 'last_entry_oid', $entryOid);
            $this->state->set($bybitSymbol, 'joined', false);
            $this->state->set($bybitSymbol, 'first_entry_px', $entryPx);
            $this->log->debug("🆕 {$bybitSymbol}: new entry_oid={$entryOid}, entry={$entryPx}");
        }

        $isSameEntry = ($entryOid !== '' && $lastOid === $entryOid);

        // last price (для rejoin better и cap)
        $ticker = $this->bybit->getTicker($cat, $bybitSymbol);
        $last   = (float)($ticker['result']['list'][0]['lastPrice'] ?? 0.0);

        // возраст entry
        $ageOk = true;
        if (!empty($guardCfg['max_entry_age_min']) && $entryTime > 0) {
            $ageOk = (time() - (int)$entryTime) <= (int)$guardCfg['max_entry_age_min'] * 60;
        }

        // политика перезахода
        $allowRejoin = (bool)($guardCfg['rejoin_same_entry'] ?? false);
        $betterPct   = (float)($guardCfg['rejoin_better_than_entry_pct'] ?? 0.0);
        $isBetterPx  = ($betterPct > 0 && $last > 0 && $entryPx > 0)
            ? ($last <= $entryPx * (1 - $betterPct / 100))
            : false;

        // нашей позиции нет, у NOF1 всё ещё та же сделка
        if ($curQty <= 0 && $nof1Qty !== 0.0 && $isSameEntry) {
            if (!$allowRejoin) {
                $this->log->debug("🛡️ guard {$bybitSymbol}: same entry_oid={$entryOid}, exited earlier → wait new entry.");
                return;
            }
            if (!$ageOk) {
                $this->log->debug("🛡️ guard {$bybitSymbol}: entry too old → skip rejoin.");
                return;
            }
            if ($betterPct > 0 && !$isBetterPx) {
                $this->log->debug("🛡️ guard {$bybitSymbol}: price not better than entry by {$betterPct}% → skip rejoin.");
                return;
            }
            $this->log->debug("🟢 guard {$bybitSymbol}: rejoin allowed (same entry, better price / age OK)");
        }

        // ===== 5) Базовый raw-объём =====
        $mode = $this->cfg['sizing']['mode'] ?? 'mirror-scale';
        if ($mode === 'mirror-scale') {
            $targetQtyRaw = Mapper::mirrorScaled($nof1Qty, (float)($this->cfg['sizing']['scale'] ?? 0.01));
        } else {
            $riskUsd = (float)($pos['risk_usd'] ?? 0.0);
            $calc = Mapper::riskBasedQty($riskUsd, $entryPx, $sl);
            $targetQtyRaw = $calc ? $calc : Mapper::mirrorScaled($nof1Qty, 0.02);
        }

        // ===== 6) Фильтры лота (min/step) =====
        $info = $this->bybit->getInstrumentsInfo($cat, $bybitSymbol);
        $minQty = 0.0;
        $step   = 0.0;
        if (($info['retCode'] ?? 1) === 0 && !empty($info['result']['list'][0]['lotSizeFilter'])) {
            $f      = $info['result']['list'][0]['lotSizeFilter'];
            $minQty = isset($f['minOrderQty']) ? (float)$f['minOrderQty'] : 0.0;
            $step   = isset($f['qtyStep'])     ? (float)$f['qtyStep']     : 0.0;
        }
        $this->log->debug("lot filter {$bybitSymbol}: min={$minQty}, step={$step}");

        // ===== 7) Ограничение по нотионалу ($cap) ДО квантования =====
        $cap = (float)($this->cfg['sizing']['per_symbol_max_notional'] ?? 0);
        if ($cap > 0 && $last > 0) {
            $maxQtyByCap = floor(($cap / $last) / max($step, 1e-8)) * max($step, 1e-8);
            if ($maxQtyByCap < abs($targetQtyRaw)) {
                $targetQtyRaw = ($targetQtyRaw >= 0 ? 1 : -1) * $maxQtyByCap;
            }
        }

        // ===== 8) Квантование qty ПОСЛЕ cap =====
        $targetQty = Quantizer::snapQty(abs($targetQtyRaw), max($minQty, 0.0), max($step, 1e-8));
        if ($targetQty === 0.0) {
            $this->log->warn("⚪ {$bybitSymbol}: targetQty < min (min={$minQty}, step={$step}) — пропуск");
            return;
        }

        // ===== 9) Флип стороны при расхождении (ONE_WAY) =====
        if ($curQty > 0 && (($curSide === 'Buy' && $side === 'Sell') || ($curSide === 'Sell' && $side === 'Buy'))) {
            $closeSide = ($curSide === 'Buy') ? 'Sell' : 'Buy';
            // действие → в файл
            $this->log->action("🔁 FLIP {$bybitSymbol}: close {$curQty} side={$closeSide}");
            $resp = $this->bybit->closeMarket($cat, $bybitSymbol, $curQty, $closeSide, self::clid('FLIP', $bybitSymbol));
            // ответ — только в консоль
            $this->log->info("resp: " . ($resp['retMsg'] ?? 'NO_RESP'));
            $curQty = 0.0;
        }

        // ===== 10) Динамический толеранс и сведение позиций =====
        // --- динамический толеранс (вместо фиксированного qty_tolerance) ---
        $tolCfg   = $this->cfg['sizing']['tolerance'] ?? ['mode' => 'by_step', 'value' => 1.0];
        $tolMode  = $tolCfg['mode']  ?? 'by_step';       // by_step | notional_usd | percent_target | absolute
        $tolValue = (float)($tolCfg['value'] ?? 1.0);

        // пер-символьное переопределение, если задано
        if (!empty($tolCfg['per_symbol'][$bybitSymbol])) {
            $ovr      = $tolCfg['per_symbol'][$bybitSymbol];
            $tolMode  = $ovr['mode']  ?? $tolMode;
            $tolValue = (float)($ovr['value'] ?? $tolValue);
        }

        $tol = 0.0;
        switch ($tolMode) {
            case 'by_step':
                $tol = max($step, 1e-8) * max($tolValue, 0.0);
                break;

            case 'notional_usd':
                if ($last > 0) {
                    $tol = max($tolValue / $last, 0.0);
                    $tol = Quantizer::snapQty($tol, 0.0, max($step, 1e-8));
                }
                break;

            case 'percent_target':
                $tol = abs($targetQty) * max($tolValue, 0.0) / 100.0;
                $tol = Quantizer::snapQty($tol, 0.0, max($step, 1e-8));
                break;

            case 'absolute':
            default:
                $tol = max($tolValue, 0.0);
                break;
        }

        $diffQty = $targetQty - $curQty;

        if (abs($diffQty) > $tol) {
            if ($diffQty > 0) {
                $this->log->action("📈 OPEN {$side} {$bybitSymbol} qty={$diffQty}");
                $resp = $this->bybit->placeMarketOrder($cat, $bybitSymbol, $side, $diffQty, self::clid('ADD', $bybitSymbol));
                $this->log->info("resp: " . ($resp['retMsg'] ?? 'NO_RESP'));

                if (($resp['retCode'] ?? -1) === 0 && $entryOid !== '') {
                    $this->state->set($bybitSymbol, 'joined', true);
                    $this->state->set($bybitSymbol, 'last_entry_oid', $entryOid);
                }
            } else {
                $closeSide = $side === 'Buy' ? 'Sell' : 'Buy';
                $abs = abs($diffQty);
                $this->log->action("📉 REDUCE {$bybitSymbol} qty={$abs} side={$closeSide}");
                $resp = $this->bybit->closeMarket($cat, $bybitSymbol, $abs, $closeSide, self::clid('REDUCE', $bybitSymbol));
                $this->log->info("resp: " . ($resp['retMsg'] ?? 'NO_RESP'));
            }
        } else {
            $this->log->debug("in sync {$bybitSymbol}: cur={$curQty}, target={$targetQty}, tol={$tol}");
        }

        // ===== 11) TP/SL — только если позиция реально есть (только консоль) =====
        $p2 = $this->bybit->getPositions($cat, $bybitSymbol);
        $curAfter = 0.0;
        if (($p2['retCode'] ?? 1) === 0 && !empty($p2['result']['list'][0]['size'])) {
            $curAfter = (float)$p2['result']['list'][0]['size'];
        }

        // позиция ушла в ноль — сбросим joined
        if ($curAfter <= 0.0) {
            $this->state->set($bybitSymbol, 'joined', false);
        }

        if (($this->cfg['risk']['place_tp'] ?? true) || ($this->cfg['risk']['place_sl'] ?? true)) {
            if ($curAfter > 0) {
                $tpVal = ($this->cfg['risk']['place_tp'] ?? true) ? ($tp ?? null) : null;
                $slVal = ($this->cfg['risk']['place_sl'] ?? true) ? ($sl ?? null) : null;
                if ($tpVal !== null || $slVal !== null) {
                    $this->log->debug("🎯 TPSL {$bybitSymbol}: TP=" . ($tpVal ?? '—') . " SL=" . ($slVal ?? '—'));
                    $r = $this->bybit->setTpSl($cat, $bybitSymbol, $tpVal ? (float)$tpVal : null, $slVal ? (float)$slVal : null);
                    $this->log->debug("resp: " . ($r['retMsg'] ?? 'NO_RESP'));
                }
            }
        }
    }

    /**
     * Закрыть всё по символам, которых нет у NOF1.
     */
    public function closeAbsentSymbols(array $presentNof1Symbols, array $symbolMap): void
    {
        $cat = $this->cfg['bybit']['account']['category'] ?? 'linear';
        foreach ($symbolMap as $nof1Symbol => $bybitSymbol) {
            if (!in_array($nof1Symbol, $presentNof1Symbols, true)) {
                $p = $this->bybit->getPositions($cat, $bybitSymbol);
                if (($p['retCode'] ?? 1) === 0 && !empty($p['result']['list'][0]['size'])) {
                    $curQty  = (float)$p['result']['list'][0]['size'];
                    $curSide = $p['result']['list'][0]['side'] ?? null;
                    if ($curQty > 0) {
                        $closeSide = ($curSide === 'Buy') ? 'Sell' : 'Buy';
                        $this->log->action("🧹 CLOSE {$bybitSymbol} qty={$curQty} side={$closeSide} (absent in NOF1)");
                        $this->bybit->closeMarket($cat, $bybitSymbol, $curQty, $closeSide, self::clid('CLOSE', $bybitSymbol));
                        $this->state->set($bybitSymbol, 'joined', false);
                    }
                }
            }
        }
    }

    private static function clid(string $prefix, string $symbol): string
    {
        return $prefix . '_' . $symbol . '_' . date('His') . '_' . bin2hex(random_bytes(2));
    }
}
