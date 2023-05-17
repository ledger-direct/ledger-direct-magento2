<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\API;

use Hardcastle\LedgerDirect\Api\CryptoPriceServiceInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class CryptoPriceService implements CryptoPriceServiceInterface
{

    protected OrderRepositoryInterface $orderRepository;

    protected SerializerInterface $serializer;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SerializerInterface $serializer
    ){
        $this->orderRepository = $orderRepository;
        $this->serializer = $serializer;
    }

    /**
     * @inheritdoc
     */
    public function getPrice(int $orderId): DataObject
    {
        $order = $this->orderRepository->get($orderId);

        $paymentMethodName = $order->getPayment()->getMethod();
        $total = $order->getTotalDue();
        $currencyCode = $order->getOrderCurrencyCode();

        // $result = ['success' => false];

        // checks

        $result = [
            'success' => true,
            'total' => $total,
            'currencyCode' => $currencyCode,

        ];

        return $this->serializer->serialize($result);
    }

    /**
     * @param string $token
     * @param string $iso
     * @inheritdoc
     */
    public function getExchangeRate(string $token, string $iso): string
    {
        // TODO: Implement getQuote() method.
    }
}
