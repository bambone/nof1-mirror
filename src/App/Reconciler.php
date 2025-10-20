<?php

namespace Mirror\App;

use Mirror\Infra\BybitClient;

final class Reconciler
{
    public function __construct(
        private BybitClient $bybit,
        private array $cfg
    ) {}

    public function syncSymbol(string $nof1Symbol, array $pos, array $symbolMap): void
    {
        $bybitSymbol = $symbolMap[$nof1Symbol] ?? null;
        if (!$bybitSymbol) return;

        $cat = $this->cfg['bybit']['account']['category'];

        // 1) Исходные данные NOF1
        $nof1Qty = (float)($pos['quantity'] ?? 0.0);
        $side    = Mapper::sideFromQty($nof1Qty);
        $entry   = (float)($pos['entry_price'] ?? 0.0);
        $tp = $pos['exit_plan']['profit_target'] ?? null;
        $sl = $pos['exit_plan']['stop_loss'] ?? null;

        // 2) Базовый raw-объём до всех ограничений
        $mode = $this->cfg['sizing']['mode'];
        if ($mode === 'mirror-scale') {
            $targetQtyRaw = Mapper::mirrorScaled($nof1Qty, (float)$this->cfg['sizing']['scale']);
        } else {
            $riskUsd = (float)($pos['risk_usd'] ?? 0.0);
            $calc = Mapper::riskBasedQty($riskUsd, $entry, $sl);
            $targetQtyRaw = $calc ? $calc : Mapper::mirrorScaled($nof1Qty, 0.02);
        }

        // 3) Получаем фильтры лотов (min/step)
        $info = $this->bybit->getInstrumentsInfo($cat, $bybitSymbol);
        $minQty = 0.0;
        $step = 0.0;
        if (($info['retCode'] ?? 1) === 0 && !empty($info['result']['list'][0]['lotSizeFilter'])) {
            $f = $info['result']['list'][0]['lotSizeFilter'];
            $minQty = isset($f['minOrderQty']) ? (float)$f['minOrderQty'] : 0.0;
            $step   = isset($f['qtyStep'])     ? (float)$f['qtyStep']     : 0.0;
        }
        echo "   lot filter {$bybitSymbol}: min={$minQty}, step={$step}\n";

        // 4) Цена и ограничение по $cap ДО квантования
        $ticker = $this->bybit->getTicker($cat, $bybitSymbol);
        $last = 0.0;
        if (($ticker['retCode'] ?? 1) === 0 && !empty($ticker['result']['list'][0]['lastPrice'])) {
            $last = (float)$ticker['result']['list'][0]['lastPrice'];
        }
        $cap = (float)($this->cfg['sizing']['per_symbol_max_notional'] ?? 0);
        if ($cap > 0 && $last > 0) {
            $maxQtyByCap = $last > 0 ? floor(($cap / $last) / max($step, 1e-8)) * max($step, 1e-8) : 0.0;
            if ($maxQtyByCap < abs($targetQtyRaw)) {
                $targetQtyRaw = $maxQtyByCap;
            }
        }

        // 5) Теперь квантайзим ПОСЛЕ cap
        $targetQty = \Mirror\App\Quantizer::snapQty(abs($targetQtyRaw), max($minQty, 0.0), max($step, 1e-8));
        if ($targetQty === 0.0) {
            echo "⚪ {$bybitSymbol}: targetQty < min (min={$minQty}, step={$step}) — пропуск\n";
            return;
        }

        // 6) Текущая позиция
        $p = $this->bybit->getPositions($cat, $bybitSymbol);
        $curQty = 0.0;
        $curSide = null;
        if (($p['retCode'] ?? 1) === 0 && !empty($p['result']['list'][0])) {
            $row = $p['result']['list'][0];
            $curQty  = (float)($row['size'] ?? 0.0);
            $curSide = $row['side'] ?? null; // "Buy"/"Sell"
        }

        $tol = (float)$this->cfg['sizing']['qty_tolerance'];

        // 7) Флип стороны при расхождении
        if ($curQty > 0 && (($curSide === 'Buy' && $side === 'Sell') || ($curSide === 'Sell' && $side === 'Buy'))) {
            $closeSide = ($curSide === 'Buy') ? 'Sell' : 'Buy';
            echo "🔁 Side flip on {$bybitSymbol}: closing {$curQty} first...\n";
            $resp = $this->bybit->closeMarket($cat, $bybitSymbol, $curQty, $closeSide, self::clid('FLIP', $bybitSymbol));
            echo "   → close resp: " . ($resp['retMsg'] ?? 'NO_RESP') . "\n";
            $curQty = 0.0;
        }

        // 8) Сведение
        $diffQty = $targetQty - $curQty;
        if (abs($diffQty) > $tol) {
            if ($diffQty > 0) {
                echo "📈 OPEN {$side} {$bybitSymbol} qty={$diffQty}\n";
                $resp = $this->bybit->placeMarketOrder($cat, $bybitSymbol, $side, $diffQty, self::clid("ADD", $bybitSymbol));
                echo "   → resp: " . ($resp['retMsg'] ?? 'NO_RESP') . "\n";
            } else {
                $closeSide = $side === 'Buy' ? 'Sell' : 'Buy';
                echo "📉 REDUCE {$bybitSymbol} qty=" . abs($diffQty) . "\n";
                $resp = $this->bybit->closeMarket($cat, $bybitSymbol, abs($diffQty), $closeSide, self::clid("REDUCE", $bybitSymbol));
                echo "   → resp: " . ($resp['retMsg'] ?? 'NO_RESP') . "\n";
            }
        } else {
            echo "✅ {$bybitSymbol}: in sync (cur={$curQty}, target={$targetQty})\n";
        }

        // 9) TP/SL — только если позиция реально есть
        $p2 = $this->bybit->getPositions($cat, $bybitSymbol);
        $curAfter = 0.0;
        if (($p2['retCode'] ?? 1) === 0 && !empty($p2['result']['list'][0]['size'])) {
            $curAfter = (float)$p2['result']['list'][0]['size'];
        }
        if (($this->cfg['risk']['place_tp'] || $this->cfg['risk']['place_sl']) && $curAfter > 0) {
            $tpVal = $this->cfg['risk']['place_tp'] ? ($tp ?? null) : null;
            $slVal = $this->cfg['risk']['place_sl'] ? ($sl ?? null) : null;
            if ($tpVal !== null || $slVal !== null) {
                echo "🎯 TPSL {$bybitSymbol}: TP=" . ($tpVal ?? '—') . " SL=" . ($slVal ?? '—') . "\n";
                $r = $this->bybit->setTpSl($cat, $bybitSymbol, $tpVal ? (float)$tpVal : null, $slVal ? (float)$slVal : null);
                echo "   → resp: " . ($r['retMsg'] ?? 'NO_RESP') . "\n";
            }
        }
    }



    public function closeAbsentSymbols(array $presentNof1Symbols, array $symbolMap): void
    {
        $cat = $this->cfg['bybit']['account']['category'];
        foreach ($symbolMap as $nof1Symbol => $bybitSymbol) {
            if (!in_array($nof1Symbol, $presentNof1Symbols, true)) {
                $p = $this->bybit->getPositions($cat, $bybitSymbol);
                if (($p['retCode'] ?? 1) === 0 && !empty($p['result']['list'][0]['size'])) {
                    $curQty = (float)$p['result']['list'][0]['size'];
                    if ($curQty > 0) {
                        // закрываем лонг
                        $this->bybit->closeMarket($cat, $bybitSymbol, $curQty, 'Sell', self::clid('CLOSE', $bybitSymbol));
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
