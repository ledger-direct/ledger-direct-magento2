<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

use GuzzleHttp\Client;

class RippleOracle implements OracleInterface
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
        /*
         * {
         * "action": "Get Exchange Rate",
         * "route": "/v2/exchange_rates/{:base}/{:counter}",
         * "example": "http://data.ripple.com/v2/exchange_rates/XRP/USD+rvYAfWj5gh67oV6fW32ZzP3Aw4Eubs59B"
         * }
         */
        $gatehubAccount = 'rhub8VRN55s94qWKDv6jmDy1pUykJzF3wq';
        $url = 'https://data.ripple.com/v2/exchange_rates/XRP/' . $code2 . '+' . $gatehubAccount;

        $response = $this->client->get($url);
        $data = json_decode((string) $response->getBody(), true);

        if (isset($data['result']['rate'])) {
            return (float) $data['result']['rate'];
        }

        return 0.0;
    }

    public function prepare(Client $client): OracleInterface
    {
        $this->client = $client;

        return $this;
    }
}
