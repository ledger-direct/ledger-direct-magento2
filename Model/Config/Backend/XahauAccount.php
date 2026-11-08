<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\Config\Backend;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * A Xahau receiving account on save: trimmed, and compared with the XRP Ledger's account on the
 * same network
 *
 * The same keys open the same r-address on both chains, and merchants are asked to use one
 * account per chain (the core's INVARIANTS.md, "Xahau"). The adapter warns, it cannot refuse: the
 * formats are identical, and the module keeps the chains apart in any case.
 */
class XahauAccount extends Value
{
    /**
     * @var ManagerInterface
     */
    private ManagerInterface $messageManager;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ManagerInterface $messageManager
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ManagerInterface $messageManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->messageManager = $messageManager;
    }

    /**
     * Trim the account and warn when the XRP Ledger receives on the same address
     *
     * @return $this
     */
    public function beforeSave()
    {
        $account = trim((string) $this->getValue());
        $this->setValue($account);

        // The field is xahau_<network>_account; the XRPL counterpart is xrpl_<network>_account.
        // Read in the scope being saved (default or a website), so an override is compared with its own.
        $network = str_contains((string) $this->getPath(), 'mainnet') ? 'mainnet' : 'testnet';
        $xrplAccount = trim((string) $this->_config->getValue(
            'payment/ledger_direct/xrpl_' . $network . '_account',
            (string) ($this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT),
            $this->getScopeId()
        ));

        if ($account !== '' && $account === $xrplAccount) {
            $this->messageManager->addWarningMessage((string) __(
                'The %1 account %2 is also the XRP Ledger account on %3. The two are different accounts on different'
                . ' chains, and a payment sent on the wrong one reaches no order. Use one account per chain.',
                ChainCatalog::chain(ChainCatalog::XAHAU)->label,
                $account,
                $network
            ));
        }

        return parent::beforeSave();
    }
}
