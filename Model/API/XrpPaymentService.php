<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\API;

use Exception;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterfaceFactory;
use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
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
     * @var SettlementPolicy
     */
    protected SettlementPolicy $settlementPolicy;

    /**
     * @param OrderPaymentService $orderPaymentService
     * @param XrpPaymentInterfaceFactory $xrpPaymentFactory
     * @param SettlementPolicy $settlementPolicy
     */
    public function __construct(
        OrderPaymentService $orderPaymentService,
        XrpPaymentInterfaceFactory $xrpPaymentFactory,
        SettlementPolicy $settlementPolicy
    ) {
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentFactory = $xrpPaymentFactory;
        $this->settlementPolicy = $settlementPolicy;
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

        if ($intent->amountPaid !== null) {
            $xrpPaymentDetails
                ->setAmountPaid($this->creditedAmount($intent))
                ->setAmountOutstanding($this->settlementPolicy->shortfall($intent));
        }

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

    /**
     * What of the delivered amount actually counts towards the request
     *
     * Derived from the core's shortfall rather than from amount_paid: a payment in a token other
     * than the requested one (same name, other issuer) delivers a value but credits nothing, and
     * the page must not present it as progress.
     *
     * @param PaymentIntent $intent a fulfilled intent
     * @return string
     */
    private function creditedAmount(PaymentIntent $intent): string
    {
        $shortfall = $this->settlementPolicy->shortfall($intent);

        if ($shortfall === null) {
            return (string) $intent->amountPaidValue();
        }

        return PaymentIntent::plainDecimal(
            BigDecimal::of($intent->amountRequestedValue())->minus(BigDecimal::of($shortfall))
        );
    }
}
