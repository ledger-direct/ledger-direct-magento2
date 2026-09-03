<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model\Cache;

use DateInterval;
use Hardcastle\LedgerDirect\Model\Cache\RateCache;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RateCacheTest extends TestCase
{
    private const CORE_KEY = 'ledger-direct.rate.v1.testnet.XRP.EUR';

    /** @var CacheInterface|MockObject */
    private $magentoCache;

    private RateCache $cache;

    protected function setUp(): void
    {
        $this->magentoCache = $this->createMock(CacheInterface::class);
        $this->cache = new RateCache($this->magentoCache);
    }

    /**
     * Magento cache identifiers allow [A-Za-z0-9_] only; the core's keys carry dots and
     * hyphens, so they are mapped - deterministically, and without two keys colliding.
     */
    public function testCoreKeysAreMappedToValidMagentoIdentifiers(): void
    {
        $identifier = $this->cache->identifier(self::CORE_KEY);

        $this->assertSame('LEDGER_DIRECT_LEDGER_DIRECT_RATE_V1_TESTNET_XRP_EUR', $identifier);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $identifier);
        $this->assertNotSame($identifier, $this->cache->identifier('ledger-direct.rate.v1.mainnet.XRP.EUR'));
    }

    public function testAnEntryRoundTripsThroughJson(): void
    {
        $entry = ['rate' => 2.5, 'fetched_at' => 1700000000];

        $this->magentoCache->expects($this->once())
            ->method('save')
            ->with(json_encode($entry), $this->cache->identifier(self::CORE_KEY), [RateCache::CACHE_TAG], 300)
            ->willReturn(true);
        $this->magentoCache->method('load')->willReturn(json_encode($entry));

        $this->assertTrue($this->cache->set(self::CORE_KEY, $entry, 300));
        $this->assertSame($entry, $this->cache->get(self::CORE_KEY));
    }

    public function testAMissReturnsTheDefault(): void
    {
        $this->magentoCache->method('load')->willReturn(false);

        $this->assertNull($this->cache->get(self::CORE_KEY));
        $this->assertSame('fallback', $this->cache->get(self::CORE_KEY, 'fallback'));
        $this->assertFalse($this->cache->has(self::CORE_KEY));
    }

    public function testAForeignValueUnderTheSameKeyReadsAsAMiss(): void
    {
        $this->magentoCache->method('load')->willReturn('not json {');

        $this->assertNull($this->cache->get(self::CORE_KEY));
    }

    public function testADateIntervalTtlIsConvertedToSeconds(): void
    {
        $this->magentoCache->expects($this->once())
            ->method('save')
            ->with($this->anything(), $this->anything(), $this->anything(), 90)
            ->willReturn(true);

        $this->cache->set(self::CORE_KEY, ['rate' => 1.0, 'fetched_at' => 1], new DateInterval('PT1M30S'));
    }
}
