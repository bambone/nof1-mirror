<?php

namespace Mirror\Infra;

use GuzzleHttp\Client;

final class BybitClient
{
    private Client $http;
    private string $apiKey;
    private string $apiSecret;

    public function __construct(
        private string $baseUrl,
        string $apiKey,
        string $apiSecret
    ) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;

        $this->http = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout'  => 7.0,
        ]);
    }

    private function ts(): string
    {
        return (string) (int) (microtime(true) * 1000);
    }

    private function sign(string $timestamp, string $recvWindow, string $payload): string
    {
        // payload = bodyJson (POST) ИЛИ queryString (GET)
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
            'X-BAPI-SIGN-TYPE'   => '2', // <— добавили обязательно для V5
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
        ];
    }


    private function buildQuery(array $q): string
    {
        if (!$q) return '';
        ksort($q);
        // В GET для подписи нужна именно queryString (без URL-декодинга!)
        return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    private function get(string $path, array $query): array
    {
        $timestamp  = $this->ts();
        $recvWindow = '5000';
        $queryString = $this->buildQuery($query);
        $sign = $this->sign($timestamp, $recvWindow, $queryString);

        $resp = $this->http->get($path, [
            'headers' => $this->headers($sign, $timestamp, $recvWindow),
            'query'   => $query, // реально уйдёт в URL
        ]);
        return json_decode((string)$resp->getBody(), true);
    }

    private function post(string $path, array $body): array
    {
        $timestamp  = $this->ts();
        $recvWindow = '5000';
        $bodyJson   = json_encode($body, JSON_UNESCAPED_SLASHES);
        $sign       = $this->sign($timestamp, $recvWindow, $bodyJson);

        $resp = $this->http->post($path, [
            'headers' => $this->headers($sign, $timestamp, $recvWindow),
            'body'    => $bodyJson,
        ]);
        return json_decode((string)$resp->getBody(), true);
    }

    // ====== PUBLIC API (V5) ======

    public function getPositions(string $category, string $symbol): array
    {
        // GET /v5/position/list
        return $this->get('/v5/position/list', [
            'category' => $category,
            'symbol'   => $symbol,
        ]);
    }

    public function placeMarketOrder(string $category, string $symbol, string $side, float $qty, string $clientId): array
    {
        // POST /v5/order/create
        return $this->post('/v5/order/create', [
            'category'     => $category,
            'symbol'       => $symbol,
            'side'         => $side,          // Buy / Sell
            'orderType'    => 'Market',
            'qty'          => (string)$qty,
            'timeInForce'  => 'IOC',
            'reduceOnly'   => false,
            'orderLinkId'  => $clientId,
        ]);
    }

    public function closeMarket(string $category, string $symbol, float $qty, string $side, string $clientId): array
    {
        // reduce-only (рыночное закрытие)
        return $this->post('/v5/order/create', [
            'category'     => $category,
            'symbol'       => $symbol,
            'side'         => $side,          // для закрытия: long→Sell, short→Buy
            'orderType'    => 'Market',
            'qty'          => (string)$qty,
            'timeInForce'  => 'IOC',
            'reduceOnly'   => true,
            'orderLinkId'  => $clientId,
        ]);
    }

    public function setTpSl(string $category, string $symbol, ?float $tp, ?float $sl): array
    {
        // POST /v5/position/trading-stop
        $body = [
            'category' => $category,
            'symbol'   => $symbol,
        ];
        if ($tp !== null) $body['takeProfit'] = (string)$tp;
        if ($sl !== null) $body['stopLoss']   = (string)$sl;

        return $this->post('/v5/position/trading-stop', $body);
    }

    public function getInstrumentsInfo(string $category, string $symbol): array
    {
        // GET /v5/market/instruments-info
        return $this->get('/v5/market/instruments-info', [
            'category' => $category,
            'symbol'   => $symbol,
        ]);
    }

    public function setLeverage(string $category, string $symbol, int $buy, int $sell): array
    {
        // POST /v5/position/set-leverage
        return $this->post('/v5/position/set-leverage', [
            'category'     => $category,
            'symbol'       => $symbol,
            'buyLeverage'  => (string)$buy,
            'sellLeverage' => (string)$sell,
        ]);
    }

    public function getTicker(string $category, string $symbol): array
    {
        // GET /v5/market/tickers
        return $this->get('/v5/market/tickers', [
            'category' => $category,
            'symbol'   => $symbol,
        ]);
    }
}
