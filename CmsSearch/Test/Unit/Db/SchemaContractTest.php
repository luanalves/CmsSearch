<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Db;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

/**
 * Static contract of etc/db_schema.xml: table names, key columns and the delete rule of every foreign key.
 */
class SchemaContractTest extends TestCase
{
    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private function schema(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/db_schema.xml');
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }

    private function table(string $name): \SimpleXMLElement
    {
        $tables = $this->schema()->xpath('/schema/table[@name="' . $name . '"]');
        $this->assertCount(1, $tables, "table $name must be declared once");
        return $tables[0];
    }

    /** @return array<string, string> attributes of a column, xsi:type under the key "type" */
    private function column(string $table, string $column): array
    {
        $nodes = $this->table($table)->xpath('column[@name="' . $column . '"]');
        $this->assertCount(1, $nodes, "$table.$column must be declared once");
        $attributes = ['type' => (string) $nodes[0]->attributes(self::XSI)['type']];
        foreach ($nodes[0]->attributes() as $key => $value) {
            $attributes[$key] = (string) $value;
        }
        return $attributes;
    }

    /** @return array{table: string, column: string, onDelete: string}|null */
    private function foreignKey(string $table, string $column): ?array
    {
        foreach ($this->table($table)->xpath('constraint') as $constraint) {
            if ((string) $constraint->attributes(self::XSI)['type'] === 'foreign'
                && (string) $constraint['column'] === $column) {
                return [
                    'table' => (string) $constraint['referenceTable'],
                    'column' => (string) $constraint['referenceColumn'],
                    'onDelete' => (string) $constraint['onDelete'],
                ];
            }
        }
        return null;
    }

    /** @return list<list<string>> column lists of the unique constraints of a table */
    private function uniqueKeys(string $table): array
    {
        $keys = [];
        foreach ($this->table($table)->xpath('constraint') as $constraint) {
            if ((string) $constraint->attributes(self::XSI)['type'] === 'unique') {
                $keys[] = array_map('strval', $constraint->xpath('column/@name'));
            }
        }
        return $keys;
    }

    public function testEveryTableConstantIsDeclared(): void
    {
        $constants = (new \ReflectionClass(Tables::class))->getConstants();
        $this->assertContains('thedevkitchen_cmssearch_classification_term', $constants);
        $this->assertContains('thedevkitchen_cmssearch_classification_term_label', $constants);
        foreach ($constants as $name) {
            $this->table($name);
        }
    }

    public function testTermColumns(): void
    {
        $table = Tables::CLASSIFICATION_TERM;
        $id = $this->column($table, 'term_id');
        $this->assertSame(['int', 'true', 'true'], [$id['type'], $id['unsigned'], $id['identity']]);
        $this->assertSame('smallint', $this->column($table, 'website_id')['type']);
        $parent = $this->column($table, 'parent_id');
        $this->assertSame(['int', 'true', 'true'], [$parent['type'], $parent['unsigned'], $parent['nullable']]);
        $this->assertSame(['varchar', '64'], [$this->column($table, 'code')['type'], $this->column($table, 'code')['length']]);
        $this->assertSame(['varchar', '255'], [$this->column($table, 'path')['type'], $this->column($table, 'path')['length']]);
        $this->assertSame('smallint', $this->column($table, 'level')['type']);
        $this->assertSame('int', $this->column($table, 'sort_order')['type']);
        $this->assertSame('1', $this->column($table, 'is_active')['default']);
        $this->assertContains(['website_id', 'code'], $this->uniqueKeys($table));
    }

    public function testTermForeignKeys(): void
    {
        $this->assertSame(
            ['table' => 'store_website', 'column' => 'website_id', 'onDelete' => 'CASCADE'],
            $this->foreignKey(Tables::CLASSIFICATION_TERM, 'website_id')
        );
        $this->assertSame(
            ['table' => Tables::CLASSIFICATION_TERM, 'column' => 'term_id', 'onDelete' => 'NO ACTION'],
            $this->foreignKey(Tables::CLASSIFICATION_TERM, 'parent_id')
        );
    }

    public function testTermLabelKeysAndForeignKeys(): void
    {
        $table = Tables::CLASSIFICATION_TERM_LABEL;
        $this->assertSame('255', $this->column($table, 'label')['length']);
        $primary = $this->table($table)->xpath('constraint[@referenceId="PRIMARY"]/column/@name');
        $this->assertSame(['term_id', 'store_id'], array_map('strval', $primary));
        $this->assertSame(
            ['table' => Tables::CLASSIFICATION_TERM, 'column' => 'term_id', 'onDelete' => 'CASCADE'],
            $this->foreignKey($table, 'term_id')
        );
        $this->assertSame(
            ['table' => 'store', 'column' => 'store_id', 'onDelete' => 'CASCADE'],
            $this->foreignKey($table, 'store_id')
        );
    }

    public function testPageLinkReferencesTheTermWithoutCascade(): void
    {
        $this->assertSame(
            ['table' => Tables::CLASSIFICATION_TERM, 'column' => 'term_id', 'onDelete' => 'NO ACTION'],
            $this->foreignKey(Tables::PAGE_CLASSIFICATION, 'term_id')
        );
        $indexed = array_map('strval', $this->table(Tables::PAGE_CLASSIFICATION)->xpath('index/column/@name'));
        $this->assertContains('term_id', $indexed);
    }

    public function testTermUnsignedFlagsDefaultsAndPathIndex(): void
    {
        $table = Tables::CLASSIFICATION_TERM;
        foreach (['term_id', 'website_id', 'parent_id', 'level', 'sort_order', 'is_active'] as $column) {
            $this->assertSame('true', $this->column($table, $column)['unsigned'] ?? null, "$column must be unsigned");
        }
        $this->assertSame('false', $this->column($table, 'code')['nullable'] ?? null);
        $this->assertSame('', $this->column($table, 'path')['default'] ?? null);
        $this->assertSame('0', $this->column($table, 'level')['default'] ?? null);
        $this->assertSame('0', $this->column($table, 'sort_order')['default'] ?? null);
        $this->assertSame('1', $this->column($table, 'is_active')['default'] ?? null);
        $pathIndexes = array_filter(
            $this->table($table)->xpath('index'),
            static fn (\SimpleXMLElement $index): bool => array_map('strval', $index->xpath('column/@name')) === ['path']
        );
        $this->assertCount(1, $pathIndexes, 'the path column needs its own index (subtree lookups by prefix)');
    }

    public function testEveryDeclaredTableAndColumnIsInTheWhitelist(): void
    {
        $json = file_get_contents(dirname(__DIR__, 3) . '/etc/db_schema_whitelist.json');
        $whitelist = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        $declared = array_map('strval', $this->schema()->xpath('/schema/table/@name'));
        sort($declared);
        $listed = array_keys($whitelist);
        sort($listed);
        $this->assertSame($declared, $listed, 'tables of db_schema.xml and of the whitelist must be the same set');
        foreach ($declared as $name) {
            foreach ($this->table($name)->xpath('column/@name') as $column) {
                $this->assertArrayHasKey((string) $column, $whitelist[$name]['column'] ?? [], "$name.$column missing in db_schema_whitelist.json");
            }
        }
    }

    public function testBlockReferenceColumnsUniqueKeyAndStoreCascade(): void
    {
        $table = Tables::BLOCK_REFERENCE;
        $types = [
            'reference_id' => 'int', 'store_id' => 'smallint', 'host_type' => 'varchar', 'host_id' => 'int',
            'block_kind' => 'varchar', 'block_id' => 'int', 'block_identifier' => 'varchar', 'source' => 'varchar',
            'source_id' => 'int', 'depth' => 'smallint', 'segment_ids' => 'varchar',
        ];
        foreach ($types as $column => $type) {
            $this->assertSame($type, $this->column($table, $column)['type'], $column);
        }
        $this->assertSame('true', $this->column($table, 'reference_id')['identity'] ?? null);
        $this->assertSame('255', $this->column($table, 'block_identifier')['length'] ?? null);
        $this->assertSame('', $this->column($table, 'block_identifier')['default'] ?? null);
        $this->assertSame('false', $this->column($table, 'block_identifier')['nullable'] ?? null);
        $this->assertSame('true', $this->column($table, 'segment_ids')['nullable'] ?? null);
        $this->assertSame(
            [['store_id', 'host_type', 'host_id', 'block_kind', 'block_id', 'block_identifier', 'source', 'source_id']],
            $this->uniqueKeys($table)
        );
        $this->assertSame(['table' => 'store', 'column' => 'store_id', 'onDelete' => 'CASCADE'], $this->foreignKey($table, 'store_id'));
        // hosts are versioned by row_id and a deleted block must still lead to its hosts: no FK on purpose
        $this->assertNull($this->foreignKey($table, 'host_id'));
        $this->assertNull($this->foreignKey($table, 'block_id'));
    }

    public function testTheGraphHasItsReverseLookupIndexes(): void
    {
        $indexes = [];
        foreach ($this->table(Tables::BLOCK_REFERENCE)->xpath('index') as $index) {
            $indexes[] = array_map('strval', $index->xpath('column/@name'));
        }
        $this->assertSame([['block_kind', 'block_id'], ['block_kind', 'block_identifier'], ['source', 'source_id']], $indexes);
    }

    public function testProductBlockTextColumnsPrimaryKeyAndStoreCascade(): void
    {
        $table = Tables::PRODUCT_BLOCK_TEXT;
        $types = ['product_id' => 'int', 'store_id' => 'smallint', 'text' => 'mediumtext', 'text_hash' => 'varchar', 'updated_at' => 'timestamp'];
        foreach ($types as $column => $type) {
            $this->assertSame($type, $this->column($table, $column)['type'], $column);
        }
        $this->assertSame('40', $this->column($table, 'text_hash')['length'] ?? null);
        $this->assertSame('false', $this->column($table, 'text')['nullable'] ?? null);
        $primary = [];
        foreach ($this->table($table)->xpath('constraint') as $constraint) {
            if ((string) $constraint->attributes(self::XSI)['type'] === 'primary') {
                $primary = array_map('strval', $constraint->xpath('column/@name'));
            }
        }
        $this->assertSame(['product_id', 'store_id'], $primary);
        $this->assertSame(['table' => 'store', 'column' => 'store_id', 'onDelete' => 'CASCADE'], $this->foreignKey($table, 'store_id'));
        $this->assertNull($this->foreignKey($table, 'product_id'));
    }
}
