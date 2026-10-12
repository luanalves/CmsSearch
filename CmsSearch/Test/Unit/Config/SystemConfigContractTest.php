<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Config;

use Magento\Framework\App\Config\Value;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Config;
use Thedevkitchen\CmsSearch\Model\Config\Backend\MaxAttempts;

/**
 * Static contract of etc/adminhtml/system.xml and etc/config.xml.
 */
class SystemConfigContractTest extends TestCase
{
    private function xml(string $file): \SimpleXMLElement
    {
        $path = dirname(__DIR__, 3) . '/etc/' . $file;
        $this->assertFileExists($path);
        $xml = simplexml_load_file($path);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml, "$file must be valid XML");
        return $xml;
    }

    public function testTheSectionIsProtectedByItsOwnAclResource(): void
    {
        $system = $this->xml('adminhtml/system.xml');
        $this->assertCount(1, $system->xpath('//tab[@id="thedevkitchen"]'));
        $section = $system->xpath('//section[@id="thedevkitchen_cmssearch"]');
        $this->assertCount(1, $section);
        $this->assertSame('thedevkitchen', (string) $section[0]->tab);
        $this->assertSame('Thedevkitchen_CmsSearch::config', (string) $section[0]->resource);
    }

    public function testMaxAttemptsIsGlobalAndValidatedInTheFormAndOnSave(): void
    {
        $field = $this->xml('adminhtml/system.xml')
            ->xpath('//section[@id="thedevkitchen_cmssearch"]/group[@id="queue"]/field[@id="max_attempts"]');
        $this->assertCount(1, $field);
        $this->assertSame(MaxAttempts::class, ltrim((string) $field[0]->backend_model, '\\'));
        $this->assertTrue(is_a(MaxAttempts::class, Value::class, true));
        $rules = explode(' ', trim((string) $field[0]->validate));
        $this->assertContains('validate-digits-range', $rules);
        $this->assertContains(
            sprintf('digits-range-%d-%d', Config::MIN_MAX_ATTEMPTS, Config::MAX_MAX_ATTEMPTS),
            $rules
        );
        $this->assertSame(
            ['1', '0', '0'],
            [(string) $field[0]['showInDefault'], (string) $field[0]['showInWebsite'], (string) $field[0]['showInStore']]
        );
    }

    public function testTheDefaultIsThree(): void
    {
        $default = $this->xml('config.xml')->xpath('/config/default/thedevkitchen_cmssearch/queue/max_attempts');
        $this->assertCount(1, $default);
        $this->assertSame('3', (string) $default[0]);
        $this->assertSame(3, Config::DEFAULT_MAX_ATTEMPTS);
        $this->assertSame('thedevkitchen_cmssearch/queue/max_attempts', Config::XML_PATH_MAX_ATTEMPTS);
    }
}
