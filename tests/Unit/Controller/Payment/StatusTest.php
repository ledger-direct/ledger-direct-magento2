<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Controller\Payment;

use Hardcastle\LedgerDirect\Controller\Payment\Status;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Model\Settlement\SettlementResult;
use Hardcastle\LedgerDirect\Service\OrderAccess;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The status endpoint answers the contract payload (INVARIANTS.md, "Payment status") for
 * every state, plus `redirect` as soon as the order no longer waits - the same values
 * PrestaShop's poll and Shopware's check() return for the same situation.
 */
class StatusTest extends TestCase
{
    private const REQUESTED = ['currency' => '5553444300000000000000000000000000000000', 'value' => '150.00', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];

    /** @var OrderAccess|MockObject */
    private $orderAccess;

    /** @var OrderPaymentService|MockObject */
    private $orderPaymentService;

    /** @var OrderSettlementService|MockObject */
    private $settlementService;

    /** @var PaymentRedirect|MockObject */
    private $paymentRedirect;

    /** @var OrderInterface|MockObject */
    private $order;

    private int $httpStatus = 0;

    private array $payload = [];

    private Status $controller;

    protected function setUp(): void
    {
        $this->orderAccess = $this->createMock(OrderAccess::class);
        $this->orderPaymentService = $this->createMock(OrderPaymentService::class);
        $this->settlementService = $this->createMock(OrderSettlementService::class);
        $this->paymentRedirect = $this->createMock(PaymentRedirect::class);
        $this->paymentRedirect->method('target')->willReturn('https://shop.test/sales/guest/view');

        $this->order = $this->createMock(OrderInterface::class);
        $this->order->method('getEntityId')->willReturn(42);
        $this->order->method('getIncrementId')->willReturn('100000042');

        $json = $this->createMock(Json::class);
        $json->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($json) {
            $this->httpStatus = $code;

            return $json;
        });
        $json->method('setHeader')->willReturnSelf();
        $json->method('setData')->willReturnCallback(function (array $data) use ($json) {
            $this->payload = $data;

            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $this->controller = new Status(
            $this->createMock(RequestInterface::class),
            $jsonFactory,
            $this->orderAccess,
            $this->orderPaymentService,
            $this->settlementService,
            new SettlementPolicy(),
            $this->paymentRedirect,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testAWrongKeyIsRefusedWithoutAHint(): void
    {
        $this->orderAccess->method('authorisedOrder')->willReturn(null);
        $this->orderPaymentService->expects($this->never())->method('syncOrderTransactionWithXrpl');

        $this->controller->execute();

        $this->assertSame(403, $this->httpStatus);
        $this->assertSame(['error' => 'forbidden'], $this->payload);
    }

    public function testAnOrderNeverQuotedForXrplIs404(): void
    {
        $this->orderAccess->method('authorisedOrder')->willReturn($this->order);
        $this->orderPaymentService->method('readPaymentIntent')->willReturn(null);

        $this->controller->execute();

        $this->assertSame(404, $this->httpStatus);
        $this->assertSame(['error' => 'no_payment_intent'], $this->payload);
    }

    public function testWaitingCarriesTheSecondsLeftAndNoRedirect(): void
    {
        $this->givenAwaitingOrder($this->xrpIntent(expiry: time() + 120), syncReturns: null);

        $this->controller->execute();

        $this->assertSame(200, $this->httpStatus);
        $this->assertSame(PaymentStatus::WAITING, $this->payload['state']);
        $this->assertSame(1, $this->payload['schema_version']);
        $this->assertSame('XRP', $this->payload['base_asset']);
        $this->assertSame(50.0, $this->payload['amount_requested']);
        $this->assertNull($this->payload['amount_paid']);
        $this->assertNull($this->payload['shortfall']);
        $this->assertGreaterThan(0, $this->payload['seconds_left']);
        $this->assertLessThanOrEqual(120, $this->payload['seconds_left']);
        $this->assertArrayNotHasKey('redirect', $this->payload);
    }

    public function testExpiredKeepsPolling(): void
    {
        $this->givenAwaitingOrder($this->xrpIntent(expiry: time() - 1), syncReturns: null);

        $this->controller->execute();

        $this->assertSame(PaymentStatus::EXPIRED, $this->payload['state']);
        $this->assertNull($this->payload['seconds_left']);
        $this->assertArrayNotHasKey('redirect', $this->payload);
    }

    /**
     * A hit that does not settle is settled (recorded) right here and the payload shows what
     * arrived and what is still due, adding up to the request.
     */
    public function testAPartialPaymentIsRecordedAndReported(): void
    {
        $fulfilled = $this->xrpIntent(expiry: time() + 120)->withFulfillment('HASH', 20.0);
        $this->givenAwaitingOrder($this->xrpIntent(expiry: time() + 120), syncReturns: $fulfilled);
        $this->settlementService->expects($this->once())->method('settle')->with($this->order, $fulfilled)
            ->willReturn(new SettlementResult(SettlementResult::UNDERPAID, '20', '50'));

        $this->controller->execute();

        $this->assertSame(PaymentStatus::PARTIAL, $this->payload['state']);
        $this->assertSame(20.0, $this->payload['amount_paid']);
        $this->assertSame(30.0, $this->payload['shortfall']);
        $this->assertNull($this->payload['seconds_left']);
        $this->assertArrayNotHasKey('redirect', $this->payload);
    }

    public function testAPaymentInTheWrongAssetNamesTheDeliveredTokenAndTheWholeRequest(): void
    {
        $rlusd = ['currency' => '524C555344000000000000000000000000000000', 'value' => '150', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'];
        $intent = PaymentIntent::quote('usdc-payment', 'XRPL', 'testnet', 'USDC', 'USD', 'USDC/USD', 1.0, self::REQUESTED, 'rMerchant', 7, time() + 120);
        $this->givenAwaitingOrder($intent, syncReturns: $intent->withFulfillment('HASH', $rlusd));
        $this->settlementService->method('settle')->willReturn(new SettlementResult(SettlementResult::UNDERPAID, '150', '150.00'));

        $this->controller->execute();

        $this->assertSame(PaymentStatus::WRONG_ASSET, $this->payload['state']);
        $this->assertSame($rlusd, $this->payload['amount_paid'], 'the delivered asset, so the page can name it');
        $this->assertSame(
            ['currency' => self::REQUESTED['currency'], 'value' => '150', 'issuer' => self::REQUESTED['issuer']],
            $this->payload['shortfall'],
            'the whole request, in the quoted asset'
        );
    }

    /**
     * Settled in this very request: the redirect must come now, not one poll later - which is
     * why the controller re-reads the order's state instead of trusting the object it started with.
     */
    public function testASettledPaymentCarriesTheRedirectInTheSamePoll(): void
    {
        $fulfilled = $this->xrpIntent(expiry: time() + 120)->withFulfillment('HASH', 50.0);
        $this->givenAwaitingOrder($this->xrpIntent(expiry: time() + 120), syncReturns: $fulfilled, awaitingAfterwards: false);
        $this->settlementService->expects($this->once())->method('settle')
            ->willReturn(new SettlementResult(SettlementResult::SETTLED, '50', '50'));

        $this->controller->execute();

        $this->assertSame(PaymentStatus::SETTLED, $this->payload['state']);
        $this->assertSame(50.0, $this->payload['amount_paid']);
        $this->assertNull($this->payload['shortfall']);
        $this->assertSame('https://shop.test/sales/guest/view', $this->payload['redirect']);
    }

    /**
     * Closed by the merchant (PS-11): the contract state is still `waiting`, but the order no
     * longer waits, so the poll carries the redirect - and the ledger is not synced for it.
     */
    public function testAnOrderClosedByTheMerchantRedirectsWhateverTheContractStateIs(): void
    {
        $this->orderAccess->method('authorisedOrder')->willReturn($this->order);
        $this->orderPaymentService->method('readPaymentIntent')->willReturn($this->xrpIntent(expiry: time() + 120));
        $this->orderPaymentService->method('isAwaitingPayment')->willReturn(false);
        $this->orderPaymentService->method('isAwaitingPaymentById')->willReturn(false);
        $this->orderPaymentService->expects($this->never())->method('syncOrderTransactionWithXrpl');

        $this->controller->execute();

        $this->assertSame(PaymentStatus::WAITING, $this->payload['state']);
        $this->assertSame('https://shop.test/sales/guest/view', $this->payload['redirect']);
    }

    /**
     * A node that is down must not turn the poll into an error page: the stored intent answers.
     */
    public function testAFailedSyncStillAnswersFromTheStoredIntent(): void
    {
        $this->orderAccess->method('authorisedOrder')->willReturn($this->order);
        $this->orderPaymentService->method('readPaymentIntent')->willReturn($this->xrpIntent(expiry: time() + 120));
        $this->orderPaymentService->method('isAwaitingPayment')->willReturn(true);
        $this->orderPaymentService->method('isAwaitingPaymentById')->willReturn(true);
        $this->orderPaymentService->method('syncOrderTransactionWithXrpl')->willThrowException(new \RuntimeException('db gone'));

        $this->controller->execute();

        $this->assertSame(200, $this->httpStatus);
        $this->assertSame(PaymentStatus::WAITING, $this->payload['state']);
    }

    private function givenAwaitingOrder(PaymentIntent $stored, ?PaymentIntent $syncReturns, bool $awaitingAfterwards = true): void
    {
        $this->orderAccess->method('authorisedOrder')->willReturn($this->order);
        $this->orderPaymentService->method('readPaymentIntent')->willReturn($stored);
        $this->orderPaymentService->method('isAwaitingPayment')->with($this->order)->willReturn(true);
        $this->orderPaymentService->method('isAwaitingPaymentById')->with(42)->willReturn($awaitingAfterwards);
        $this->orderPaymentService->expects($this->once())->method('syncOrderTransactionWithXrpl')
            ->with($this->order, true)->willReturn($syncReturns);
    }

    private function xrpIntent(?int $expiry): PaymentIntent
    {
        return PaymentIntent::quote('xrp-payment', 'XRPL', 'testnet', 'XRP', 'EUR', 'XRP/EUR', 2.0, 50.0, 'rMerchant', 7, $expiry);
    }
}
