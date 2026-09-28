<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model\Email;

use Hardcastle\LedgerDirect\Model\Email\PendingPaymentSender;
use Hardcastle\LedgerDirect\Service\OrderAccess;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Status\History;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PendingPaymentSenderTest extends TestCase
{
    /** @var TransportBuilder|MockObject */
    private $transportBuilder;

    /** @var TransportInterface|MockObject */
    private $transport;

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepository;

    /** @var LoggerInterface|MockObject */
    private $logger;

    /** @var array<string, mixed> what the template was given */
    private array $templateVars = [];

    private array $config = [PendingPaymentSender::CONFIG_ENABLED => 1];

    private PendingPaymentSender $sender;

    protected function setUp(): void
    {
        $this->transport = $this->createMock(TransportInterface::class);
        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope', 'addTo'] as $fluent) {
            $this->transportBuilder->method($fluent)->willReturnSelf();
        }
        $this->transportBuilder->method('setTemplateVars')->willReturnCallback(function (array $vars) {
            $this->templateVars = $vars;

            return $this->transportBuilder;
        });
        $this->transportBuilder->method('getTransport')->willReturn($this->transport);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn (string $path) => (bool) ($this->config[$path] ?? false));
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $this->config[$path] ?? null);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $orderAccess = $this->createMock(OrderAccess::class);
        $orderAccess->method('keyOf')->willReturn('a1b2c3d4e5f60718293a4b5c6d7e8f90');

        $this->sender = new PendingPaymentSender(
            $this->transportBuilder,
            $scopeConfig,
            $this->orderRepository,
            $orderAccess,
            $this->logger
        );
    }

    public function testAFreshLedgerDirectOrderGetsTheLinkWithItsKey(): void
    {
        $order = $this->givenOrder();
        $this->transport->expects($this->once())->method('sendMessage');
        $this->transportBuilder->expects($this->once())->method('addTo')->with('guest@example.com', 'Ledger Guest');
        $order->getPayment()->expects($this->once())->method('setAdditionalInformation')
            ->with(PendingPaymentSender::SENT_AT_KEY, $this->anything());
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->callback(static fn ($c): bool => str_contains((string) $c, 'guest@example.com')));
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->assertTrue($this->sender->send($order));
        $this->assertSame(
            'https://shop.test/ledger-direct/payment/index/id/42/key/a1b2c3d4e5f60718293a4b5c6d7e8f90/',
            $this->templateVars['payment_url']
        );
        $this->assertSame('Pay with XRP', $this->templateVars['payment_method_title']);
        $this->assertSame('Ledger Guest', $this->templateVars['order_data']['customer_name']);
    }

    public function testItIsSentOnlyOnce(): void
    {
        $order = $this->givenOrder(sentAt: '2026-09-25T10:00:00+00:00');
        $this->transport->expects($this->never())->method('sendMessage');
        $this->orderRepository->expects($this->never())->method('save');

        $this->assertFalse($this->sender->send($order));
    }

    public function testItCanBeSwitchedOff(): void
    {
        $this->config[PendingPaymentSender::CONFIG_ENABLED] = 0;
        $this->transport->expects($this->never())->method('sendMessage');

        $this->assertFalse($this->sender->send($this->givenOrder()));
    }

    public function testOtherPaymentMethodsAndOtherStatesAreLeftAlone(): void
    {
        $this->transport->expects($this->never())->method('sendMessage');

        $this->assertFalse($this->sender->send($this->givenOrder(method: 'checkmo')));
        $this->assertFalse($this->sender->send($this->givenOrder(state: Order::STATE_PROCESSING)));
        $this->assertFalse($this->sender->send($this->givenOrder(email: '')));
    }

    /**
     * A mail server that is down must not fail the checkout: logged, not thrown, not marked as sent.
     */
    public function testAFailingTransportIsLoggedAndNotMarkedAsSent(): void
    {
        $order = $this->givenOrder();
        $this->transport->method('sendMessage')->willThrowException(new \RuntimeException('SMTP down'));
        $this->logger->expects($this->once())->method('error');
        $order->getPayment()->expects($this->never())->method('setAdditionalInformation');
        $this->orderRepository->expects($this->never())->method('save');

        $this->assertFalse($this->sender->send($order));
    }

    /**
     * @return Order|MockObject
     */
    private function givenOrder(
        string $method = 'xrp_payment',
        string $state = Order::STATE_PENDING_PAYMENT,
        string $email = 'guest@example.com',
        ?string $sentAt = null
    ) {
        $methodInstance = $this->createMock(MethodInterface::class);
        $methodInstance->method('getTitle')->willReturn('Pay with XRP');
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getMethodInstance')->willReturn($methodInstance);
        $payment->method('getAdditionalInformation')->with(PendingPaymentSender::SENT_AT_KEY)->willReturn($sentAt);

        $store = $this->createMock(Store::class);
        $store->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params) => 'https://shop.test/' . $route . '/id/' . $params['id'] . '/key/' . $params['key'] . '/'
        );
        $history = $this->createMock(History::class);
        $history->method('setIsCustomerNotified')->willReturnSelf();

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('getCustomerEmail')->willReturn($email);
        $order->method('getCustomerName')->willReturn('Ledger Guest');
        $order->method('getEntityId')->willReturn(42);
        $order->method('getId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getStore')->willReturn($store);
        $order->method('addCommentToStatusHistory')->willReturn($history);

        return $order;
    }
}
