<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

use GuzzleHttp\Client;

class BinanceOracle implements OracleInterface
{
    private Client $client;
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $symbol = $code1 . (($code2 === 'USD') ? 'USDT' : $code2);
        $url = 'https://api.binance.com/api/v1/ticker/price?symbol=' . $symbol;


        $response = $this->client->get($url);
        $data = json_decode((string) $response->getBody(), true);

        if (isset($data['price'])) {
            return (float) $data['price'];
        }

        return 0.0;
    }

    public function prepare(Client $client): OracleInterface
    {
        $this->client = $client;

        return $this;
    }
}
