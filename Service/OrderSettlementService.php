<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Model\Settlement\AmountCheck;
use Hardcastle\LedgerDirect\Model\Settlement\SettlementResult;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

/**
 * Turns a matched ledger payment into a paid Magento order.
 *
 * "Paid" in Magento means invoiced: an offline-captured invoice is what sets total_paid,
 * clears total_due and makes the order look settled to every report, export and the
 * fulfilment flow. The order then moves to the method's configured settled status, the
 * order confirmation that InitializeCommand held back at placement goes out, and the
 * invoice email with it.
 *
 * The decision whether the delivered amount is enough is {@see AmountCheck}; the core
 * records what was requested and what arrived, this service acts on it.
 */
class OrderSettlementService
{
    /**
     * @var AmountCheck
     */
    private AmountCheck $amountCheck;

    /**
     * @var InvoiceService
     */
    private InvoiceService $invoiceService;

    /**
     * @var TransactionFactory
     */
    private TransactionFactory $transactionFactory;

    /**
     * @var OrderSender
     */
    private OrderSender $orderSender;

    /**
     * @var InvoiceSender
     */
    private InvoiceSender $invoiceSender;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param AmountCheck $amountCheck
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param OrderSender $orderSender
     * @param InvoiceSender $invoiceSender
     * @param LoggerInterface $logger
     */
    public function __construct(
        AmountCheck $amountCheck,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        LoggerInterface $logger
    ) {
        $this->amountCheck = $amountCheck;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->logger = $logger;
    }

    /**
     * Settle the order against its fulfilled intent
     *
     * Idempotent: an order that is already fully invoiced reports SETTLED again without
     * creating a second invoice, so the payment page and the cron may both call this.
     *
     * @param OrderInterface|Order $order
     * @param PaymentIntent $intent a fulfilled intent (hash and amount_paid set)
     * @return SettlementResult
     */
    public function settle(OrderInterface $order, PaymentIntent $intent): SettlementResult
    {
        $paid = (string) $this->amountCheck->paidValue($intent);
        $requested = $this->amountCheck->requestedValue($intent);

        if ((float) $order->getTotalDue() <= 0.0) {
            return new SettlementResult(SettlementResult::SETTLED, $paid, $requested);
        }

        if (!$order->canInvoice()) {
            $this->logger->warning('LedgerDirect: ledger payment received for an order that can no longer be paid', [
                'order' => $order->getIncrementId(),
                'state' => $order->getState(),
                'hash' => $intent->hash,
            ]);
            $order->addCommentToStatusHistory(__(
                'XRPL payment %1 %2 received (tx %3), but the order can no longer be paid.',
                $paid,
                $intent->baseAsset,
                $intent->hash
            ));
            $order->save();

            return new SettlementResult(SettlementResult::NOT_PAYABLE, $paid, $requested);
        }

        if (!$this->amountCheck->isSettled($intent)) {
            $order->addCommentToStatusHistory(__(
                'XRPL payment %1 of %2 %3 received (tx %4); the order stays pending.',
                $paid,
                $requested,
                $intent->baseAsset,
                $intent->hash
            ));
            $order->save();

            return new SettlementResult(SettlementResult::UNDERPAID, $paid, $requested);
        }

        $this->invoice($order, $intent, $paid);

        return new SettlementResult(SettlementResult::SETTLED, $paid, $requested);
    }

    /**
     * Invoice the order offline, move it to the settled status and send the held-back emails
     *
     * @param Order $order
     * @param PaymentIntent $intent
     * @param string $paid
     * @return void
     */
    private function invoice(Order $order, PaymentIntent $intent, string $paid): void
    {
        $invoice = $this->invoiceService->prepareInvoice($order);
        // Both are DataObject fields register() reads; the magic setters are avoided on purpose so
        // the call is visible to static analysis and mockable in tests.
        $invoice->setData('requested_capture_case', Invoice::CAPTURE_OFFLINE);
        $invoice->setData('transaction_id', $intent->hash);
        $invoice->register();

        $order->setData('is_in_process', true);
        $order->setState(Order::STATE_PROCESSING)->setStatus($this->settledStatus($order));
        $order->addCommentToStatusHistory(
            __('XRPL payment settled: %1 %2 received (tx %3).', $paid, $intent->baseAsset, $intent->hash),
            $order->getStatus()
        );

        $this->transactionFactory->create()->addObject($invoice)->addObject($order)->save();

        if (!$order->getEmailSent()) {
            $this->orderSender->send($order);
        }
        $this->invoiceSender->send($invoice);
    }

    /**
     * The status configured for a settled order, if it belongs to the processing state
     *
     * @param Order $order
     * @return string
     */
    private function settledStatus(Order $order): string
    {
        $configured = (string) $order->getPayment()->getMethodInstance()->getConfigData('settled_status');
        $allowed = $order->getConfig()->getStateStatuses(Order::STATE_PROCESSING);

        return isset($allowed[$configured])
            ? $configured
            : $order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING);
    }
}
