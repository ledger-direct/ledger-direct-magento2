<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * What the admin offers must be what the module reads.
 *
 * - Every payment method in config.xml has its own group in system.xml with *Enabled* and *Title*:
 *   Magento's payment adapter reads payment/<method>/active and /title, so without the group the
 *   merchant cannot switch the method off. Up to 1.1.5 XRP had none.
 * - Every field of the shared group *Ledger Direct / XRPL* is read by the module as
 *   payment/ledger_direct/<field>. Up to 1.1.5 its *Enabled* switch saved a value nothing read,
 *   so switching it off left XRP in the checkout.
 */
class AdminConfigurationTest extends TestCase
{
    private const SHARED_GROUP = 'ledger_direct';

    private static function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, SimpleXMLElement> group id => group
     */
    private static function adminGroups(): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents(self::moduleDir() . '/etc/adminhtml/system.xml'));
        $groups = [];
        foreach ($xml->xpath('/config/system/section[@id="payment"]/group') ?: [] as $group) {
            $groups[(string) $group['id']] = $group;
        }

        return $groups;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paymentMethods(): iterable
    {
        $xml = new SimpleXMLElement((string) file_get_contents(self::moduleDir() . '/etc/config.xml'));
        foreach ($xml->xpath('/config/default/payment/*[model]') ?: [] as $method) {
            yield $method->getName() => [$method->getName()];
        }
    }

    /**
     * @dataProvider paymentMethods
     */
    #[DataProvider('paymentMethods')]
    public function testEveryPaymentMethodCanBeSwitchedAndNamedInTheAdmin(string $method): void
    {
        $groups = self::adminGroups();
        $this->assertArrayHasKey($method, $groups, "system.xml has no group for $method");

        $fields = [];
        foreach ($groups[$method]->field as $field) {
            $fields[] = (string) $field['id'];
        }
        $this->assertContains('active', $fields, "$method has no Enabled switch");
        $this->assertContains('title', $fields, "$method has no Title field");
    }

    public function testThePaymentMethodsAreFound(): void
    {
        $this->assertSame(
            ['xrp_payment', 'xrpl_rlusd_payment', 'xrpl_usdc_payment', 'xahau_xah_payment'],
            array_keys(iterator_to_array(self::paymentMethods()))
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    /**
     * The shared group's settings, without the XRPL network and accounts: SystemConfig builds
     * those keys from the chain's name, and testEveryChainGroupHasTheFieldsSystemConfigReads
     * covers them.
     */
    public static function sharedFields(): iterable
    {
        foreach (self::adminGroups()[self::SHARED_GROUP]->field as $field) {
            $id = (string) $field['id'];
            if (in_array($id, ['use_testnet', 'xrpl_testnet_account', 'xrpl_mainnet_account'], true)) {
                continue;
            }
            yield $id => [$id];
        }
    }

    /**
     * The groups that hold a chain's network and accounts, with the keys SystemConfig builds for
     * them from the chain's name: `<group>/use_testnet` and `<group>/<chain>_<network>_account`.
     */
    public static function chainGroups(): iterable
    {
        yield 'XRPL' => [self::SHARED_GROUP, 'xrpl'];
        yield 'XAHAU' => ['ledger_direct_xahau', 'xahau'];
    }

    /**
     * @dataProvider chainGroups
     */
    #[DataProvider('chainGroups')]
    public function testEveryChainGroupHasTheFieldsSystemConfigReads(string $group, string $prefix): void
    {
        $fields = [];
        foreach (self::adminGroups()[$group]->field as $field) {
            $fields[] = (string) $field['id'];
        }

        foreach (['use_testnet', $prefix . '_testnet_account', $prefix . '_mainnet_account'] as $expected) {
            $this->assertContains($expected, $fields, "$group has no field $expected");
        }
    }

    /**
     * @dataProvider sharedFields
     */
    #[DataProvider('sharedFields')]
    public function testEverySharedSettingIsRead(string $field): void
    {
        $path = 'payment/' . self::SHARED_GROUP . '/' . $field;
        $readers = [];
        $codeDirs = ['Helper', 'Port', 'Model', 'Service', 'ViewModel', 'Block', 'Observer', 'Controller', 'Cron', 'Gateway'];
        foreach ($codeDirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::moduleDir() . '/' . $dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'phtml'], true)
                    && str_contains((string) file_get_contents($file->getPathname()), $path)) {
                    $readers[] = $file->getFilename();
                }
            }
        }

        $this->assertNotEmpty($readers, "$path is offered in the admin, but no module code reads it");
    }
}
