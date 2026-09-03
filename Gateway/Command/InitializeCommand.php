<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Gateway\Command;

use Magento\Framework\DataObject;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;

/**
 * Moves the order to pending_payment on checkout placement and suppresses the new-order email,
 * since XRPL settlement is confirmed asynchronously via {@see \Hardcastle\LedgerDirect\Service\OrderPaymentService}.
 *
 * The state is reported through the $stateObject Magento hands to the initialize command, not
 * set on the order: {@see \Magento\Sales\Model\Order\Payment::place()} reads state and status
 * from that object after initialize() returns and overwrites whatever the order carries.
 * The command only runs when the method has a non-empty payment_action configured - see config.xml.
 */
class InitializeCommand implements CommandInterface
{
    /**
     * @inheritdoc
     */
    public function execute(array $commandSubject)
    {
        /** @var PaymentDataObjectInterface $paymentDataObject */
        $paymentDataObject = $commandSubject['payment'];
        /** @var DataObject $stateObject */
        $stateObject = $commandSubject['stateObject'];
        $order = $paymentDataObject->getPayment()->getOrder();

        $orderState = Order::STATE_PENDING_PAYMENT;
        $orderStatus = $order->getConfig()->getStateDefaultStatus($orderState);

        $stateObject->setData('state', $orderState);
        $stateObject->setData('status', $orderStatus);
        $stateObject->setData('is_notified', false);

        $order->setCanSendNewEmailFlag(false);
        $order->addCommentToStatusHistory(
            __('The customer was redirected for payment processing. The payment is pending.'),
            $orderStatus
        );
    }
}
