<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\ResourceModel\XrplTx;

use Hardcastle\LedgerDirect\Model\XrplTx as XrplTxModel;
use Hardcastle\LedgerDirect\Model\ResourceModel\XrplTx as XrpTxResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(XrplTxModel::class, XrpTxResourceModel::class);
    }
}
