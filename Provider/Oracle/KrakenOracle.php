<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

use GuzzleHttp\Client;

class KrakenOracle implements OracleInterface
{
    private Client $client;

    /**
     * @param string $code1
     * @param string $code2
     * @return float
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $pair = $code1 . $code2;
        $url = 'https://api.kraken.com/0/public/Ticker?pair=' . $pair;

        $response = $this->client->get($url);
        $data = json_decode((string) $response->getBody(), true);

        // TODO: Get proper array keys from code1 and code2
        if (isset($data['result']['XXRPZUSD']['c'])) {
            return (float) $data['result']['XXRPZUSD']['c'][0];
        }

        return 0.0;
    }

    public function prepare(Client $client): OracleInterface
    {
        $this->client = $client;

        return $this;
    }
}
