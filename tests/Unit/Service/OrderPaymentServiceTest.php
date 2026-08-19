<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\StablecoinRegistry;
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
            $this->orderFactory,
            new StablecoinRegistry()
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

    public function testPrepareOrderPaymentForXrplSetsRlusdMetadataForNonUsdStore()
    {
        /** @var OrderPaymentInterface|MockObject $payment */
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalData')->willReturn('');
        $payment->method('getMethod')->willReturn('xrpl_rlusd_payment');

        $capturedPayloads = [];
        $payment->method('setAdditionalData')->willReturnCallback(function (string $json) use (&$capturedPayloads) {
            $capturedPayloads[] = json_decode($json, true);
        });

        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getTotalDue')->willReturn(100.0);

        $this->configHelper->method('isTest')->willReturn(true);
        $this->configHelper->method('getDestinationAccount')->willReturn('rMerchantDest');
        $this->xrplTxService->method('generateDestinationTag')->willReturn(999);

        // A real (non-1:1) rate proves a EUR store's amount is actually converted, not
        // just passed through as if RLUSD were pegged to EUR too.
        $this->priceFinder->expects($this->once())
            ->method('getCurrentExchangeRate')
            ->with('RLUSD', 'EUR')
            ->willReturn(0.92);

        $this->orderRepository->expects($this->exactly(2))->method('save');

        $this->service->prepareOrderPaymentForXrpl($order);

        $this->assertCount(2, $capturedPayloads);
        $payload = $capturedPayloads[1]['xrpl'];
        $this->assertSame('xrpl_rlusd_payment', $payload['type']);
        $this->assertSame('RLUSD', $payload['base_asset']);
        $this->assertSame('EUR', $payload['quote_currency']);
        $this->assertSame('RLUSD/EUR', $payload['pairing']);
        $this->assertEquals(0.92, $payload['exchange_rate']);
        // 100 / 0.92 = 108.6956... rounded to 2 decimals = 108.7
        $this->assertSame([
            'currency' => '524C555344000000000000000000000000000000',
            'value' => '108.7',
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV', // testnet RLUSD issuer
        ], $payload['amount_requested']);
    }

    public function testPrepareOrderPaymentForXrplSetsUsdcMetadataOnMainnet()
    {
        /** @var OrderPaymentInterface|MockObject $payment */
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalData')->willReturn('');
        $payment->method('getMethod')->willReturn('xrpl_usdc_payment');

        $capturedPayloads = [];
        $payment->method('setAdditionalData')->willReturnCallback(function (string $json) use (&$capturedPayloads) {
            $capturedPayloads[] = json_decode($json, true);
        });

        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getTotalDue')->willReturn(150.0);

        // false = mainnet, exercising StablecoinRegistry's other branch (vs. the RLUSD/
        // testnet case above).
        $this->configHelper->method('isTest')->willReturn(false);
        $this->configHelper->method('getDestinationAccount')->willReturn('rMerchantDest');
        $this->xrplTxService->method('generateDestinationTag')->willReturn(999);

        $this->priceFinder->expects($this->once())
            ->method('getCurrentExchangeRate')
            ->with('USDC', 'USD')
            ->willReturn(1.0);

        $this->orderRepository->expects($this->exactly(2))->method('save');

        $this->service->prepareOrderPaymentForXrpl($order);

        $payload = $capturedPayloads[1]['xrpl'];
        $this->assertSame('xrpl_usdc_payment', $payload['type']);
        $this->assertSame([
            'currency' => '5553444300000000000000000000000000000000',
            'value' => '150',
            'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE', // mainnet USDC issuer
        ], $payload['amount_requested']);
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
