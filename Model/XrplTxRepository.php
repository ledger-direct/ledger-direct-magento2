<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model;

use Exception;
use Hardcastle\LedgerDirect\Api\Data\XrplTxInterface;
use Hardcastle\LedgerDirect\Api\XrplTxRepositoryInterface;
use Hardcastle\LedgerDirect\Model\ResourceModel\XrplTx as XrplTxResourceModel;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class XrplTxRepository implements XrplTxRepositoryInterface
{
    private XrplTxFactory $xrplTxFactory;

    private XrplTxResourceModel $xrplTxResourceModel;

    public function __construct(
        XrplTxFactory       $xrplTxFactory,
        XrplTxResourceModel $xrplTxResourceModel
    )
    {
        $this->xrplTxFactory = $xrplTxFactory;
        $this->xrplTxResourceModel = $xrplTxResourceModel;
    }

    /**
     * @inheritdoc
     */
    public function getById(int $id): XrplTxInterface
    {
        $xrplTx = $this->xrplTxFactory->create();
        $this->xrplTxResourceModel->load($xrplTx, $id);

        if (!$xrplTx->getId()) {
            throw new NoSuchEntityException(__('The transaction with ID "%1" does not exist', $id));
        }

        return $xrplTx;
    }

    /**
     * @inheritdoc
     */
    public function getLastLedgerIndex(string $accountAddress): ?int
    {
        $connection = $this->xrplTxResourceModel->getConnection();
        $select = $connection
            ->select()
            ->from($this->xrplTxResourceModel->getMainTable(), ['last_ledger_index' => new \Zend_Db_Expr('MAX(ledger_index)')])
            ->where('account = ?', $accountAddress);

        $lastLedgerIndex = (int)$connection->fetchOne($select);

        return $lastLedgerIndex === 0 ? null : $lastLedgerIndex;

    }

    public function createFromArray(array $rawTx): XrplTxInterface
    {
        /** @var XrplTxInterface $xrplTx */
        $xrplTx = $this->xrplTxFactory->create();

        $meta = $rawTx['meta'];
        $tx = $rawTx['tx'];

        $xrplTx->setLedgerIndex($tx['ledger_index'])
            ->setHash($tx['hash'])
            ->setAccountAddress($tx['Account'])
            ->setDestinationAddress($tx['Destination'] ?? null)
            ->setDestinationTag($tx['DestinationTag'] ?? null)
            ->setDate($tx['date'])
            ->setMeta(json_encode($meta))
            ->setTx(json_encode($tx));

        //TODO: Add ctid, see XLS-37d

        return $xrplTx;
    }

    /**
     * @inheritdoc
     */
    public function save(XrplTxInterface $xrplTx): XrplTxInterface
    {
        try {
            $this->xrplTxResourceModel->save($xrplTx);
        } catch (Exception $exception) {
            throw new CouldNotSaveException(__($exception->getMessage()));
        }

        return $xrplTx;
    }

    /**
     * @inheritdoc
     */
    public function deleteById(int $id): bool
    {
        // TODO: Implement deleteById() method.
    }
}
