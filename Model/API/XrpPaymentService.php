<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\API;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterfaceFactory;
use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Symfony\Component\Intl\Currencies;

class XrpPaymentService implements XrpPaymentServiceInterface
{
    protected SystemConfig $configHelper;

    protected OrderRepositoryInterface $orderRepository;

    protected CryptoPriceProviderInterface $priceFinder;

    protected XrplTxService $xrplTxService;

    protected XrpPaymentInterfaceFactory $xrpPaymentFactory;


    protected SerializerInterface $serializer;

    public function __construct(
        SystemConfig $configHelper,
        OrderRepositoryInterface $orderRepository,
        XrpPaymentInterfaceFactory $xrpPaymentFactory,
        CryptoPriceProviderInterface $priceFinder,
        XrplTxService $xrplTxService,
        SerializerInterface $serializer
    ){
        $this->configHelper = $configHelper;
        $this->orderRepository = $orderRepository;
        $this->priceFinder = $priceFinder;
        $this->xrplTxService = $xrplTxService;
        $this->xrpPaymentFactory = $xrpPaymentFactory;
        $this->serializer = $serializer;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentDetails(int $orderId): XrpPaymentInterface
    {
        $order = $this->orderRepository->get($orderId);

        $paymentMethodName = $order->getPayment()->getMethod();
        if($paymentMethodName !== 'xrp_payment') {
            throw new \Error('Endpoint is designed for XRP only');
        }

        $total = $order->getTotalDue();
        $currencyCode = $order->getOrderCurrencyCode();
        $currencySymbol = Currencies::getSymbol($currencyCode);
        $exchangeRate = $this->priceFinder->getCurrentExchangeRate($currencyCode);
        $network = $this->configHelper->isTest() ? 'testnet' : 'mainnet';
        $destinationAccount = $this->configHelper->getReceiverAccountAddress();
        $destinationTag = $this->xrplTxService->generateDestinationTag($destinationAccount);


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
            ->setXrpAmount($total/$exchangeRate) // TODO: Double check this
            ->setExchangeRate($exchangeRate);

        return $xrpPaymentDetails;
    }

    private function getCurrentPriceForOrder(OrderInterface $order): array
    {
        // TODO: Build equivalent of "OrderTransactionService" in Shopware6

        $xrpUnitPrice = $this->priceFinder->getCurrentExchangeRate($order->getOrderCurrencyCode());

        return [
            'pairing' => XrpPriceProvider::CRYPTO_CODE . '/' . $order->getOrderCurrencyCode(),
            'exchange_rate' => $xrpUnitPrice,
            'amount_requested' => $order->getTotalDue() / $xrpUnitPrice
        ];
    }
}
