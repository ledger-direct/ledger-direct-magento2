<?php

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Payment;

class OrderPaymentService
{
    private const XRP_ASSET_CODE = 'XRP';

    /**
     * Fallback precision (decimal places) for the requested token amount when the configured
     * token isn't in TOKEN_DECIMALS. Matches the common stablecoin convention (e.g. USDC).
     */
    private const DEFAULT_TOKEN_DECIMALS = 6;

    /**
     * Decimal precision per known XRPL token, for formatting the requested payment amount.
     */
    private const TOKEN_DECIMALS = [
        'RLUSD' => 6,
        'USDC' => 6,
        'EURC' => 6,
    ];

    /**
     * @var SystemConfig
     */
    protected SystemConfig $configHelper;

    /**
     * @var OrderRepositoryInterface
     */
    protected OrderRepositoryInterface $orderRepository;

    /**
     * @var CryptoPriceProviderInterface
     */
    protected CryptoPriceProviderInterface $priceFinder;

    /**
     * @var XrplTxService
     */
    protected XrplTxService $xrplTxService;

    /**
     * @var OrderFactory
     */
    protected OrderFactory $orderFactory;

    /**
     * @param SystemConfig $configHelper
     * @param OrderRepositoryInterface $orderRepository
     * @param CryptoPriceProviderInterface $priceFinder
     * @param XrplTxService $xrplTxService
     * @param OrderFactory $orderFactory
     */
    public function __construct(
        SystemConfig                 $configHelper,
        OrderRepositoryInterface     $orderRepository,
        CryptoPriceProviderInterface $priceFinder,
        XrplTxService                $xrplTxService,
        OrderFactory                 $orderFactory
    ) {
        $this->configHelper = $configHelper;
        $this->orderRepository = $orderRepository;
        $this->priceFinder = $priceFinder;
        $this->xrplTxService = $xrplTxService;
        $this->orderFactory = $orderFactory;
    }

    /**
     * Get an order by its entity ID
     *
     * @param int $orderId
     * @return OrderInterface
     */
    public function getOrderById(int $orderId): OrderInterface
    {
        return $this->orderRepository->get($orderId);
    }

    /**
     * Get an order by its increment ID
     *
     * @param string $orderNumber
     * @return OrderInterface
     */
    public function getOrderByOrderNumber(string $orderNumber): OrderInterface
    {
        return $this->orderFactory->create()->loadByIncrementId($orderNumber);
    }

    /**
     * Get the current XRP price and requested amount for the order
     *
     * @param OrderInterface $order
     * @return array
     */
    public function getCurrentPriceForOrder(OrderInterface $order): array
    {
        $baseAsset = self::XRP_ASSET_CODE;
        $quoteCurrency = $order->getOrderCurrencyCode();
        $xrpUnitPrice = $this->priceFinder->getCurrentExchangeRate($baseAsset, $quoteCurrency);

        return [
            'base_asset' => $baseAsset,
            'quote_currency' => $quoteCurrency,
            'pairing' => $baseAsset . '/' . $quoteCurrency,
            'exchange_rate' => $xrpUnitPrice,
            'amount_requested' => $order->getTotalDue() / $xrpUnitPrice
        ];
    }

    /**
     * Assign the destination account/tag and payment-method-specific price data to the order's payment
     *
     * @param OrderInterface $order
     * @return void
     */
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

    /**
     * Assign XRP price data to the order's payment
     *
     * @param OrderInterface $order
     * @return void
     */
    private function prepareXrpPayment(OrderInterface $order): void
    {
        $additionalData = [
            'xrpl' => $this->getCurrentPriceForOrder($order)
        ];
        $additionalData['xrpl']['type'] = 'xrp_payment';

        $this->addAdditionalDataToPayment($order, $additionalData);
    }

    /**
     * Assign token payment data to the order's payment
     *
     * Queries the token's actual market price in the order's currency (rather than assuming
     * a 1:1 peg), so a de-pegged stablecoin or a store/token currency mismatch (e.g. a EUR
     * store with a USD-pegged token) is still converted correctly - same formula as the XRP
     * path: amount = order total / exchange rate.
     *
     * @param OrderInterface $order
     * @return void
     */
    private function prepareTokenPayment(OrderInterface $order): void
    {
        $issuer = $this->configHelper->getTokenIssuer();
        $baseAsset = $this->configHelper->getTokenName();
        $quoteCurrency = $order->getOrderCurrencyCode();
        $exchangeRate = $this->priceFinder->getCurrentExchangeRate($baseAsset, $quoteCurrency);

        $additionalData = [
            'xrpl' => [
                'type' => 'xrpl_token_payment',
                'issuer' => $issuer,
                'currency' => $baseAsset,
                'base_asset' => $baseAsset,
                'quote_currency' => $quoteCurrency,
                'pairing' => $baseAsset . '/' . $quoteCurrency,
                'exchange_rate' => $exchangeRate,
                'value' => $this->formatTokenAmount($baseAsset, $order->getTotalDue() / $exchangeRate),
            ]
        ];

        $this->addAdditionalDataToPayment($order, $additionalData);
    }

    /**
     * Format a requested token amount to that token's decimal precision
     *
     * @param string $tokenName
     * @param float $amount
     * @return string
     */
    private function formatTokenAmount(string $tokenName, float $amount): string
    {
        $decimals = self::TOKEN_DECIMALS[$tokenName] ?? self::DEFAULT_TOKEN_DECIMALS;

        return bcdiv((string) $amount, '1', $decimals);
    }

    /**
     * Sync XRPL account transactions and match the settling transaction to the order, if found
     *
     * @param OrderInterface $order
     * @return array|null
     */
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
                        'amount_paid' => $this->formatDeliveredAmount($txMeta['delivered_amount'] ?? null)
                    ]
                ]);

                return $tx;
            }
        }

        return null;
    }

    /**
     * Merge the given data into the order payment's additional_data under the "xrpl" key
     *
     * @param OrderInterface $order
     * @param array $xrplAdditionalData
     * @return void
     */
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

    /**
     * Normalises an XRPL "delivered_amount" to a human-readable decimal string
     *
     * Accepts either a drops string (XRP) or an issued currency object (stablecoins).
     *
     * @param mixed $deliveredAmount
     * @return string
     */
    private function formatDeliveredAmount(mixed $deliveredAmount): string
    {
        if (is_array($deliveredAmount)) {
            return (string) ($deliveredAmount['value'] ?? '0');
        }

        // XRP is delivered in drops (1 XRP = 1,000,000 drops).
        return bcdiv((string) $deliveredAmount, '1000000', 6);
    }
}
