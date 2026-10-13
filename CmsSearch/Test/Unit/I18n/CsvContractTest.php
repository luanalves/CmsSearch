<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

class CsvContractTest extends TestCase
{
    /** @return array<string, string> */
    private function rows(string $locale): array
    {
        $handle = fopen(dirname(__DIR__, 3) . '/i18n/' . $locale . '.csv', 'r');
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $this->assertCount(2, $row, 'every row has a phrase and a translation');
            $rows[$row[0]] = $row[1];
        }
        fclose($handle);

        return $rows;
    }

    public function testBothLocalesHaveTheSameKeys(): void
    {
        $this->assertSame(array_keys($this->rows('en_US')), array_keys($this->rows('pt_BR')));
    }

    public function testPaginationUsesModuleSpecificKeys(): void
    {
        $en = $this->rows('en_US');
        $pt = $this->rows('pt_BR');
        $this->assertSame('Next', $en['Next page']);
        $this->assertSame('Previous', $en['Previous page']);
        $this->assertSame('Próxima', $pt['Next page']);
        $this->assertSame('Anterior', $pt['Previous page']);
    }

    public function testCorePhrasesWithAGenderedTranslationAreNotOverridden(): void
    {
        // The pt_BR language pack has no CSV: a module key equal to a core phrase changes it store-wide
        // (checkout 'Next' must not become the feminine 'Próxima').
        foreach (['Next', 'Previous'] as $bare) {
            $this->assertArrayNotHasKey($bare, $this->rows('pt_BR'));
            $this->assertArrayNotHasKey($bare, $this->rows('en_US'));
        }
    }

    public function testThePaginationTemplateUsesTheModuleKeys(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/view/frontend/templates/result/pagination.phtml');
        $this->assertStringContainsString("__('Next page')", $template);
        $this->assertStringContainsString("__('Previous page')", $template);
        $this->assertStringNotContainsString("__('Next')", $template);
        $this->assertStringNotContainsString("__('Previous')", $template);
    }
}
