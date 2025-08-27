<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class CoingeckoOracle implements OracleInterface
{
    private Client $client;

    /**
     * Fetches the current price for a given pair using Coingecko API.
     *
     * @param string $code1 Base currency (e.g., XRP).
     * @param string $code2 Quote currency (e.g., USD).
     * @return float
     * @throws GuzzleException
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $code1 = $this->mapCurrencyCode($code1);
        $code2 = $this->mapCurrencyCode($code2);

        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . strtolower($code1) . '&vs_currencies=' . strtolower($code2);

        $response = $this->client->get($url);
        $data = json_decode((string) $response->getBody(), true);

        if (isset($data[strtolower($code1)][strtolower($code2)])) {
            return (float) $data[strtolower($code1)][strtolower($code2)];
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

    /**
     * Maps currency codes to Coingecko's expected format.
     *
     * @param string $currencyCode The currency code to map.
     * @return string Mapped currency code.
     */
    private function mapCurrencyCode(string $currencyCode): string
    {
        $mappings = [
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'USDT' => 'tether',
            'XRP' => 'ripple',
            'USDC' => 'usd-coin',
            'RLUSD' => 'ripple-usd',
        ];

        return $mappings[$currencyCode] ?? strtolower($currencyCode);
    }
}
