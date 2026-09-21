<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\Cache;

use DateInterval;
use DateTimeImmutable;
use Magento\Framework\App\CacheInterface as MagentoCache;
use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 view of Magento's application cache, for the core's exchange-rate cache.
 *
 * The core caches only the exchange rate, and only so that a brief oracle outage does not
 * make the payment method vanish mid-checkout; it runs without a cache just as well. Magento
 * cache identifiers allow [A-Za-z0-9_] only, while PSR-16 keys carry dots, so the key is
 * mapped deterministically before it reaches the cache frontend.
 */
class RateCache implements CacheInterface
{
    public const CACHE_TAG = 'LEDGER_DIRECT';

    private const KEY_PREFIX = 'LEDGER_DIRECT_';

    /**
     * @var MagentoCache
     */
    private MagentoCache $cache;

    /**
     * @param MagentoCache $cache
     */
    public function __construct(MagentoCache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @inheritdoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->cache->load($this->identifier($key));

        if (!is_string($raw) || $raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    /**
     * @inheritdoc
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return (bool) $this->cache->save(
            json_encode($value, JSON_THROW_ON_ERROR),
            $this->identifier($key),
            [self::CACHE_TAG],
            $this->lifetime($ttl)
        );
    }

    /**
     * @inheritdoc
     */
    public function delete(string $key): bool
    {
        return (bool) $this->cache->remove($this->identifier($key));
    }

    /**
     * @inheritdoc
     */
    public function clear(): bool
    {
        return (bool) $this->cache->clean([self::CACHE_TAG]);
    }

    /**
     * @inheritdoc
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @inheritdoc
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            $success = $this->set((string) $key, $value, $ttl) && $success;
        }

        return $success;
    }

    /**
     * @inheritdoc
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }

        return $success;
    }

    /**
     * @inheritdoc
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Map a PSR-16 key onto a Magento cache identifier
     *
     * The readable part keeps the key recognisable in the cache backend; the hash keeps two
     * keys apart that differ only in characters the mapping folds - punctuation, or letter
     * case, which Magento's cache frontend upper-cases anyway while XRPL addresses in the
     * sync-throttle keys are case-sensitive.
     *
     * @param string $key
     * @return string
     */
    public function identifier(string $key): string
    {
        return self::KEY_PREFIX
            . strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '_', $key))
            . '_' . substr(hash('sha256', $key), 0, 8);
    }

    /**
     * Normalise a PSR-16 TTL to the lifetime in seconds Magento's cache expects
     *
     * @param null|int|DateInterval $ttl
     * @return int|null
     */
    private function lifetime(null|int|DateInterval $ttl): ?int
    {
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }
}
