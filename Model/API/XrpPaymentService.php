<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\API;

use Exception;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterfaceFactory;
use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Intl\Currencies;

class XrpPaymentService implements XrpPaymentServiceInterface
{
    /**
     * @var SystemConfig
     */
    protected SystemConfig $configHelper;

    /**
     * @var OrderPaymentService
     */
    protected OrderPaymentService $orderPaymentService;

    /**
     * @var XrpPaymentInterfaceFactory
     */
    protected XrpPaymentInterfaceFactory $xrpPaymentFactory;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @param SystemConfig $configHelper
     * @param OrderPaymentService $orderPaymentService
     * @param XrpPaymentInterfaceFactory $xrpPaymentFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        SystemConfig $configHelper,
        OrderPaymentService $orderPaymentService,
        XrpPaymentInterfaceFactory $xrpPaymentFactory,
        LoggerInterface $logger
    ) {
        $this->configHelper = $configHelper;
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentFactory = $xrpPaymentFactory;
        $this->logger = $logger;
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
     * Build the XRP payment details data object for the given order
     *
     * @param OrderInterface $order
     * @return XrpPaymentInterface
     * @throws WebapiException
     */
    protected function getPaymentDetails(OrderInterface $order): XrpPaymentInterface
    {
        $this->orderPaymentService->prepareOrderPaymentForXrpl($order);
        $customFields = $order->getPayment()->getAdditionalData();
        $xrplPaymentData = json_decode($customFields, true)['xrpl'];

        $total = $order->getTotalDue();
        $currencyCode = $order->getOrderCurrencyCode();
        $currencySymbol = Currencies::getSymbol($currencyCode);
        $type = $xrplPaymentData['type'];
        $exchangeRate = $xrplPaymentData['exchange_rate'];
        $network = $xrplPaymentData['network'];
        $destinationAccount = $this->configHelper->getDestinationAccount();
        $destinationTag = $xrplPaymentData['destination_tag'];
        $txHash = $xrplPaymentData['hash'] ?? null;

        /** @var XrpPaymentInterface $xrpPaymentDetails */
        $xrpPaymentDetails = $this->xrpPaymentFactory->create();

        $xrpPaymentDetails
            ->setType($type)
            ->setOrderId((int) $order->getEntityId())
            ->setOrderNumber($order->getIncrementId())
            ->setCurrencyCode($currencyCode)
            ->setCurrencySymbol($currencySymbol)
            ->setPrice($total)
            ->setNetwork($network)
            ->setDestinationAccount($destinationAccount)
            ->setDestinationTag($destinationTag)
            ->setExchangeRate($exchangeRate)
            ->setTxHash($txHash);

        if ($type === 'xrp_payment') {
            $xrpPaymentDetails->setXrpAmount(round($total / $exchangeRate, 2));
        } else {
            // xrpl_rlusd_payment / xrpl_usdc_payment: amount_requested is the full
            // XRPL issued-currency amount object built by StablecoinRegistry.
            $amountRequested = $xrplPaymentData['amount_requested'];
            $xrpPaymentDetails
                ->setXrpAmount(0.0)
                ->setTokenAmount((string) $amountRequested['value'])
                ->setCurrency((string) $amountRequested['currency'])
                ->setIssuer((string) $amountRequested['issuer']);
        }

        return $xrpPaymentDetails;
    }
}
