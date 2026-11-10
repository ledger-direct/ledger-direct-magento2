<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit;

use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Both chains' sync services get the PSR-16 cache and their chain from etc/di.xml.
 *
 * Without the cache the core cannot remember a testnet reset, and every sync after one starts with a
 * failing request instead of only the first sync per account. Magento leaves an optional constructor
 * argument at its default when di.xml does not name it, so nothing else fails when the wiring is
 * missing.
 */
class SyncServiceWiringTest extends TestCase
{
    private const CACHE = 'Hardcastle\\LedgerDirect\\Model\\Cache\\RateCache';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function syncServices(): iterable
    {
        yield 'XRPL' => ['/config/type[@name="Hardcastle\\LedgerDirect\\Core\\Xrpl\\SyncService"]', 'XRPL'];
        yield 'XAHAU' => ['/config/virtualType[@name="Hardcastle\\LedgerDirect\\Xahau\\SyncService"]', 'XAHAU'];
    }

    /**
     * @dataProvider syncServices
     */
    public function testTheSyncServiceGetsTheCacheAndItsChain(string $path, string $chain): void
    {
        $di = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 2) . '/etc/di.xml'));
        $nodes = $di->xpath($path);
        $this->assertCount(1, $nodes, $path);

        $arguments = [];
        foreach ($nodes[0]->arguments->argument as $argument) {
            $arguments[(string) $argument['name']] = trim((string) $argument);
        }

        $this->assertSame(self::CACHE, $arguments['cache'] ?? null, 'cache');
        $this->assertSame($chain, $arguments['chain'] ?? null, 'chain');
    }

    public function testTheArgumentNamesAreTheConstructorParameters(): void
    {
        $parameters = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod(SyncService::class, '__construct'))->getParameters()
        );

        $this->assertContains('cache', $parameters);
        $this->assertContains('chain', $parameters);
    }
}
