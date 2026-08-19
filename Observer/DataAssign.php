<?php

namespace Hardcastle\LedgerDirect\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Observer\AbstractDataAssignObserver;

class DataAssign extends AbstractDataAssignObserver
{
    /**
     * Copy the transaction_result data key into the payment's additional information
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $method = $this->readMethodArgument($observer);
        $data = $this->readDataArgument($observer);

        $paymentInfo = $method->getInfoInstance();

        if ($data->getDataByKey('transaction_result') !== null) {
            $paymentInfo->setAdditionalInformation(
                'transaction_result',
                $data->getDataByKey('transaction_result')
            );
        }
    }
}
