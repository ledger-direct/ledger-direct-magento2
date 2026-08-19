<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Provider\Oracle\CoingeckoOracle;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CoingeckoOracleTest extends TestCase
{
    /** @var Client|MockObject */
    private $client;

    private CoingeckoOracle $oracle;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->oracle = (new CoingeckoOracle())->prepare($this->client);
    }

    public function testGetCurrentPriceForPairMapsXrpToRippleId()
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with($this->stringContains('ids=ripple&vs_currencies=usd'))
            ->willReturn(new Response(200, [], json_encode(['ripple' => ['usd' => 0.999978]])));

        $price = $this->oracle->getCurrentPriceForPair('XRP', 'USD');

        $this->assertSame(0.999978, $price);
    }

    public function testGetCurrentPriceForPairMapsUsdcToUsdCoinId()
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with($this->stringContains('ids=usd-coin&vs_currencies=usd'))
            ->willReturn(new Response(200, [], json_encode(['usd-coin' => ['usd' => 0.999742]])));

        $price = $this->oracle->getCurrentPriceForPair('USDC', 'USD');

        $this->assertSame(0.999742, $price);
    }

    public function testGetCurrentPriceForPairMapsRlusdToRippleUsdId()
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with($this->stringContains('ids=ripple-usd&vs_currencies=usd'))
            ->willReturn(new Response(200, [], json_encode(['ripple-usd' => ['usd' => 1.0]])));

        $price = $this->oracle->getCurrentPriceForPair('RLUSD', 'USD');

        $this->assertSame(1.0, $price);
    }

    /**
     * Regression test: EURC used to be unmapped and fell back to the literal "eurc",
     * which Coingecko doesn't recognise (empty response, silently priced as 0.0).
     * The real id, confirmed via Coingecko's own /search endpoint, is "euro-coin".
     */
    public function testGetCurrentPriceForPairMapsEurcToEuroCoinId()
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with($this->stringContains('ids=euro-coin&vs_currencies=usd'))
            ->willReturn(new Response(200, [], json_encode(['euro-coin' => ['usd' => 1.0]])));

        $price = $this->oracle->getCurrentPriceForPair('EURC', 'USD');

        $this->assertSame(1.0, $price);
    }

    public function testGetCurrentPriceForPairReturnsZeroWhenIdIsUnrecognised()
    {
        // Verified live: an unmapped id Coingecko doesn't know returns an empty object.
        $this->client->method('get')->willReturn(new Response(200, [], json_encode([])));

        $price = $this->oracle->getCurrentPriceForPair('NOTACOIN', 'USD');

        $this->assertSame(0.0, $price);
    }
}
