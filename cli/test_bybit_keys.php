<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mirror\Infra\BybitClient;

// === Подключаем конфиг из одного места ===
$config = require __DIR__ . '/../config/nof1_bybit.local.php';

// Берём ключи и URL прямо из конфига
$baseUrl   = $config['bybit']['base_url'];
$apiKey    = $config['bybit']['api_key'];
$apiSecret = $config['bybit']['api_secret'];
$category  = $config['bybit']['account']['category'] ?? 'linear';

$client = new BybitClient($baseUrl, $apiKey, $apiSecret);

echo "🔐 Проверка подключения к Bybit ($category)\n";
$response = $client->getPositions($category, 'BTCUSDT');

// Красивый вывод
if (($response['retCode'] ?? null) === 0) {
    echo "✅ Ключи работают! Получена позиция:\n";
} else {
    echo "⚠️ Ошибка доступа: {$response['retMsg']} (код {$response['retCode']})\n";
}
print_r($response);
