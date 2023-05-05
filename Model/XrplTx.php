<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model;

use Hardcastle\LedgerDirect\Api\Data\XrplTxInterface;
use Magento\Framework\Model\AbstractModel;

class XrplTx extends AbstractModel implements XrplTxInterface
{
    protected function _construct()
    {
        $this->_init(ResourceModel\XrplTx::class);
    }

    /**
     * @inheritDoc
     */
    public function getLedgerIndex(): int
    {
        return $this->getData(self::LEDGER_INDEX);
    }

    /**
     * @inheritDoc
     */
    public function setLedgerIndex(int $ledgerIndex): self
    {
        return $this->setData(self::LEDGER_INDEX, $ledgerIndex);
    }

    /**
     * @inheritDoc
     */
    public function getHash(): string
    {
        return $this->getData(self::HASH);
    }

    /**
     * @inheritDoc
     */
    public function setHash(string $hash): self
    {
        return $this->setData(self::HASH, $hash);
    }

    /**
     * @inheritDoc
     */
    public function getAccountAddress(): string
    {
        return $this->getData(self::ACCOUNT_ADDRESS);
    }

    /**
     * @inheritDoc
     */
    public function setAccountAddress(string $accountAddress): self
    {
        return $this->setData(self::ACCOUNT_ADDRESS, $accountAddress);
    }

    /**
     * @inheritDoc
     */
    public function getDestinationAddress(): string
    {
        return $this->getData(self::DESTINATION_ADDRESS);
    }

    /**
     * @inheritDoc
     */
    public function setDestinationAddress(string $destinationAddress): self
    {
        return $this->setData(self::DESTINATION_ADDRESS, $destinationAddress);
    }

    /**
     * @inheritDoc
     */
    public function getDestinationTag(): int|null
    {
        return $this->getData(self::DESTINATION_TAG);
    }

    /**
     * @inheritDoc
     */
    public function setDestinationTag(int $destinationTag): self
    {
        return $this->setData(self::DESTINATION_TAG, $destinationTag);
    }

    /**
     * @inheritDoc
     */
    public function getDate(): int
    {
        return $this->getData(self::DATE);
    }

    /**
     * @inheritDoc
     */
    public function setDate(int $date): self
    {
        return $this->setData(self::DATE, $date);
    }

    /**
     * @inheritDoc
     */
    public function getMeta(): string
    {
        return $this->getData(self::META);
    }

    /**
     * @inheritDoc
     */
    public function setMeta(string $meta): self
    {
        return $this->setData(self::META, $meta);
    }

    /**
     * @inheritDoc
     */
    public function getTx(): string
    {
        return $this->getData(self::TX);
    }

    /**
     * @inheritDoc
     */
    public function setTx(string $tx): self
    {
        return $this->setData(self::TX, $tx);
    }
}
