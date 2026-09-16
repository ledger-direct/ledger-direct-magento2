<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Magento\Sales\Api\Data\OrderInterface;

interface XrpPaymentServiceInterface
{
    /**
     * The payment details the payment page renders for an order, from its stored PaymentIntent
     *
     * @param OrderInterface $order
     * @return XrpPaymentInterface
     */
    public function getPaymentDetails(OrderInterface $order): XrpPaymentInterface;
}
