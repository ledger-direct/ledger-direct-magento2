<?php

namespace Hardcastle\LedgerDirect\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class CoinbaseOracle implements OracleInterface
{
    private Client $client;

    /**
     * Get the current exchange rate for a currency pair from Coinbase.
     *
     * @param string $code1 Base currency code (e.g., 'XRP').
     * @param string $code2 Quote currency code (e.g., 'USD').
     * @return float Current price of the currency pair.
     * @throws GuzzleException
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $url = 'https://api.coinbase.com/v2/prices/' . strtoupper($code1) . '-' . strtoupper($code2) . '/spot';

        $response = $this->client->get($url);
        $data = json_decode((string) $response->getBody(), true);

        if (isset($data['data']['amount'])) {
            return (float) $data['data']['amount'];
        }

        return 0.0;
    }

    /**
     * Set the HTTP client.
     *
     * @param Client $client
     * @return OracleInterface
     */
    public function prepare(Client $client): OracleInterface
    {
        $this->client = $client;
        return $this;
    }
}
