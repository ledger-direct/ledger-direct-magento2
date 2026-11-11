<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit;

use FilesystemIterator;
use Hardcastle\LedgerDirect\Model\PaymentMethods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every `LedgerDirect.*` key the module prints has a translation in every language file. Magento
 * shows a missing key as it is, so the customer would read the key instead of a sentence.
 */
class TranslationsTest extends TestCase
{
    /**
     * Keys the templates complete at runtime, with what follows the literal part: the chain, and
     * for the network name also the network.
     */
    private const COMPOSED = [
        'LedgerDirect.paymentPage.network.' => ['.mainnet', '.testnet'],
        'LedgerDirect.paymentPage.poweredBy.' => [''],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function languageFiles(): iterable
    {
        foreach (glob(self::moduleDir() . '/i18n/*.csv') as $file) {
            yield basename($file, '.csv') => [$file];
        }
    }

    /**
     * @dataProvider languageFiles
     */
    #[DataProvider('languageFiles')]
    public function testEveryKeyTheModuleUsesIsTranslated(string $file): void
    {
        $translated = [];
        $handle = fopen($file, 'rb');
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (isset($row[0])) {
                $translated[$row[0]] = true;
            }
        }
        fclose($handle);

        $missing = array_values(array_filter(
            self::usedKeys(),
            static fn(string $key): bool => !isset($translated[$key])
        ));

        $this->assertSame([], $missing, basename($file) . ' has no translation for these keys');
    }

    public function testTheModulePrintsKeysAtAll(): void
    {
        $this->assertContains('LedgerDirect.paymentPage.partialArrived', self::usedKeys());
    }

    /**
     * The keys in the module's PHP and templates, the composed ones completed for each chain the
     * module carries
     *
     * @return string[]
     */
    private static function usedKeys(): array
    {
        $root = self::moduleDir();
        $chains = (new PaymentMethods())->supportedChains();
        $keys = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root));
            if (!preg_match('/\.(php|phtml)$/', $relative) || preg_match('#^/(tests|dev|vendor)/#', $relative)) {
                continue;
            }
            preg_match_all("/'(LedgerDirect\\.[A-Za-z0-9_.]+)'/", (string) file_get_contents($file->getPathname()), $found);
            foreach ($found[1] as $key) {
                if (!str_ends_with($key, '.')) {
                    $keys[] = $key;
                    continue;
                }
                // A composed key without a rule above shows up as missing, prefix and all.
                foreach (self::COMPOSED[$key] ?? [] as $suffix) {
                    foreach ($chains as $chain) {
                        $keys[] = $key . $chain . $suffix;
                    }
                }
                if (!isset(self::COMPOSED[$key])) {
                    $keys[] = $key;
                }
            }
        }
        return array_values(array_unique($keys));
    }

    private static function moduleDir(): string
    {
        return (string) realpath(__DIR__ . '/../..');
    }
}
