<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\API;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterfaceFactory;
use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Symfony\Component\Intl\Currencies;

class XrpPaymentService implements XrpPaymentServiceInterface
{
    protected SystemConfig $configHelper;

    protected OrderPaymentService $orderPaymentService;

    protected XrpPaymentInterfaceFactory $xrpPaymentFactory;


    protected SerializerInterface $serializer;

    public function __construct(
        SystemConfig $configHelper,
        OrderPaymentService $orderPaymentService,
        XrpPaymentInterfaceFactory $xrpPaymentFactory,
        SerializerInterface $serializer
    ){
        $this->configHelper = $configHelper;
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentFactory = $xrpPaymentFactory;
        $this->serializer = $serializer;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentDetails(int $orderId): XrpPaymentInterface
    {
        $order = $this->orderPaymentService->getOrder($orderId);

        if ($order->getPayment()->getMethod() !== 'xrp_payment') {
            throw new \Error('Endpoint is designed for XRP only');
        }

        $this->orderPaymentService->prepareOrderPaymentForXrpl($order);
        $xrplPaymentData = json_decode($order->getPayment()->getAdditionalData(), true)['xrpl'];

        $total = $order->getTotalDue();
        $currencyCode = $order->getOrderCurrencyCode();
        $currencySymbol = Currencies::getSymbol($currencyCode);
        $exchangeRate = $xrplPaymentData['exchange_rate'];
        $network = $xrplPaymentData['network'];
        $destinationAccount = $this->configHelper->getDestinationAccount();
        $destinationTag = $xrplPaymentData['destination_tag'];
        $xrpAmount = round($total/$exchangeRate,2); // TODO: Double check this


        /** @var XrpPaymentInterface $xrpPayment*/
        $xrpPaymentDetails = $this->xrpPaymentFactory->create();

        $xrpPaymentDetails
            ->setOrderId($orderId)
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
