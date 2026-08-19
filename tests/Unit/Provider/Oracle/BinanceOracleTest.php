<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Provider\Oracle\BinanceOracle;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BinanceOracleTest extends TestCase
{
    /** @var Client|MockObject */
    private $client;

    private BinanceOracle $oracle;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->oracle = (new BinanceOracle())->prepare($this->client);
    }

    /**
     * A USD quote is requested against USDT (Binance has no direct USD pairs), verified
     * live: symbol=XRPUSDT.
     */
    public function testGetCurrentPriceForPairQueriesUsdtForUsdQuote()
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with($this->stringContains('symbol=XRPUSDT'))
            ->willReturn(new Response(200, [], json_encode(['symbol' => 'XRPUSDT', 'price' => '1.00060000'])));

        $price = $this->oracle->getCurrentPriceForPair('XRP', 'USD');

        $this->assertSame(1.0006, $price);
    }

    /**
     * A non-USD quote is used as-is, verified live: symbol=XRPEUR.
     */
    public function testGetCurrentPriceForPairUsesNonUsdQuoteDirectly()
    {
        $this->client->expects($this->once())
            ->method('get')
            ->with($this->stringContains('symbol=XRPEUR'))
            ->willReturn(new Response(200, [], json_encode(['symbol' => 'XRPEUR', 'price' => '0.86390000'])));

        $price = $this->oracle->getCurrentPriceForPair('XRP', 'EUR');

        $this->assertSame(0.8639, $price);
    }

    /**
     * Verified live: an unlisted symbol (e.g. EURCEUR - Binance has no EURC pairs at all)
     * gets HTTP 400 with an error body, not a 200 with a missing "price" key. Guzzle's
     * default http_errors setting turns that into a thrown exception, matching this
     * method's own @throws GuzzleException - the caller (CryptoPriceProvider) is the one
     * that catches it and treats this oracle as unavailable for the pair, not this class.
     */
    public function testGetCurrentPriceForPairThrowsForUnknownSymbol()
    {
        $this->client->method('get')->willThrowException(
            new ClientException(
                'Client error',
                new Request('GET', 'https://api.binance.com'),
                new Response(400, [], json_encode(['code' => -1121, 'msg' => 'Invalid symbol.']))
            )
        );

        $this->expectException(ClientException::class);

        $this->oracle->getCurrentPriceForPair('EURC', 'USD');
    }
}
