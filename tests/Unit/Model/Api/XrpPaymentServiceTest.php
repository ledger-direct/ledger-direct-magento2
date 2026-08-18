<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model\Api;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterfaceFactory;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Model\API\XrpPaymentService;
use Hardcastle\LedgerDirect\Model\XrpPayment;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class XrpPaymentServiceTest extends TestCase
{
    /** @var SystemConfig|MockObject */
    private $configHelper;

    /** @var OrderPaymentService|MockObject */
    private $orderPaymentService;

    private XrpPaymentService $service;

    protected function setUp(): void
    {
        $this->configHelper = $this->createMock(SystemConfig::class);
        $this->configHelper->method('getDestinationAccount')->willReturn('rMerchantDest');

        $this->orderPaymentService = $this->createMock(OrderPaymentService::class);

        $xrpPaymentFactory = $this->createMock(XrpPaymentInterfaceFactory::class);
        $xrpPaymentFactory->method('create')->willReturnCallback(
            fn () => new XrpPayment($this->createMock(Context::class), $this->createMock(Registry::class))
        );

        $this->service = new XrpPaymentService(
            $this->configHelper,
            $this->orderPaymentService,
            $xrpPaymentFactory,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function mockOrder(array $xrplData, float $totalDue, string $currencyCode): OrderInterface
    {
        /** @var OrderPaymentInterface|MockObject $payment */
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalData')->willReturn(json_encode(['xrpl' => $xrplData]));

        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getTotalDue')->willReturn($totalDue);
        $order->method('getOrderCurrencyCode')->willReturn($currencyCode);

        $this->orderPaymentService->method('getOrderById')->willReturn($order);

        return $order;
    }

    public function testGetPaymentDetailsByOrderIdPopulatesXrpAmountForXrpPayment()
    {
        $this->mockOrder([
            'type' => 'xrp_payment',
            'exchange_rate' => 0.5,
            'network' => 'Testnet',
            'destination_tag' => 12345,
            'hash' => null,
        ], 100.0, 'USD');

        $details = $this->service->getPaymentDetailsByOrderId(42);

        $this->assertSame('xrp_payment', $details->getType());
        $this->assertSame(200.0, $details->getXrpAmount());
        $this->assertNull($details->getTokenAmount());
        $this->assertNull($details->getCurrency());
        $this->assertNull($details->getIssuer());
    }

    public function testGetPaymentDetailsByOrderIdPopulatesTokenFieldsForStablecoinPayment()
    {
        $this->mockOrder([
            'type' => 'xrpl_usdc_payment',
            'exchange_rate' => 1.0,
            'network' => 'Mainnet',
            'destination_tag' => 999,
            'hash' => null,
            'amount_requested' => [
                'currency' => '5553444300000000000000000000000000000000',
                'value' => '150',
                'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE',
            ],
        ], 150.0, 'USD');

        $details = $this->service->getPaymentDetailsByOrderId(42);

        $this->assertSame('xrpl_usdc_payment', $details->getType());
        $this->assertSame(0.0, $details->getXrpAmount());
        $this->assertSame('150', $details->getTokenAmount());
        $this->assertSame('5553444300000000000000000000000000000000', $details->getCurrency());
        $this->assertSame('rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE', $details->getIssuer());
    }
}
