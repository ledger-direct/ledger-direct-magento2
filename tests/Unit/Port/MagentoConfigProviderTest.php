<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Port;

use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Port\MagentoConfigProvider;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use PHPUnit\Framework\TestCase;

/**
 * The port over the real SystemConfig helper, with only Magento's scope config stubbed:
 * what is asserted is the mapping from store configuration to the core's vocabulary.
 */
class MagentoConfigProviderTest extends TestCase
{
    /**
     * @param array<string, mixed> $config config path => value
     */
    private function createProvider(array $config): MagentoConfigProvider
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => isset($config[$path]) ? (string) $config[$path] : null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn (string $path) => (bool) ($config[$path] ?? false)
        );

        $context = $this->createMock(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);

        return new MagentoConfigProvider(new SystemConfig($context));
    }

    public function testTestnetSettingsAreReportedInTheCoreVocabulary(): void
    {
        $provider = $this->createProvider([
            'payment/ledger_direct/use_testnet' => 1,
            'payment/ledger_direct/xrpl_testnet_account' => 'rTestnetMerchant',
            'payment/ledger_direct/xrpl_mainnet_account' => 'rMainnetMerchant',
        ]);

        $this->assertSame('testnet', $provider->getNetwork('XRPL'));
        $this->assertSame('rTestnetMerchant', $provider->getDestinationAccount('XRPL'));
    }

    public function testMainnetIsSelectedWhenTheTestnetToggleIsOff(): void
    {
        $provider = $this->createProvider([
            'payment/ledger_direct/use_testnet' => 0,
            'payment/ledger_direct/xrpl_testnet_account' => 'rTestnetMerchant',
            'payment/ledger_direct/xrpl_mainnet_account' => 'rMainnetMerchant',
        ]);

        $this->assertSame('mainnet', $provider->getNetwork('XRPL'));
        $this->assertSame('rMainnetMerchant', $provider->getDestinationAccount('XRPL'));
    }

    /**
     * There is no separate per-asset toggle: the payment method is the asset, so its
     * "Enabled" flag is what decides whether the core may quote in it.
     */
    public function testAssetsFollowTheirPaymentMethodsEnabledFlag(): void
    {
        $provider = $this->createProvider([
            'payment/xrp_payment/active' => 1,
            'payment/xrpl_rlusd_payment/active' => 1,
            'payment/xrpl_usdc_payment/active' => 0,
        ]);

        $this->assertTrue($provider->isAssetEnabled('XRPL', 'XRP'));
        $this->assertTrue($provider->isAssetEnabled('XRPL', 'RLUSD'));
        $this->assertFalse($provider->isAssetEnabled('XRPL', 'USDC'));
        $this->assertFalse($provider->isAssetEnabled('XRPL', 'DOGE'), 'an unknown asset is never enabled');
    }

    public function testQuoteExpiryIsReadFromConfiguration(): void
    {
        $this->assertSame(
            600,
            $this->createProvider(['payment/ledger_direct/quote_expiry' => '600'])->getQuoteExpirySeconds()
        );
    }

    public function testQuoteExpiryFallsBackToTheDefaultWhenUnsetOrUnusable(): void
    {
        $this->assertSame(
            SystemConfig::DEFAULT_QUOTE_EXPIRY_SECONDS,
            $this->createProvider([])->getQuoteExpirySeconds()
        );
        $this->assertSame(
            SystemConfig::DEFAULT_QUOTE_EXPIRY_SECONDS,
            $this->createProvider(['payment/ledger_direct/quote_expiry' => 'soon'])->getQuoteExpirySeconds()
        );
        $this->assertSame(
            SystemConfig::DEFAULT_QUOTE_EXPIRY_SECONDS,
            $this->createProvider(['payment/ledger_direct/quote_expiry' => '0'])->getQuoteExpirySeconds()
        );
    }

    public function testAnotherChainIsAWiringMistake(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createProvider([])->getNetwork('XLM');
    }
}
