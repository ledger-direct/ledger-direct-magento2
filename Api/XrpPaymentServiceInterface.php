<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;

interface XrpPaymentServiceInterface
{
    /**
     * Get crypto price for Order
     *
     * @api
     * @param int $orderId
     *
     * @return XrpPaymentInterface
     */
    public function getPaymentDetailsByOrderId(int $orderId): XrpPaymentInterface;

    /**
     * Get crypto price for Order
     *
     * @api
     * @param string $orderNumber
     *
     * @return XrpPaymentInterface
     */
    public function getPaymentDetailsByOrderNumber(string  $orderNumber): XrpPaymentInterface;

}
