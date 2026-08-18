<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\API;

use Hardcastle\LedgerDirect\Api\CryptoPriceServiceInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class CryptoPriceService implements CryptoPriceServiceInterface
{
    /**
     * @var OrderRepositoryInterface
     */
    protected OrderRepositoryInterface $orderRepository;

    /**
     * @var SerializerInterface
     */
    protected SerializerInterface $serializer;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SerializerInterface $serializer
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SerializerInterface $serializer
    ) {
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
     * @inheritdoc
     */
    public function getExchangeRate(string $token, string $iso): string
    {
        throw new LocalizedException(__('getExchangeRate() is not implemented.'));
    }
}
