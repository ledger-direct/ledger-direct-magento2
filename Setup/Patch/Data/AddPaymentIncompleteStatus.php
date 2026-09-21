<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Setup\Patch\Data;

use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

/**
 * The order status a LedgerDirect order takes while a payment has arrived that does not
 * settle it - a shortfall, or a token other than the quoted one.
 *
 * Magento knows no partially-paid state, and until now such a payment reached the merchant
 * only as a comment in the status history: in the order grid the order looked like any
 * other unpaid one. A status of its own under the pending_payment state is visible and
 * filterable there, and Magento's CleanExpiredOrders cron, which cancels by *status*
 * pending_payment, no longer cancels an order that has real money on the ledger.
 *
 * Assigned to the state as a non-default status, so orders placed with LedgerDirect still
 * start out as pending_payment.
 */
class AddPaymentIncompleteStatus implements DataPatchInterface
{
    public const LABEL = 'XRPL payment incomplete';

    /**
     * @var ModuleDataSetupInterface
     */
    private ModuleDataSetupInterface $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();

        $connection->insertOnDuplicate(
            $this->moduleDataSetup->getTable('sales_order_status'),
            ['status' => OrderSettlementService::PAYMENT_INCOMPLETE_STATUS, 'label' => self::LABEL],
            ['label']
        );
        $connection->insertOnDuplicate(
            $this->moduleDataSetup->getTable('sales_order_status_state'),
            [
                'status' => OrderSettlementService::PAYMENT_INCOMPLETE_STATUS,
                'state' => Order::STATE_PENDING_PAYMENT,
                'is_default' => 0,
                'visible_on_front' => 1,
            ],
            ['state', 'visible_on_front']
        );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
