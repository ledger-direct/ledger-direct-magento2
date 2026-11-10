<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Controller\Payment;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Controller\Payment\Index;
use Hardcastle\LedgerDirect\Controller\Payment\Refresh;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Price\OraclesDisagreeException;
use Hardcastle\LedgerDirect\Service\OrderAccess;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Message\Collection;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The payment page when the core has no price: a new order gets a "try again" page and
 * nothing is stored; an expired quote whose refresh found no price says so once.
 */
class IndexTest extends TestCase
{
    /** @var OrderPaymentService|MockObject */
    private $orderPaymentService;

    /** @var XrpPaymentServiceInterface|MockObject */
    private $xrpPaymentService;

    /** @var ManagerInterface|MockObject */
    private $messageManager;

    /** @var Template|MockObject */
    private $block;

    /** @var Page|MockObject */
    private $page;

    private array $blockData = [];

    private ?string $template = null;

    private Index $controller;

    protected function setUp(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('100000042');

        $orderAccess = $this->createMock(OrderAccess::class);
        $orderAccess->method('authorisedOrder')->willReturn($order);
        $orderAccess->method('keyOf')->willReturn('KEY');
        $orderAccess->method('hasValidKey')->willReturn(true);

        $this->orderPaymentService = $this->createMock(OrderPaymentService::class);
        $this->orderPaymentService->method('isAwaitingPayment')->willReturn(false);
        $this->orderPaymentService->method('isAwaitingPaymentById')->willReturn(true);

        $this->xrpPaymentService = $this->createMock(XrpPaymentServiceInterface::class);

        $this->block = $this->createMock(Template::class);
        $this->block->method('setData')->willReturnCallback(function ($key, $value = null) {
            $this->blockData[$key] = $value;

            return $this->block;
        });
        $this->block->method('setTemplate')->willReturnCallback(function (string $template) {
            $this->template = $template;

            return $this->block;
        });
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturn($this->block);
        $pageConfig = $this->createMock(Config::class);
        $pageConfig->method('getTitle')->willReturn($this->createMock(Title::class));
        $this->page = $this->createMock(Page::class);
        $this->page->method('getConfig')->willReturn($pageConfig);
        $this->page->method('getLayout')->willReturn($layout);
        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($this->page);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(fn (string $path) => 'https://shop.test/' . $path);

        $this->messageManager = $this->createMock(ManagerInterface::class);

        $this->controller = new Index(
            $this->createMock(Session::class),
            $this->createMock(RequestInterface::class),
            $pageFactory,
            $this->createMock(RedirectFactory::class),
            $urlBuilder,
            $this->createMock(FormKey::class),
            $orderAccess,
            $this->orderPaymentService,
            $this->xrpPaymentService,
            $this->createMock(OrderSettlementService::class),
            new SettlementPolicy(),
            $this->createMock(PaymentRedirect::class),
            $this->messageManager
        );
    }

    /**
     * A new order, first quote, oracles disagree: no amount, no account, a "try again" page
     * whose button reloads this page. The intent is never read - there is none.
     */
    public function testANewOrderWithoutAPriceGetsTheTryAgainPage(): void
    {
        $this->xrpPaymentService->method('getPaymentDetails')
            ->willThrowException(new OraclesDisagreeException('No usable price for XRP/EUR: the oracles disagree.'));
        $this->orderPaymentService->expects($this->never())->method('readPaymentIntent');

        $this->assertSame($this->page, $this->controller->execute());

        $this->assertSame('Hardcastle_LedgerDirect::payment/price-unavailable.phtml', $this->template);
        $this->assertSame('100000042', $this->blockData['order_number']);
        $this->assertSame('https://shop.test/ledger-direct/payment/index', $this->blockData['page_url']);
        $this->assertArrayNotHasKey('payment_info', $this->blockData);
    }

    public function testAnExpiredQuoteWhoseRefreshFoundNoPriceSaysSoOnce(): void
    {
        $this->givenExpiredQuote();
        $messages = $this->createMock(Collection::class);
        $messages->method('getCount')->willReturn(1);
        $this->messageManager->expects($this->once())
            ->method('getMessages')
            ->with(true, Refresh::PRICE_UNAVAILABLE_GROUP)
            ->willReturn($messages);

        $this->controller->execute();

        $this->assertNull($this->template, 'the regular page, still expired, with its refresh button');
        $this->assertTrue($this->blockData['requote_failed']);
    }

    public function testAnExpiredQuoteWithoutAFailedRefreshShowsNoNotice(): void
    {
        $this->givenExpiredQuote();
        $messages = $this->createMock(Collection::class);
        $messages->method('getCount')->willReturn(0);
        $this->messageManager->method('getMessages')->willReturn($messages);

        $this->controller->execute();

        $this->assertFalse($this->blockData['requote_failed']);
    }

    private function givenExpiredQuote(): void
    {
        $this->xrpPaymentService->method('getPaymentDetails')->willReturn($this->createMock(XrpPaymentInterface::class));
        $this->orderPaymentService->method('readPaymentIntent')->willReturn(
            PaymentIntent::quote('xrp-payment', 'XRPL', 'testnet', 'XRP', 'EUR', 'XRP/EUR', 2.0, 50.0, 'rMerchant', 7, time() - 1)
        );
    }
}
