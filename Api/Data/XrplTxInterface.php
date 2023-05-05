<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api\Data;

/**
 * XrplTx DTO interface
 * @api
 * @since 1.0.0
 */
interface XrplTxInterface
{
    public const ID = 'id';

    public const LEDGER_INDEX = 'ledger_index';

    public const HASH = 'hash';

    public const ACCOUNT_ADDRESS = 'account';

    public const DESTINATION_ADDRESS = 'destination';

    public const DESTINATION_TAG = 'destination_tag';

    public const DATE = 'date';

    public const META = 'meta';

    public const TX = 'tx';

    /**
     * Get ID
     *
     * @return int
     */
    public function getId();

    /**
     * Set ID
     *
     * @param int $id
     * @return $this
     */
    public function setId(int $id);

    /**
     * Get Ledger Index
     *
     * @return int
     */
    public function getLedgerIndex(): int;

    /**
     * Set Ledger Index
     *
     * @param int $ledgerIndex
     * @return $this
     */
    public function setLedgerIndex(int $ledgerIndex): self;

    /**
     * Get Transaction Hash
     *
     * @return string
     */
    public function getHash(): string;

    /**
     * Set Transaction Hash
     *
     * @param string $hash
     * @return $this
     */
    public function setHash(string $hash): self;

    /**
     * Get Account Address
     *
     * @return string
     */
    public function getAccountAddress(): string;

    /**
     * Set AccountAddress
     *
     * @param string $accountAddress
     * @return $this
     */
    public function setAccountAddress(string $accountAddress): self;

    /**
     * Get Destination Address
     *
     * @return string
     */
    public function getDestinationAddress(): string;

    /**
     * Set Destination Address
     *
     * @param string $destinationAddress
     * @return $this
     */
    public function setDestinationAddress(string $destinationAddress): self;

    /**
     * Get Destination Tag
     *
     * @return int|null
     */
    public function getDestinationTag(): int|null;

    /**
     * Set Destination Tag
     *
     * @param int $destinationTag
     * @return $this
     */
    public function setDestinationTag(int $destinationTag): self;

    /**
     * Get Date / Tx Timestamp
     *
     * @return int
     */
    public function getDate(): int;

    /**
     * Set Date / Tx Timestamp
     *
     * @param int $date
     * @return $this
     */
    public function setDate(int $date): self;

    /**
     * Get Metadata (json)
     *
     * @return string
     */
    public function getMeta(): string;

    /**
     * Set Metadata (json)
     *
     * @param string $meta
     * @return $this
     */
    public function setMeta(string $meta): self;

    /**
     * Get Transaction (Tx) Payload (json)
     *
     * @return string
     */
    public function getTx(): string;

    /**
     * Set Transaction (Tx) Payload (json)
     *
     * @param string $tx
     * @return $this
     */
    public function setTx(string $tx): self;
}
