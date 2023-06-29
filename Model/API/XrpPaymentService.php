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
    protected SystemConfig $configHelper;

    protected OrderPaymentService $orderPaymentService;

    protected XrpPaymentInterfaceFactory $xrpPaymentFactory;

    protected LoggerInterface $logger;

    public function __construct(
        SystemConfig $configHelper,
        OrderPaymentService $orderPaymentService,
        XrpPaymentInterfaceFactory $xrpPaymentFactory,
        LoggerInterface $logger
    ){
        $this->configHelper = $configHelper;
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentFactory = $xrpPaymentFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function getPaymentDetailsByOrderId(int $orderId): XrpPaymentInterface
    {
        $order = $this->orderPaymentService->getOrderById($orderId);

        return $this->getPaymentDetails($order);
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function getPaymentDetailsByOrderNumber(string $orderNumber): XrpPaymentInterface
    {
        $order = $this->orderPaymentService->getOrderByOrderNumber($orderNumber);

        return $this->getPaymentDetails($order);
    }

    protected function getPaymentDetails(OrderInterface $order): XrpPaymentInterface
    {
        if ($order->getPayment()->getMethod() !== 'xrp_payment') {
            throw new \Error('Endpoint is designed for XRP only');
        }

        $this->orderPaymentService->prepareOrderPaymentForXrpl($order);
        try {
            $xrplPaymentData = json_decode($order->getPayment()->getAdditionalData(), true)['xrpl'];
            $total = $order->getTotalDue();
            $currencyCode = $order->getOrderCurrencyCode();
            $currencySymbol = Currencies::getSymbol($currencyCode);
            $exchangeRate = $xrplPaymentData['exchange_rate'];
            $network = $xrplPaymentData['network'];
            $destinationAccount = $this->configHelper->getDestinationAccount();
            $destinationTag = $xrplPaymentData['destination_tag'];
            $xrpAmount = round($total/$exchangeRate,2); // TODO: Double check this
        } catch (Exception $exception) {
            $this->logger->critical($exception->getMessage());
            throw new WebapiException(__(''),400);
        }


        /** @var XrpPaymentInterface $xrpPayment*/
        $xrpPaymentDetails = $this->xrpPaymentFactory->create();

        $xrpPaymentDetails
            ->setOrderId((int) $order->getEntityId())
            ->setOrderNumber($order->getIncrementId())
            ->setCurrencyCode($currencyCode)
            ->setCurrencySymbol($currencySymbol)
            ->setPrice($total)
            ->setNetwork($network)
            ->setDestinationAccount($destinationAccount)
            ->setDestinationTag($destinationTag)
            ->setXrpAmount($xrpAmount)
            ->setExchangeRate($exchangeRate);

        return $xrpPaymentDetails;
    }
}
