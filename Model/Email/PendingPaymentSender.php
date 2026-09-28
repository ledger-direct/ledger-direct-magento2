<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\Email;

use Exception;
use Hardcastle\LedgerDirect\Service\OrderAccess;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the customer the link to the payment page right after the order is placed.
 *
 * The order confirmation is held back until the payment has arrived (InitializeCommand), so
 * without this mail a customer who closes the payment page has no way back to it: the
 * checkout only ever showed the URL in the address bar. The link carries the order's key
 * (OrderAccess), so it works for guests, in another browser, without a login.
 *
 * Sent once per order; the mark lives in the payment's additional_information, next to
 * nothing else. A failure is logged and never reaches the checkout.
 */
class PendingPaymentSender
{
    public const CONFIG_ENABLED = 'payment/ledger_direct/pending_email_enabled';
    public const CONFIG_IDENTITY = 'payment/ledger_direct/pending_email_identity';
    public const CONFIG_TEMPLATE = 'payment/ledger_direct/pending_email_template';

    public const SENT_AT_KEY = 'ledger_direct_pending_email_sent_at';

    /**
     * @var TransportBuilder
     */
    private TransportBuilder $transportBuilder;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var OrderAccess
     */
    private OrderAccess $orderAccess;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param TransportBuilder $transportBuilder
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderAccess $orderAccess
     * @param LoggerInterface $logger
     */
    public function __construct(
        TransportBuilder $transportBuilder,
        ScopeConfigInterface $scopeConfig,
        OrderRepositoryInterface $orderRepository,
        OrderAccess $orderAccess,
        LoggerInterface $logger
    ) {
        $this->transportBuilder = $transportBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->orderAccess = $orderAccess;
        $this->logger = $logger;
    }

    /**
     * Send the payment instructions for a freshly placed LedgerDirect order, once
     *
     * @param Order $order
     * @return bool whether a mail went out
     */
    public function send(Order $order): bool
    {
        if (!$this->shouldSend($order)) {
            return false;
        }

        $storeId = (int) $order->getStoreId();
        $customerName = trim((string) $order->getCustomerName());

        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier((string) $this->config(self::CONFIG_TEMPLATE, $storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'order' => $order,
                    'order_id' => $order->getId(),
                    'order_data' => ['customer_name' => $customerName],
                    'payment_url' => $this->paymentUrl($order),
                    'payment_method_title' => (string) $order->getPayment()->getMethodInstance()->getTitle(),
                    'store' => $order->getStore(),
                    'store_phone' => $this->config('general/store_information/phone', $storeId),
                    'store_email' => $this->config('trans_email/ident_support/email', $storeId),
                    'store_hours' => $this->config('general/store_information/hours', $storeId),
                ])
                ->setFromByScope((string) $this->config(self::CONFIG_IDENTITY, $storeId), $storeId)
                ->addTo((string) $order->getCustomerEmail(), $customerName)
                ->getTransport();

            $transport->sendMessage();
        } catch (Exception $exception) {
            $this->logger->error('LedgerDirect: payment instructions email could not be sent', [
                'order' => $order->getIncrementId(),
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        $order->getPayment()->setAdditionalInformation(self::SENT_AT_KEY, date('c'));
        $order->addCommentToStatusHistory(
            __('Payment instructions with the link to the payment page sent to %1.', $order->getCustomerEmail())
        )->setIsCustomerNotified(true);
        $this->orderRepository->save($order);

        return true;
    }

    /**
     * Whether this order gets the mail: enabled, ours, still waiting, not yet sent, has a recipient
     *
     * @param Order $order
     * @return bool
     */
    public function shouldSend(Order $order): bool
    {
        $payment = $order->getPayment();

        return $payment !== null
            && in_array($payment->getMethod(), OrderPaymentService::PAYMENT_METHODS, true)
            && $order->getState() === Order::STATE_PENDING_PAYMENT
            && (string) $order->getCustomerEmail() !== ''
            && $payment->getAdditionalInformation(self::SENT_AT_KEY) === null
            && $this->scopeConfig->isSetFlag(self::CONFIG_ENABLED, ScopeInterface::SCOPE_STORE, $order->getStoreId());
    }

    /**
     * The payment page URL with the order's key - the one that keeps working without a session
     *
     * @param Order $order
     * @return string
     */
    private function paymentUrl(Order $order): string
    {
        return $order->getStore()->getUrl('ledger-direct/payment/index', [
            OrderAccess::ORDER_ID_PARAMETER => (int) $order->getEntityId(),
            OrderAccess::KEY_PARAMETER => $this->orderAccess->keyOf($order),
            '_nosid' => true,
        ]);
    }

    /**
     * A store-scoped configuration value as a string
     *
     * @param string $path
     * @param int $storeId
     * @return string|null
     */
    private function config(string $path, int $storeId): ?string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return $value === null ? null : (string) $value;
    }
}
