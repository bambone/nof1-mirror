<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mirror\Infra\Nof1Client;
use Mirror\Infra\BybitClient;
use Mirror\App\Reconciler;

$cfg = require __DIR__ . '/../config/nof1_bybit.local.php';

$nof1 = new Nof1Client(
    $cfg['nof1']['positions_url'],
    $cfg['nof1']['connect_timeout'],
    $cfg['nof1']['timeout']
);

$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret']
);

$recon = new Reconciler($bybit, $cfg);

// ---- Graceful shutdown (для Linux/Mac) ----
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function() use (&$running) {
        echo "\n⏹  Stopping...\n";
        $running = false;
    });
}

$targetModel = $cfg['nof1']['model_id'];
$symbolMap   = $cfg['bybit']['symbol_map'];

echo "🚀 Follow: {$targetModel}\n";
echo "Press Ctrl+C to stop...\n\n";

$iteration = 0;
while ($running) {
    $iteration++;
    echo "=== Tick {$iteration} @ " . date('H:i:s') . " ===\n";

    try {
        $positions = $nof1->fetchPositions();
        $present = [];

        foreach ($positions as $block) {
            if (($block['id'] ?? '') !== $targetModel) {
                continue;
            }

            $posSet = $block['positions'] ?? [];
            foreach ($posSet as $sym => $pos) {
                if (!isset($symbolMap[$sym])) continue;
                $present[] = $sym;

                echo "→ {$sym}: entry={$pos['entry_price']} qty={$pos['quantity']} lev={$pos['leverage']} conf={$pos['confidence']}\n";
                $recon->syncSymbol($sym, $pos, $symbolMap);
            }
        }

        $recon->closeAbsentSymbols($present, $symbolMap);

        echo "✅ Sync complete.\n\n";
    } catch (\Throwable $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }

    usleep((int)($cfg['nof1']['poll_interval_ms'] * 1000));
}
