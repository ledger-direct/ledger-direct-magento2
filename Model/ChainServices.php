<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use InvalidArgumentException;

/**
 * The core's services, one set per chain
 *
 * Xahau speaks the XRP Ledger's protocol on a network of its own, so the core serves it with the
 * XRPL classes built for the chain: another node, another transaction table, another tag
 * counter, another throttle mark. etc/di.xml builds both sets and hands them in here by chain;
 * the services that act on an order pick the set by the intent's chain.
 */
class ChainServices
{
    /**
     * @var array<string, XrplClient>
     */
    private array $clients;

    /**
     * @var array<string, SyncService>
     */
    private array $syncServices;

    /**
     * @var array<string, SyncThrottle>
     */
    private array $throttles;

    /**
     * @var array<string, PaymentIntentService>
     */
    private array $intentServices;

    /**
     * @param XrplClient[] $clients by chain
     * @param SyncService[] $syncServices by chain
     * @param SyncThrottle[] $throttles by chain
     * @param PaymentIntentService[] $intentServices by chain
     */
    public function __construct(
        array $clients,
        array $syncServices,
        array $throttles,
        array $intentServices
    ) {
        $this->clients = $clients;
        $this->syncServices = $syncServices;
        $this->throttles = $throttles;
        $this->intentServices = $intentServices;
    }

    /**
     * The chains a service set exists for
     *
     * @return string[]
     */
    public function chains(): array
    {
        return array_keys($this->intentServices);
    }

    /**
     * The node client of a chain
     *
     * @param string $chain
     * @return XrplClient
     */
    public function client(string $chain): XrplClient
    {
        return $this->clients[$chain] ?? $this->unknown($chain);
    }

    /**
     * The ledger sync of a chain
     *
     * @param string $chain
     * @return SyncService
     */
    public function sync(string $chain): SyncService
    {
        return $this->syncServices[$chain] ?? $this->unknown($chain);
    }

    /**
     * The sync throttle of a chain
     *
     * @param string $chain
     * @return SyncThrottle
     */
    public function throttle(string $chain): SyncThrottle
    {
        return $this->throttles[$chain] ?? $this->unknown($chain);
    }

    /**
     * The quoting service of a chain
     *
     * @param string $chain
     * @return PaymentIntentService
     */
    public function intents(string $chain): PaymentIntentService
    {
        return $this->intentServices[$chain] ?? $this->unknown($chain);
    }

    /**
     * A chain without a service set is a wiring mistake, not a configuration state
     *
     * @param string $chain
     * @return never
     */
    private function unknown(string $chain): never
    {
        throw new InvalidArgumentException(sprintf('LedgerDirect has no services for chain "%s".', $chain));
    }
}
