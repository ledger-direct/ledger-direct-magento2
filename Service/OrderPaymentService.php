<?php

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use function Hardcastle\XRPL_PHP\Sugar\dropsToXrp;

class OrderPaymentService
{
    protected SystemConfig $configHelper;

    protected OrderRepositoryInterface $orderRepository;

    protected CryptoPriceProviderInterface $priceFinder;

    protected XrplTxService $xrplTxService;

    public function __construct(
        SystemConfig                 $configHelper,
        OrderRepositoryInterface     $orderRepository,
        CryptoPriceProviderInterface $priceFinder,
        XrplTxService                $xrplTxService
    )
    {
        $this->configHelper = $configHelper;
        $this->orderRepository = $orderRepository;
        $this->priceFinder = $priceFinder;
        $this->xrplTxService = $xrplTxService;
    }

    public function getOrderById(int $orderId): OrderInterface
    {
        return $this->orderRepository->get($orderId);
    }

    public function getOrderByOrderNumber(string $orderNumber): OrderInterface
    {
        // TODO: Refactor bad style
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        return $objectManager->get(\Magento\Sales\Api\Data\OrderInterface::class)->loadByIncrementId($orderNumber);
    }

    public function getCurrentPriceForOrder(OrderInterface $order): array
    {
        $xrpUnitPrice = $this->priceFinder->getCurrentExchangeRate($order->getOrderCurrencyCode());

        return [
            'pairing' => XrpPriceProvider::CRYPTO_CODE . '/' . $order->getOrderCurrencyCode(),
            'exchange_rate' => $xrpUnitPrice,
            'amount_requested' => $order->getTotalDue() / $xrpUnitPrice
        ];
    }

    public function prepareOrderPaymentForXrpl(OrderInterface $order): void
    {
        $payment = $order->getPayment();
        $paymentMethod = $payment->getMethod();
        $rawAdditionalData = $payment->getAdditionalData();
        if (!empty($rawAdditionalData)) {
            $additionalData = json_decode($rawAdditionalData, true);
            if (isset($additionalData['xrpl'])) {
                return;
            }
        }

        $network = $this->configHelper->isTest() ? 'Testnet' : 'Mainnet'; // TODO: Use NetworkId
        $destinationAccount = $this->configHelper->getDestinationAccount();
        $destinationTag = $this->xrplTxService->generateDestinationTag($destinationAccount);

        $xrplData = [
            'xrpl' => [
            'network' => $network,
            'destination_account' => $destinationAccount,
            'destination_tag' => $destinationTag
            ]
        ];

        $this->addAdditionalDataToPayment($order, $xrplData);

        match ($paymentMethod) {
            'xrp_payment' => $this->prepareXrpPayment($order),
            'xrpl_token_payment' => $this->prepareTokenPayment($order),
        };
    }

    private function prepareXrpPayment(OrderInterface $order): void
    {
        $additionalData = [
            'xrpl' => $this->getCurrentPriceForOrder($order)
        ];
        $additionalData['xrpl']['type'] = 'xrp_payment';

        $this->addAdditionalDataToPayment($order, $additionalData);
    }

    private function prepareTokenPayment(OrderInterface $order): void
    {
        $issuer = $this->configHelper->getTokenIssuer();
        $tokenName = $order->getOrderCurrencyCode();
        $additionalData = [
            'xrpl' => [
                'type' => 'xrpl_token_payment',
                'issuer' => $issuer,
                'currency' => $tokenName,
                'value' => $order->getTotalDue(),
            ]
        ];

        $this->addAdditionalDataToPayment($order, $additionalData);
    }

    public function syncOrderTransactionWithXrpl(OrderInterface $order): ?array
    {
        $customFields = $order->getPayment()->getAdditionalData();
        if (empty($customFields)) {
            return null;
        }

        $xrplPaymentData = json_decode($customFields, true)['xrpl'] ?? null;
        if (isset($xrplPaymentData['destination_account']) && isset($xrplPaymentData['destination_tag'])) {

            // TODO: Exception when orderTransaction.customFields are different form xrpl_tx

            $this->xrplTxService->syncAccountTransactions($xrplPaymentData['destination_account']);

            $tx = $this->xrplTxService->findTransaction(
                $xrplPaymentData['destination_account'],
                (int)$xrplPaymentData['destination_tag']
            );

            if ($tx) {
                $txMeta = json_decode($tx['meta'], true); // war: 'tx'
                $this->addAdditionalDataToPayment($order, [
                    'xrpl' => [
                        'hash' => $tx['hash'],
                        'ctid' => $tx['hash'], //TODO: Add CTID here
                        'amount_paid' => dropsToXrp($txMeta['delivered_amount'])
                    ]
                ]);

                return $tx;
            }
        }

        return null;
    }

    private function addAdditionalDataToPayment(OrderInterface $order, array $xrplAdditionalData): void
    {
        $rawAdditionalData = $order->getPayment()->getAdditionalData();
        if (!empty($rawAdditionalData)) {
            $additionalData = json_decode($rawAdditionalData, true);
        } else {
            $additionalData = [];
        }

        $mergedAdditionalData = array_replace_recursive($additionalData, $xrplAdditionalData);
        $order->getPayment()->setAdditionalData(json_encode($mergedAdditionalData));

        $this->orderRepository->save($order);
    }
}
