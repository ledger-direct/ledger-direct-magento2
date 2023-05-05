<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class XrplTx extends AbstractDb
{
    public const MAIN_TABLE = 'xrpl_tx';

    public const ID_FIELD_NAME = 'id';
    protected function _construct()
    {
        $this->_init(self::MAIN_TABLE, self::ID_FIELD_NAME);
    }
}
