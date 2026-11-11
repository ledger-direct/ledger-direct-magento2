<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit;

use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * etc/db_schema.xml declares what the core's Schema describes for each chain the module
 * carries: the same tables, the same columns under the same names. The CTID lives in tx_ctid
 * on both (core 0.9), the XRPL table carries the migration from before, Xahau's does not.
 */
class DbSchemaTest extends TestCase
{
    /**
     * @dataProvider chains
     */
    #[DataProvider('chains')]
    public function testTheDeclaredTablesMatchTheCoreSchema(string $chain): void
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 2) . '/etc/db_schema.xml'));
        $whitelist = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/etc/db_schema_whitelist.json'), true);

        foreach (Schema::tables($chain) as $table => $definition) {
            $declared = $xml->xpath('/schema/table[@name="' . $table . '"]');
            $this->assertCount(1, $declared, "$table is declared");
            $columns = [];
            foreach ($declared[0]->column as $column) {
                $columns[] = (string) $column['name'];
            }
            $this->assertSame(array_column($definition['columns'], 'name'), $columns, "$table columns");
            $this->assertArrayHasKey($table, $whitelist, "$table is whitelisted");
            // The whitelist also keeps columns from before (ctid, the old tag columns) so the
            // declarative schema drops them; every declared column must be in it.
            $this->assertSame([], array_diff($columns, array_keys($whitelist[$table]['column'])), "$table whitelist columns");
        }
    }

    public static function chains(): iterable
    {
        yield 'XRPL' => ['XRPL'];
        yield 'XAHAU' => ['XAHAU'];
    }
}
