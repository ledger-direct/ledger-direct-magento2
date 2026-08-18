<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CryptoPriceProviderTest extends TestCase
{
    /** @var Client|MockObject */
    private $client;

    /** @var LoggerInterface|MockObject */
    private $logger;

    private CryptoPriceProvider $provider;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->provider = new CryptoPriceProvider($this->client, $this->logger);
    }

    /**
     * The provider used to be hardcoded to XRP; this is the core regression test proving
     * it now works generically for any base asset (e.g. a configured stablecoin token).
     */
    public function testGetCurrentExchangeRateWorksForNonXrpBaseAsset()
    {
        $this->client->method('get')->willReturnCallback(function (string $uri) {
            if (str_contains($uri, 'binance.com')) {
                return new Response(200, [], json_encode(['price' => '0.92']));
            }
            if (str_contains($uri, 'coingecko.com')) {
                return new Response(200, [], json_encode(['usd-coin' => ['eur' => 0.93]]));
            }
            // KrakenOracle only recognises a hardcoded XRP/USD response shape, so any other
            // pair naturally yields 0.0 and gets filtered out - no need to fake a match here.
            return new Response(200, [], json_encode(['result' => []]));
        });

        $rate = $this->provider->getCurrentExchangeRate('USDC', 'EUR');

        $this->assertEqualsWithDelta(0.925, $rate, 0.0001);
    }

    /**
     * RLUSD/USDC are pegged to USD, so a USD quote returns rate=1 without ever calling
     * an oracle - this is the fast-path used to avoid a redundant round-trip for the
     * common case of a USD store, matching Shopware's RlusdPriceProvider/UsdcPriceProvider.
     */
    public function testGetCurrentExchangeRateShortCircuitsToOneForUsdPeggedAssetQuotedInUsd()
    {
        $this->client->expects($this->never())->method('get');

        $this->assertSame(1.0, $this->provider->getCurrentExchangeRate('RLUSD', 'USD'));
        $this->assertSame(1.0, $this->provider->getCurrentExchangeRate('USDC', 'USD'));
    }

    /**
     * XRP is deliberately not treated as USD-pegged: even for a USD quote, its price must
     * always come from a real oracle query.
     */
    public function testGetCurrentExchangeRateStillQueriesOraclesForXrpQuotedInUsd()
    {
        $this->client->expects($this->atLeastOnce())
            ->method('get')
            ->willReturn(new Response(200, [], json_encode(['price' => '1.0006'])));

        $rate = $this->provider->getCurrentExchangeRate('XRP', 'USD');

        $this->assertEqualsWithDelta(1.0006, $rate, 0.0001);
    }

    public function testGetCurrentExchangeRateReturnsFalseWhenAllOraclesFail()
    {
        $this->client->method('get')->willThrowException(
            new RequestException('network error', new Request('GET', 'https://example.test'))
        );

        $this->logger->expects($this->exactly(3))->method('warning');

        $rate = $this->provider->getCurrentExchangeRate('USDC', 'EUR');

        $this->assertFalse($rate);
    }

    public function testGetCurrentExchangeRateSkipsFailingOracleAndUsesTheRest()
    {
        $this->client->method('get')->willReturnCallback(function (string $uri) {
            if (str_contains($uri, 'binance.com')) {
                throw new RequestException('binance down', new Request('GET', $uri));
            }
            if (str_contains($uri, 'coingecko.com')) {
                return new Response(200, [], json_encode(['usd-coin' => ['eur' => 0.93]]));
            }
            return new Response(200, [], json_encode(['result' => []]));
        });

        $this->logger->expects($this->once())->method('warning');

        $rate = $this->provider->getCurrentExchangeRate('USDC', 'EUR');

        $this->assertEqualsWithDelta(0.93, $rate, 0.0001);
    }
}
