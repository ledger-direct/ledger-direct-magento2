<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Provider\Oracle\KrakenOracle;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class KrakenOracleTest extends TestCase
{
    /** @var Client|MockObject */
    private $client;

    private KrakenOracle $oracle;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->oracle = (new KrakenOracle())->prepare($this->client);
    }

    /**
     * Kraken's own pair naming is inconsistent - "XXRPZUSD" for XRP/USD, but plain
     * "USDCUSD" for USDC/USD. The oracle used to hardcode "XXRPZUSD", silently returning
     * 0.0 for every other pair. This is the regression test for reading whichever single
     * key a Ticker response actually comes back under, instead of predicting it.
     */
    public function testGetCurrentPriceForPairReadsResponseKeyGenerically()
    {
        $this->client->method('get')->willReturn(new Response(200, [], json_encode([
            'error' => [],
            'result' => [
                'USDCUSD' => ['c' => ['0.99980000', '61.20736953']],
            ],
        ])));

        $price = $this->oracle->getCurrentPriceForPair('USDC', 'USD');

        $this->assertSame(0.9998, $price);
    }

    public function testGetCurrentPriceForPairStillWorksForLegacyXrpKeyFormat()
    {
        $this->client->method('get')->willReturn(new Response(200, [], json_encode([
            'error' => [],
            'result' => [
                'XXRPZUSD' => ['c' => ['1.00000000', '2.30046975']],
            ],
        ])));

        $price = $this->oracle->getCurrentPriceForPair('XRP', 'USD');

        $this->assertSame(1.0, $price);
    }

    public function testGetCurrentPriceForPairReturnsZeroOnUnknownPairError()
    {
        $this->client->method('get')->willReturn(new Response(200, [], json_encode([
            'error' => ['EQuery:Unknown asset pair'],
        ])));

        $price = $this->oracle->getCurrentPriceForPair('NOTAPAIR', 'XYZ');

        $this->assertSame(0.0, $price);
    }
}
