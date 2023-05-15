<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\ViewModel;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class XrplTx implements ArgumentInterface
{
    public function getList(): array
    {
        return [
            new DataObject(['id' => 1, 'hash' => ''])
        ];
    }
}
