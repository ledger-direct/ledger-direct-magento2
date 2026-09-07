<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Fills the `network` column core 0.4 added to ledger_direct_xrpl_tx for rows synced before it
 * existed. The declarative schema creates the column; it cannot fill it.
 *
 * The network is read off each row's CTID, whose last four hex digits are the network id
 * (C + 7 ledger index + 4 transaction index + 4 network id; 0 = mainnet, 1 = testnet). Exact
 * rather than guessed: the configured setting would be wrong for every shop that tested on the
 * testnet and then switched to the mainnet. Rows without a usable CTID stay '' and are simply
 * not counted for the sync cursor - at worst the next sync paginates once more, and the unique
 * hash index keeps that from storing anything twice.
 */
class BackfillXrplTransactionNetwork implements DataPatchInterface
{
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
        $table = $this->moduleDataSetup->getTable('ledger_direct_xrpl_tx');

        $connection->update(
            $table,
            ['network' => new \Magento\Framework\DB\Sql\Expression(
                "CASE CONV(RIGHT(ctid, 4), 16, 10) WHEN 0 THEN 'mainnet' WHEN 1 THEN 'testnet' ELSE '' END"
            )],
            ['network = ?' => '', 'CHAR_LENGTH(ctid) = ?' => 16]
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
