<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model\Settlement;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Model\Settlement\AmountCheck;
use PHPUnit\Framework\TestCase;

class AmountCheckTest extends TestCase
{
    private const USDC = ['currency' => '5553444300000000000000000000000000000000', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];

    private AmountCheck $check;

    protected function setUp(): void
    {
        $this->check = new AmountCheck();
    }

    /**
     * @dataProvider xrpAmounts
     */
    public function testXrpToleratesTheSlippageShopwareAccepts(float $paid, bool $expected): void
    {
        $intent = $this->xrpIntent(100.0)->withFulfillment('HASH', $paid);

        $this->assertSame($expected, $this->check->isSettled($intent));
    }

    public function xrpAmounts(): array
    {
        return [
            'exact' => [100.0, true],
            'overpaid' => [100.5, true],
            'within 0.15 %' => [99.85, true],
            'just outside' => [99.8499, false],
            'half' => [50.0, false],
            'nothing measurable' => [0.0, false],
        ];
    }

    public function testAStablecoinMustDeliverAtLeastTheQuotedValue(): void
    {
        $this->assertTrue($this->check->isSettled($this->usdcIntent('39.00')->withFulfillment('H', self::USDC + ['value' => '39'])));
        $this->assertTrue($this->check->isSettled($this->usdcIntent('39.00')->withFulfillment('H', self::USDC + ['value' => '40.5'])));
        $this->assertFalse($this->check->isSettled($this->usdcIntent('39.00')->withFulfillment('H', self::USDC + ['value' => '38.99'])));
    }

    /**
     * A token with the right name from another issuer is not the token that was asked for.
     */
    public function testAStablecoinFromAnotherIssuerOrCurrencyDoesNotSettle(): void
    {
        $otherIssuer = ['currency' => self::USDC['currency'], 'issuer' => 'rSomebodyElse', 'value' => '39.00'];
        $otherCurrency = ['currency' => '524C555344000000000000000000000000000000', 'issuer' => self::USDC['issuer'], 'value' => '39.00'];

        $this->assertFalse($this->check->isSettled($this->usdcIntent('39.00')->withFulfillment('H', $otherIssuer)));
        $this->assertFalse($this->check->isSettled($this->usdcIntent('39.00')->withFulfillment('H', $otherCurrency)));
    }

    public function testNothingPaidIsNotSettled(): void
    {
        $this->assertFalse($this->check->isSettled($this->xrpIntent(100.0)));
        $this->assertNull($this->check->paidValue($this->xrpIntent(100.0)));
    }

    public function testValuesAreRenderedAsPlainDecimals(): void
    {
        $xrp = $this->xrpIntent(26.75411)->withFulfillment('H', 0.84);
        $this->assertSame('26.75411', $this->check->requestedValue($xrp));
        $this->assertSame('0.84', $this->check->paidValue($xrp));

        $usdc = $this->usdcIntent('39.00')->withFulfillment('H', self::USDC + ['value' => '1.16']);
        $this->assertSame('39.00', $this->check->requestedValue($usdc));
        $this->assertSame('1.16', $this->check->paidValue($usdc));
    }

    private function xrpIntent(float $requested): PaymentIntent
    {
        return PaymentIntent::quote('xrp-payment', 'XRPL', 'testnet', 'XRP', 'EUR', 'XRP/EUR', 1.25, $requested, 'rMerchant', 1);
    }

    private function usdcIntent(string $requested): PaymentIntent
    {
        return PaymentIntent::quote(
            'usdc-payment', 'XRPL', 'testnet', 'USDC', 'USD', 'USDC/USD', 1.0, self::USDC + ['value' => $requested], 'rMerchant', 1
        );
    }
}
