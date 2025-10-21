<?php
// src/App/StateStore.php
namespace Mirror\App;

final class StateStore
{
    private string $file;
    private array $data = [];

    public function __construct(string $file)
    {
        $this->file = $file;
        if (is_file($file)) {
            $json = file_get_contents($file);
            $this->data = $json ? json_decode($json, true) ?: [] : [];
        }
    }

    public function get(string $symbol, string $key, $default=null) {
        return $this->data[$symbol][$key] ?? $default;
    }

    public function set(string $symbol, string $key, $val): void {
        $this->data[$symbol][$key] = $val;
        $this->flush();
    }

    private function flush(): void {
        @mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode($this->data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
}
