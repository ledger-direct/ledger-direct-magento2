<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\ResourceModel\XrplTx;

use Hardcastle\LedgerDirect\Model\XrplTx as XrplTxModel;
use Hardcastle\LedgerDirect\Model\ResourceModel\XrplTx as XrpTxResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * Initialize the collection with its model and resource model
     */
    protected function _construct()
    {
        $this->_init(XrplTxModel::class, XrpTxResourceModel::class);
    }
}
