````markdown
# ⚡ NOF1 DeepSeek → Bybit Mirror (PHP)

Бот-зеркало, который синхронизирует реальные позиции модели  
**DeepSeek Chat v3.1** с платформы [nof1.ai](https://nof1.ai)  
на **Bybit Linear USDT Perpetual (Unified Trading Account)**.

---

## 🚀 Возможности

- 🔁 Каждую секунду опрашивает публичный API **nof1.ai**.  
- 📊 Автоматически **открывает, уменьшает или закрывает** позиции в Bybit, зеркаля действия DeepSeek.  
- 🎯 Устанавливает **Take Profit** и **Stop Loss**, если они заданы в *exit plan*.  
- 💰 Поддерживает ограничение по размеру позиции (`per_symbol_max_notional`).  
- ⚙️ Имеет защиту от мгновенного входа после рестарта и повторных входов в ту же сделку.  
- 🪵 Ведёт **подробный лог** в файл `var/deepseek_follow.log` и в консоль.  

---

## 🧩 Установка

```bash
git clone https://github.com/bambone/nof1-mirror.git
cd nof1-mirror
composer install
````

---

## 🔑 Настройка ключей

Сначала создайте локальный конфиг с ключами Bybit:

```bash
cp config/nof1_bybit.example.php config/nof1_bybit.local.php
```

Затем откройте `config/nof1_bybit.local.php`
и вставьте свои реальные **API Key** и **Secret** от Bybit:

```php
return [
    'bybit' => [
        'base_url'   => 'https://api.bybit.com',
        'api_key'    => 'YOUR_REAL_API_KEY',
        'api_secret' => 'YOUR_REAL_API_SECRET',
    ],
];
```

> ⚠️ Этот файл **входит в .gitignore** — его нельзя коммитить в публичный репозиторий.

---

## ⚙️ Конфигурация проекта

Основные настройки лежат в `config/config.global.php`:

* Источник сигналов (`nof1`)
* Биржевые параметры (`bybit.account`, `symbol_map`)
* Политика управления размером (`sizing`)
* Take Profit / Stop Loss (`risk`)
* Логирование и защитные параметры (`log`, `guards`)

Пример:

```php
'sizing' => [
    'mode'                    => 'mirror-scale',
    'scale'                   => 0.05,
    'qty_tolerance'           => 0.1,
    'max_symbols'             => 2,
    'per_symbol_max_notional' => 20,
],
```

---

## ▶️ Запуск

### Проверка подключения к API Bybit

```bash
php cli/test_bybit_keys.php
```

### Установка плеча для всех символов

```bash
php cli/set_leverage.php
```

### Запуск основного зеркала DeepSeek

```bash
php cli/follow_deepseek.php
```

После запуска увидите лог наподобие:

```
🚀 Follow: deepseek-chat-v3.1
=== Tick 1 @ 09:13:42 ===
→ DOGE: entry=0.184 qty=27858 lev=10 conf=0.65
📈 OPEN Buy DOGEUSDT qty=1392
🎯 TPSL DOGEUSDT: TP=0.212 SL=0.175
✅ Sync complete.
```

---

## 📂 Структура проекта

```
cli/
  follow_deepseek.php     → основной цикл синхронизации
  test_bybit_keys.php     → проверка ключей Bybit
  set_leverage.php        → установка плеча

config/
  config.global.php        → все настройки проекта
  nof1_bybit.example.php   → пример (без ключей)
  nof1_bybit.local.php     → твой приватный конфиг (.gitignore)

src/
  Infra/BybitClient.php    → обёртка Bybit REST v5
  Infra/Nof1Client.php     → загрузка позиций с nof1.ai
  App/Reconciler.php       → логика синхронизации позиций
  App/Mapper.php           → расчёт направлений и qty
  App/Quantizer.php        → округление объёма по фильтрам
  App/StateStore.php       → хранение локального состояния

var/
  deepseek_follow.log      → файл логов
  state.json               → текущее состояние (последние сделки)
```

---

## 🪄 Быстрая настройка (Windows)

Можно добавить `setup_local_keys.bat` для удобства:

```bat
@echo off
echo === NOF1 Mirror Initial Setup ===
if exist config\nof1_bybit.local.php (
    echo Local config already exists: config\nof1_bybit.local.php
    echo Open it in any text editor and insert your Bybit keys.
    pause
    exit /b
)
copy config\nof1_bybit.example.php config\nof1_bybit.local.php >nul
echo Created config\nof1_bybit.local.php
echo.
echo Please open this file and insert your real API key/secret.
start notepad config\nof1_bybit.local.php
pause
```

---

## 🧠 Безопасность

* ✅ Ключи Bybit хранятся только локально (`nof1_bybit.local.php`).
* 🚫 Никакие ключи, логи и state-файлы не коммитятся (см. `.gitignore`).
* 🧾 Лог `var/deepseek_follow.log` содержит только технические сообщения, без секретов.

---

## 🧱 Технологии

* PHP 8.2+
* GuzzleHTTP
* Bybit REST API v5
* JSON state persistence
* CLI-режим с graceful shutdown (SIGINT)

---

## 🧑‍💻 Автор

**Andrey Dupliakin (bambone)**
[GitHub](https://github.com/bambone) · [nof1.ai](https://nof1.ai)

---

## 📜 Лицензия

MIT License © 2025 [Andrey Dupliakin (bambone)](https://github.com/bambone)