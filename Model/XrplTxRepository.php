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
        XrplTxFactory $xrplTxFactory,
        XrplTxResourceModel $xrplTxResourceModel
    ) {
        $this->xrplTxFactory = $xrplTxFactory;
        $this->xrplTxResourceModel = $xrplTxResourceModel;
    }

    public function getById(int $id): XrplTxInterface
    {
        $xrplTx = $this->xrplTxFactory->create();
        $this->xrplTxResourceModel->load($xrplTx, $id);

        if (!$xrplTx->getId()) {
            throw new NoSuchEntityException(__('The transaction with ID "%1" does not exist', $id));
        }

        return $xrplTx;
    }

    public function save(XrplTxInterface $xrplTx): XrplTxInterface
    {
        try {
            $this->xrplTxResourceModel->save($xrplTx);
        } catch (Exception $exception) {
            throw new CouldNotSaveException(__($exception->getMessage()));
        }

        return $xrplTx;
    }

    public function deleteById(int $id): bool
    {
        // TODO: Implement deleteById() method.
    }
}
