<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mirror\App\Logger;
use Mirror\App\StateStore;
use Mirror\Infra\BybitClient;
use Mirror\Infra\Nof1Client;
use Mirror\App\Quantizer;

// ---------- config ----------
$global = require __DIR__ . '/../config/config.global.php';
$local  = file_exists(__DIR__ . '/../config/nof1_bybit.local.php')
    ? require __DIR__ . '/../config/nof1_bybit.local.php'
    : require __DIR__ . '/../config/nof1_bybit.example.php';
$cfg = array_replace_recursive($global, $local);

// ---------- loggers ----------
// 1) подробные объяснения решений
$reasonFile   = $cfg['log']['spot_file'] ?? (__DIR__ . '/../var/spot_scalp.log');
$fileLevel    = $cfg['log']['file_level']    ?? ($cfg['log']['level'] ?? 'notice');
$consoleLevel = $cfg['log']['console_level'] ?? 'debug';
$log = new Logger($reasonFile, $fileLevel, $consoleLevel);

// 2) только реально исполненные сделки (отдельный файл)
$dealsFile = $cfg['log']['spot_deals_file'] ?? (__DIR__ . '/../var/spot_deals.log');
$deals = new Logger($dealsFile, 'notice', 'error');

// выключено?
if (empty($cfg['spot_scalp']['enabled'])) {
    $log->info('💤 Spot scalp disabled (spot_scalp.enabled=false). Exit.');
    exit(0);
}

// ---------- clients ----------
$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'] ?? '',
    $cfg['bybit']['api_secret'] ?? ''
);

$nof1 = Nof1Client::fromConfig($cfg);

$state = new StateStore(__DIR__ . '/../var/state.json');

$log->info('🟢 Spot Range Scalp started');

$symbolMap = $cfg['bybit']['symbol_map'] ?? [];

// === параметры модуля ===
$budgetUSD      = (float)($cfg['spot_scalp']['per_trade_usd'] ?? 20.0);
$minFreeUSD     = (float)($cfg['spot_scalp']['min_free_usd'] ?? 25.0);
$maxSlots       = (int)($cfg['spot_scalp']['max_concurrent'] ?? 2);
$cooldownSec    = (int)($cfg['spot_scalp']['per_symbol_cooldown_sec'] ?? 8); // антидребезг после BUY

$winMinutes     = (int)($cfg['spot_scalp']['window_min'] ?? 30);
$interval       = (string)($cfg['spot_scalp']['interval'] ?? '1');
$profit_bp_min  = (float)($cfg['spot_scalp']['profit_bp_min'] ?? 20.0);
$profit_bp_max  = (float)($cfg['spot_scalp']['profit_bp_max'] ?? 60.0);

$feesMaker = (float)($cfg['spot_scalp']['fees']['maker'] ?? 0.0002);
$feesTaker = (float)($cfg['spot_scalp']['fees']['taker'] ?? 0.00055);
$slipBp    = (float)($cfg['spot_scalp']['fees']['slippage_bp'] ?? 2.0);

$minOrderValueUsdCfg = (float)($cfg['bybit']['account']['min_order_value_usd'] ?? 5.0); // страхуемся по $-минимуму

$allowNoBiasIfApiFail = (bool)($cfg['spot_scalp']['allow_no_bias_on_api_fail'] ?? false);

// === helpers ===============================================================

function longBiasSymbols(array $blocks, array $symbolMap, string $modelId): array
{
    $allow = [];
    foreach ($blocks as $b) {
        if (($b['id'] ?? '') !== $modelId) continue;
        foreach ($b['positions'] ?? [] as $sym => $pos) {
            $qty = (float)($pos['quantity'] ?? 0);
            if ($qty > 0 && isset($symbolMap[$sym])) {
                $allow[$symbolMap[$sym]] = true; // Bybit-тикер
            }
        }
    }
    return array_keys($allow);
}

// ==========================================================================


// ---- после объявления $symbolMap и параметров, ПЕРЕД while(true) ----
$quote = $cfg['bybit']['account']['symbol_quote'] ?? 'USDT';

function baseCoinFromSymbol(string $sym, string $quote = 'USDT'): string
{
    return str_ends_with($sym, $quote) ? substr($sym, 0, -strlen($quote)) : $sym;
}

// однократный авто-подхват спотовых остатков в state
foreach (($cfg['bybit']['symbol_map'] ?? []) as $modelSym => $bybitSymbol) {
    $hold = $state->get($bybitSymbol, 'spot_hold', null);
    $holdingQty = (float)($hold['qty'] ?? 0);

    if ($holdingQty > 0) continue; // уже знаем про позицию

    // что реально лежит на UTA по базовой монете?
    $base = baseCoinFromSymbol($bybitSymbol, $quote);
    $freeBase = $bybit->getAvailable('UNIFIED', $base);

    if ($freeBase > 0) {
        // берём текущий last, чтобы не слить мгновенно — в плюс продаст позже
        $kl = $bybit->getKlines('spot', $bybitSymbol, '1', 3);
        $rows = $kl['result']['list'] ?? [];
        $last = $rows ? (float)end($rows)[4] : 0.0;

        if ($last > 0) {
            // аккуратно отщелкнуть по шагу лота
            $info = $bybit->getSpotLots($bybitSymbol);
            $step = 0.0;
            $minQty = 0.0;
            if (($info['retCode'] ?? 1) === 0) {
                $f = $info['result']['list'][0]['lotSizeFilter'] ?? [];
                $minQty = (float)($f['minOrderQty'] ?? 0.0);
                $step   = (float)($f['qtyStep'] ?? 0.0);
            }
            $qty = $step > 0
                ? Mirror\App\Quantizer::snapQty($freeBase, max($minQty, 0.0), $step)
                : $freeBase;

            if ($qty > 0) {
                $state->set($bybitSymbol, 'spot_hold', ['qty' => $qty, 'entry' => $last]);
                $log->info("🤝 Подхватил существующий спот {$bybitSymbol}: qty={$qty}, entry≈{$last}");
            }
        }
    }
}




$lastAllowed = []; // кэш bias

while (true) {
    try {
        // 0) баланс
        $free = $bybit->getAnyUsdtAvailable(false);
        if ($free < $minFreeUSD) {
            $log->debug("⛔ Недостаточно свободных средств: free={$free} < {$minFreeUSD} — ждём");
            usleep(700_000);
            continue;
        }

        // 1) разрешённые символы по bias модели (+кэш/фолбэки)
        $modelId = (string)($cfg['nof1']['model_id'] ?? 'deepseek-chat-v3.1');
        $allowed = [];
        try {
            $blocks  = $nof1->fetchPositions();
            $allowed = longBiasSymbols($blocks, $symbolMap, $modelId);
            if ($allowed) {
                $lastAllowed = $allowed;
            } else {
                $log->debug('bias: пусто — используем кэш последнего удачного набора');
                $allowed = $lastAllowed;
            }
        } catch (\Throwable $e) {
            $log->warn('bias: nof1 fetch failed, using lastAllowed; ' . $e->getMessage());
            $allowed = $lastAllowed;
        }

        if (!$allowed) {
            if ($allowNoBiasIfApiFail) {
                $allowed = array_values($symbolMap);
                $log->warn('bias: пусто, но allow_no_bias_on_api_fail=true — разрешаем все маппленные символы');
            } else {
                $log->debug('⏳ Нет разрешённых символов (bias пуст) — ждём');
                usleep(650_000);
                continue;
            }
        }

        // 2) подсчёт занятых слотов (ТОЛЬКО по state; не трогаем кошелёк!)
        $openSlots = 0;
        foreach ($allowed as $s) {
            $h = $state->get($s, 'spot_hold', null);
            if ($h && ($h['qty'] ?? 0) > 0) {
                $openSlots++;
                $log->debug(sprintf("📦 HOLD %s: qty=%s entry=%s", $s, $h['qty'], $h['entry']));
            }
        }
        if ($openSlots >= $maxSlots) {
            $log->debug("⏳ Лимит позиций: занято {$openSlots}/{$maxSlots} — ждём");
            usleep(600_000);
            continue;
        }

        // 3) прорабатываем разрешённые символы
        foreach ($allowed as $bybitSymbol) {
            // свечи в окне
            $need = max(10, (int)ceil($winMinutes / max(1, (int)$interval)));
            $kl   = $bybit->getKlines('spot', $bybitSymbol, $interval, $need);
            $rows = $kl['result']['list'] ?? [];
            if (!$rows) {
                $log->debug("📉 {$bybitSymbol}: нет свечей (kline пуст) — пропуск");
                continue;
            }

            // v5: [startTs, open, high, low, close, volume, turnover]
            $lows = [];
            $highs = [];
            $closes = [];
            foreach ($rows as $r) {
                $highs[]  = (float)$r[2];
                $lows[]   = (float)$r[3];
                $closes[] = (float)$r[4];
            }
            $last = end($closes);
            if ($last <= 0) {
                $log->debug("📉 {$bybitSymbol}: last<=0 — пропуск");
                continue;
            }

            $minWin = min($lows);
            $maxWin = max($highs);
            if ($minWin <= 0 || $maxWin <= $minWin) {
                $log->debug("📏 {$bybitSymbol}: диапазон некорректен (min={$minWin}, max={$maxWin}) — пропуск");
                continue;
            }

            // диапазон и цель
            $rangePct  = ($maxWin - $minWin) / $minWin;
            $midBp     = ($rangePct * 10000) / 2;
            $targetBp  = min(max($profit_bp_min, $midBp), $profit_bp_max);
            $targetPct = $targetBp / 10000.0;

            // round-trip cost (taker+taker) + слиппедж
            $roundTripCostPct = ($feesTaker + $feesTaker) + ($slipBp / 10000.0);
            $needPct          = $targetPct + $roundTripCostPct;

            // позиция в диапазоне [0..1]
            $rel = ($last - $minWin) / ($maxWin - $minWin);

            $log->debug(sprintf(
                "🧮 %s: min=%.6f max=%.6f last=%.6f rel=%.2f%% range=%.2f%% target≈%.2f%% costs≈%.2f%% need≈%.2f%%",
                $bybitSymbol,
                $minWin,
                $maxWin,
                $last,
                $rel * 100,
                $rangePct * 100,
                $targetPct * 100,
                $roundTripCostPct * 100,
                $needPct * 100
            ));

            // лот-фильтры и точности СПОТА
            $info = $bybit->getSpotLots($bybitSymbol);
            $step = 0.0;
            $minQty = 0.0;
            $basePrec = 8;
            $minOrderAmt = 0.0;
            if (($info['retCode'] ?? 1) === 0 && !empty($info['result']['list'][0]['lotSizeFilter'])) {
                $f = $info['result']['list'][0]['lotSizeFilter'];
                $minQty      = (float)($f['minOrderQty'] ?? 0.0);
                $step        = (float)($f['qtyStep'] ?? 0.0);
                $basePrec    = (int)($f['basePrecision'] ?? 8);
                // на споте у Bybit бывает ограничение по сумме:
                if (!empty($info['result']['list'][0]['priceFilter']['minOrderAmt'])) {
                    $minOrderAmt = (float)$info['result']['list'][0]['priceFilter']['minOrderAmt'];
                }
            }
            $log->debug("🔢 {$bybitSymbol}: lotFilter minQty={$minQty} step={$step} basePrec={$basePrec} minOrderAmt={$minOrderAmt}");

            // текущее удержание + антидребезг
            $hold          = $state->get($bybitSymbol, 'spot_hold', null);
            $holdingQty    = (float)($hold['qty'] ?? 0.0);
            $holdingEntry  = (float)($hold['entry'] ?? 0.0);
            $lastBuyTs     = (int)($state->get($bybitSymbol, 'last_buy_ts', 0) ?? 0);
            if ($holdingQty <= 0 && $lastBuyTs > 0 && (time() - $lastBuyTs) < $cooldownSec) {
                $log->debug("🧊 {$bybitSymbol}: cooldown после BUY ещё " . ($cooldownSec - (time() - $lastBuyTs)) . "s — пропуск входа");
                continue;
            }
            // ========== ЛОГИКА ВХОДА ==========
            if ($holdingQty <= 0) {
                if ($rel <= 0.20) {
                    // рассчитать qty ≈ $budgetUSD
                    $rawQty = $budgetUSD / $last;

                    // учесть min order value ($) — берём максимум из config и биржевого (если пришёл)
                    $minOrderUsd = max($minOrderValueUsdCfg, $minOrderAmt > 0 ? $minOrderAmt : 0.0);
                    if ($minOrderUsd > 0 && ($rawQty * $last) < $minOrderUsd) {
                        $rawQty = $minOrderUsd / $last;
                    }

                    // квантование: если есть step — по step, иначе по precision
                    if ($step > 0) {
                        $qty = Quantizer::snapQty($rawQty, max($minQty, 0.0), $step);
                    } else {
                        $qty = max($rawQty, $minQty);
                        $qty = (float) number_format($qty, $basePrec, '.', '');
                    }

                    // контроль на 0
                    if ($qty <= 0) {
                        $log->debug("🚫 {$bybitSymbol}: qty после квантования = 0 — пропуск");
                        continue;
                    }

                    $valUsd = $qty * $last;
                    $log->debug(sprintf(
                        "✅ ВХОД-КАНДИДАТ %s: rel=%.2f%%; qty≈%s (~$%.2f) → BUY…",
                        $bybitSymbol,
                        $rel * 100,
                        $qty,
                        $valUsd
                    ));

                    // BUY market (строго СПОТ) — используем стабильный orderLinkId
                    $clid = 'SPOTIN_' . date('His');
                    $resp = $bybit->placeSpotMarket($bybitSymbol, 'Buy', $qty, $clid);

                    if (($resp['retCode'] ?? 1) === 0) {
                        // сохраняем удержание
                        $state->set($bybitSymbol, 'spot_hold', [
                            'qty'   => $qty,
                            'entry' => $last,
                        ]);
                        $state->set($bybitSymbol, 'last_buy_ts', time());

                        $log->action("🟩 BUY {$bybitSymbol} qty={$qty} @~{$last} (≈$" . round($valUsd, 2) . ")");
                        $deals->action("BUY {$bybitSymbol} qty={$qty} ~{$last} usd≈" . round($valUsd, 2));

                        // необязательный блок подтверждения фактических исполнений (если метод есть)
                        if (method_exists($bybit, 'getExecutions')) {
                            $fills = $bybit->getExecutions('spot', $bybitSymbol, $clid, 20);
                            if (($fills['retCode'] ?? 1) === 0) {
                                foreach ($fills['result']['list'] ?? [] as $fill) {
                                    $px = $fill['execPrice'] ?? null;
                                    $q  = $fill['execQty']   ?? null;
                                    $id = $fill['execId']    ?? '';
                                    if ($px && $q) {
                                        $deals->action("FILL BUY {$bybitSymbol} execId={$id} qty={$q} price={$px}");
                                    }
                                }
                            }
                        }

                        break; // один вход за тик
                    } else {
                        $log->warn("⛔ BUY fail {$bybitSymbol}: " . ($resp['retMsg'] ?? 'NO_RESP'));
                    }
                } else {
                    $log->debug(sprintf(
                        "↩️ %s: ещё высоко для входа (rel=%.2f%% > 20%% низа) — ждём",
                        $bybitSymbol,
                        $rel * 100
                    ));
                }
            }


            // ========== ЛОГИКА ВЫХОДА ==========
            else {
                $takeByTarget = $holdingEntry * (1.0 + $needPct);
                $takeByRange  = $maxWin * 0.995;
                $isProfit     = $last > $holdingEntry;

                $log->debug(sprintf(
                    "🎯 %s: hold qty=%.8f entry=%.6f last=%.6f need>=%.4f (%.2f%%) / range<=%.6f → profit=%s",
                    $bybitSymbol,
                    $holdingQty,
                    $holdingEntry,
                    $last,
                    $needPct,
                    $needPct * 100,
                    $takeByRange,
                    $isProfit ? 'yes' : 'no'
                ));

                if ($isProfit && ($last >= $takeByTarget || $last >= $takeByRange)) {
                    // квантование SELL
                    if ($step > 0) {
                        $sellQty = Quantizer::snapQty($holdingQty, max($minQty, 0.0), $step);
                    } else {
                        $sellQty = (float)number_format($holdingQty, $basePrec, '.', '');
                    }

                    if ($sellQty <= 0) {
                        $log->debug("🚫 {$bybitSymbol}: sellQty после квантования = 0 — пропуск");
                        continue;
                    }

                    $resp = $bybit->placeSpotMarket($bybitSymbol, 'Sell', $sellQty, 'SPOTOUT_' . date('His'));
                    if (($resp['retCode'] ?? 1) === 0) {
                        $profitPct = ($last / max($holdingEntry, 1e-12) - 1.0) * 100.0;
                        $state->set($bybitSymbol, 'spot_hold', ['qty' => 0.0, 'entry' => 0.0]);
                        $log->action("🟥 SELL {$bybitSymbol} qty={$sellQty} @~{$last} (P≈" . round($profitPct, 2) . "%)");
                        $deals->action("SELL {$bybitSymbol} qty={$sellQty} ~{$last} profit≈" . round($profitPct, 2) . "%");
                        break; // один выход за тик
                    } else {
                        $log->warn("⛔ SELL fail {$bybitSymbol}: " . ($resp['retMsg'] ?? 'NO_RESP'));
                    }
                } else {
                    $needPct100 = round($needPct * 100, 3);
                    $log->debug("⏱ {$bybitSymbol}: держим. last={$last} < take(min)={$takeByTarget}; need>=+{$needPct100}% и/или верх диапазона.");
                }
            }
        }

        usleep(800_000);
    } catch (\Throwable $e) {
        $log->error("spot scalp error: " . $e->getMessage());
        usleep(900_000);
    }
}
