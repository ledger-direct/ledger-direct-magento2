<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\API;

use Exception;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterfaceFactory;
use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Magento\Sales\Api\Data\OrderInterface;
use Symfony\Component\Intl\Currencies;

class XrpPaymentService implements XrpPaymentServiceInterface
{
    /**
     * @var OrderPaymentService
     */
    protected OrderPaymentService $orderPaymentService;

    /**
     * @var XrpPaymentInterfaceFactory
     */
    protected XrpPaymentInterfaceFactory $xrpPaymentFactory;

    /**
     * @param OrderPaymentService $orderPaymentService
     * @param XrpPaymentInterfaceFactory $xrpPaymentFactory
     */
    public function __construct(
        OrderPaymentService $orderPaymentService,
        XrpPaymentInterfaceFactory $xrpPaymentFactory
    ) {
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentFactory = $xrpPaymentFactory;
    }

    /**
     * @inheritdoc
     *
     * @throws Exception
     */
    public function getPaymentDetailsByOrderId(int $orderId): XrpPaymentInterface
    {
        $order = $this->orderPaymentService->getOrderById($orderId);

        return $this->getPaymentDetails($order);
    }

    /**
     * @inheritdoc
     *
     * @throws Exception
     */
    public function getPaymentDetailsByOrderNumber(string $orderNumber): XrpPaymentInterface
    {
        $order = $this->orderPaymentService->getOrderByOrderNumber($orderNumber);

        return $this->getPaymentDetails($order);
    }

    /**
     * Build the payment details data object for the given order from its PaymentIntent
     *
     * @param OrderInterface $order
     * @return XrpPaymentInterface
     */
    protected function getPaymentDetails(OrderInterface $order): XrpPaymentInterface
    {
        $intent = $this->orderPaymentService->prepareOrderPaymentForXrpl($order);

        $total = (float) $order->getTotalDue();
        $currencyCode = (string) $order->getOrderCurrencyCode();

        /** @var XrpPaymentInterface $xrpPaymentDetails */
        $xrpPaymentDetails = $this->xrpPaymentFactory->create();

        $xrpPaymentDetails
            ->setType($intent->type)
            ->setOrderId((int) $order->getEntityId())
            ->setOrderNumber((string) $order->getIncrementId())
            ->setCurrencyCode($currencyCode)
            ->setCurrencySymbol(Currencies::getSymbol($currencyCode))
            ->setPrice($total)
            ->setNetwork($intent->network)
            ->setDestinationAccount($intent->destinationAccount)
            ->setDestinationTag($intent->destinationTag)
            ->setExchangeRate($intent->exchangeRate)
            ->setTxHash($intent->hash);

        if (is_array($intent->amountRequested)) {
            // Stablecoins carry the full XRPL issued-currency amount object.
            $xrpPaymentDetails
                ->setXrpAmount(0.0)
                ->setTokenAmount((string) $intent->amountRequested['value'])
                ->setCurrency((string) $intent->amountRequested['currency'])
                ->setIssuer((string) $intent->amountRequested['issuer']);
        } else {
            $xrpPaymentDetails->setXrpAmount((float) $intent->amountRequested);
        }

        return $xrpPaymentDetails;
    }
}
