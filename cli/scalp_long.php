<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mirror\App\Logger;
use Mirror\App\StateStore;
use Mirror\Infra\Nof1Client;
use Mirror\Infra\BybitClient;
use Mirror\App\Quantizer;

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap: конфиги
// ─────────────────────────────────────────────────────────────────────────────
$global = require __DIR__ . '/../config/config.global.php';
$local  = file_exists(__DIR__ . '/../config/nof1_bybit.local.php')
    ? require __DIR__ . '/../config/nof1_bybit.local.php'
    : require __DIR__ . '/../config/nof1_bybit.example.php';
$cfg = array_replace_recursive($global, $local);

// Логгер: в консоль — всё; в файл — только действия (настройки в config.global.php)
$log = new Logger(
    $cfg['log']['file']  ?? __DIR__ . '/../var/deepseek_follow.log',
    $cfg['log']['level'] ?? 'info'
);

date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');

// Флаг включения модуля
if (empty($cfg['scalp']['enabled'])) {
    $log->info('💤 Scalp module disabled (scalp.enabled=false). Exit.');
    exit(0);
}

// Быстрая проверка ключей
if (empty($cfg['bybit']['api_key']) || str_starts_with((string)$cfg['bybit']['api_key'], 'PUT_')) {
    $log->error('❌ Bybit API keys are not set for scalp module.');
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Клиенты API
// ─────────────────────────────────────────────────────────────────────────────
$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret']
);

$nof1 = new Nof1Client(
    $cfg['nof1']['positions_url'],
    (float)($cfg['nof1']['connect_timeout'] ?? 3.0),
    (float)($cfg['nof1']['timeout'] ?? 7.0)
);

// ─────────────────────────────────────────────────────────────────────────────
// Параметры модуля
// ─────────────────────────────────────────────────────────────────────────────
$log->info('⚡ Scalp Long module started');

$cat       = $cfg['bybit']['account']['category'] ?? 'linear';
$symbolMap = $cfg['bybit']['symbol_map'] ?? [];

$perTradeCapUSD = (float)($cfg['scalp']['per_trade_notional_cap'] ?? 5.0);   // ≈ размер входа одной микросделки
$minFreeUSD     = (float)($cfg['scalp']['min_free_balance_usd'] ?? 15.0);   // ниже — не входим
$maxConc        = (int)($cfg['scalp']['max_concurrent_scalps'] ?? 1);       // максимум параллельных «скальп-лонгов»
$pollMs         = (int)($cfg['scalp']['poll_interval_ms'] ?? 500);          // период тика

$tagPrefix = 'SCALP'; // clientOrderId префикс наших ордеров

// ─────────────────────────────────────────────────────────────────────────────
// Утилиты
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Берём направление ТОЛЬКО из модели:
 * qty > 0 → разрешён скальп-лонг по этому символу.
 * qty <= 0 → запрещено.
 */
function buildLongAllowedFromModel(array $blocks, string $targetModel, array $symbolMap): array {
    $allowed = [];
    foreach ($blocks as $b) {
        if (($b['id'] ?? '') !== $targetModel) continue;
        foreach (($b['positions'] ?? []) as $sym => $pos) {
            if (!isset($symbolMap[$sym])) continue;
            if ((float)($pos['quantity'] ?? 0) > 0) {
                $allowed[$symbolMap[$sym]] = true;
            }
        }
    }
    return $allowed;
}

/**
 * Рассчитываем qty так, чтобы нотационал ≈ $perTradeCapUSD, и снапаем к шагу/минимуму.
 */
function calcScalpQty(BybitClient $bybit, string $cat, string $bybitSymbol, float $perTradeCapUSD): array {
    $ticker = $bybit->getTicker($cat, $bybitSymbol);
    $price  = (float)($ticker['result']['list'][0]['lastPrice'] ?? 0.0);
    if ($price <= 0) {
        return [0.0, 0.0]; // qty, price
    }

    $info = $bybit->getInstrumentsInfo($cat, $bybitSymbol);
    $minQty = 0.0; $step = 0.0;
    if (($info['retCode'] ?? 1) === 0 && !empty($info['result']['list'][0]['lotSizeFilter'])) {
        $f      = $info['result']['list'][0]['lotSizeFilter'];
        $minQty = (float)($f['minOrderQty'] ?? 0.0);
        $step   = (float)($f['qtyStep']     ?? 0.0);
    }

    $rawQty = $perTradeCapUSD / $price;
    $qty    = Quantizer::snapQty($rawQty, max($minQty, 0.0), max($step, 1e-8));

    return [$qty, $price];
}

/**
 * Считаем, сколько наших «скальп»-позиций уже открыто (по нашему префиксу clientId).
 * Тут можно улучшить до реального поиска по активным ордерам/позициям.
 */
function countActiveScalps(BybitClient $bybit, string $cat, string $tagPrefix): int {
    // Заглушка: вернём 0. Если добавим маркировку позиций — будем считать точно.
    return 0;
}

/**
 * Логика триггера входа (заглушка):
 * сюда встраиваем «двойное касание + подтверждение», уровни/паттерны и т.п.
 */
function longTriggerFired(BybitClient $bybit, string $cat, string $symbol): bool {
    // TODO: реализовать: локальные свечи/лента/двойное касание low + pullback
    return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// Основной цикл
// ─────────────────────────────────────────────────────────────────────────────
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use (&$running, $log) {
        $log->info('⏹ Stopping scalp module by SIGINT…');
        $running = false;
    });
}

$targetModel = (string)($cfg['nof1']['model_id'] ?? 'deepseek-chat-v3.1');

while ($running) {
    try {
        // 1) Баланс-страховка: ниже порога — простаиваем
        $free = (float)$bybit->getUsdtAvailable();
        $log->debug('free USDT = '. $free);
        if ($free < $minFreeUSD) {
            $log->debug("⛔ free < min_free_balance_usd ({$free} < {$minFreeUSD}) — skip tick");
            usleep($pollMs * 1000);
            continue;
        }

        // 2) Число уже активных скальп-позиций
        $active = countActiveScalps($bybit, $cat, $tagPrefix);
        if ($active >= $maxConc) {
            $log->debug("limit reached: active={$active} / max={$maxConc} — skip new entries");
            usleep($pollMs * 1000);
            continue;
        }

        // 3) Направление берём ТОЛЬКО из модели (qty > 0)
        $blocks = $nof1->fetchPositions();
        $longAllowed = buildLongAllowedFromModel($blocks, $targetModel, $symbolMap);

        if (!$longAllowed) {
            $log->debug('no long-bias symbols from model — idle');
            usleep($pollMs * 1000);
            continue;
        }

        // 4) Перебираем разрешённые символы
        foreach (array_keys($longAllowed) as $bybitSymbol) {
            // (а) сначала проверяем, не превышен ли лимит параллельных входов
            if ($active >= $maxConc) break;

            // (б) расчёт безопасного объёма под ~5$ (или то, что задано)
            [$qty, $price] = calcScalpQty($bybit, $cat, $bybitSymbol, $perTradeCapUSD);
            if ($qty <= 0.0) {
                $log->debug("skip {$bybitSymbol}: {$perTradeCapUSD} USD is below min order qty at current price");
                continue;
            }

            // (в) Триггер входа (заглушка): тут будем искать «двойное касание + отскок»
            if (!longTriggerFired($bybit, $cat, $bybitSymbol)) {
                $log->debug("no trigger on {$bybitSymbol} — waiting (qty≈{$qty}, px≈{$price})");
                continue;
            }

            // (г) ВХОД (когда включим) — по рынку или лимитом с post-only.
            // Сейчас: только логируем место, где будет реальный вход.
            $clientId = $tagPrefix . '_BUY_' . $bybitSymbol . '_' . date('His');
            $log->action("⚡ SCALP ENTER Buy {$bybitSymbol} qty={$qty} (~\${$perTradeCapUSD}) cid={$clientId}");

            // Пример реального входа (КОГДА РЕШИМ ВКЛЮЧИТЬ):
            // $resp = $bybit->placeMarketOrder($cat, $bybitSymbol, 'Buy', $qty, $clientId);
            // $log->info('resp: ' . ($resp['retMsg'] ?? 'NO_RESP'));

            $active++; // считаем как занятый слот
        }

        usleep($pollMs * 1000);
    } catch (\Throwable $e) {
        $log->error('scalp loop error: ' . $e->getMessage());
        $log->debug($e->getTraceAsString());
        usleep(max(500, $pollMs) * 1000);
    }
}

$log->info('👋 Scalp module stopped');
