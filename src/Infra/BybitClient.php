<?php

declare(strict_types=1);

namespace Mirror\Infra;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Bybit REST V5 клиент (универсальный для spot/linear/inverse/option).
 *
 * Ключевые моменты:
 * - Подпись V5: preSign = timestamp + apiKey + recvWindow + payload
 *   где payload = bodyJson (POST) ИЛИ queryString (GET, RFC3986).
 * - Обязательно: заголовок X-BAPI-SIGN-TYPE: 2
 * - category:
 *     spot    — спот
 *     linear  — USDT/USDC перпетуалы
 *     inverse — inverse перпетуалы
 *     option  — опционы
 *
 * Включает:
 *  • подписанные GET/POST
 *  • маркет-данные: тикер, kline, instruments-info
 *  • торговлю: маркет фьючи/спот, reduce-only, TP/SL, плечо
 *  • кошелёк: доступный баланс по типу аккаунта/монете + «умный» фолбэк для UTA
 */
final class BybitClient
{
    private Client $http;
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $apiSecret,
        float $timeout = 7.0
    ) {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->apiKey    = $apiKey;
        $this->apiSecret = $apiSecret;

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => $timeout,
        ]);
    }

    // ========= ВСПОМОГАТЕЛЬНЫЕ =========

    private function ts(): string
    {
        // V5 ожидает миллисекунды
        return (string)((int)(microtime(true) * 1000));
    }

    private function sign(string $timestamp, string $recvWindow, string $payload): string
    {
        // payload = bodyJson (POST) или queryString (GET)
        $preSign = $timestamp . $this->apiKey . $recvWindow . $payload;
        return hash_hmac('sha256', $preSign, $this->apiSecret);
    }

    private function headers(string $sign, string $timestamp, string $recvWindow): array
    {
        return [
            'X-BAPI-API-KEY'     => $this->apiKey,
            'X-BAPI-SIGN'        => $sign,
            'X-BAPI-TIMESTAMP'   => $timestamp,
            'X-BAPI-RECV-WINDOW' => $recvWindow,
            'X-BAPI-SIGN-TYPE'   => '2', // V5, обязательный заголовок
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
        ];
    }

    private function buildQuery(array $q): string
    {
        if (!$q) return '';
        ksort($q);
        // Для подписи нужен RFC3986 (сырой, без декодирования пробелов и т.п.)
        return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /** Подписанный GET. В сетевой ошибке возвращает retCode=-1. */
    private function get(string $path, array $query): array
    {
        $timestamp   = $this->ts();
        $recvWindow  = '5000';
        $queryString = $this->buildQuery($query);
        $sign        = $this->sign($timestamp, $recvWindow, $queryString);

        try {
            $resp = $this->http->get($path, [
                'headers' => $this->headers($sign, $timestamp, $recvWindow),
                'query'   => $query,
            ]);
            return json_decode((string)$resp->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            return ['retCode' => -1, 'retMsg' => 'HTTP GET failed: ' . $e->getMessage()];
        }
    }

    /** Подписанный POST. В сетевой ошибке возвращает retCode=-1. */
    private function post(string $path, array $body): array
    {
        $timestamp  = $this->ts();
        $recvWindow = '5000';
        $bodyJson   = json_encode($body, JSON_UNESCAPED_SLASHES);
        $sign       = $this->sign($timestamp, $recvWindow, (string)$bodyJson);

        try {
            $resp = $this->http->post($path, [
                'headers' => $this->headers($sign, $timestamp, $recvWindow),
                'body'    => $bodyJson,
            ]);
            return json_decode((string)$resp->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            return ['retCode' => -1, 'retMsg' => 'HTTP POST failed: ' . $e->getMessage()];
        }
    }

    // ========= МАРКЕТ-ДАННЫЕ =========

    /** GET /v5/market/tickers */
    public function getTicker(string $category, string $symbol): array
    {
        return $this->get('/v5/market/tickers', [
            'category' => $category,
            'symbol'   => $symbol,
        ]);
    }

    /**
     * GET /v5/market/kline
     * interval: '1','3','5','15','30','60','120','240','360','720','D','W','M'
     */
    public function getKlines(string $category, string $symbol, string $interval, int $limit = 100): array
    {
        return $this->get('/v5/market/kline', [
            'category' => $category,   // 'spot' для спота; 'linear' для perp
            'symbol'   => $symbol,
            'interval' => $interval,
            'limit'    => $limit,
        ]);
    }

    /** GET /v5/market/instruments-info */
    public function getInstrumentsInfo(string $category, string $symbol): array
    {
        return $this->get('/v5/market/instruments-info', [
            'category' => $category,
            'symbol'   => $symbol,
        ]);
    }

    // ========= ПОЗИЦИИ (деривативы) =========

    /** GET /v5/position/list */
    public function getPositions(string $category, string $symbol): array
    {
        return $this->get('/v5/position/list', [
            'category' => $category, // linear/inverse/option
            'symbol'   => $symbol,
        ]);
    }

    // ========= ОРДЕРА: ДЕРИВАТИВЫ =========

    /** POST /v5/order/create — маркет-вход (qty в контрактах). */
    public function placeMarketOrder(string $category, string $symbol, string $side, float $qty, string $clientId): array
    {
        return $this->post('/v5/order/create', [
            'category'     => $category,  // linear/inverse/option
            'symbol'       => $symbol,
            'side'         => $side,      // Buy / Sell
            'orderType'    => 'Market',
            'qty'          => (string)$qty,
            'timeInForce'  => 'IOC',
            'reduceOnly'   => false,
            'orderLinkId'  => $clientId,
        ]);
    }

    /** POST /v5/order/create — reduce-only закрытие/уменьшение. */
    public function closeMarket(string $category, string $symbol, float $qty, string $side, string $clientId): array
    {
        return $this->post('/v5/order/create', [
            'category'     => $category,
            'symbol'       => $symbol,
            'side'         => $side,          // закрытие: long→Sell, short→Buy
            'orderType'    => 'Market',
            'qty'          => (string)$qty,
            'timeInForce'  => 'IOC',
            'reduceOnly'   => true,
            'orderLinkId'  => $clientId,
        ]);
    }

    /** POST /v5/position/trading-stop — установка TP/SL. */
    public function setTpSl(string $category, string $symbol, ?float $tp, ?float $sl): array
    {
        $body = [
            'category' => $category,
            'symbol'   => $symbol,
        ];
        if ($tp !== null) $body['takeProfit'] = (string)$tp;
        if ($sl !== null) $body['stopLoss']   = (string)$sl;

        return $this->post('/v5/position/trading-stop', $body);
    }

    /** POST /v5/position/set-leverage */
    public function setLeverage(string $category, string $symbol, int $buy, int $sell): array
    {
        return $this->post('/v5/position/set-leverage', [
            'category'     => $category,
            'symbol'       => $symbol,
            'buyLeverage'  => (string)$buy,
            'sellLeverage' => (string)$sell,
        ]);
    }

    // ========= ОРДЕРА: СПОТ =========

    /** POST /v5/order/create — спот-маркет (qty в базовой валюте, напр. 12.34 XRP). */
    public function placeSpotMarket(string $symbol, string $side, float $qtyBase, string $clientId): array
    {
        return $this->post('/v5/order/create', [
            'category'     => 'spot',
            'symbol'       => $symbol,
            'side'         => $side,       // Buy / Sell
            'orderType'    => 'Market',
            'qty'          => (string)$qtyBase,
            'timeInForce'  => 'IOC',
            'orderLinkId'  => $clientId,
        ]);
    }

    /** Шаг/минимум лота для спота (через instruments-info с category='spot'). */
    public function getSpotLots(string $symbol): array
    {
        return $this->getInstrumentsInfo('spot', $symbol);
    }

    // ========= КОШЕЛЁК / БАЛАНС =========

    /**
     * Возвращает доступный баланс по типу аккаунта и монете.
     * accountType: 'UNIFIED' | 'SPOT' | 'CONTRACT' | 'FUND' | 'OPTION'
     */
    public function getAvailable(string $accountType = 'UNIFIED', string $coin = 'USDT'): float
    {
        $resp = $this->get('/v5/account/wallet-balance', [
            'accountType' => $accountType,
            'coin'        => $coin,
        ]);

        if (($resp['retCode'] ?? 1) !== 0) {
            return 0.0;
        }

        // result.list[0].coin[] — ищем нашу монету
        $list  = $resp['result']['list'][0] ?? [];
        $coins = $list['coin'] ?? [];
        if (!is_array($coins) || empty($coins)) return 0.0;

        $row = null;
        foreach ($coins as $c) {
            if (($c['coin'] ?? '') === $coin) {
                $row = $c;
                break;
            }
        }
        if ($row === null) {
            $row = $coins[0];
        }

        // Возможные поля: availableToWithdraw, availableBalance
        $avail = $row['availableToWithdraw'] ?? ($row['availableBalance'] ?? 0);
        return (float)$avail;
    }

    /** Сахар для USDT (по умолчанию UNIFIED). */
    public function getUsdtAvailable(string $accountType = 'UNIFIED'): float
    {
        return $this->getAvailable($accountType, 'USDT');
    }

    /** «Сырой» ответ /v5/account/wallet-balance (можно запросить без coin, чтобы получить все монеты) */
    public function getWalletBalanceRaw(string $accountType = 'UNIFIED', ?string $coin = 'USDT'): array
    {
        $query = ['accountType' => $accountType];
        if ($coin !== null) {
            $query['coin'] = $coin;
        }
        return $this->get('/v5/account/wallet-balance', $query);
    }

    /**
     * Попытка извлечь «доступный» баланс из сырого ответа кошелька.
     * Учитывает особенности UNIFIED (totalAvailableBalance и т.п.)
     */
    private function extractAvailableFromWallet(array $resp, string $coin = 'USDT'): float
    {
        if (($resp['retCode'] ?? 1) !== 0) return 0.0;

        $list = $resp['result']['list'][0] ?? [];
        if (!$list) return 0.0;

        // 1) Ищем конкретную монету в coin[]
        $coins = $list['coin'] ?? [];
        if (is_array($coins) && $coins) {
            foreach ($coins as $c) {
                if (($c['coin'] ?? '') === $coin) {
                    foreach (['availableToWithdraw', 'availableBalance', 'walletBalance', 'cashBalance'] as $k) {
                        if (isset($c[$k]) && is_numeric($c[$k])) {
                            return (float)$c[$k];
                        }
                    }
                }
            }
        }

        // 2) Фолбэк: агрегаты UTA
        foreach (['totalAvailableBalance', 'totalEquity', 'accountIM', 'accountMM'] as $k) {
            if (isset($list[$k]) && is_numeric($list[$k])) {
                return (float)$list[$k];
            }
        }

        // 3) На всякий случай
        if (isset($list['walletBalance']) && is_numeric($list['walletBalance'])) {
            return (float)$list['walletBalance'];
        }

        return 0.0;
    }

    /**
     * Вернёт доступный USDT, перебирая кошельки в приоритете:
     * UNIFIED → SPOT → FUND → CONTRACT → OPTION. Возвращает максимум.
     * Можно передать логгер для построчного вывода разбиения (breakdown).
     */
    public function getAnyUsdtAvailable(bool $withBreakdown = false, ?callable $logger = null): float
    {
        $types = ['UNIFIED', 'SPOT', 'FUND', 'CONTRACT', 'OPTION'];
        $max   = 0.0;

        foreach ($types as $t) {
            $raw = ($t === 'UNIFIED')
                ? $this->getWalletBalanceRaw($t, null)      // без coin → доступны агрегаты
                : $this->getWalletBalanceRaw($t, 'USDT');

            $val = $this->extractAvailableFromWallet($raw, 'USDT');

            if ($withBreakdown && $logger) {
                $logger(sprintf('balance[%s]=%s (retCode=%s)', $t, $val, $raw['retCode'] ?? 'n/a'));
            }

            if ($val > $max) $max = $val;
        }
        return $max;
    }
}
