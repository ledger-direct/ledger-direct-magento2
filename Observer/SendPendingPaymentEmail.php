<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Observer;

use Hardcastle\LedgerDirect\Model\Email\PendingPaymentSender;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * After the checkout has placed and saved the order (checkout_submit_all_after, for the
 * storefront checkout and the REST checkout alike), mail the customer the payment link.
 */
class SendPendingPaymentEmail implements ObserverInterface
{
    /**
     * @var PendingPaymentSender
     */
    private PendingPaymentSender $sender;

    /**
     * @param PendingPaymentSender $sender
     */
    public function __construct(PendingPaymentSender $sender)
    {
        $this->sender = $sender;
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if ($order instanceof Order) {
            $this->sender->send($order);
        }
    }
}
