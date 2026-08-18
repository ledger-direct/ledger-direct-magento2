<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\ViewModel;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class XrplTx implements ArgumentInterface
{
    /**
     * Get the list of XRPL transactions
     *
     * @return DataObject[]
     */
    public function getList(): array
    {
        return [
            new DataObject(['id' => 1, 'hash' => ''])
        ];
    }
}
