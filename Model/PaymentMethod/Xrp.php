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
use \Magento\Payment\Model\Method\Adapter;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;

class Xrp extends Adapter
{
    public function __construct(
        ManagerInterface $eventManager,
        ValueHandlerPoolInterface $valueHandlerPool,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        $code, $formBlockType,
        $infoBlockType,
        CommandPoolInterface $commandPool = null,
        ValidatorPoolInterface $validatorPool = null,
        CommandManagerInterface $commandExecutor = null,
        LoggerInterface $logger = null
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
            $commandExecutor,
            $logger
        );
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
