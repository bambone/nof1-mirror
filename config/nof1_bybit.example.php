<?php
return [
    'nof1' => [
        'positions_url'    => 'https://nof1.ai/api/positions?limit=1000',
        'model_id'         => 'deepseek-chat-v3.1',
        'poll_interval_ms' => 1000,
        'connect_timeout'  => 2.0,
        'timeout'          => 3.0,
    ],
    'bybit' => [
        'base_url'   => 'https://api.bybit.com',
        'api_key'    => 'PUT_YOUR_API_KEY_HERE',
        'api_secret' => 'PUT_YOUR_API_SECRET_HERE',
        'account' => [
            'category'        => 'linear',
            'symbol_quote'    => 'USDT',
            'position_mode'   => 'ONE_WAY',
            'leverage_default'=> 10,
            'unified_margin'  => true,
        ],
        'symbol_map' => [
            'BTC'=>'BTCUSDT','ETH'=>'ETHUSDT','SOL'=>'SOLUSDT',
            'BNB'=>'BNBUSDT','DOGE'=>'DOGEUSDT','XRP'=>'XRPUSDT',
        ],
    ],
    'sizing' => [
        'mode' => 'mirror-scale',
        'scale' => 0.05,
        'qty_tolerance' => 0.1,
        'max_symbols' => 1,
        'per_symbol_max_notional' => 10,
    ],
    'risk' => [
        'place_tp' => true,
        'place_sl' => true,
    ],
    'log' => [
        'file' => __DIR__ . '/../var/deepseek_follow.log',
        'level'=> 'info',
    ],
];
