<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Config;

use Magento\Config\Model\Config\Source\Yesno;
use Magento\Framework\App\Config\Value;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Config\Backend\PageSize;
use Thedevkitchen\CmsSearch\Model\Config\Backend\SummaryLength;

/**
 * Static contract of the storefront settings in etc/adminhtml/system.xml and etc/config.xml (spec 5.1).
 */
class SearchConfigContractTest extends TestCase
{
    private function xml(string $file): \SimpleXMLElement
    {
        $path = dirname(__DIR__, 3) . '/etc/' . $file;
        $this->assertFileExists($path);
        $xml = simplexml_load_file($path);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml, "$file must be valid XML");
        return $xml;
    }

    private function field(string $group, string $id): \SimpleXMLElement
    {
        $field = $this->xml('adminhtml/system.xml')->xpath(
            sprintf('//section[@id="thedevkitchen_cmssearch"]/group[@id="%s"]/field[@id="%s"]', $group, $id)
        );
        $this->assertCount(1, $field, "$group/$id");
        return $field[0];
    }

    /** @return string[] showInDefault, showInWebsite, showInStore */
    private function scopes(\SimpleXMLElement $node): array
    {
        return [(string) $node['showInDefault'], (string) $node['showInWebsite'], (string) $node['showInStore']];
    }

    private function defaultValue(string $path): ?string
    {
        $node = $this->xml('config.xml')->xpath('/config/default/' . $path);
        return $node ? (string) $node[0] : null;
    }

    public function testStorefrontGroupsAreVisiblePerStoreViewWhileTheQueueStaysGlobal(): void
    {
        $system = $this->xml('adminhtml/system.xml');
        $section = $system->xpath('//section[@id="thedevkitchen_cmssearch"]');
        $this->assertCount(1, $section);
        $this->assertSame(['1', '1', '1'], $this->scopes($section[0]));
        foreach (['general', 'tabs', 'results'] as $group) {
            $node = $system->xpath('//section[@id="thedevkitchen_cmssearch"]/group[@id="' . $group . '"]');
            $this->assertCount(1, $node, $group);
            $this->assertSame(['1', '1', '1'], $this->scopes($node[0]), $group);
        }
        $queue = $system->xpath('//section[@id="thedevkitchen_cmssearch"]/group[@id="queue"]');
        $this->assertCount(1, $queue);
        $this->assertSame(['1', '0', '0'], $this->scopes($queue[0]));
    }

    public function testTheModuleSwitchIsAYesNoFlagOnByDefault(): void
    {
        $field = $this->field('general', 'enabled');
        $this->assertSame('select', (string) $field['type']);
        $this->assertSame(Yesno::class, ltrim((string) $field->source_model, '\\'));
        $this->assertSame(['1', '1', '1'], $this->scopes($field));
        $this->assertSame('thedevkitchen_cmssearch/general/enabled', Config::XML_PATH_ENABLED);
        $this->assertSame('1', $this->defaultValue(Config::XML_PATH_ENABLED));
    }

    public function testTabLabelsAreOptionalStoreViewTexts(): void
    {
        $paths = ['products_label' => Config::XML_PATH_PRODUCTS_LABEL, 'categories_label' => Config::XML_PATH_CATEGORIES_LABEL];
        foreach ($paths as $id => $path) {
            $field = $this->field('tabs', $id);
            $this->assertSame(['1', '1', '1'], $this->scopes($field), $id);
            $this->assertContains(
                'maximum-length-' . Config::MAX_LABEL_LENGTH,
                explode(' ', trim((string) $field->validate)),
                $id
            );
            $this->assertSame('thedevkitchen_cmssearch/tabs/' . $id, $path);
            $this->assertSame('', $this->defaultValue($path), $id);
        }
    }

    public function testResultSettingsAreValidatedInTheFormAndOnSave(): void
    {
        $cases = [
            'page_size' => [PageSize::class, Config::MIN_PAGE_SIZE, Config::MAX_PAGE_SIZE, Config::DEFAULT_PAGE_SIZE, Config::XML_PATH_PAGE_SIZE],
            'summary_length' => [SummaryLength::class, Config::MIN_SUMMARY_LENGTH, Config::MAX_SUMMARY_LENGTH, Config::DEFAULT_SUMMARY_LENGTH, Config::XML_PATH_SUMMARY_LENGTH],
        ];
        foreach ($cases as $id => [$backend, $min, $max, $default, $path]) {
            $field = $this->field('results', $id);
            $this->assertSame($backend, ltrim((string) $field->backend_model, '\\'), $id);
            $this->assertTrue(is_a($backend, Value::class, true), $id);
            $rules = explode(' ', trim((string) $field->validate));
            $this->assertContains('validate-digits-range', $rules, $id);
            $this->assertContains(sprintf('digits-range-%d-%d', $min, $max), $rules, $id);
            $this->assertSame(['1', '1', '1'], $this->scopes($field), $id);
            $this->assertSame('thedevkitchen_cmssearch/results/' . $id, $path);
            $this->assertSame((string) $default, $this->defaultValue($path), $id);
        }
    }

    public function testTheSearchTimeoutIsAnOperatorSettingWithoutFormField(): void
    {
        $this->assertSame('thedevkitchen_cmssearch/results/search_timeout_ms', Config::XML_PATH_SEARCH_TIMEOUT_MS);
        $this->assertSame((string) Config::DEFAULT_SEARCH_TIMEOUT_MS, $this->defaultValue(Config::XML_PATH_SEARCH_TIMEOUT_MS));
        $this->assertSame([], $this->xml('adminhtml/system.xml')->xpath('//field[@id="search_timeout_ms"]'));
    }
}
