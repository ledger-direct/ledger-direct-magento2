<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Helper;

use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

/**
 * The configuration's receiving-account row reads the scope the form shows: a website's or store
 * view's override. Every read on that path takes the store; without one it reads as before.
 */
class SystemConfigTest extends TestCase
{
    public function testTheAccountNetworkAndMethodsAreReadForTheGivenStore(): void
    {
        $values = [
            '5|payment/ledger_direct/use_testnet' => '0',
            '5|payment/ledger_direct/xrpl_mainnet_account' => ' rWebsiteAccount ',
            '|payment/ledger_direct/use_testnet' => '1',
            '|payment/ledger_direct/xrpl_testnet_account' => 'rDefaultAccount',
        ];
        $flags = ['5|payment/xrpl_rlusd_payment/active' => true];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope, $storeId = null) use ($values) {
                self::assertSame(ScopeInterface::SCOPE_STORE, $scope);

                return $values[$storeId . '|' . $path] ?? null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope, $storeId = null): bool => $flags[$storeId . '|' . $path] ?? false
        );
        $context = $this->createMock(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);
        $config = new SystemConfig($context);

        $this->assertSame('mainnet', $config->getNetwork('XRPL', 5));
        $this->assertSame('rWebsiteAccount', $config->getDestinationAccount('XRPL', 5));
        $this->assertTrue($config->isPaymentMethodActive('xrpl_rlusd_payment', 5));

        $this->assertSame('testnet', $config->getNetwork('XRPL'));
        $this->assertSame('rDefaultAccount', $config->getDestinationAccount('XRPL'));
        $this->assertFalse($config->isPaymentMethodActive('xrpl_rlusd_payment'));
    }
}
