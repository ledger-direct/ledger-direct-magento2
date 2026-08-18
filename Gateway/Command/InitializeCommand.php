<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;

/**
 * Moves the order to pending_payment on checkout placement and suppresses the new-order email,
 * since XRPL settlement is confirmed asynchronously via {@see \Hardcastle\LedgerDirect\Service\OrderPaymentService}.
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
        $order = $paymentDataObject->getPayment()->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $orderState = Order::STATE_PENDING_PAYMENT;
        $orderStatus = $order->getConfig()->getStateDefaultStatus($orderState);
        $comment = __('The customer was redirected for payment processing. The payment is pending.');
        $order->setState($orderState)->addStatusToHistory($orderStatus, $comment, false);
    }
}
