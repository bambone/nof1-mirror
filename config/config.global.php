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
        'positions_url' => 'https://nof1.ai/api/positions?limit=1000', // legacy (fallback)
        'account_totals_url' => 'https://nof1.ai/api/account_totals',

        // ID модели, за которой следим (DeepSeek Chat v3.1)
        'model_id'      => 'deepseek-chat-v3.1',

        // Частота опроса API, мс (1000 = раз в секунду)
        'poll_interval_ms' => 1000,

        // Таймауты HTTP-запросов
        'connect_timeout'  => 6.0,
        'timeout'          => 8.0,
    ],

    // === Настройки биржи Bybit ===
    'bybit' => [
        'account' => [
            'category'         => 'linear',        // тип аккаунта unified v5: linear/inverse/option
            'symbol_quote'     => 'USDT',          // котируемая валюта для линейных perp
            'position_mode'    => 'ONE_WAY',       // режим позиции: ONE_WAY или HEDGE
            'leverage_default' => 10,              // плечо по умолчанию
            'unified_margin'   => true,            // true, если UTA (Unified Margin Account)
            'min_order_value_usd' => 5.0,          // минимальный номинал ордера (биржевой лимит Bybit)

            'balance_account_type' => 'SPOT',   // 'UNIFIED' | 'SPOT' | 'CONTRACT' | 'FUND' | 'OPTION'
            'balance_coin'         => 'USDT',   // если торгуете в USDC — поменяйте на 'USDC'
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
        'mode'  => 'mirror-scale', // берём qty модели и масштабируем
        'scale' => 0.05,           // коэффициент масштабирования (например, 5% от объёма модели)

        // === динамическая чувствительность (сколько расхождение игнорировать) ===
        // Толеранс определяет, при каком минимальном расхождении между нашей текущей позицией
        // и целевой позицией модели мы НЕ будем совершать сделки (чтобы не дёргаться по мелочи).
        'tolerance' => [
            // базовый режим на случай неизвестного тикера
            // by_step        — игнор по количеству шагов лота (qtyStep)
            // notional_usd   — игнор, пока расхождение < X долларов по номиналу
            // percent_target — игнор, пока |diff| < X% от целевого qty
            // absolute       — игнор фиксированного количества контрактов
            'mode'  => 'by_step',
            'value' => 1.0, // = 1 шаг лота

            // точечные настройки по символам (Bybit тикер)
            'per_symbol' => [
                // Для дорогих монет — комфортнее мерить «в шагах»:
                'BTCUSDT' => ['mode' => 'by_step', 'value' => 2.0],  // ~2 шага qtyStep (примерно 0.002 BTC)
                'ETHUSDT' => ['mode' => 'by_step', 'value' => 2.0],  // ~2 шага qtyStep
                'SOLUSDT' => ['mode' => 'by_step', 'value' => 2.0],  // ~2 шага qtyStep
                'BNBUSDT' => ['mode' => 'by_step', 'value' => 2.0],  // ~2 шага qtyStep

                // Для дешёвых монет — удобнее игнорировать расхождение до ~$1
                'DOGEUSDT' => ['mode' => 'notional_usd', 'value' => 1.0], // игнор ≤ $1 по номиналу
                'XRPUSDT'  => ['mode' => 'notional_usd', 'value' => 1.0], // игнор ≤ $1 по номиналу
            ],
        ],

        // ⚠️ ВАЖНО: эти два лимита ограничивают риск и «ширину охвата» модели.

        // 1) max_symbols — максимальное число инструментов, которые бот может держать открытыми одновременно.
        //    Если модель даёт сигналы на 3+ монеты, а лимит = 2, то третью и далее бот НЕ откроет.
        'max_symbols'             => 2,

        // 2) per_symbol_max_notional — «потолок» по номиналу одной позиции в USD.
        //    Перед выставлением ордера мы вычисляем qty так, чтобы стоимость позиции не превышала этот лимит.
        //    Пример: при цене SOL=180 и лимите 20 бот урежет qty ≈ до 0.11 SOL (≈$20).
        'per_symbol_max_notional' => 20,
    ],


    // === Параметры TP/SL ===
    'risk' => [
        'place_tp'           => true,   // выставлять Take Profit, если пришёл из exit plan модели
        'place_sl'           => true,   // выставлять Stop Loss, если пришёл из exit plan модели
        'tp_sl_reduce_only'  => true,   // только reduce-only (не наращивать позицию TP/SL-ордерами)
    ],

    // === Логирование ===
    'log' => [
        // путь к файлу логов
        'file'          => __DIR__ . '/../var/deepseek_follow.log',
        'spot_file'     => __DIR__ . '/../var/spot_scalp.log',      // спот-скальп

        // уровень вывода в консоль (всё подряд)
        'console_level' => 'debug',

        // уровень записи в файл:
        //  - debug, info  → пропускаются (не захламляем диск)
        //  - notice, warn, error → пишутся (только реальные действия/важные события)
        'file_level'    => 'notice',
    ],

    // === Guard-защиты и политика перезахода ===
    'guards' => [
        'startup_cooldown_sec'         => 10,  // после старта N секунд не торгуем (защита от мгновенного входа)
        'rejoin_same_entry'            => false, // не перезаходить в ту же entry_oid после ручного выхода
        'rejoin_better_than_entry_pct' => 1.0, // если разрешён перезаход — входить только на ≥1% лучше цены entry
        'max_entry_age_min'            => 120, // перезаход только если вход «свежий» (минуты)
    ],

    // === Скальп-аддон (доп. модуль) ===
    'scalp' => [
        'enabled' => false,            // ← глобальный флаг: включить/выключить модуль скальпа

        // риск/лимиты скальпа:
        'per_trade_notional_cap' => 5.0,   // одна скальп-сделка ≈ на $5
        'min_free_balance_usd'   => 15.0,  // не входить в скальп, если доступно < $15
        'max_concurrent_scalps'  => 1,     // одновременно не более N скальп-сделок

        // комиссии/оценка edge (подставь свои реальные комиссии на аккаунте!)
        'fees' => [
            'maker'       => 0.00020,  // 0.02%
            'taker'       => 0.00055,  // 0.055%
            'slippage_bp' => 2.0       // допуск на слиппедж в б.п. (2 б.п. = 0.02%)
        ],

        // базовые параметры сигналов (draft — для будущей детекции паттернов/волатильности)
        'vol' => [
            'atr_tf'         => '1m',
            'atr_len'        => 14,
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
            'sl_k_atr'     => 0.5,
            'tp_k_atr'     => 0.6,
            'be_k_atr'     => 0.3,
            'trail_k_atr'  => 0.3
        ],
    ],

    // === СПОТ-скальпинг без плеча ===
    'spot_scalp' => [
        'enabled'        => true,   // включить модуль
        'per_trade_usd'  => 20.0,   // вход примерно на $20
        'min_free_usd'   => 25.0,   // не входить, если свободно < $25
        'max_concurrent' => 1,      // одновременно до 2 удержаний (по разным символам)

        // диапазон и тайминг
        'window_min'     => 30,     // окно диапазона, минут
        'interval'       => '1',    // таймфрейм свечей (мин)

        // прибыль в б.п. (1bp=0.01%)
        'profit_bp_min'  => 20.0,   // минимальная цель 0.20%
        'profit_bp_max'  => 60.0,   // максимум 0.60%

        'fees' => [
            'maker'       => 0.00020,  // 0.02%
            'taker'       => 0.00055,  // 0.055%
            'slippage_bp' => 2.0,      // допуск на слиппедж (0.02%)
        ],
    ],

];
