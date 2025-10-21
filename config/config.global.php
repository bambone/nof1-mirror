<?php

/**
 * === NOF1 DeepSeek → Bybit Mirror global config ===
 *
 * Этот файл хранит все параметры поведения бота, кроме приватных ключей API.
 * Ключи находятся в config/nof1_bybit.local.php (он в .gitignore).
 */

return [

    // === Источник сигналов NOF1 ===
    'nof1' => [
        // URL публичного API для получения позиций модели
        'positions_url' => 'https://nof1.ai/api/positions?limit=1000',

        // ID модели, за которой следим (DeepSeek Chat v3.1)
        'model_id'      => 'deepseek-chat-v3.1',

        // Частота опроса API, мс (1000 = раз в секунду)
        'poll_interval_ms' => 1000,

        // Таймауты HTTP-запросов
        'connect_timeout'  => 2.0,
        'timeout'          => 3.0,
    ],

    // === Настройки биржи Bybit ===
    'bybit' => [
        'account' => [
            'category'        => 'linear',        // тип аккаунта unified v5: linear/inverse/option
            'symbol_quote'    => 'USDT',          // котируемая валюта для линейных perp
            'position_mode'   => 'ONE_WAY',       // режим позиции: ONE_WAY или HEDGE
            'leverage_default' => 10,              // плечо по умолчанию
            'unified_margin'  => true,            // true, если UTA (Unified Margin Account)
            'min_order_value_usd' => 5.0,         // минимальный номинал ордера (Bybit лимит)
        ],

        // Маппинг тикеров: как называются пары у NOF1 → у Bybit
        'symbol_map' => [
            'BTC'  => 'BTCUSDT',
            'ETH'  => 'ETHUSDT',
            'SOL'  => 'SOLUSDT',
            'BNB'  => 'BNBUSDT',
            'DOGE' => 'DOGEUSDT',
            'XRP'  => 'XRPUSDT',
        ],
    ],

    // === Политика управления размером позиции ===
    'sizing' => [
        'mode'  => 'mirror-scale',
        'scale' => 0.05,

        // === динамическая чувствительность (сколько расхождение игнорировать) ===
        'tolerance' => [
            // базовый режим на случай неизвестного тикера
            'mode'  => 'by_step',
            'value' => 1.0, // = 1 шаг лота

            // точечные настройки по символам (Bybit тикер)
            'per_symbol' => [
                // Высокая цена → меряем в шагах лота (qtyStep):
                'BTCUSDT' => ['mode' => 'by_step', 'value' => 2.0],  // ~0.002 BTC
                'ETHUSDT' => ['mode' => 'by_step', 'value' => 2.0],  // ~0.02 ETH
                'SOLUSDT' => ['mode' => 'by_step', 'value' => 2.0],  // ~0.2 SOL
                'BNBUSDT' => ['mode' => 'by_step', 'value' => 2.0],  // ~0.02 BNB

                // Дешёвые/«сотые-центовые» → меряем в $:
                'DOGEUSDT' => ['mode' => 'notional_usd', 'value' => 1.0], // игнор ≤ $1 расхождения
                'XRPUSDT'  => ['mode' => 'notional_usd', 'value' => 1.0], // игнор ≤ $1 расхождения
            ],
        ],

        'max_symbols'             => 2,
        'per_symbol_max_notional' => 20,
    ],


    // === Параметры TP/SL ===
    'risk' => [
        'place_tp'           => true,   // выставлять Take Profit
        'place_sl'           => true,   // выставлять Stop Loss
        'tp_sl_reduce_only'  => true,   // только reduce-only ордера
    ],

    // === Логирование ===
    'log' => [
        // путь к файлу логов
        'file'          => __DIR__ . '/../var/deepseek_follow.log',

        // уровень вывода в консоль (всё подряд)
        'console_level' => 'debug',

        // уровень записи в файл:
        //  - debug, info  → пропускаются
        //  - notice, warn, error → пишутся
        'file_level'    => 'notice',
    ],

    // === Guard-защиты и политика перезахода ===
    'guards' => [
        'startup_cooldown_sec'        => 10,   // после старта 10 сек без торговли
        'rejoin_same_entry'           => false, // не перезаходить в ту же entry_oid после выхода
        'rejoin_better_than_entry_pct' => 1.0,  // если включено — входить только на ≥1% лучше цены entry
        'max_entry_age_min'           => 120,  // разрешать перезаход, только если entry моложе N минут
    ],

    // скальп-аддон (доп. модуль)
    'scalp' => [
        'enabled' => false,            // ← вкл/выкл модуль одним флагом

        // риск/лимиты
        'per_trade_notional_cap' => 5.0,   // размер одной скальп-сделки (в USD)
        'min_free_balance_usd'   => 15.0,  // не входить, если доступно меньше этой суммы
        'max_concurrent_scalps'  => 1,

        // комиссии/оценка edge (подставь свои реальные комиссии!)
        'fees' => [
            'maker' => 0.00020,        // 0.02%
            'taker' => 0.00055,        // 0.055%
            'slippage_bp' => 2.0       // 2 б.п. допуск на слиппедж при оценке входа
        ],

        // базовая логика сигналов (можно будет детальнее настроить позже)
        'vol' => [
            'atr_tf'  => '1m',
            'atr_len' => 14,
            'k_pullback_min' => 0.6,
            'k_pullback_max' => 1.2,
        ],
        'double_touch' => [
            'win_seconds'     => 600,
            'min_gap_candles' => 6,
            'eps_ticks'       => 2,
            'rsi_len'         => 7,
        ],
        'entry' => [
            'trigger_delta_ticks' => 1,
            'post_only_wait_ms'   => 250,
        ],
        'targets' => [
            'sl_k_atr'   => 0.5,
            'tp_k_atr'   => 0.6,
            'be_k_atr'   => 0.3,
            'trail_k_atr' => 0.3
        ],
    ],
];
