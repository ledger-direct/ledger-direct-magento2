<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model;

/**
 * Where a transaction hash can be looked up, per chain and network
 *
 * The core knows no explorers; this is the module's mapping, the same one the Shopware plugin
 * carries. The payment page and the admin's Payment Information build their links from it.
 */
class Explorer
{
    private const TRANSACTIONS = [
        'XRPL' => [
            'mainnet' => 'https://livenet.xrpl.org/transactions/',
            'testnet' => 'https://testnet.xrpl.org/transactions/',
        ],
        'XAHAU' => [
            'mainnet' => 'https://explorer.xahau.network/tx/',
            'testnet' => 'https://explorer.xahau-test.net/tx/',
        ],
    ];

    /**
     * The URL prefix a hash is appended to; '' for a chain or network without an explorer here
     *
     * @param string $chain
     * @param string $network
     * @return string
     */
    public function transactionBase(string $chain, string $network): string
    {
        return self::TRANSACTIONS[$chain][$network] ?? '';
    }

    /**
     * The explorer link of a transaction, or null without a hash or an explorer
     *
     * @param string $chain
     * @param string $network
     * @param string|null $hash
     * @return string|null
     */
    public function transactionUrl(string $chain, string $network, ?string $hash): ?string
    {
        $base = $this->transactionBase($chain, $network);
        return $base === '' || $hash === null || $hash === '' ? null : $base . $hash;
    }
}
