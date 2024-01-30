<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api;

use Hardcastle\LedgerDirect\Api\Data\XrplTxInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * XrplTx CRUD interface
 * @api
 * @since 1.0.0
 */
interface XrplTxRepositoryInterface
{
    /**
     * Fetch a tx
     *
     * @param int $id
     * @return XrplTxInterface
     * @throws LocalizedException
     */
    public function getById(int $id): XrplTxInterface;

    /**
     * Fetch latest ledger_index for given account from DB
     *
     * @param string $accountAddress
     * @return ?int
     */
    public function getLastLedgerIndex(string $accountAddress): ?int;

    /**
     * Create XrplTx object from rawTx value
     *
     * @param array $rawTx
     * @return XrplTxInterface
     */
    public function createFromArray(array $rawTx): XrplTxInterface;

    /**
     * Persist a tx
     *
     * @param XrplTxInterface $xrplTx
     * @return XrplTxInterface
     * @throws LocalizedException
     */
    public function save(XrplTxInterface $xrplTx): XrplTxInterface;

    /**
     * Deletes a tx
     *
     * @param int $id
     * @return bool
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function deleteById(int $id): bool;
}
