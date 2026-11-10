<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Controller\Payment;

use Hardcastle\LedgerDirect\Controller\Payment\Refresh;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Price\OraclesDisagreeException;
use Hardcastle\LedgerDirect\Service\OrderAccess;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Request a new amount" when there is no price: back to the payment page with a notice,
 * the expired intent untouched, no stale rate.
 */
class RefreshTest extends TestCase
{
    /** @var OrderPaymentService|MockObject */
    private $orderPaymentService;

    /** @var ManagerInterface|MockObject */
    private $messageManager;

    /** @var Redirect|MockObject */
    private $redirect;

    private array $redirectPath = [];

    private Refresh $controller;

    protected function setUp(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(42);

        $orderAccess = $this->createMock(OrderAccess::class);
        $orderAccess->method('authorisedOrder')->willReturn($order);
        $orderAccess->method('keyOf')->willReturn('KEY');

        $this->orderPaymentService = $this->createMock(OrderPaymentService::class);
        $this->orderPaymentService->method('isAwaitingPayment')->willReturn(true);
        $this->orderPaymentService->method('readPaymentIntent')->willReturn($this->expiredIntent());

        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnCallback(function (string $path, array $params = []) {
            $this->redirectPath = [$path, $params];

            return $this->redirect;
        });
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->messageManager = $this->createMock(ManagerInterface::class);

        $this->controller = new Refresh(
            $this->createMock(RequestInterface::class),
            $redirectFactory,
            $this->createMock(Session::class),
            $orderAccess,
            $this->orderPaymentService,
            new SettlementPolicy(),
            $this->messageManager
        );
    }

    public function testAnExpiredQuoteIsRequoted(): void
    {
        $this->orderPaymentService->expects($this->once())->method('prepareOrderPayment')->with($this->anything(), true)
            ->willReturn($this->expiredIntent());
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame('ledger-direct/payment/index', $this->redirectPath[0]);
    }

    /**
     * The oracles disagree: the core throws and stores nothing, the customer goes back to
     * the still-expired page with a one-time notice instead of an error page.
     */
    public function testNoPriceReturnsToThePaymentPageWithANotice(): void
    {
        $this->orderPaymentService->method('prepareOrderPayment')
            ->willThrowException(new OraclesDisagreeException('No usable price for XRP/EUR: the oracles disagree.'));
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->anything(), Refresh::PRICE_UNAVAILABLE_GROUP);

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame(
            ['ledger-direct/payment/index', [OrderAccess::ORDER_ID_PARAMETER => 42, OrderAccess::KEY_PARAMETER => 'KEY']],
            $this->redirectPath
        );
    }

    private function expiredIntent(): PaymentIntent
    {
        return PaymentIntent::quote('xrp-payment', 'XRPL', 'testnet', 'XRP', 'EUR', 'XRP/EUR', 2.0, 50.0, 'rMerchant', 7, time() - 1);
    }
}
