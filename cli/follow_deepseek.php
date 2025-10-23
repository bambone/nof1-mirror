<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mirror\App\Logger;
use Mirror\App\StateStore;
use Mirror\Infra\Nof1Client;
use Mirror\Infra\BybitClient;
use Mirror\App\Reconciler;

// ---------- bootstrap ----------
$global = require __DIR__ . '/../config/config.global.php';
$local  = file_exists(__DIR__ . '/../config/nof1_bybit.local.php')
    ? require __DIR__ . '/../config/nof1_bybit.local.php'
    : require __DIR__ . '/../config/nof1_bybit.example.php';

$cfg = array_replace_recursive($global, $local);

// Проверяем ключи перед стартом (до логгера)
if (empty($cfg['bybit']['api_key']) || str_starts_with((string)$cfg['bybit']['api_key'], 'PUT_')) {
    echo "❌ ERROR: Bybit API key/secret not set.\n";
    echo "→ Copy config/nof1_bybit.example.php → config/nof1_bybit.local.php and fill in your credentials.\n";
    exit(1);
}

// ---------- logger ----------
$fileLevel    = $cfg['log']['file_level']    ?? ($cfg['log']['level'] ?? 'notice');
$consoleLevel = $cfg['log']['console_level'] ?? 'info';
$verboseStartup = (bool)($cfg['log']['verbose_startup'] ?? false);

$log = new Logger(
    $cfg['log']['file']  ?? __DIR__ . '/../var/deepseek_follow.log',
    $fileLevel,
    $consoleLevel
);

date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');
$log->info('🚀 DeepSeek Mirror started');

$state = new StateStore(__DIR__ . '/../var/state.json');

// Nof1Client::fromConfig — новая версия с diagnostics() и account-totals
$nof1 = Nof1Client::fromConfig($cfg);

$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret']
);

// ---------- one-time leverage ensure ----------
(function () use ($cfg, $bybit, $log) {
    $cat     = $cfg['bybit']['account']['category'] ?? 'linear';
    $lev     = (int)($cfg['bybit']['account']['leverage_default'] ?? 0);
    $symbols = $cfg['bybit']['symbol_map'] ?? [];

    if ($lev <= 0 || !$symbols) {
        $log->debug('skip leverage init: leverage_default not set or no symbols');
        return;
    }

    $log->info("🛠  Setting leverage={$lev}x for mapped symbols…");
    foreach ($symbols as $nof1Sym => $bybitSymbol) {
        try {
            $resp = $bybit->setLeverage($cat, $bybitSymbol, $lev, $lev);
            $retCode = (int)($resp['retCode'] ?? 1);
            $retMsg  = (string)($resp['retMsg'] ?? 'UNKNOWN');

            if ($retCode === 0) {
                $log->info("   ✅ {$bybitSymbol}: leverage set to {$lev}x");
            } elseif (stripos($retMsg, 'not modified') !== false) {
                // уже установлено — не шумим в info
                $log->debug("   ℹ️ {$bybitSymbol}: leverage already {$lev}x (not modified)");
            } else {
                $log->warn("   ⚠️ {$bybitSymbol}: leverage set failed: {$retMsg} (retCode={$retCode})");
            }
        } catch (\Throwable $e) {
            $log->warn("   ⚠️ {$bybitSymbol}: leverage set exception: " . $e->getMessage());
        }
    }
    $log->info('🛠  Leverage init done.');
})();

$recon = new Reconciler($bybit, $cfg, $state, $log);

// ---------- graceful shutdown ----------
$running = true;
if (function_exists('pcntl_async_signals')) {
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

// антиспам WARN и heartbeat
$noModelsWarnNextAt        = 0;
$modelNotFoundWarnNextAt   = 0;
$warnCooldownSec           = 60;

$lastNonEmptyFetchAt       = 0;  // когда пришли непустые blocks
$lastMatchedAt             = 0;  // когда нашли нужную модель
$lastPresentCount          = 0;  // сколько символов в последнем матче
$lastHeartbeatAt           = 0;
$heartbeatSec              = 30; // как часто говорить «жив»

$norm = static function (string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
};
$wantModel = $norm($targetModel);

// канонизатор: убираем хвост вида "_<число>" (экземпляр), затем нормализуем
$canon = static function (string $s) use ($norm): string {
    $s = preg_replace('/_\d+$/', '', $s); // важно: не трогаем ".1" в "v3.1"
    return $norm($s);
};
$wantCanon = $canon($targetModel);

$log->info("🎯 Following model: {$targetModel}");
$log->info(sprintf(
    "🧭 Bybit: %s, category=%s, leverage_default=%s, UM=%s",
    $cfg['bybit']['base_url'],
    $cfg['bybit']['account']['category'] ?? 'linear',
    $cfg['bybit']['account']['leverage_default'] ?? '—',
    ($cfg['bybit']['account']['unified_margin'] ?? false) ? 'on' : 'off'
));
$log->info('Press Ctrl+C to stop…');
$log->info('⏳ Waiting for signals from NOF1…');

// ---------- NOF1 diagnostics (one-time, optional) ----------
if ($verboseStartup) {
    try {
        $diag = $nof1->diagnostics();
        $p = $diag['positions'] ?? [];
        $log->debug("NOF1 positions   url=" . ($p['url'] ?? 'n/a') . " status=" . ($p['status'] ?? 'n/a'));
        $log->debug("NOF1 positions   body=" . ($p['body'] ?? ''));
        foreach (($diag['account_totals_probes'] ?? []) as $i => $probe) {
            $log->debug(sprintf(
                "NOF1 acct_probe#%d url=%s status=%s body=%s",
                $i + 1,
                $probe['url'] ?? 'n/a',
                (string)($probe['status'] ?? 'n/a'),
                (string)($probe['body'] ?? '')
            ));
        }
    } catch (\Throwable $e) {
        $log->warn("NOF1 diagnostics failed: " . $e->getMessage());
    }
}

/**
 * Универсальная обработка блока модели:
 *  - принимает $block (id/name/positions)
 *  - разбирает positions как map {SYM:{...}} или как массив объектов
 *  - вызывает $recon->syncSymbol(...)
 *  - возвращает список тикеров $present
 */
$syncModelBlock = function (array $block) use ($cfg, $bybit, $symbolMap, $recon, $log): array {
    $present   = [];
    $posSetRaw = (array)($block['positions'] ?? []);
    $isAssoc   = array_keys($posSetRaw) !== range(0, count($posSetRaw) - 1);

    $process = function (string $coin, array $raw) use ($cfg, $bybit, $symbolMap, $recon, $log, &$present) {
        $coin = strtoupper($coin);
        if (!isset($symbolMap[$coin])) {
            $log->debug("skip {$coin}: not mapped in symbol_map");
            return;
        }
        $present[] = $coin;

        $sideStr = strtolower((string)($raw['side'] ?? 'long')); // long|short
        $lev     = (float)($raw['leverage'] ?? ($raw['lev'] ?? 0.0));
        $conf    = $raw['confidence'] ?? ($raw['conf'] ?? null);

        $cat   = $cfg['bybit']['account']['category'] ?? 'linear';
        $bbSym = $symbolMap[$coin];
        $entry = (float)($raw['entry_price'] ?? $raw['avg_price'] ?? 0.0);
        if ($entry <= 0.0) {
            $t    = $bybit->getTicker($cat, $bbSym);
            $last = (float)($t['result']['list'][0]['lastPrice'] ?? 0.0);
            $entry = $last > 0 ? $last : 0.0;
        }

        if (isset($raw['quantity']))      $qty = (float)$raw['quantity'];
        elseif (isset($raw['qty']))       $qty = (float)$raw['qty'];
        else {
            $notional = (float)($raw['notional'] ?? $raw['value'] ?? 0.0);
            $qty = ($entry > 0 && $notional > 0) ? ($notional / $entry) : 0.0;
        }

        $qtySigned = ($sideStr === 'short' ? -abs($qty) : abs($qty));
        $exitPlan  = $raw['exit_plan'] ?? [];

        $pos = [
            'quantity'     => $qtySigned,
            'entry_price'  => $entry,
            'leverage'     => $lev,
            'confidence'   => $conf,
            'exit_plan'    => $exitPlan,
            'entry_oid'    => (string)($raw['entry_oid'] ?? ''),
            'entry_time'   => (float)($raw['entry_time'] ?? 0.0),
        ];

        $log->debug(sprintf(
            "→ %s: side=%s qty=%s lev=%s entry=%s conf=%s",
            $coin,
            $sideStr,
            $pos['quantity'],
            $pos['leverage'],
            $pos['entry_price'],
            (string)$pos['confidence']
        ));

        $recon->syncSymbol($coin, $pos, $symbolMap);
    };

    if ($isAssoc) {
        foreach ($posSetRaw as $coin => $raw) {
            if (is_array($raw)) $process((string)$coin, $raw);
        }
    } else {
        foreach ($posSetRaw as $raw) {
            if (!is_array($raw)) continue;
            $coin = null;
            if (isset($raw['coin']))       $coin = strtoupper((string)$raw['coin']);
            elseif (isset($raw['symbol'])) $coin = strtoupper((string)$raw['symbol']);
            elseif (isset($raw['ticker'])) $coin = strtoupper((string)$raw['ticker']);
            if ($coin) $process($coin, $raw);
        }
    }

    return $present;
};

// ---------- main loop ----------
$iteration = 0;
$backoffMs = 0;

while ($running) {
    $iteration++;
    $log->debug("=== Tick {$iteration} @ " . date('H:i:s') . " ===");

    try {
        // 1) Тянем нормализованные блоки у клиента
        $blocks = $nof1->fetchPositions();

        if (is_array($blocks) && count($blocks) > 0) {
            $lastNonEmptyFetchAt = time();
        }

        if (!$blocks) {
            $now = time();
            if ($now >= $noModelsWarnNextAt) {
                $log->warn("⚠️ NOF1 returned no models. Check URLs in config:");
                $log->warn("   positions_url=" . ($cfg['nof1']['positions_url'] ?? ''));
                $log->warn("   account_totals_url=" . ($cfg['nof1']['account_totals_url'] ?? '(auto derived)'));
                $noModelsWarnNextAt = $now + $warnCooldownSec;
            }
        }

        // fallback: если в ответе ровно один блок — можем использовать его
        $singleModelFallback = (is_array($blocks) && count($blocks) === 1) ? $blocks[0] : null;

        // 2) Ищем нужную модель по id/name (нормализовано)
        $present    = [];
        $modelFound = false;

        $seenOnce = false;
        foreach ((array)$blocks as $block) {
            $rawId   = (string)($block['id']   ?? '');
            $rawName = (string)($block['name'] ?? '');

            $idOk   = $rawId   !== '' && $canon($rawId)   === $wantCanon;
            $nameOk = $rawName !== '' && $canon($rawName) === $wantCanon;

            // запасной вариант: deepseek-chat-v3.1-<номер>
            if (!$idOk && !$nameOk) {
                $idOk   = $rawId   !== '' && str_starts_with($norm($rawId),   $wantModel . '-');
                $nameOk = $rawName !== '' && str_starts_with($norm($rawName), $wantModel . '-');
            }

            if (!$idOk && !$nameOk) {
                if (!$seenOnce) {
                    $list = [];
                    foreach ((array)$blocks as $m) {
                        $list[] = ($m['id'] ?? $m['name'] ?? '<??>');
                    }
                    $log->debug('NOF1 models in payload: ' . implode(' | ', $list));
                    $seenOnce = true;
                }
                continue;
            }

            $modelFound = true;
            $present = $syncModelBlock($block);

            // отметим успешный матч
            $lastMatchedAt    = time();
            $lastPresentCount = is_array($present) ? count($present) : 0;

            break; // с нужной моделью закончили
        }

        if (!$modelFound) {
            if ($singleModelFallback) {
                $chosen = (($singleModelFallback['id'] ?? '') ?: ($singleModelFallback['name'] ?? '<?>'));
                $log->warn("⚠️ Model '{$targetModel}' не найдена; используем единственную доступную: {$chosen}");
                $present = $syncModelBlock($singleModelFallback);

                $lastMatchedAt    = time(); // всё же сматчили fallback
                $lastPresentCount = is_array($present) ? count($present) : 0;
            } else {
                $now = time();
                if ($now >= $modelNotFoundWarnNextAt) {
                    $log->warn("⚠️ Model block '{$targetModel}' not found in payload (want={$wantModel}).");
                    $modelNotFoundWarnNextAt = $now + $warnCooldownSec;
                }
            }
        }

        // 3) Закрываем то, чего нет у модели
        $recon->closeAbsentSymbols($present, $symbolMap);

        // Итог тика — шум
        $log->debug("✅ Sync complete.");
        $backoffMs = 0;
    } catch (\Throwable $e) {
        $log->error("❌ Error: " . $e->getMessage());
        $log->debug($e->getTraceAsString());

        // экспоненциальный бэкофф до 5 секунд
        $backoffMs = min($backoffMs > 0 ? $backoffMs * 2 : 250, 5000);
        $log->warn("⏳ Backoff {$backoffMs}ms due to error.");
        usleep($backoffMs * 1000);
    }

    // Heartbeat (раз в $heartbeatSec)
    $now = time();
    if ($now - $lastHeartbeatAt >= $heartbeatSec) {
        $feedAge    = $lastNonEmptyFetchAt ? ($now - $lastNonEmptyFetchAt) . 's ago' : 'never';
        $matchedAge = $lastMatchedAt ? ($now - $lastMatchedAt) . 's ago' : 'never';
        $log->info(sprintf(
            "⏱ alive: tick=%d, feed=%s, model=%s (last=%s), symbols=%d",
            $iteration,
            $feedAge,
            $lastMatchedAt ? 'matched' : 'not-found',
            $matchedAge,
            (int)$lastPresentCount
        ));

        // эскалация, если фид молчит > 120 секунд
        if ($lastNonEmptyFetchAt && ($now - $lastNonEmptyFetchAt) > 120) {
            $log->warn(sprintf("⚠️ NOF1 feed stalled: no non-empty payload for %ds", $now - $lastNonEmptyFetchAt));
        }

        $lastHeartbeatAt = $now;
    }

    // Пауза между тиками
    usleep(max(0, $pollMs) * 1000);
}

$log->info('👋 Bye!');
