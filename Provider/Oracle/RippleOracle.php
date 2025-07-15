<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

use GuzzleHttp\Client;

class RippleOracle implements OracleInterface
{
    private Client $client;

    /**
     * Get the current price for a currency pair from Ripple. It seems this is no longer supported as of 2025.
     *
     * @param string $code1 Currency code for the first currency (e.g., 'XRP').
     * @param string $code2 Currency code for the second currency (e.g., 'USD').
     * @return float Current price of the currency pair.
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        // This seems obsolete as of 2025

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
