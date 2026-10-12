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
}
