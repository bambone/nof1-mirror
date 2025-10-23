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
