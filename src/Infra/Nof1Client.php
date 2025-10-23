<?php
declare(strict_types=1);

namespace Mirror\Infra;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * NOF1 API client: account_totals-first, positions (legacy) fallback.
 * Возвращает унифицированный формат:
 * [
 *   ['id' => 'deepseek-chat-v3.1', 'positions' => ['BTC'=>['quantity'=>1.23], ...]],
 *   ...
 * ]
 */
final class Nof1Client
{
    private Client $http;
    private string $positionsUrl;
    private string $accountTotalsUrl;

    public function __construct(
        string $positionsUrl,
        float $connectTimeout = 2.0,
        float $timeout = 3.0,
        ?string $accountTotalsUrl = null
    ) {
        $this->positionsUrl     = $positionsUrl;
        $this->accountTotalsUrl = $accountTotalsUrl ?? $positionsUrl;

        $this->http = new Client([
            'timeout'         => $timeout,
            'connect_timeout' => $connectTimeout,
            'http_errors'     => true,
            'headers'         => ['Accept' => 'application/json'],
        ]);
    }

    public static function fromConfig(array $cfg): self
    {
        $p = (string)($cfg['nof1']['positions_url']      ?? '');
        $a = (string)($cfg['nof1']['account_totals_url'] ?? $p);
        return new self(
            $p,
            (float)($cfg['nof1']['connect_timeout'] ?? 2.0),
            (float)($cfg['nof1']['timeout'] ?? 3.0),
            $a
        );
    }

    /** Основной метод: сначала account_totals, затем — legacy positions. */
    public function fetchPositionsSmart(): array
    {
        $totals = $this->safeGetJson($this->accountTotalsUrl);
        if ($totals['ok']) {
            $parsed = $this->normalizeFromTotals($totals['json']);
            if ($parsed) return $parsed;
        }

        $legacy = $this->safeGetJson($this->positionsUrl);
        if ($legacy['ok']) {
            $parsed = $this->normalizeFromLegacyPositions($legacy['json']);
            if ($parsed) return $parsed;
        }

        return [];
    }

    /** Сохранённое старое имя метода — для обратной совместимости. */
    public function fetchPositions(): array
    {
        return $this->fetchPositionsSmart();
    }

    // ------------------------ helpers ------------------------

    private function safeGetJson(string $url): array
    {
        try {
            $resp = $this->http->get($url);
            $code = $resp->getStatusCode();
            if ($code >= 200 && $code < 300) {
                $json = json_decode((string)$resp->getBody(), true);
                return ['ok' => true, 'json' => is_array($json) ? $json : []];
            }
            return ['ok' => false, 'json' => []];
        } catch (GuzzleException $e) {
            return ['ok' => false, 'json' => []];
        }
    }

    /** Приводим «account_totals» к формату blocks[{id, positions{sym:{quantity}}}] */
    private function normalizeFromTotals(array $data): array
    {
        // Вариант A: { "accounts":[ {"id":"...","assets":[{"symbol":"BTC","qty":...}, ...]}, ... ] }
        if (isset($data['accounts']) && is_array($data['accounts'])) {
            $out = [];
            foreach ($data['accounts'] as $acc) {
                $id   = (string)($acc['id'] ?? '');
                $rows = $acc['assets'] ?? $acc['positions'] ?? $acc['totals'] ?? [];
                $pos  = $this->pickPositionsArray($rows);
                if ($id !== '' && $pos) $out[] = ['id' => $id, 'positions' => $pos];
            }
            return $out;
        }

        // Вариант B: { "totals":[ {"id":"...","symbol":"BTC","quantity":...}, ... ] }
        if (isset($data['totals']) && is_array($data['totals'])) {
            $byId = [];
            foreach ($data['totals'] as $row) {
                $id  = (string)($row['id'] ?? 'default');
                $sym = $this->pickSymbol($row);
                $qty = $this->pickQty($row);
                if ($sym !== null && $qty !== null) {
                    $byId[$id][$sym] = ['quantity' => $qty];
                }
            }
            $out = [];
            foreach ($byId as $id => $positions) {
                $out[] = ['id' => $id, 'positions' => $positions];
            }
            return $out;
        }

        // Вариант C: одиночный блок { "id":"...", "assets":[...] }
        if (isset($data['id']) && (isset($data['assets']) || isset($data['positions']) || isset($data['totals']))) {
            $rows = $data['assets'] ?? $data['positions'] ?? $data['totals'] ?? [];
            $pos  = $this->pickPositionsArray($rows);
            if ($pos) return [['id' => (string)$data['id'], 'positions' => $pos]];
        }

        return [];
    }

    /** Приводим старый /positions к унифицированному формату. */
    private function normalizeFromLegacyPositions(array $data): array
    {
        if (isset($data[0]['id']) && isset($data[0]['positions'])) {
            return $data;
        }
        if (isset($data['positions']) && is_array($data['positions'])) {
            $pos = $this->pickPositionsArray($data['positions']);
            $id  = (string)($data['modelId'] ?? 'default');
            return [['id' => $id, 'positions' => $pos]];
        }
        return [];
    }

    /** Делает positions{SYM:{quantity}} из массива строк. */
    private function pickPositionsArray(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $sym = $this->pickSymbol($row);
            $qty = $this->pickQty($row);
            if ($sym !== null && $qty !== null) {
                $out[$sym] = ['quantity' => $qty];
            }
        }
        return $out;
    }

    private function pickSymbol(array $row): ?string
    {
        foreach (['symbol','ticker','coin','asset','name'] as $k) {
            if (isset($row[$k]) && is_string($row[$k]) && $row[$k] !== '') {
                return strtoupper($row[$k]);
            }
        }
        return null;
    }

    private function pickQty(array $row): ?float
    {
        foreach (['quantity','qty','position','amount','size','balance','free'] as $k) {
            if (isset($row[$k]) && is_numeric($row[$k])) {
                return (float)$row[$k];
            }
        }
        return null;
    }
}
