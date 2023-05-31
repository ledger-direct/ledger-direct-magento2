<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\PaymentMethod;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Payment\Gateway\Command\CommandManagerInterface;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Gateway\Validator\ValidatorPoolInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\Adapter;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;

class Xrp extends Adapter
{
    protected CartRepositoryInterface $quoteRepository;

    public function __construct(
        ManagerInterface $eventManager,
        ValueHandlerPoolInterface $valueHandlerPool,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        $code,
        $formBlockType,
        $infoBlockType,
        CommandPoolInterface $commandPool = null,
        ValidatorPoolInterface $validatorPool = null,
        LoggerInterface $logger = null,
        CartRepositoryInterface $quoteRepository
    ) {
        parent::__construct(
            $eventManager,
            $valueHandlerPool,
            $paymentDataObjectFactory,
            $code,
            $formBlockType,
            $infoBlockType,
            $commandPool,
            $validatorPool,
            null,
            $logger
        );

        $this->quoteRepository = $quoteRepository;
    }

    public function initialize($paymentAction, $stateObject)
    {
        //$session = $this->checkoutHelper->getCheckout();
        $order = $this->getInfoInstance()->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $quote = $this->quoteRepository->get($order->getQuoteId());

        $orderState = Order::STATE_PENDING_PAYMENT;
        $orderStatus = $order->getConfig()->getStateDefaultStatus($orderState);
        $comment = __("The customer was redirected for payment processing. The payment is pending.");
        $order->setState($orderState)->addStatusToHistory($orderStatus, $comment, false);

        $test = 2;
    }

    public function assignData(DataObject $data)
    {
        parent::assignData($data);

        $test = 1;

        return $this;
    }

    public function authorize(InfoInterface $payment, $amount)
    {
        $test = 1;
    }

    public function capture(InfoInterface $payment, $amount)
    {
        $test = 1;
    }

    public function sale(InfoInterface $payment, $amount)
    {
        $test = 1;
    }

    public function pay(InfoInterface $payment, $amount)
    {
        $test = 1;
    }
}
