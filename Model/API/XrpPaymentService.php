<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\API;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterfaceFactory;
use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Magento\Sales\Api\Data\OrderInterface;
use Symfony\Component\Intl\Currencies;

/**
 * Turns an order's PaymentIntent into the data object the payment page renders.
 *
 * Every amount here is the core's plain decimal; nothing is rounded or formatted a second
 * time in the view. Whether a delivered payment counts, and why not, is the core's
 * SettlementPolicy - the page only shows the answer.
 */
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
     */
    public function getPaymentDetails(OrderInterface $order): XrpPaymentInterface
    {
        // Quotes an order that has no intent yet; a stored one is handed back as it is, even
        // expired - the page shows that, and only the refresh action re-quotes.
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
            ->setAmountRequested($intent->amountRequestedValue())
            ->setTxHash($intent->hash)
            ->setWrongAsset($this->settlementPolicy->isWrongAsset($intent));

        if ($intent->amountPaid !== null) {
            // The delivered value, so the page can name what actually arrived - in the
            // wrong-asset case that is the other token, and wrongAsset says it counts for nothing.
            $xrpPaymentDetails
                ->setAmountPaid($intent->amountPaidValue())
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
}
