<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\PaymentMethod;

use \Magento\Payment\Model\Method\Adapter as MagentoPaymentMethodAdapter;
use Magento\Quote\Api\Data\CartInterface;

class Xrp extends MagentoPaymentMethodAdapter
{
    public const METHOD_CODE = 'xrp_payment';

    protected string $_code = self::METHOD_CODE;

    /**
     * @inheritdoc
     */
    public function isAvailable(CartInterface $quote = null)
    {
        return true;
    }

    public function isGateway()
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function canUseCheckout()
    {
        return true;
    }
}
