<?php
namespace Mirror\Infra;

use GuzzleHttp\Client;

final class Nof1Client
{
    public function __construct(
        private string $positionsUrl,
        private float $connectTimeout,
        private float $timeout
    ) {}

    public function fetchPositions(): array
    {
        $cli = new Client([
            'headers' => [
                'accept' => '*/*',
                'x-kl-ajax-request' => 'Ajax_Request',
                'user-agent' => 'MirrorBot/1.0',
            ],
            'connect_timeout' => $this->connectTimeout,
            'timeout' => $this->timeout,
        ]);

        $r = $cli->get($this->positionsUrl);
        $json = json_decode((string)$r->getBody(), true);
        return $json['positions'] ?? [];
    }
}
