<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mirror\App\Logger;
use Mirror\App\StateStore;
use Mirror\Infra\Nof1Client;
use Mirror\Infra\BybitClient;
use Mirror\App\Quantizer;

$global = require __DIR__ . '/../config/config.global.php';
$local  = file_exists(__DIR__ . '/../config/nof1_bybit.local.php')
    ? require __DIR__ . '/../config/nof1_bybit.local.php'
    : require __DIR__ . '/../config/nof1_bybit.example.php';
$cfg = array_replace_recursive($global, $local);

// Лог: в консоль всё, в файл — как в проекте
$fileLevel    = $cfg['log']['file_level']    ?? ($cfg['log']['level'] ?? 'notice');
$consoleLevel = $cfg['log']['console_level'] ?? 'debug';
$log = new Logger(
    $cfg['log']['file']  ?? __DIR__ . '/../var/deepseek_follow.log',
    $fileLevel,
    $consoleLevel
);

if (empty($cfg['scalp']['enabled'])) {
    $log->info('💤 Scalp module disabled (scalp.enabled=false). Exit.');
    exit(0);
}

// ключи заданы?
if (empty($cfg['bybit']['api_key']) || str_starts_with($cfg['bybit']['api_key'], 'PUT_')) {
    $log->error('❌ Bybit API keys are not set for scalp module.');
    exit(1);
}

$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret']
);

$nof1 = Nof1Client::fromConfig($cfg);

// состояние для резерва скальпа
$state = new StateStore(__DIR__ . '/../var/state.json');

$log->info('⚡ Scalp Long module started');

$cat       = $cfg['bybit']['account']['category'] ?? 'linear';
$symbolMap = $cfg['bybit']['symbol_map'] ?? [];

$perTradeCap = (float)($cfg['scalp']['per_trade_notional_cap'] ?? 5.0); // размер сделки, $
$minFree     = (float)($cfg['scalp']['min_free_balance_usd'] ?? 15.0);  // входить, если свободно ≥
$maxConc     = (int)($cfg['scalp']['max_concurrent_scalps'] ?? 1);

// примитивный счётчик открытых «скальп-слотов»
$openScalps = 0;

while (true) {
    try {
        // 1) доступный баланс
        $accType = $cfg['bybit']['account']['balance_account_type'] ?? 'UNIFIED';
        $coin    = $cfg['bybit']['account']['balance_coin'] ?? 'USDT';
        $free    = $bybit->getAvailable($accType, $coin);

        $log->debug('free USDT=' . $free);

        if ($free < $minFree) {
            $log->debug("⛔ free < min_free_balance_usd ({$free} < {$minFree}) — skip tick");
            usleep(500_000);
            continue;
        }

        // 2) следуем только в сторону long ТЕХ символов, где модель сейчас long
        $blocks = $nof1->fetchPositions();
        $longAllowedBySymbol = [];
        foreach ($blocks as $b) {
            if (($b['id'] ?? '') !== ($cfg['nof1']['model_id'] ?? 'deepseek-chat-v3.1')) continue;
            foreach ($b['positions'] ?? [] as $sym => $pos) {
                $qty = (float)($pos['quantity'] ?? 0);
                if ($qty > 0 && isset($symbolMap[$sym])) {
                    $longAllowedBySymbol[$symbolMap[$sym]] = true;
                }
            }
        }

        if (!$longAllowedBySymbol) {
            $log->debug('no long-bias symbols from model — idle');
            usleep(800_000);
            continue;
        }

        // 3) псевдо-триггер (заглушка): берём первый разрешённый, если слотов ещё есть
        if ($openScalps >= $maxConc) {
            $log->debug("slots full: {$openScalps}/{$maxConc}");
            usleep(500_000);
            continue;
        }

        foreach (array_keys($longAllowedBySymbol) as $bybitSymbol) {
            // Цена и шаги
            $t = $bybit->getTicker($cat, $bybitSymbol);
            $last = (float)($t['result']['list'][0]['lastPrice'] ?? 0.0);
            if ($last <= 0) continue;

            $info = $bybit->getInstrumentsInfo($cat, $bybitSymbol);
            $step = 0.0;
            $minQty = 0.0;
            if (($info['retCode'] ?? 1) === 0 && !empty($info['result']['list'][0]['lotSizeFilter'])) {
                $f = $info['result']['list'][0]['lotSizeFilter'];
                $minQty = (float)($f['minOrderQty'] ?? 0.0);
                $step   = (float)($f['qtyStep'] ?? 0.0);
            }

            // объём на ~5$ (переменная): квантован по шагу
            $rawQty = $perTradeCap / $last;
            $qty    = Quantizer::snapQty($rawQty, max($minQty, 0.0), max($step, 1e-8));
            if ($qty <= 0) {
                $log->debug("skip {$bybitSymbol}: {$perTradeCap}$ below min qty");
                continue;
            }

            // --- Здесь должен быть реальный триггер (double-touch, pullback и т.п.) ---
            // Пока — просто демонстрация «вошёл бы», если слот свободен.
            // Когда включим ордер — резервируем объём, чтобы Reconciler его не трогал.

            // резервируем объём под вход (для защиты от «зеркала»)
            $curRes = (float)$state->get($bybitSymbol, 'scalp_reserved_buy', 0.0);
            $state->set($bybitSymbol, 'scalp_reserved_buy', $curRes + $qty);

            $log->action("⚡ SCALP RESERVE {$bybitSymbol} qty={$qty} (≈\${$perTradeCap})");

            // Реальный вход (когда решишь включить):
            // $resp = $bybit->placeMarketOrder($cat, $bybitSymbol, 'Buy', $qty, 'SCALP_' . date('His'));
            // if (($resp['retCode'] ?? 1) === 0) {
            //     $openScalps++;
            //     $log->action("✅ SCALP ENTERED {$bybitSymbol} qty={$qty}");
            // } else {
            //     // если вход не прошёл — снимем резерв
            //     $state->set($bybitSymbol, 'scalp_reserved_buy', $curRes);
            //     $log->warn("⛔ scalp order failed {$bybitSymbol}: ".($resp['retMsg'] ?? 'NO_RESP'));
            // }

            // для демо — один резерв за тик
            break;
        }

        usleep(500_000);
    } catch (\Throwable $e) {
        $log->error($e->getMessage());
        usleep(800_000);
    }
}
