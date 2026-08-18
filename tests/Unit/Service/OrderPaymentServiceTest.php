<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderPaymentServiceTest extends TestCase
{
    /** @var SystemConfig|MockObject */
    private $configHelper;

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepository;

    /** @var CryptoPriceProviderInterface|MockObject */
    private $priceFinder;

    /** @var XrplTxService|MockObject */
    private $xrplTxService;

    /** @var OrderFactory|MockObject */
    private $orderFactory;

    private OrderPaymentService $service;

    protected function setUp(): void
    {
        $this->configHelper = $this->createMock(SystemConfig::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->priceFinder = $this->createMock(CryptoPriceProviderInterface::class);
        $this->xrplTxService = $this->createMock(XrplTxService::class);
        $this->orderFactory = $this->createMock(OrderFactory::class);

        $this->service = new OrderPaymentService(
            $this->configHelper,
            $this->orderRepository,
            $this->priceFinder,
            $this->xrplTxService,
            $this->orderFactory
        );
    }

    public function testGetCurrentPriceForOrder()
    {
        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getTotalDue')->willReturn(100.0);

        $this->priceFinder->expects($this->once())
            ->method('getCurrentExchangeRate')
            ->with('XRP', 'USD')
            ->willReturn(0.5);

        $result = $this->service->getCurrentPriceForOrder($order);

        $this->assertSame([
            'base_asset' => 'XRP',
            'quote_currency' => 'USD',
            'pairing' => 'XRP/USD',
            'exchange_rate' => 0.5,
            'amount_requested' => 200.0,
        ], $result);
    }

    public function testSyncOrderTransactionWithXrplReturnsNullWhenNoAdditionalData()
    {
        /** @var OrderPaymentInterface|MockObject $payment */
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalData')->willReturn('');

        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);

        $this->xrplTxService->expects($this->never())->method('syncAccountTransactions');

        $result = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertNull($result);
    }

    public function testSyncOrderTransactionWithXrplMatchesAndUpdatesPayment()
    {
        $existingData = json_encode([
            'xrpl' => [
                'destination_account' => 'rAddr',
                'destination_tag' => 12345,
            ],
        ]);

        /** @var OrderPaymentInterface|MockObject $payment */
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalData')->willReturn($existingData);

        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);

        $this->xrplTxService->expects($this->once())
            ->method('syncAccountTransactions')
            ->with('rAddr');

        $tx = ['hash' => 'ABC123', 'meta' => json_encode(['delivered_amount' => '1500000'])];
        $this->xrplTxService->expects($this->once())
            ->method('findTransaction')
            ->with('rAddr', 12345)
            ->willReturn($tx);

        $payment->expects($this->once())
            ->method('setAdditionalData')
            ->with($this->callback(function (string $json): bool {
                $decoded = json_decode($json, true);

                return ($decoded['xrpl']['hash'] ?? null) === 'ABC123'
                    && ($decoded['xrpl']['amount_paid'] ?? null) === '1.500000'
                    && ($decoded['xrpl']['destination_account'] ?? null) === 'rAddr';
            }));

        $this->orderRepository->expects($this->once())
            ->method('save')
            ->with($order);

        $result = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertSame($tx, $result);
    }

    public function testPrepareOrderPaymentForXrplSetsTokenMetadataForTokenPayment()
    {
        /** @var OrderPaymentInterface|MockObject $payment */
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalData')->willReturn('');
        $payment->method('getMethod')->willReturn('xrpl_token_payment');

        $capturedPayloads = [];
        $payment->method('setAdditionalData')->willReturnCallback(function (string $json) use (&$capturedPayloads) {
            $capturedPayloads[] = json_decode($json, true);
        });

        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getTotalDue')->willReturn(150.0);

        $this->configHelper->method('isTest')->willReturn(true);
        $this->configHelper->method('getDestinationAccount')->willReturn('rMerchantDest');
        $this->configHelper->method('getTokenIssuer')->willReturn('rIssuerAccount');
        $this->configHelper->method('getTokenName')->willReturn('USDC');

        $this->xrplTxService->method('generateDestinationTag')->willReturn(999);

        // A real (non-1:1) rate proves the amount is actually converted, not just passed through.
        $this->priceFinder->expects($this->once())
            ->method('getCurrentExchangeRate')
            ->with('USDC', 'USD')
            ->willReturn(0.99);

        $this->orderRepository->expects($this->exactly(2))->method('save');

        $this->service->prepareOrderPaymentForXrpl($order);

        $this->assertCount(2, $capturedPayloads);
        $tokenPayload = $capturedPayloads[1]['xrpl'];
        $this->assertSame('USDC', $tokenPayload['currency']);
        $this->assertSame('USDC', $tokenPayload['base_asset']);
        $this->assertSame('USD', $tokenPayload['quote_currency']);
        $this->assertSame('USDC/USD', $tokenPayload['pairing']);
        $this->assertEquals(0.99, $tokenPayload['exchange_rate']);
        // 150 / 0.99 = 151.515151515... truncated to USDC's 6 decimals.
        $this->assertSame('151.515151', $tokenPayload['value']);
        $this->assertSame('rIssuerAccount', $tokenPayload['issuer']);
    }

    public function testSyncOrderTransactionWithXrplReturnsNullWhenNoMatchFound()
    {
        $existingData = json_encode([
            'xrpl' => [
                'destination_account' => 'rAddr',
                'destination_tag' => 12345,
            ],
        ]);

        /** @var OrderPaymentInterface|MockObject $payment */
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalData')->willReturn($existingData);

        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);

        $this->xrplTxService->expects($this->once())
            ->method('syncAccountTransactions')
            ->with('rAddr');
        $this->xrplTxService->method('findTransaction')->willReturn(null);

        $payment->expects($this->never())->method('setAdditionalData');
        $this->orderRepository->expects($this->never())->method('save');

        $result = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertNull($result);
    }
}
