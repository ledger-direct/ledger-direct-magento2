<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Controller\Payment;

use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderAccess;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use InvalidArgumentException;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The payment-status endpoint: "is this order paid?" while its customer watches the
 * payment page.
 *
 * The answer is the core's PaymentStatus payload (INVARIANTS.md, "Payment status") - the
 * same seven fields every LedgerDirect plugin returns - plus the one field only this
 * platform can build: `redirect`. It is present as soon as the order no longer waits for
 * its payment, whatever ended the wait: settled on the ledger, or canceled by the merchant
 * or by Magento's pending-payment cron, for which the contract has no state.
 *
 * While the order waits the ledger is synced (throttled per receiving account, see
 * SyncThrottle) and a hit settles the order right here - the customer need not come back
 * anywhere for the merchant to see "paid" or "payment incomplete".
 *
 * A frontend controller rather than a webapi.xml route on purpose: the REST layer wraps
 * the answer in its own envelope and knows only ACL resources or `anonymous`, which is
 * exactly the hole this replaces. Guarded by the order's key or the session it belongs to,
 * see OrderAccess; 403 for anything else, without telling whether the order exists.
 */
class Status implements HttpGetActionInterface
{
    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var JsonFactory
     */
    private JsonFactory $jsonFactory;

    /**
     * @var OrderAccess
     */
    private OrderAccess $orderAccess;

    /**
     * @var OrderPaymentService
     */
    private OrderPaymentService $orderPaymentService;

    /**
     * @var OrderSettlementService
     */
    private OrderSettlementService $settlementService;

    /**
     * @var SettlementPolicy
     */
    private SettlementPolicy $settlementPolicy;

    /**
     * @var PaymentRedirect
     */
    private PaymentRedirect $paymentRedirect;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param OrderAccess $orderAccess
     * @param OrderPaymentService $orderPaymentService
     * @param OrderSettlementService $settlementService
     * @param SettlementPolicy $settlementPolicy
     * @param PaymentRedirect $paymentRedirect
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        OrderAccess $orderAccess,
        OrderPaymentService $orderPaymentService,
        OrderSettlementService $settlementService,
        SettlementPolicy $settlementPolicy,
        PaymentRedirect $paymentRedirect,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->orderAccess = $orderAccess;
        $this->orderPaymentService = $orderPaymentService;
        $this->settlementService = $settlementService;
        $this->settlementPolicy = $settlementPolicy;
        $this->paymentRedirect = $paymentRedirect;
        $this->logger = $logger;
    }

    /**
     * Answer the contract payload for the order in the request
     *
     * @return Json
     */
    public function execute(): Json
    {
        $order = $this->orderAccess->authorisedOrder($this->request);

        if ($order === null) {
            return $this->respond(403, ['error' => 'forbidden']);
        }

        try {
            $intent = $this->orderPaymentService->readPaymentIntent($order);
        } catch (InvalidArgumentException $exception) {
            $this->logger->error('LedgerDirect: status asked for an order with an unreadable payment record', [
                'order' => $order->getIncrementId(),
                'exception' => $exception->getMessage(),
            ]);

            return $this->respond(500, ['error' => 'payment_intent_unreadable']);
        }

        if ($intent === null) {
            // The page renders no poll URL for such an order; this only answers a hand-made request.
            return $this->respond(404, ['error' => 'no_payment_intent']);
        }

        if ($this->orderPaymentService->isAwaitingPayment($order)) {
            $intent = $this->syncAndSettle($order) ?? $intent;
        }

        $payload = PaymentStatus::fromIntent($intent, $this->settlementPolicy)->toArray();

        // Read fresh, not from the object above: a settlement in this very request leaves it stale.
        if (!$this->orderPaymentService->isAwaitingPaymentById((int) $order->getEntityId())) {
            $payload['redirect'] = $this->paymentRedirect->target($order);
        }

        return $this->respond(200, $payload);
    }

    /**
     * Sync the ledger for the order and settle it on a hit
     *
     * A failure here is logged and the poll still answers from the stored intent: the next
     * poll, or the cron, tries again.
     *
     * @param OrderInterface $order
     * @return \Hardcastle\LedgerDirect\Core\Payment\PaymentIntent|null the fulfilled intent, if any
     */
    private function syncAndSettle(OrderInterface $order): ?\Hardcastle\LedgerDirect\Core\Payment\PaymentIntent
    {
        try {
            $fulfilledIntent = $this->orderPaymentService->syncOrderTransactionWithXrpl($order, true);

            if ($fulfilledIntent !== null) {
                $this->settlementService->settle($order, $fulfilledIntent);
            }

            return $fulfilledIntent;
        } catch (Throwable $exception) {
            $this->logger->error('LedgerDirect: status check could not sync or settle the order', [
                'order' => $order->getIncrementId(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A JSON answer that no cache may hold on to
     *
     * @param int $httpStatus
     * @param array $payload
     * @return Json
     */
    private function respond(int $httpStatus, array $payload): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($httpStatus);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setData($payload);

        return $result;
    }
}
