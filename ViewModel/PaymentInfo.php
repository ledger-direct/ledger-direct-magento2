<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\ViewModel;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class PaymentInfo implements ArgumentInterface
{
    private DataObject $internalDataObject;

    function __construct()
    {
        $this->internalDataObject = new DataObject([
            'orderId' => '1',
            'orderNumber' => '1000',
            'price' => '12.98',
            'currencyCode' => 'USD',
            'currencySymbol' => '$',
            'network' => 'testnet',
            'destinationAccount' => 'rnKcXxM3KBRiLLhPJmJwWH2qdccys8Vdk2',
            'destinationTag' => '10001',
            'xrpAmount' => '26.77',
            'exchangeRate' => '0.42',
            'returnUrl' => 'https://magento.test',
            'showNoTransactionFoundError' => true,
        ]);
    }
    public function getPaymentInfo(): DataObject
    {
        return $this->internalDataObject;
    }
}
