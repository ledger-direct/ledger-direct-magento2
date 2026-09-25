<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Block;

use Hardcastle\LedgerDirect\Block\Info;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use InvalidArgumentException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The merchant's view of a LedgerDirect payment: the rows the admin order view, the customer's
 * order view and the order emails show under "Payment Information".
 */
class InfoTest extends TestCase
{
    /** @var OrderPaymentService|MockObject */
    private $orderPaymentService;

    private Info $block;

    protected function setUp(): void
    {
        $this->orderPaymentService = $this->createMock(OrderPaymentService::class);
        $localeDate = $this->createMock(TimezoneInterface::class);
        $localeDate->method('formatDateTime')->willReturn('Sep 16, 2026, 4:23:08 PM');

        $this->block = (new ObjectManager($this))->getObject(Info::class, [
            'orderPaymentService' => $this->orderPaymentService,
            'settlementPolicy' => new SettlementPolicy(),
            'localeDate' => $localeDate,
            'data' => ['is_secure_mode' => false],
        ]);
    }

    public function testAWaitingXrpOrderShowsTheQuoteAndNoTransaction(): void
    {
        $this->givenIntent($this->xrpIntent(expiry: time() + 300));

        $rows = $this->block->getSpecificInformation();

        $this->assertSame([
            'Asset' => 'XRP (XRPL testnet)',
            'Amount requested' => '4.72843 XRP',
            'Exchange rate' => '1.268933 XRP/USD',
            'Destination account' => 'rMerchant',
            'Destination tag' => '2573293867',
            'Quote valid until' => 'Sep 16, 2026, 4:23:08 PM',
            'Payment status' => 'Waiting for payment',
        ], $rows);
        $this->assertNull($this->block->getExplorerUrl());
    }

    public function testAPartialPaymentShowsReceivedOutstandingAndTheTransaction(): void
    {
        $this->givenIntent($this->xrpIntent(expiry: time() + 300)->withFulfillment('HASH', 2.36, 'CTID'));

        $rows = $this->block->getSpecificInformation();

        $this->assertSame('Partially paid', $rows['Payment status']);
        $this->assertArrayNotHasKey('Quote valid until', $rows, 'the quote is spent once something arrived');
        $this->assertSame('2.36 XRP', $rows['Amount received']);
        $this->assertSame('2.36843 XRP', $rows['Amount outstanding']);
        $this->assertSame('HASH', $rows['Transaction']);
        $this->assertSame('CTID', $rows['CTID']);
        $this->assertSame('https://testnet.xrpl.org/transactions/HASH', $this->block->getExplorerUrl());
    }

    public function testASettledStablecoinOrderNamesTheIssuerAndLinksTheMainnetExplorer(): void
    {
        $usdc = ['currency' => '5553444300000000000000000000000000000000', 'value' => '6.00', 'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE'];
        $intent = PaymentIntent::quote('usdc-payment', 'XRPL', 'mainnet', 'USDC', 'USD', 'USDC/USD', 1.0, $usdc, 'rMerchant', 7)
            ->withFulfillment('HASH', ['currency' => $usdc['currency'], 'value' => '6', 'issuer' => $usdc['issuer']]);
        $this->givenIntent($intent);

        $rows = $this->block->getSpecificInformation();

        $this->assertSame('USDC (XRPL mainnet)', $rows['Asset']);
        $this->assertSame('6.00 USDC', $rows['Amount requested']);
        $this->assertSame('rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE', $rows['Issuer']);
        $this->assertSame('Settled', $rows['Payment status']);
        $this->assertSame('6 USDC', $rows['Amount received']);
        $this->assertArrayNotHasKey('Amount outstanding', $rows);
        $this->assertArrayNotHasKey('Quote valid until', $rows, 'no expiry, no row');
        $this->assertSame('https://livenet.xrpl.org/transactions/HASH', $this->block->getExplorerUrl());
    }

    /**
     * The merchant must see *what* arrived when it is the wrong token: the other currency and
     * its issuer, and that nothing was credited.
     */
    public function testAPaymentInTheWrongAssetNamesTheOtherTokenAndItsIssuer(): void
    {
        $usdc = ['currency' => '5553444300000000000000000000000000000000', 'value' => '6.00', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];
        $rlusd = ['currency' => '524C555344000000000000000000000000000000', 'value' => '6', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'];
        $this->givenIntent(
            PaymentIntent::quote('usdc-payment', 'XRPL', 'testnet', 'USDC', 'USD', 'USDC/USD', 1.0, $usdc, 'rMerchant', 7)
                ->withFulfillment('HASH', $rlusd)
        );

        $rows = $this->block->getSpecificInformation();

        $this->assertSame('Payment in the wrong asset', $rows['Payment status']);
        $this->assertSame(
            '6 RLUSD from issuer rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV - not the requested asset, not credited',
            $rows['Amount received']
        );
        $this->assertSame('6 USDC', $rows['Amount outstanding'], "the core's plain decimal");
    }

    public function testAnOrderNeverQuotedShowsNoRows(): void
    {
        $this->givenIntent(null);

        $this->assertSame([], $this->block->getSpecificInformation());
    }

    /**
     * An old record ("Missing schema_version") must not break the admin order view.
     */
    public function testAnUnreadableRecordShowsNoRows(): void
    {
        $payment = $this->createMock(Payment::class);
        $this->block->setData('info', $payment);
        $this->orderPaymentService->method('readPaymentIntentOf')->willThrowException(new InvalidArgumentException('Missing schema_version'));

        $this->assertSame([], $this->block->getSpecificInformation());
    }

    /**
     * In the checkout the block gets a quote payment, which has no order record yet.
     */
    public function testAQuotePaymentShowsNoRows(): void
    {
        $this->block->setData('info', $this->createMock(QuotePayment::class));
        $this->orderPaymentService->expects($this->never())->method('readPaymentIntentOf');

        $this->assertSame([], $this->block->getSpecificInformation());
    }

    public function testCurrencyCodesDecodeToTheirName(): void
    {
        $this->assertSame('RLUSD', $this->block->currencyName('524C555344000000000000000000000000000000'));
        $this->assertSame('USD', $this->block->currencyName('USD'));
        $this->assertSame('0123456789ABCDEF0123456789ABCDEF01234567', $this->block->currencyName('0123456789ABCDEF0123456789ABCDEF01234567'));
    }

    private function givenIntent(?PaymentIntent $intent): void
    {
        $payment = $this->createMock(Payment::class);
        $this->block->setData('info', $payment);
        $this->orderPaymentService->method('readPaymentIntentOf')->with($payment)->willReturn($intent);
    }

    private function xrpIntent(?int $expiry): PaymentIntent
    {
        return PaymentIntent::quote('xrp-payment', 'XRPL', 'testnet', 'XRP', 'USD', 'XRP/USD', 1.2689333333, 4.72843, 'rMerchant', 2573293867, $expiry);
    }
}
