<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Model\PaymentMethods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The map between the core's chain catalogue and Magento's payment methods, and the
 * completeness rule behind it: every chain the core knows is either carried by this module,
 * with a payment method for each of its assets wired through every file the checkout needs,
 * or stands in PENDING_CHAINS. A new chain in the core turns this red until the module
 * decides.
 */
class PaymentMethodsTest extends TestCase
{
    private static function moduleDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testEveryCatalogueChainIsCarriedOrExplicitlyPending(): void
    {
        foreach (ChainCatalog::chains() as $chain) {
            $pending = in_array($chain->chain, PaymentMethods::PENDING_CHAINS, true);
            $this->assertSame(!$pending, (new PaymentMethods())->supportsChain($chain->chain), $chain->chain);

            foreach ($chain->assets as $asset) {
                $code = (new PaymentMethods())->codeFor($chain->chain, $asset->code);
                if ($pending) {
                    $this->assertNull($code, "$chain->chain is pending but $asset->code has a method");
                    continue;
                }

                $this->assertNotNull($code, "$chain->chain $asset->code has no payment method");
                $this->assertSame($code, (new PaymentMethods())->codeForType($asset->type));
                $this->assertSame([$chain, $asset], (new PaymentMethods())->chainAndAssetFor($code), 'round trip');
            }
        }
    }

    public function testTheXrplCodesAreTheOnesFromBeforeTheCatalogue(): void
    {
        $this->assertSame('xrp_payment', (new PaymentMethods())->codeFor('XRPL', 'XRP'));
        $this->assertSame('xrpl_rlusd_payment', (new PaymentMethods())->codeFor('XRPL', 'rlusd'));
        $this->assertSame('xrpl_usdc_payment', (new PaymentMethods())->codeFor('XRPL', 'USDC'));
        $this->assertSame('xahau_xah_payment', (new PaymentMethods())->codeFor('XAHAU', 'XAH'));
        $this->assertSame(['XRPL', 'XAHAU'], (new PaymentMethods())->supportedChains());
    }

    public function testUnknownChainsAssetsAndCodesHaveNoCounterpart(): void
    {
        $this->assertNull((new PaymentMethods())->codeFor('XRPL', 'XAH'), 'XAH is not an XRPL asset');
        $this->assertNull((new PaymentMethods())->codeFor('XAHAU', 'RLUSD'));
        $this->assertNull((new PaymentMethods())->codeFor('DOGE', 'DOGE'));
        $this->assertNull((new PaymentMethods())->codeForType('stellar-xlm-payment'));
        $this->assertNull((new PaymentMethods())->chainAndAssetFor('checkmo'));
    }

    /**
     * Every method code is wired where Magento needs it: the facade in etc/di.xml, the defaults
     * in etc/config.xml, etc/payment.xml, the admin group, the checkout renderer, its icon.
     *
     * @dataProvider methodCodes
     */
    #[DataProvider('methodCodes')]
    public function testEveryPaymentMethodIsWiredEverywhere(string $code): void
    {
        $dir = self::moduleDir();

        $di = (string) file_get_contents($dir . '/etc/di.xml');
        $this->assertStringContainsString('<argument name="code" xsi:type="string">' . $code . '</argument>', $di, 'facade');
        $this->assertStringContainsString('<argument name="methodCode" xsi:type="string">' . $code . '</argument>', $di, 'config');

        $config = new SimpleXMLElement((string) file_get_contents($dir . '/etc/config.xml'));
        $this->assertCount(1, $config->xpath('/config/default/payment/' . $code . '[model]'), 'config.xml defaults');

        $payment = new SimpleXMLElement((string) file_get_contents($dir . '/etc/payment.xml'));
        $this->assertCount(1, $payment->xpath('/payment/methods/method[@name="' . $code . '"]'), 'payment.xml');

        $system = new SimpleXMLElement((string) file_get_contents($dir . '/etc/adminhtml/system.xml'));
        $this->assertCount(1, $system->xpath('/config/system/section[@id="payment"]/group[@id="' . $code . '"]'), 'admin group');

        $layout = (string) file_get_contents($dir . '/view/frontend/layout/checkout_index_index.xml');
        $this->assertStringContainsString('<item name="' . $code . '" xsi:type="array">', $layout, 'checkout layout');

        $renderers = (string) file_get_contents($dir . '/view/frontend/web/js/view/payment/ledger-direct.js');
        $this->assertMatchesRegularExpression(
            "/type: '" . $code . "',\s*component: 'Hardcastle_LedgerDirect\/js\/view\/payment\/method-renderer\/([a-z-]+)'/",
            $renderers,
            'renderer registration'
        );
        preg_match("/type: '" . $code . "',\s*component: 'Hardcastle_LedgerDirect\/js\/view\/payment\/method-renderer\/([a-z-]+)'/", $renderers, $m);
        $renderer = $dir . '/view/frontend/web/js/view/payment/method-renderer/' . $m[1] . '.js';
        $this->assertFileExists($renderer);
        $rendererJs = (string) file_get_contents($renderer);
        $this->assertStringContainsString("code: '" . $code . "'", $rendererJs);
        $this->assertSame(1, preg_match('/images\/([a-z_]+\.svg)/', $rendererJs, $icon), 'the renderer names an icon');
        $this->assertFileExists($dir . '/view/frontend/web/images/' . $icon[1]);
    }

    public static function methodCodes(): iterable
    {
        foreach ((new PaymentMethods())->codes() as $code) {
            yield $code => [$code];
        }
    }
}
