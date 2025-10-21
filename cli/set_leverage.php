<?php
declare(strict_types=1);

/**
 * CLI: Установка плеча (leverage) на Bybit для всех (или одного) символов.
 *
 * Пример:
 *   php cli/set_leverage.php                 # возьмёт leverage_default из config
 *   php cli/set_leverage.php 10              # проставит плечо 10x всем символам
 *   php cli/set_leverage.php 15 --symbol=BTCUSDT  # только для BTCUSDT
 */

require __DIR__ . '/../vendor/autoload.php';

use Mirror\App\Logger;
use Mirror\Infra\BybitClient;

// ---------- bootstrap ----------
$global = require __DIR__ . '/../config/config.global.php';
$local  = file_exists(__DIR__ . '/../config/nof1_bybit.local.php')
    ? require __DIR__ . '/../config/nof1_bybit.local.php'
    : require __DIR__ . '/../config/nof1_bybit.example.php';
$cfg = array_replace_recursive($global, $local);

// простой лог в консоль
$log = new Logger(
    $cfg['log']['file']  ?? __DIR__ . '/../var/deepseek_follow.log',
    'info',   // в консоль — инфо
    'notice'  // в файл — только действия (но мы почти ничего не пишем)
);

// проверка ключей
if (empty($cfg['bybit']['api_key']) || str_starts_with($cfg['bybit']['api_key'], 'PUT_')) {
    fwrite(STDERR, "❌ Bybit API key/secret are not set. Fill config/nof1_bybit.local.php\n");
    exit(1);
}

// входные параметры
$argLev   = $argv[1] ?? null;                         // желаемое плечо
$argSym   = null;                                     // опционально один символ
foreach ($argv as $a) {
    if (str_starts_with($a, '--symbol=')) {
        $argSym = strtoupper(substr($a, 9));
    }
}

// плечо берём из аргумента, либо из конфига
$lev = is_numeric($argLev)
    ? max(1, (int)$argLev)
    : (int)($cfg['bybit']['account']['leverage_default'] ?? 10);

$category = $cfg['bybit']['account']['category'] ?? 'linear';

// формируем список символов
$symbolMap = $cfg['bybit']['symbol_map'] ?? [];
$symbolsBybit = array_values($symbolMap);
if ($argSym) {
    $symbolsBybit = [ $argSym ];
}

$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret']
);

$log->info("🪙 Category: {$category}");
$log->info('🎯 Target leverage: ' . $lev . 'x');
$log->info('🔧 Symbols: ' . implode(', ', $symbolsBybit));
$log->info('—');

// установка
$ok = 0; $fail = 0;
foreach ($symbolsBybit as $sym) {
    try {
        $resp = $bybit->setLeverage($category, $sym, $lev, $lev);
        $rc   = $resp['retCode'] ?? -1;
        $msg  = $resp['retMsg']  ?? 'NO_RESP';

        if ($rc === 0) {
            $log->info("✅ {$sym}: leverage set to {$lev}x");
            $ok++;
        } else {
            $log->info("❌ {$sym}: {$msg} (retCode={$rc})");
            $fail++;
        }
        usleep(150_000); // чуть притормозим, чтобы не спамить API
    } catch (\Throwable $e) {
        $log->info("❌ {$sym}: exception " . $e->getMessage());
        $fail++;
    }
}

$log->info("—");
$log->info("Done. OK={$ok}, FAIL={$fail}");
