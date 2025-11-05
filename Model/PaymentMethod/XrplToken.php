<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\PaymentMethod;

use Magento\Framework\DataObject;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Sales\Model\Order;

class XrplToken extends AbstractMethod
{
    /**
     * Payment method code as declared in etc/payment.xml
     */
    protected $_code = 'xrpl_token_payment';

    /**
     * XRPL token settlement is confirmed asynchronously
     */
    protected $_isOffline = true;
    protected $_canAuthorize = false;
    protected $_canCapture = false;
    protected $_canRefund = false;

    public function initialize($paymentAction, $stateObject)
    {
        $order = $this->getInfoInstance()->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $orderState = Order::STATE_PENDING_PAYMENT;
        $orderStatus = $order->getConfig()->getStateDefaultStatus($orderState);
        $comment = __('The customer was redirected for payment processing. The payment is pending.');
        $order->setState($orderState)->addStatusToHistory($orderStatus, $comment, false);

        return $this;
    }

    public function assignData(DataObject $data)
    {
        parent::assignData($data);
        return $this;
    }
}
