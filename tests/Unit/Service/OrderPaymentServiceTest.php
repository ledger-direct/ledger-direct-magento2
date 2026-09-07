<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Port\MagentoConfigProvider;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Tests\Mock\Http\StubHttpClient;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Adapter-level tests: the core services are the real ones, only the edges the platform
 * owns are stubbed (HTTP, the transaction repository port, Magento's order repository).
 * What is asserted here is the adapter's job - that an order is quoted in the right asset
 * and that the resulting PaymentIntent is written to, and read back from, the order
 * payment's additional_data.
 */
class OrderPaymentServiceTest extends TestCase
{
    private const DESTINATION_ACCOUNT = 'rTestnetMerchant';

    /** Sequence 0 run through the core's fixed permutation. */
    private const FIRST_DESTINATION_TAG = 114729;

    /** @var XrplTransactionRepositoryInterface|MockObject */
    private $transactionRepository;

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepository;

    /** @var LoggerInterface|MockObject */
    private $logger;

    /** @var array<int, array<string, mixed>> every additional_data payload written, decoded */
    private array $writtenAdditionalData = [];

    protected function setUp(): void
    {
        $this->transactionRepository = $this->createMock(XrplTransactionRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->writtenAdditionalData = [];
    }

    public function testPrepareStoresASchemaV1QuoteOnTheOrderPayment(): void
    {
        $this->transactionRepository->expects($this->once())->method('nextDestinationTagSequence')->willReturn(0);
        $this->orderRepository->expects($this->once())->method('save');

        $order = $this->givenOrder('xrp_payment');

        $returned = $this->createService()->prepareOrderPaymentForXrpl($order);

        $intent = $this->writtenAdditionalData[0][OrderPaymentService::ADDITIONAL_DATA_KEY];

        // The stored record went through JSON, where a whole-number float may come back as an
        // int (serialize_precision) - PaymentIntent::fromArray() accepts both, so compare loosely.
        $this->assertEquals($returned->toArray(), $intent, 'the stored record is the one returned');
        $this->assertSame(PaymentIntent::SCHEMA_VERSION, $intent['schema_version']);
        $this->assertSame('schema_version', array_key_first($intent), 'the record describes itself first');
        $this->assertSame('xrp-payment', $intent['type']);
        $this->assertSame('XRPL', $intent['chain']);
        $this->assertSame('testnet', $intent['network']);
        $this->assertSame('XRP', $intent['base_asset']);
        $this->assertSame('EUR', $intent['quote_currency']);
        $this->assertSame('XRP/EUR', $intent['pairing']);
        $this->assertSame(2.5, $intent['exchange_rate']);
        $this->assertEquals(40.0, $intent['amount_requested']); // 100.00 EUR / 2.5
        $this->assertSame(self::DESTINATION_ACCOUNT, $intent['destination_account']);
        $this->assertSame(self::FIRST_DESTINATION_TAG, $intent['destination_tag']);
        $this->assertGreaterThan(time(), $intent['expiry']);
        $this->assertNull($intent['hash']);
        $this->assertNull($intent['amount_paid']);
    }

    /**
     * Stablecoins carry an XRPL issued-currency amount, not a bare number - the shape the
     * ledger needs to route the payment to the right issuer. A real (non-1:1) rate proves a
     * EUR store's amount is actually converted, not passed through as if pegged to EUR too.
     */
    public function testPrepareQuotesStablecoinsAsAnIssuedCurrencyAmount(): void
    {
        $this->transactionRepository->method('nextDestinationTagSequence')->willReturn(0);

        $this->createService()->prepareOrderPaymentForXrpl($this->givenOrder('xrpl_rlusd_payment'));

        $intent = $this->writtenAdditionalData[0][OrderPaymentService::ADDITIONAL_DATA_KEY];

        $this->assertSame('rlusd-payment', $intent['type']);
        $this->assertSame('RLUSD', $intent['base_asset']);
        $this->assertSame('RLUSD/EUR', $intent['pairing']);
        $this->assertSame([
            'currency' => '524C555344000000000000000000000000000000',
            'value' => '40.00',
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV', // testnet RLUSD issuer, from the core's registry
        ], $intent['amount_requested']);
    }

    /**
     * The payment page calls prepare on every view: a quote that is still valid must be handed
     * back untouched - no new price, no new tag, no write.
     */
    public function testPrepareKeepsAValidExistingQuote(): void
    {
        $stored = $this->givenStoredIntent(expiry: time() + 300);

        $this->transactionRepository->expects($this->never())->method('nextDestinationTagSequence');
        $this->orderRepository->expects($this->never())->method('save');

        $returned = $this->createService()->prepareOrderPaymentForXrpl($this->givenOrder('xrp_payment', $stored));

        $this->assertSame($stored->toArray(), $returned->toArray());
    }

    /**
     * Once the quote has expired the price is fetched again, but the customer keeps the
     * destination tag they may already be looking at, or have a payment in flight against.
     */
    public function testPrepareRequotesAnExpiredQuoteButKeepsTheDestinationTag(): void
    {
        $stored = $this->givenStoredIntent(expiry: time() - 1);

        $this->transactionRepository->expects($this->never())->method('nextDestinationTagSequence');
        $this->orderRepository->expects($this->once())->method('save');

        $this->createService()->prepareOrderPaymentForXrpl($this->givenOrder('xrp_payment', $stored));

        $intent = $this->writtenAdditionalData[0][OrderPaymentService::ADDITIONAL_DATA_KEY];

        $this->assertSame(4294967295, $intent['destination_tag']);
        $this->assertEquals(40.0, $intent['amount_requested'], 'the price is re-quoted (was 50.0 at 2.0)');
        $this->assertSame(2.5, $intent['exchange_rate']);
        $this->assertGreaterThan(time(), $intent['expiry']);
    }

    public function testPrepareNeverTouchesASettledPayment(): void
    {
        $stored = $this->givenStoredIntent(expiry: time() - 1)->withFulfillment('HASH', 50.0, 'CTID');

        $this->orderRepository->expects($this->never())->method('save');

        $returned = $this->createService()->prepareOrderPaymentForXrpl($this->givenOrder('xrp_payment', $stored));

        $this->assertSame('HASH', $returned->hash);
    }

    public function testPrepareRejectsAnUnknownPaymentMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported payment method: checkmo');

        $this->createService()->prepareOrderPaymentForXrpl($this->givenOrder('checkmo'));
    }

    /**
     * The port wiring end to end: switching a payment method off in the admin stops the core
     * from quoting in that asset.
     */
    public function testPrepareRefusesAnAssetWhosePaymentMethodIsDisabled(): void
    {
        $this->expectException(AssetNotAcceptedException::class);

        $this->createService(['payment/xrpl_usdc_payment/active' => 0])
            ->prepareOrderPaymentForXrpl($this->givenOrder('xrpl_usdc_payment'));
    }

    public function testSyncRecordsTheSettlementOnTheStoredQuote(): void
    {
        $this->transactionRepository->method('getLastSyncedLedgerIndex')
            ->with(self::DESTINATION_ACCOUNT, 'testnet')->willReturn(null);
        $this->transactionRepository->expects($this->once())
            ->method('findTransactions')
            ->with(self::DESTINATION_ACCOUNT, 4294967295)
            ->willReturn([$this->givenLedgerTransaction(['delivered_amount' => '40000000'])]);
        $this->orderRepository->expects($this->once())->method('save');

        $fulfilled = $this->createService()->syncOrderTransactionWithXrpl(
            $this->givenOrder('xrp_payment', $this->givenStoredIntent())
        );

        $this->assertNotNull($fulfilled);
        $this->assertSame('HASH', $fulfilled->hash);
        $this->assertSame('CTID', $fulfilled->ctid);
        // 40000000 drops is 40 XRP - the adapter no longer converts this itself.
        $this->assertSame(40.0, $fulfilled->amountPaid);

        $intent = $this->writtenAdditionalData[0][OrderPaymentService::ADDITIONAL_DATA_KEY];
        $this->assertSame('HASH', $intent['hash']);
        $this->assertSame('CTID', $intent['ctid']);
        $this->assertEquals(40.0, $intent['amount_paid']);
        $this->assertSame(4294967295, $intent['destination_tag'], 'the quote part survives fulfillment');
    }

    /**
     * Not every transaction carrying this destination tag delivered money - an EscrowCreate
     * to the same account has no delivered amount. The core skips it (since 0.3 that is its
     * decision, not the adapter's), and the order stays unpaid rather than settling on a null.
     */
    public function testSyncIgnoresATransactionThatDeliveredNothing(): void
    {
        $this->transactionRepository->method('getLastSyncedLedgerIndex')->willReturn(null);
        $this->transactionRepository->method('findTransactions')->willReturn([$this->givenLedgerTransaction([])]);
        $this->orderRepository->expects($this->never())->method('save');

        $this->assertNull($this->createService()->syncOrderTransactionWithXrpl(
            $this->givenOrder('xrp_payment', $this->givenStoredIntent())
        ));
    }

    /**
     * A destination tag can carry a stray payment in another asset class next to the real
     * one. Before core 0.3 whichever row surfaced first was handed to withFulfillment(),
     * which rejects the wrong shape and aborted the sync - the order stayed open forever with
     * its real payment unexamined. Now the core picks the newest candidate of the quoted
     * asset class, regardless of which one is newer overall.
     */
    public function testAStrayPaymentInAnotherAssetClassIsSkippedInFavourOfTheRealOne(): void
    {
        $rlusd = ['currency' => '524C555344000000000000000000000000000000', 'value' => '1.16', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'];
        $xrpTx = $this->givenLedgerTransaction(['delivered_amount' => '40000000'], hash: 'XRP_HASH', ledgerIndex: '1000');
        $rlusdTx = $this->givenLedgerTransaction(['delivered_amount' => $rlusd], hash: 'RLUSD_HASH', ledgerIndex: '2000');

        foreach ([[$rlusdTx, $xrpTx], [$xrpTx, $rlusdTx]] as $newestFirst) {
            $this->setUp();
            $this->transactionRepository->method('getLastSyncedLedgerIndex')->willReturn(null);
            $this->transactionRepository->method('findTransactions')->willReturn($newestFirst);

            $fulfilled = $this->createService()->syncOrderTransactionWithXrpl(
                $this->givenOrder('xrp_payment', $this->givenStoredIntent())
            );

            $this->assertSame('XRP_HASH', $fulfilled?->hash);
            $this->assertSame(40.0, $fulfilled?->amountPaid);
        }
    }

    /**
     * A token with the right name from another issuer is a real payment attempt: the core hands
     * it back (right asset class), SettlementPolicy declares it non-settling with the whole
     * amount still due, and XrpPaymentService turns that into the wrong-token notice - rather
     * than skipping it and showing the customer nothing.
     */
    public function testAPaymentFromTheWrongIssuerIsReturnedButNeverSettles(): void
    {
        $requested = ['currency' => '524C555344000000000000000000000000000000', 'value' => '1.16', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'];
        $wrongIssuer = ['currency' => '524C555344000000000000000000000000000000', 'value' => '1.16', 'issuer' => 'rSomebodyElse'];
        $intent = PaymentIntent::quote('rlusd-payment', 'XRPL', 'testnet', 'RLUSD', 'USD', 'RLUSD/USD', 1.0, $requested, self::DESTINATION_ACCOUNT, 4294967295, time() + 300);

        $this->transactionRepository->method('getLastSyncedLedgerIndex')->willReturn(null);
        $this->transactionRepository->method('findTransactions')
            ->willReturn([$this->givenLedgerTransaction(['delivered_amount' => $wrongIssuer])]);

        $fulfilled = $this->createService()->syncOrderTransactionWithXrpl($this->givenOrder('xrpl_rlusd_payment', $intent));

        $this->assertNotNull($fulfilled, 'a genuine payment attempt reaches the order');
        $policy = new SettlementPolicy();
        $this->assertFalse($policy->isSettled($fulfilled));
        $this->assertSame('1.16', $policy->shortfall($fulfilled), 'nothing is credited');
    }

    public function testSyncWithoutAStoredQuoteReturnsNull(): void
    {
        $this->transactionRepository->expects($this->never())->method('findTransactions');
        $this->orderRepository->expects($this->never())->method('save');

        $this->assertNull($this->createService()->syncOrderTransactionWithXrpl($this->givenOrder('xrp_payment')));
    }

    public function testSyncReturnsAnAlreadySettledPaymentWithoutTouchingTheLedger(): void
    {
        $stored = $this->givenStoredIntent()->withFulfillment('HASH', 50.0, 'CTID');
        $httpClient = new StubHttpClient();

        $this->transactionRepository->expects($this->never())->method('findTransactions');

        $fulfilled = $this->createService(httpClient: $httpClient)
            ->syncOrderTransactionWithXrpl($this->givenOrder('xrp_payment', $stored));

        $this->assertSame('HASH', $fulfilled->hash);
        $this->assertSame([], $httpClient->requestedUris);
    }

    /**
     * The node being unreachable must not take the payment page down with it: the order
     * simply stays pending, and the failure is logged.
     */
    public function testSyncKeepsTheOrderPendingWhenTheNodeIsUnreachable(): void
    {
        $this->transactionRepository->method('getLastSyncedLedgerIndex')->willReturn(null);
        $this->transactionRepository->expects($this->never())->method('findTransactions');
        $this->orderRepository->expects($this->never())->method('save');
        $this->logger->expects($this->once())->method('warning');

        $result = $this->createService(httpClient: new StubHttpClient(2.5, [], 503))
            ->syncOrderTransactionWithXrpl($this->givenOrder('xrp_payment', $this->givenStoredIntent()));

        $this->assertNull($result);
    }

    /**
     * A record that is not a readable schema v1 intent is a real problem, not an unpaid order.
     */
    public function testReadingAnUnversionedRecordFails(): void
    {
        $order = $this->givenOrder('xrp_payment', null, ['destination_account' => 'rAddr', 'destination_tag' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema_version');

        $this->createService()->readPaymentIntent($order);
    }

    /**
     * @param array<string, mixed> $configOverrides config path => value
     */
    private function createService(array $configOverrides = [], ?StubHttpClient $httpClient = null): OrderPaymentService
    {
        $httpClient ??= new StubHttpClient(2.5);
        $httpFactory = new HttpFactory();

        $config = $configOverrides + [
            'payment/ledger_direct/use_testnet' => 1,
            'payment/ledger_direct/xrpl_testnet_account' => self::DESTINATION_ACCOUNT,
            'payment/ledger_direct/xrpl_mainnet_account' => 'rMainnetMerchant',
            'payment/ledger_direct/quote_expiry' => 300,
            'payment/xrp_payment/active' => 1,
            'payment/xrpl_rlusd_payment/active' => 1,
            'payment/xrpl_usdc_payment/active' => 1,
        ];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => isset($config[$path]) ? (string) $config[$path] : null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn (string $path) => (bool) ($config[$path] ?? false));
        $context = $this->createMock(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);
        $configProvider = new MagentoConfigProvider(new SystemConfig($context));

        $paymentIntentService = new PaymentIntentService(
            new PriceService($httpClient, $httpFactory, new NullLogger()),
            new DestinationTagService($this->transactionRepository),
            $configProvider
        );

        $syncService = new SyncService(
            new XrplClient($httpClient, $httpFactory, $httpFactory),
            $this->transactionRepository,
            new NullLogger()
        );

        return new OrderPaymentService(
            $this->orderRepository,
            $this->createMock(OrderFactory::class),
            $paymentIntentService,
            $syncService,
            $this->logger
        );
    }

    private function givenStoredIntent(?int $expiry = null): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.0,
            amountRequested: 50.0,
            destinationAccount: self::DESTINATION_ACCOUNT,
            // Deliberately at the top of XRPL's unsigned 32-bit tag range: the core issues
            // tags there, so the adapter must round-trip them.
            destinationTag: 4294967295,
            expiry: $expiry ?? time() + 300,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function givenLedgerTransaction(array $meta, string $hash = 'HASH', string $ledgerIndex = '1000'): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet',
            ledgerIndex: $ledgerIndex,
            hash: $hash,
            ctid: 'CTID',
            account: 'rSenderAccount',
            destination: self::DESTINATION_ACCOUNT,
            destinationTag: 4294967295,
            date: 0,
            meta: $meta,
            tx: [],
        );
    }

    /**
     * @param array<string, mixed>|null $rawXrplData written verbatim under the storage key, bypassing PaymentIntent
     */
    private function givenOrder(string $paymentMethod, ?PaymentIntent $storedIntent = null, ?array $rawXrplData = null): OrderInterface
    {
        $additionalData = [];
        if ($storedIntent !== null) {
            $additionalData[OrderPaymentService::ADDITIONAL_DATA_KEY] = $storedIntent->toArray();
        }
        if ($rawXrplData !== null) {
            $additionalData[OrderPaymentService::ADDITIONAL_DATA_KEY] = $rawXrplData;
        }

        /** @var OrderPaymentInterface|MockObject $payment */
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);
        $payment->method('getAdditionalData')->willReturn($additionalData === [] ? '' : json_encode($additionalData));
        $payment->method('setAdditionalData')->willReturnCallback(function (string $json) use ($payment) {
            $this->writtenAdditionalData[] = json_decode($json, true);

            return $payment;
        });

        /** @var OrderInterface|MockObject $order */
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getTotalDue')->willReturn(100.0);
        $order->method('getIncrementId')->willReturn('100000042');

        return $order;
    }
}
