<?php
declare(strict_types=1);

namespace Mirror\App;

/**
 * Простой логгер для CLI-бота.
 * Пишет одновременно в stdout и файл.
 */
final class Logger
{
    private string $file;
    private string $level;

    public function __construct(string $file, string $level = 'info')
    {
        $this->file  = $file;
        $this->level = strtolower($level);
        @mkdir(dirname($file), 0777, true);
    }

    public function info(string $msg): void  { $this->log('INFO',  $msg); }
    public function warn(string $msg): void  { $this->log('WARN',  $msg); }
    public function error(string $msg): void { $this->log('ERROR', $msg); }
    public function debug(string $msg): void
    {
        if ($this->level === 'debug') $this->log('DEBUG', $msg);
    }

    private function log(string $lvl, string $msg): void
    {
        $ts = date('Y-m-d H:i:s');
        $line = "[$ts][$lvl] $msg\n";

        // вывод в консоль
        echo $line;

        // запись в файл
        file_put_contents($this->file, $line, FILE_APPEND);
    }
}
