<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\API;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterfaceFactory;
use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class XrpPaymentService implements XrpPaymentServiceInterface
{
    protected OrderRepositoryInterface $orderRepository;

    protected XrplTxService $xrplTxService;

    protected XrpPaymentInterfaceFactory $xrpPaymentFactory;


    protected SerializerInterface $serializer;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        XrpPaymentInterfaceFactory $xrpPaymentFactory,
        XrplTxService $xrplTxService,
        SerializerInterface $serializer
    ){
        $this->orderRepository = $orderRepository;
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
        $total = $order->getTotalDue();
        $currencyCode = $order->getOrderCurrencyCode();


        /** @var XrpPaymentInterface $xrpPayment*/
        $xrpPaymentDetails = $this->xrpPaymentFactory->create();

        $xrpPaymentDetails
            ->setOrderId($orderId)
            ->setOrderNumber('0000014')
            ->setCurrencyCode($currencyCode)
            ->setCurrencySymbol('$')
            ->setPrice($total)
            ->setNetwork('testnet')
            ->setDestinationAccount('rnKcXxM3KBRiLLhPJmJwWH2qdccys8Vdk2')
            ->setDestinationTag(10001)
            ->setXrpAmount(190.33)
            ->setExchangeRate(0.45);

        return $xrpPaymentDetails;
    }
}
