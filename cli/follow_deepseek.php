<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mirror\App\Logger;
use Mirror\App\StateStore;
use Mirror\Infra\Nof1Client;
use Mirror\Infra\BybitClient;
use Mirror\App\Reconciler;

// ---------- bootstrap ----------
// грузим безопасный глобальный + приватный слой
$global = require __DIR__ . '/../config/config.global.php';
$local  = file_exists(__DIR__ . '/../config/nof1_bybit.local.php')
    ? require __DIR__ . '/../config/nof1_bybit.local.php'
    : require __DIR__ . '/../config/nof1_bybit.example.php';

// merge (bybit.local перекрывает bybit из глобального)
$cfg = array_replace_recursive($global, $local);

// Проверяем ключи перед стартом (до логгера)
if (empty($cfg['bybit']['api_key']) || str_starts_with($cfg['bybit']['api_key'], 'PUT_')) {
    echo "❌ ERROR: Bybit API key/secret not set.\n";
    echo "→ Copy config/nof1_bybit.example.php → config/nof1_bybit.local.php and fill in your credentials.\n";
    exit(1);
}

// ---------- logger ----------
// В файл пишем только notice+ (действия/варнинги/ошибки),
// в консоль — debug+ (всё).
$fileLevel    = $cfg['log']['file_level']    ?? ($cfg['log']['level'] ?? 'notice');
$consoleLevel = $cfg['log']['console_level'] ?? 'debug';

$log = new Logger(
    $cfg['log']['file']  ?? __DIR__ . '/../var/deepseek_follow.log',
    $fileLevel,
    $consoleLevel
);

date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');
$log->info('🚀 DeepSeek Mirror started');

$state = new StateStore(__DIR__ . '/../var/state.json');

$nof1 = new Nof1Client(
    $cfg['nof1']['positions_url'],
    (float)($cfg['nof1']['connect_timeout'] ?? 3.0),
    (float)($cfg['nof1']['timeout'] ?? 7.0)
);

$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret']
);

$recon = new Reconciler($bybit, $cfg, $state, $log);

// ---------- graceful shutdown ----------
$running = true;
if (function_exists('pcntl_async_signals')) { // Linux/Mac
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use (&$running, $log) {
        $log->info('⏹  Stopping by SIGINT…');
        $running = false;
    });
}

// ---------- runtime info ----------
$targetModel = (string)($cfg['nof1']['model_id'] ?? 'deepseek-chat-v3.1');
$symbolMap   = $cfg['bybit']['symbol_map'] ?? [];
$pollMs      = (int)($cfg['nof1']['poll_interval_ms'] ?? 1000);

$log->info("🎯 Following model: {$targetModel}");
$log->info(sprintf(
    "🧭 Bybit: %s, category=%s, leverage_default=%s, UM=%s",
    $cfg['bybit']['base_url'],
    $cfg['bybit']['account']['category'] ?? 'linear',
    $cfg['bybit']['account']['leverage_default'] ?? '—',
    ($cfg['bybit']['account']['unified_margin'] ?? false) ? 'on' : 'off'
));
$log->info('Press Ctrl+C to stop…');

// ---------- main loop ----------
$iteration = 0;
$backoffMs = 0;

while ($running) {
    $iteration++;
    // это шум — в консоль, не в файл
    $log->debug("=== Tick {$iteration} @ " . date('H:i:s') . " ===");

    try {
        // 1) Тянем актуальные позиции со всех моделей
        $blocks = $nof1->fetchPositions();

        // 2) Ищем нужную модель
        $present    = [];
        $modelFound = false;

        foreach ($blocks as $block) {
            if (($block['id'] ?? '') !== $targetModel) continue;
            $modelFound = true;

            $posSet = $block['positions'] ?? [];
            if (!$posSet) {
                $log->debug("model {$targetModel} returned no symbols");
            }

            // 3) Обрабатываем каждый символ модели
            foreach ($posSet as $sym => $pos) {
                if (!isset($symbolMap[$sym])) {
                    $log->debug("skip {$sym}: not mapped in symbol_map");
                    continue;
                }
                $present[] = $sym;

                // лаконичный статус — только в консоль
                $entry = $pos['entry_price'] ?? '—';
                $qty   = $pos['quantity'] ?? '—';
                $lev   = $pos['leverage'] ?? '—';
                $conf  = $pos['confidence'] ?? '—';
                $log->debug("→ {$sym}: entry={$entry} qty={$qty} lev={$lev} conf={$conf}");

                // Синхронизация по символу (действия логируются внутри как notice)
                $recon->syncSymbol($sym, $pos, $symbolMap);
            }
        }

        if (!$modelFound) {
            $log->warn("⚠️ Model block '{$targetModel}' not found in positions payload.");
        }

        // 4) Закрываем то, чего нет у модели (внутри — notice)
        $recon->closeAbsentSymbols($present, $symbolMap);

        // итог тика — шум
        $log->debug("✅ Sync complete.");
        $backoffMs = 0; // сбросить бэкофф после удачного шага
    } catch (\Throwable $e) {
        $log->error("❌ Error: " . $e->getMessage());
        $log->debug($e->getTraceAsString());

        // экспоненциальный бэкофф до 5 секунд
        $backoffMs = min($backoffMs > 0 ? $backoffMs * 2 : 250, 5000);
        $log->warn("⏳ Backoff {$backoffMs}ms due to error.");
        usleep($backoffMs * 1000);
    }

    // Пауза между тиками
    usleep(max(0, $pollMs) * 1000);
}

$log->info('👋 Bye!');
