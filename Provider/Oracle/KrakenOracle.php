<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class KrakenOracle implements OracleInterface
{
    /**
     * @var Client
     */
    private Client $client;

    /**
     * Fetches the current price for a currency pair from Kraken.
     *
     * @param string $code1 Base currency code (e.g., 'XRP').
     * @param string $code2 Quote currency code (e.g., 'USD').
     * @return float Current price of the currency pair.
     * @throws GuzzleException
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $pair = $code1 . $code2;
        $url = 'https://api.kraken.com/0/public/Ticker?pair=' . $pair;

        $response = $this->client->get($url);
        $data = json_decode((string) $response->getBody(), true);

        // Kraken's own pair naming is inconsistent (e.g. "XXRPZUSD" but plain "USDCUSD"),
        // so rather than predict it, read whichever single entry a successful Ticker
        // response for one pair actually comes back under.
        $result = $data['result'] ?? [];
        $ticker = reset($result);

        if ($ticker !== false && isset($ticker['c'][0])) {
            return (float) $ticker['c'][0];
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
