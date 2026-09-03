<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model\Api;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterfaceFactory;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Model\API\XrpPaymentService;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Model\XrpPayment;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class XrpPaymentServiceTest extends TestCase
{
    /** @var OrderPaymentService|MockObject */
    private $orderPaymentService;

    private XrpPaymentService $service;

    protected function setUp(): void
    {
        $this->orderPaymentService = $this->createMock(OrderPaymentService::class);

        $xrpPaymentFactory = $this->createMock(XrpPaymentInterfaceFactory::class);
        $xrpPaymentFactory->method('create')->willReturnCallback(
            fn () => new XrpPayment($this->createMock(Context::class), $this->createMock(Registry::class))
        );

        $this->service = new XrpPaymentService($this->orderPaymentService, $xrpPaymentFactory, new SettlementPolicy());
    }

    private function givenOrderQuotedAs(PaymentIntent $intent, float $totalDue, string $currencyCode): void
    {
        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getTotalDue')->willReturn($totalDue);
        $order->method('getOrderCurrencyCode')->willReturn($currencyCode);

        $this->orderPaymentService->method('getOrderById')->willReturn($order);
        $this->orderPaymentService->method('prepareOrderPaymentForXrpl')->with($order)->willReturn($intent);
    }

    public function testXrpDetailsComeStraightFromThePaymentIntent(): void
    {
        $this->givenOrderQuotedAs(PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'USD',
            pairing: 'XRP/USD',
            exchangeRate: 0.5,
            amountRequested: 200.0,
            destinationAccount: 'rMerchantDest',
            destinationTag: 12345,
        ), 100.0, 'USD');

        $details = $this->service->getPaymentDetailsByOrderId(42);

        $this->assertSame('xrp-payment', $details->getType());
        $this->assertSame(42, $details->getOrderId());
        $this->assertSame('100000042', $details->getOrderNumber());
        $this->assertSame('testnet', $details->getNetwork());
        $this->assertSame('rMerchantDest', $details->getDestinationAccount());
        $this->assertSame(12345, $details->getDestinationTag());
        $this->assertSame(0.5, $details->getExchangeRate());
        $this->assertSame(200.0, $details->getXrpAmount());
        $this->assertSame(100.0, $details->getPrice());
        $this->assertSame('USD', $details->getCurrencyCode());
        $this->assertSame('$', $details->getCurrencySymbol());
        $this->assertNull($details->getTxHash());
        $this->assertNull($details->getAmountPaid());
        $this->assertNull($details->getAmountOutstanding());
        $this->assertNull($details->getTokenAmount());
        $this->assertNull($details->getCurrency());
        $this->assertNull($details->getIssuer());
    }

    /**
     * A same-named token from another issuer delivers a value but credits nothing; the page must
     * not present it as progress.
     */
    public function testAPaymentInTheWrongTokenCreditsNothing(): void
    {
        $this->givenOrderQuotedAs(PaymentIntent::quote(
            type: 'rlusd-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'RLUSD',
            quoteCurrency: 'USD',
            pairing: 'RLUSD/USD',
            exchangeRate: 1.0,
            amountRequested: ['currency' => '524C555344000000000000000000000000000000', 'value' => '1.16', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'],
            destinationAccount: 'rMerchantDest',
            destinationTag: 7,
        )->withFulfillment('HASH', ['currency' => '5553444300000000000000000000000000000000', 'value' => '1.16', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt']), 1.16, 'USD');

        $details = $this->service->getPaymentDetailsByOrderId(42);

        $this->assertSame('0', $details->getAmountPaid());
        $this->assertSame('1.16', $details->getAmountOutstanding());
    }

    public function testAnUnderpaymentExposesWhatIsCreditedAndWhatIsOutstanding(): void
    {
        $this->givenOrderQuotedAs(PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'USD',
            pairing: 'XRP/USD',
            exchangeRate: 1.45,
            amountRequested: 26.72673,
            destinationAccount: 'rMerchantDest',
            destinationTag: 7,
        )->withFulfillment('HASH', 0.84), 39.0, 'USD');

        $details = $this->service->getPaymentDetailsByOrderId(42);

        $this->assertSame('0.84', $details->getAmountPaid());
        $this->assertSame('25.88673', $details->getAmountOutstanding());
    }

    public function testStablecoinDetailsExposeTheIssuedCurrencyAmount(): void
    {
        $this->givenOrderQuotedAs(PaymentIntent::quote(
            type: 'usdc-payment',
            chain: 'XRPL',
            network: 'mainnet',
            baseAsset: 'USDC',
            quoteCurrency: 'USD',
            pairing: 'USDC/USD',
            exchangeRate: 1.0,
            amountRequested: [
                'currency' => '5553444300000000000000000000000000000000',
                'value' => '150.00',
                'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE',
            ],
            destinationAccount: 'rMerchantDest',
            destinationTag: 999,
        )->withFulfillment('HASH', ['currency' => '5553444300000000000000000000000000000000', 'value' => '150', 'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE'], 'CTID'), 150.0, 'USD');

        $details = $this->service->getPaymentDetailsByOrderId(42);

        $this->assertSame('usdc-payment', $details->getType());
        $this->assertSame('mainnet', $details->getNetwork());
        $this->assertSame(0.0, $details->getXrpAmount());
        $this->assertSame('150.00', $details->getTokenAmount());
        $this->assertSame('5553444300000000000000000000000000000000', $details->getCurrency());
        $this->assertSame('rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE', $details->getIssuer());
        $this->assertSame('HASH', $details->getTxHash());
        $this->assertSame('150', $details->getAmountPaid());
        $this->assertNull($details->getAmountOutstanding(), 'paid in full');
    }
}
