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
use function XRPL_PHP\Sugar\dropsToXrp;

class OrderPaymentService
{
    protected SystemConfig $configHelper;

    protected OrderRepositoryInterface $orderRepository;

    protected CryptoPriceProviderInterface $priceFinder;

    protected XrplTxService $xrplTxService;

    public function __construct(
        SystemConfig $configHelper,
        OrderRepositoryInterface $orderRepository,
        CryptoPriceProviderInterface $priceFinder,
        XrplTxService $xrplTxService
    ){
        $this->configHelper = $configHelper;
        $this->orderRepository = $orderRepository;
        $this->priceFinder = $priceFinder;
        $this->xrplTxService = $xrplTxService;
    }

    public function getOrder(int $orderId): OrderInterface
    {
        return $this->orderRepository->get($orderId);
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
            'type' => 'xrp-payment',
            'network' => $network,
            'destination_account' => $destinationAccount,
            'destination_tag' => $destinationTag
        ];
        $orderPriceCustomFields = $this->getCurrentPriceForOrder($order);

        $additionalData = [
            'xrpl' => array_merge($xrplData, $orderPriceCustomFields)
        ];

        $this->addAdditionalDataToPayment($order, $additionalData);
    }

    public function syncOrderTransactionWithXrpl(OrderInterface $order): ?array
    {
        $xrplPaymentData = json_decode($order->getPayment()->getAdditionalData(), true)['xrpl'];
        if (isset($xrplPaymentData['destination_account']) && isset($xrplPaymentData['destination_tag'])) {

            // TODO: Exception when orderTransaction.customFields are different form xrpl_tx

            $this->xrplTxService->syncAccountTransactions($xrplPaymentData['destination_account']);

            $tx = $this->xrplTxService->findTransaction(
                $xrplPaymentData['destination_account'],
                (int)$xrplPaymentData['destination_tag']
            );

            if ($tx) {
            $txPayload = json_decode($tx['tx'], true);
                $this->addAdditionalDataToPayment($order, [
                    'xrpl' => [
                        'hash' => $tx['hash'],
                        'ctid' => $tx['hash'], // TODO: Add CTID here
                        'amount_paid' => dropsToXrp($txPayload['Amount'])
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
