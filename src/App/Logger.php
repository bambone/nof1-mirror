<?php
declare(strict_types=1);

namespace Mirror\App;

/**
 * Двухканальный логгер:
 *  - Консоль: выводит всё по порогу $consoleLevel (по умолчанию 'debug').
 *  - Файл:    по умолчанию ПУСТО, но метод action() всегда пишет в файл.
 *
 * Т.е. файл содержит ТОЛЬКО реальные действия (OPEN/REDUCE/CLOSE/FLIP),
 * а консоль — весь поток.
 */
final class Logger
{
    public const DEBUG  = 10;
    public const INFO   = 20;
    public const NOTICE = 25;
    public const WARN   = 30;
    public const ERROR  = 40;

    private string $filePath;
    private int $consoleLevel;

    public function __construct(
        string $filePath,
        string $fileLevelIgnored = 'notice', // оставлено для совместимости, но не используется
        string $consoleLevel = 'debug'
    ) {
        $this->filePath     = $filePath;
        $this->consoleLevel = $this->toLevel($consoleLevel);

        // убеждаемся, что каталог существует
        $dir = \dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    // === Публичные методы ===

    public function debug(string $msg): void  { $this->log(self::DEBUG,  'DEBUG',  $msg); }
    public function info(string $msg): void   { $this->log(self::INFO,   'INFO',   $msg); }
    public function notice(string $msg): void { $this->log(self::NOTICE, 'NOTICE', $msg); }
    public function warn(string $msg): void   { $this->log(self::WARN,   'WARN',   $msg); }
    public function error(string $msg): void  { $this->log(self::ERROR,  'ERROR',  $msg); }

    /**
     * ACTION — единственный метод, который пишет в ФАЙЛ.
     * Используем ТОЛЬКО для реальных торговых действий:
     * OPEN / REDUCE / CLOSE / FLIP.
     */
    public function action(string $msg): void
    {
        $row = $this->format('ACTION', $msg);

        // 1) всегда в консоль
        $this->writeConsole($row);

        // 2) всегда в файл (append)
        $this->writeFile($row);
    }

    // === Внутреннее ===

    private function log(int $lvl, string $tag, string $msg): void
    {
        $row = $this->format($tag, $msg);

        // В КОНСОЛЬ: по порогу
        if ($lvl >= $this->consoleLevel) {
            $this->writeConsole($row);
        }

        // В ФАЙЛ: обычные сообщения НЕ пишем (только action()).
        // Оставляем возможность легко включить порог — просто раскомментируй:
        // if ($lvl >= $this->fileLevel) { $this->writeFile($row); }
    }

    private function format(string $tag, string $msg): string
    {
        $ts = date('[Y-m-d H:i:s]');
        return sprintf("%s %-7s %s\n", $ts, $tag, $msg);
    }

    private function writeConsole(string $row): void
    {
        // stdout
        echo $row;
    }

    private function writeFile(string $row): void
    {
        @file_put_contents($this->filePath, $row, FILE_APPEND);
    }

    private function toLevel(string $name): int
    {
        $name = strtolower($name);
        return match ($name) {
            'debug'  => self::DEBUG,
            'info'   => self::INFO,
            'notice' => self::NOTICE,
            'warn', 'warning' => self::WARN,
            'error'  => self::ERROR,
            default  => self::INFO,
        };
    }
}
