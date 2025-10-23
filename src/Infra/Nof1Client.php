<?php
declare(strict_types=1);

namespace Mirror\Infra;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * NOF1 API client.
 * Ищет рабочий endpoint: account-totals (дефис/змейка) → legacy /positions.
 * Возвращает массив блоков-моделей:
 *   [ ['id' => 'deepseek-chat-v3.1', 'name' => '', 'positions' => <array>], ... ]
 */
final class Nof1Client
{
    private Client $http;
    private ?string $positionsUrl;
    private ?string $accountTotalsUrl;
    private ?string $authToken;

    public function __construct(
        ?string $positionsUrl,
        float $connectTimeout = 2.0,
        float $timeout = 3.0,
        ?string $accountTotalsUrl = null,
        ?string $authToken = null
    ) {
        $this->positionsUrl     = self::nz($positionsUrl);
        $this->accountTotalsUrl = self::nz($accountTotalsUrl) ?: 'https://nof1.ai/api/account-totals';
        $this->authToken        = self::nz($authToken);

        $headers = ['Accept' => 'application/json'];
        if ($this->authToken) {
            $headers['Authorization'] = 'Bearer ' . $this->authToken;
        }

        $this->http = new Client([
            'timeout'         => $timeout,
            'connect_timeout' => $connectTimeout,
            'http_errors'     => false,
            'headers'         => $headers,
        ]);
    }

    public static function fromConfig(array $cfg): self
    {
        // поддержка лишней вложенности ['nof1']['nof1' => [...]]
        $section = (array)($cfg['nof1'] ?? []);
        if (isset($section['nof1']) && is_array($section['nof1'])) {
            $section = array_replace($section, $section['nof1']);
        }

        $p  = (string)($section['positions_url']      ?? '');
        $a  = (string)($section['account_totals_url'] ?? '');
        $tk = (string)($section['auth_token']         ?? '');

        return new self(
            self::nz($p),
            (float)($section['connect_timeout'] ?? 2.0),
            (float)($section['timeout'] ?? 3.0),
            self::nz($a) ?: 'https://nof1.ai/api/account-totals',
            self::nz($tk)
        );
    }

    /** Основной метод: сначала account-totals → потом (опц.) legacy /positions. */
    public function fetchPositions(): array
    {
        foreach ($this->candidateTotalsUrls() as $url) {
            $res = $this->safeGetJson($url);
            if ($res['ok']) {
                $blocks = $this->normalizeFromAccountTotals($res['json']);
                if ($blocks) return $blocks;
            }
        }

        if ($this->positionsUrl) {
            $legacy = $this->safeGetJson($this->positionsUrl);
            if ($legacy['ok']) {
                $blocks = $this->normalizeFromLegacyPositions($legacy['json']);
                if ($blocks) return $blocks;
            }
        }

        return [];
    }

    /** Диагностика: статусы/обрезанные тела. */
    public function diagnostics(): array
    {
        $probe = [];
        foreach ($this->candidateTotalsUrls() as $url) {
            $r = $this->safeGetJson($url);
            $probe[] = [
                'url'    => $url,
                'status' => $r['status'],
                'body'   => $this->short($r['raw']),
            ];
        }

        $posBlock = $this->positionsUrl
            ? (function () {
                $pos = $this->safeGetJson($this->positionsUrl);
                return [
                    'url'    => $this->positionsUrl,
                    'status' => $pos['status'],
                    'body'   => $this->short($pos['raw']),
                ];
            })()
            : [
                'url'    => 'n/a',
                'status' => 'n/a',
                'body'   => 'positions_url empty (skipped; endpoint deprecated)',
            ];

        return [
            'positions' => $posBlock,
            'account_totals_probes' => $probe,
        ];
    }

    // ------------------------ helpers ------------------------

    private function candidateTotalsUrls(): array
    {
        $urls = [];

        if ($this->accountTotalsUrl) $urls[] = $this->accountTotalsUrl;

        if ($this->positionsUrl) {
            $p = $this->positionsUrl;
            $snake = preg_replace('~(/api/)positions(\?.*)?$~', '$1account_totals$2', $p);
            $dash  = preg_replace('~(/api/)positions(\?.*)?$~', '$1account-totals$2', $p);
            foreach ([$snake, $dash] as $u) if (is_string($u) && self::nz($u)) $urls[] = $u;
        }

        // вариации: без query и со/без завершающего слэша
        $variations = [];
        foreach ($urls as $u) {
            $variations[] = $u;
            $noQuery = preg_replace('~\?.*$~', '', $u) ?: $u;
            $variations[] = $noQuery;
        }

        $more = [];
        foreach ($variations as $u) {
            $u = rtrim($u ?? '');
            if (!self::nz($u)) continue;
            $more[] = $u;
            $more[] = rtrim($u, '/') . '/';
        }

        // дедуп
        $uniq = [];
        $seen = [];
        foreach ($more as $u) {
            if (!isset($seen[$u])) { $uniq[] = $u; $seen[$u] = true; }
        }

        // если всё равно пусто — подстрахуемся жёстким дефолтом
        if (!$uniq) {
            $uniq = ['https://nof1.ai/api/account-totals', 'https://nof1.ai/api/account-totals/'];
        }

        return $uniq;
    }

    private function safeGetJson(?string $url): array
    {
        $url = self::nz($url);
        if (!$url) {
            return ['ok' => false, 'json' => [], 'status' => null, 'raw' => 'skipped (empty url)'];
        }

        try {
            $resp   = $this->http->get($url);
            $status = $resp->getStatusCode();
            $raw    = (string)$resp->getBody();
            $json   = json_decode($raw, true);
            $isJson = is_array($json);
            $ok     = $status >= 200 && $status < 300 && $isJson;

            return [
                'ok'     => $ok,
                'json'   => $isJson ? $json : [],
                'status' => $status,
                'raw'    => $raw,
            ];
        } catch (GuzzleException $e) {
            return ['ok' => false, 'json' => [], 'status' => 0, 'raw' => $e->getMessage()];
        }
    }

    private function short(string $s, int $limit = 500): string
    {
        return mb_strlen($s) > $limit ? (mb_substr($s, 0, $limit) . '…') : $s;
    }

    private function normalizeFromAccountTotals(array $data): array
    {
        if (isset($data['accountTotals']) && is_array($data['accountTotals'])) {
            $out = [];
            foreach ($data['accountTotals'] as $m) {
                $id   = (string)($m['id'] ?? '');
                $name = (string)($m['name'] ?? '');
                $pos  = $m['positions'] ?? [];
                if ($id !== '' && (is_array($pos) || is_object($pos))) {
                    $out[] = ['id' => $id, 'name' => $name, 'positions' => (array)$pos];
                }
            }
            if ($out) return $out;
        }

        if (isset($data['models']) && is_array($data['models'])) {
            $out = [];
            foreach ($data['models'] as $m) {
                $out[] = [
                    'id'        => (string)($m['id'] ?? ''),
                    'name'      => (string)($m['name'] ?? ''),
                    'positions' => (array)($m['positions'] ?? []),
                ];
            }
            if ($out) return $out;
        }

        if (isset($data['data']['models']) && is_array($data['data']['models'])) {
            $out = [];
            foreach ($data['data']['models'] as $m) {
                $out[] = [
                    'id'        => (string)($m['id'] ?? ''),
                    'name'      => (string)($m['name'] ?? ''),
                    'positions' => (array)($m['positions'] ?? []),
                ];
            }
            if ($out) return $out;
        }

        if (isset($data['accounts']) && is_array($data['accounts'])) {
            $out = [];
            foreach ($data['accounts'] as $acc) {
                $id   = (string)($acc['id'] ?? ($acc['name'] ?? 'default'));
                $rows = $acc['positions'] ?? $acc['assets'] ?? $acc['totals'] ?? [];
                $pos  = $this->positionsMapOrArray($rows);
                if ($id !== '' && $pos) $out[] = ['id' => $id, 'name' => (string)($acc['name'] ?? ''), 'positions' => $pos];
            }
            if ($out) return $out;
        }

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
                $out[] = ['id' => $id, 'name' => '', 'positions' => $positions];
            }
            if ($out) return $out;
        }

        if (isset($data['id']) && (isset($data['positions']) || isset($data['assets']) || isset($data['totals']))) {
            $rows = $data['positions'] ?? $data['assets'] ?? $data['totals'] ?? [];
            $pos  = $this->positionsMapOrArray($rows);
            if ($pos) return [[
                'id'        => (string)$data['id'],
                'name'      => (string)($data['name'] ?? ''),
                'positions' => $pos,
            ]];
        }

        return [];
    }

    private function normalizeFromLegacyPositions(array $data): array
    {
        if (isset($data[0]['id']) && isset($data[0]['positions'])) {
            return $data;
        }
        if (isset($data['positions']) && is_array($data['positions'])) {
            $id  = (string)($data['modelId'] ?? 'default');
            $pos = $this->positionsMapOrArray($data['positions']);
            return [[ 'id' => $id, 'name' => '', 'positions' => $pos ]];
        }
        return [];
    }

    private function positionsMapOrArray(array $rows): array
    {
        $isAssoc = array_keys($rows) !== range(0, count($rows) - 1);
        $out = [];

        if ($isAssoc) {
            foreach ($rows as $sym => $row) {
                if (!is_array($row)) continue;
                $qty = $this->pickQty($row);
                if ($qty === null) continue;
                $out[strtoupper((string)$sym)] = ['quantity' => (float)$qty] + $row;
            }
            return $out;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $sym = $this->pickSymbol($row);
            $qty = $this->pickQty($row);
            if ($sym !== null && $qty !== null) {
                $out[$sym] = ['quantity' => (float)$qty] + $row;
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
        foreach (['quantity','qty','position','amount','size','balance','free','value'] as $k) {
            if (isset($row[$k]) && is_numeric($row[$k])) {
                return (float)$row[$k];
            }
        }
        return null;
    }

    private static function nz(?string $s): ?string
    {
        $s = is_string($s) ? trim($s) : '';
        return $s === '' ? null : $s;
    }
}
