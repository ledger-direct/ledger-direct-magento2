<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Observer;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Model\ChainServices;
use Hardcastle\LedgerDirect\Port\MagentoConfigProvider;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps a LedgerDirect payment method out of the checkout while the receiving account cannot
 * take its asset
 *
 * The core's ReceivingAccountCheck::offers() (INVARIANTS.md, "Receiving account"): no trustline,
 * a frozen or full one, and so on. On the testnet a shop offered RLUSD to an account without a
 * trustline; the order existed, and the customer's payment failed with tecPATH_DRY. The amount is
 * not known when the methods are listed: an unactivated account keeps the native asset, and the
 * configuration warns. Methods that are not ours, and methods Magento already took out, pass.
 */
class ReceivingAccountAvailability implements ObserverInterface
{
    /**
     * Each LedgerDirect method's chain and asset, as the core's catalogue names them
     */
    public const METHODS = [
        'xrp_payment' => ['XRPL', 'XRP'],
        'xrpl_rlusd_payment' => ['XRPL', 'RLUSD'],
        'xrpl_usdc_payment' => ['XRPL', 'USDC'],
        'xahau_xah_payment' => ['XAHAU', 'XAH'],
    ];

    /**
     * @var ChainServices
     */
    private ChainServices $chains;

    /**
     * @var MagentoConfigProvider
     */
    private MagentoConfigProvider $config;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ChainServices $chains
     * @param MagentoConfigProvider $config
     * @param LoggerInterface $logger
     */
    public function __construct(ChainServices $chains, MagentoConfigProvider $config, LoggerInterface $logger)
    {
        $this->chains = $chains;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Takes the method out when the receiving account cannot take its asset
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $code = (string) $event->getMethodInstance()->getCode();
        $result = $event->getResult();

        if (!isset(self::METHODS[$code]) || !$result->getData('is_available')) {
            return;
        }

        [$chain, $asset] = self::METHODS[$code];

        try {
            $offered = $this->chains->accountCheck($chain)->offers(
                $this->config->getDestinationAccount($chain),
                $asset,
                $this->config->getNetwork($chain)
            );
        } catch (Throwable $exception) {
            // offers() does not throw for a node it cannot reach; anything else is a bug. Same
            // rule as for an unreachable node: tokens out, the native asset stays.
            $this->logger->error('LedgerDirect: checking the receiving account failed', [
                'method' => $code,
                'exception' => $exception->getMessage(),
            ]);
            $offered = ChainCatalog::chain($chain)->nativeAsset === $asset;
        }

        if (!$offered) {
            $result->setData('is_available', false);
        }
    }
}
