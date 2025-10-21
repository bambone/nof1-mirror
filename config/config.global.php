<?php
return [
    // источник сигналов
    'nof1' => [
        'positions_url' => 'https://nof1.ai/api/positions?limit=1000',
        'model_id'      => 'deepseek-chat-v3.1',
        'poll_interval_ms' => 1000,
        'connect_timeout'  => 2.0,
        'timeout'          => 3.0,
    ],

    // биржа
    'bybit' => [
        'account' => [
            'category' => 'linear',    // unified v5: linear/inverse/option
            'symbol_quote' => 'USDT',  // для линейных perp
            'position_mode' => 'ONE_WAY', // иначе учесть hedge
            'leverage_default' => 10,
            'unified_margin'  => true,
        ],
        // маппинг тикеров NOF1 → Bybit
        'symbol_map' => [
            'BTC' => 'BTCUSDT',
            'ETH' => 'ETHUSDT',
            'SOL' => 'SOLUSDT',
            'BNB' => 'BNBUSDT',
            'DOGE' => 'DOGEUSDT',
            'XRP' => 'XRPUSDT',
        ],
    ],

    // политика управления размером
    'sizing' => [
        'mode'                   => 'mirror-scale',
        'scale'                  => 0.05,   // 1 % от объёма DEEPSEEK
        'qty_tolerance'          => 0.1,    // чувствительность
        'max_symbols'            => 2,
        'per_symbol_max_notional' => 20,    // максимум 200 USD на позицию
    ],
    // TP/SL
    'risk' => [
        'place_tp' => true,
        'place_sl' => true,
        'tp_sl_reduce_only' => true
    ],

    // логирование
    'log' => [
        'file'  => __DIR__ . '/../var/deepseek_follow.log',
        'level' => 'debug',
    ],

    'guards' => [
        'startup_cooldown_sec' => 10,     // первые N секунд после старта не торговать
        'rejoin_same_entry'    => false,  // НЕ перезаходить в ту же entry_oid после ручного выхода
        'rejoin_better_than_entry_pct' => 1.0, // если всё же разрешишь — входить только на ≥1% лучше цены entry
        'max_entry_age_min'    => 120,    // перезаход только если вход свежий (≤ N минут)
    ],


];
