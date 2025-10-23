````markdown
# ⚡ NOF1 DeepSeek → Bybit Mirror (PHP)

Бот-зеркало, синхронизирующий реальные позиции модели  
**DeepSeek Chat v3.1** с платформы [nof1.ai](https://nof1.ai)  
на **Bybit Linear USDT Perpetual (Unified Trading Account)**.

---

## 🚀 Возможности

- 🔁 Каждую секунду опрашивает публичный API **nof1.ai**.  
- 📊 Автоматически **открывает, уменьшает или закрывает** позиции на Bybit.  
- 🎯 Устанавливает **Take Profit** и **Stop Loss** по плану выхода модели.  
- 💰 Учитывает ограничения по размеру позиции (`per_symbol_max_notional`).  
- ⚙️ Имеет защиту от перезахода и холодный старт (`startup_cooldown_sec`).  
- 🧮 Автоматически выставляет **плечо (leverage)** при старте.  
- 💎 Ведёт раздельное логирование (в файл — только действия, в консоль — всё).  
- ⚡ Поддерживает экспериментальный **модуль скальпинга** (в режиме long).  

---

## 🧩 Установка

```bash
git clone https://github.com/bambone/nof1-mirror.git
cd nof1-mirror
composer install
````

---

## 🔑 Настройка ключей

Создайте локальный конфиг (игнорируется Git):

```bash
cp config/nof1_bybit.example.php config/nof1_bybit.local.php
```

и вставьте свои ключи Bybit:

```php
return [
    'bybit' => [
        'base_url'   => 'https://api.bybit.com',
        'api_key'    => 'YOUR_REAL_API_KEY',
        'api_secret' => 'YOUR_REAL_API_SECRET',
    ],
];
```

---

## ⚙️ Конфигурация

Главный файл — `config/config.global.php`.

### Основные блоки:

| Раздел          | Назначение                                      |
| --------------- | ----------------------------------------------- |
| `nof1`          | источник сигналов (DeepSeek Chat v3.1)          |
| `bybit.account` | тип аккаунта, плечо, режим позиции              |
| `sizing`        | масштабирование и динамическая чувствительность |
| `risk`          | установка TP/SL                                 |
| `guards`        | защита от повторных входов                      |
| `log`           | уровни логирования                              |
| `scalp`         | параметры высокочастотного дополнения           |

---

### Пример `sizing`

```php
'sizing' => [
    'mode'  => 'mirror-scale',
    'scale' => 0.05,

    'tolerance' => [
        'mode'  => 'by_step',
        'value' => 1.0,
        'per_symbol' => [
            'BTCUSDT' => ['mode' => 'by_step', 'value' => 2.0],
            'DOGEUSDT'=> ['mode' => 'notional_usd', 'value' => 1.0],
            'XRPUSDT' => ['mode' => 'notional_usd', 'value' => 1.0],
        ],
    ],

    'max_symbols'             => 2,
    'per_symbol_max_notional' => 20,
],
```

---

### Новый блок `scalp`

```php
'scalp' => [
    'enabled' => false,              // ← включение/выключение модуля
    'per_trade_notional_cap' => 5.0, // размер одной сделки (USD)
    'min_free_balance_usd'   => 15.0,
    'max_concurrent_scalps'  => 1,
    'fees' => [
        'maker' => 0.00020,
        'taker' => 0.00055,
        'slippage_bp' => 2.0
    ],
],
```

> 💡 Скальпинг запускается только если бот стоит в **лонге**,
> доступный баланс выше `min_free_balance_usd`,
> и сумма сделки не превышает `per_trade_notional_cap`.

---

## ▶️ Запуск

### Проверка API Bybit

```bash
php cli/test_bybit_keys.php
```

### Автоустановка плеча (если нужно вручную)

```bash
php cli/set_leverage.php
```

### Основной цикл синхронизации

```bash
php cli/follow_deepseek.php
```

После запуска:

```
🚀 DeepSeek Mirror started
🛠  Setting leverage=10x for mapped symbols…
✅ BTCUSDT: leverage set to 10x
✅ DOGEUSDT: leverage set to 10x
🎯 Following model: deepseek-chat-v3.1
=== Tick 1 @ 07:31:40 ===
📈 OPEN Buy DOGEUSDT qty=51
✅ Sync complete.
```

### Скальпинг-модуль (эксперимент)

```bash
php cli/scalp_long.php
```

Он работает как отдельный процесс, следит за рынком и открывает микровходы
в сторону текущей позиции модели (лонг).



---



СПОТ 


## 🪵 Формат логов

Все операции, расчёты и ошибки логируются в двух потоках:

| Файл                 | Назначение                                          |
| -------------------- | --------------------------------------------------- |
| `var/spot_scalp.log` | Подробные шаги алгоритма (DEBUG, INFO, WARN, ERROR) |
| `var/spot_deals.log` | Только реальные сделки (BUY / SELL / FILL)          |

---

### 🔹 Типы сообщений

| Уровень  | Префикс в логе                    | Что означает                                              |
| -------- | --------------------------------- | --------------------------------------------------------- |
| `DEBUG`  | `🧮`, `📉`, `↩️`, `⏱`, `🔢`, `📦` | Технические детали: диапазоны, расчёты, фильтры, пропуски |
| `INFO`   | `🟢`, `🤝`, `⛔`, `💤`             | Общие статусы, старт, выключение, подхват активов         |
| `ACTION` | `🟩 BUY`, `🟥 SELL`               | Реально выполненные ордера                                |
| `WARN`   | `⚠️`, `⏳`, `🚫`                   | Ошибки API, таймауты, невозможность входа/выхода          |
| `ERROR`  | `❌`                               | Исключения PHP, сетевые ошибки, сбои Bybit API            |

---

### 🧾 Примеры логов

#### Старт и подхват

```
[2025-10-23 07:15:01] INFO    🟢 Spot Range Scalp started
[2025-10-23 07:15:03] INFO    🤝 Подхватил существующий спот XRPUSDT: qty=16.64, entry≈2.4012
```

#### Проверка диапазона

```
[2025-10-23 07:16:10] DEBUG   🧮 XRPUSDT: min=2.398000 max=2.411000 last=2.402000 rel=30.2% range=0.54% target≈0.45% costs≈0.11% need≈0.56%
```

#### Попытка входа

```
[2025-10-23 07:17:02] DEBUG   ✅ ВХОД-КАНДИДАТ XRPUSDT: rel=19.8%; qty≈8.0 (~$19.21) → BUY…
[2025-10-23 07:17:02] ACTION  🟩 BUY XRPUSDT qty=8 @~2.4012 (≈$19.21)
```

#### Подтверждение исполнения

```
[2025-10-23 07:17:03] ACTION  FILL BUY XRPUSDT execId=ab23cd qty=8.0 price=2.4012
```

#### Ожидание выхода

```
[2025-10-23 07:24:10] DEBUG   ⏱ XRPUSDT: держим. last=2.406 < take(min)=2.414; need>=+0.56%
```

#### Фиксация прибыли

```
[2025-10-23 07:29:11] DEBUG   🎯 XRPUSDT: hold qty=8.00000000 entry=2.401200 last=2.416000 need>=0.0056 (0.56%) / range<=2.410000 → profit=yes
[2025-10-23 07:29:11] ACTION  🟥 SELL XRPUSDT qty=8 @~2.416 (P≈0.62%)
```

#### Ошибка API и бэкофф

```
[2025-10-23 07:30:02] ERROR   ❌ Error: Client error: `GET https://nof1.ai/api/account_totals` → 502 Bad Gateway
[2025-10-23 07:30:02] WARN    ⏳ Backoff 5000ms due to error.
```

---

### 🔸 Цветовая легенда действий

| Цвет | Значение              | Пример                                   |
| ---- | --------------------- | ---------------------------------------- |
| 🟢   | запуск, готовность    | `🟢 Spot Range Scalp started`            |
| 🟩   | покупка (вход)        | `🟩 BUY XRPUSDT qty=8 @~2.4012`          |
| 🟥   | продажа (выход)       | `🟥 SELL XRPUSDT qty=8 @~2.4158`         |
| 🧊   | кулдаун после покупки | `🧊 XRPUSDT: cooldown ещё 5s — пропуск`  |
| ⚠️   | предупреждение        | `⚠️ API timeout, retrying...`            |
| ❌    | ошибка                | `❌ Bybit API returned 410 Gone`          |
| 🤝   | подхват спота         | `🤝 Подхватил существующий спот XRPUSDT` |

---

### 📦 Структура состояния (`state.json`)

```json
{
  "XRPUSDT": {
    "spot_hold": {
      "qty": 8.0,
      "entry": 2.4012
    },
    "last_buy_ts": 1729653120
  },
  "DOGEUSDT": {
    "spot_hold": {
      "qty": 0.0,
      "entry": 0.0
    }
  }
}
```

---

### 🧰 Уровни логирования

| Уровень  | Включает                 | Где пишется    |
| -------- | ------------------------ | -------------- |
| `debug`  | все сообщения            | консоль + файл |
| `notice` | только действия и ошибки | файл           |
| `error`  | только фатальные         | файл           |

Задаются в `config/nof1_bybit.local.php`:

```php
'log' => [
  'file_level'    => 'notice',
  'console_level' => 'debug',
  'spot_file'     => __DIR__ . '/../var/spot_scalp.log',
  'spot_deals_file' => __DIR__ . '/../var/spot_deals.log',
],
```


---

## 📂 Структура проекта

```
cli/
  follow_deepseek.php     → основной цикл
  set_leverage.php        → ручная установка плеча
  scalp_long.php          → экспериментальный HFT модуль

config/
  config.global.php        → настройки
  nof1_bybit.local.php     → ключи API (в .gitignore)

src/
  Infra/BybitClient.php    → обёртка Bybit REST v5
  App/Reconciler.php       → синхронизация позиций
  App/Logger.php           → логирование
  App/StateStore.php       → локальное состояние

var/
  deepseek_follow.log      → лог действий
  state.json               → последнее состояние
```

---

## 🧠 Безопасность

* Ключи Bybit хранятся только локально.
* `.gitignore` защищает `*.local.php`, `var/`, логи и стейт.
* Скрипты не содержат API-секретов в логах.

---

## 🧱 Технологии

* PHP 8.2+
* GuzzleHTTP
* Bybit REST V5
* JSON state persistence
* CLI с graceful shutdown

---

## 👤 Автор

**Andrey Dupliakin (bambone)**
[GitHub](https://github.com/bambone) · [nof1.ai](https://nof1.ai)

---

## 📜 Лицензия

MIT License © 2025 Andrey Dupliakin

```