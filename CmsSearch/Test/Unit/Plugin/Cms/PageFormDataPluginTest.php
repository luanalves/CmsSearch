<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Plugin\Cms;

use Magento\Cms\Model\Page\DataProvider as PageDataProvider;
use Magento\CmsStaging\Model\Page\DataProvider as StagingPageDataProvider;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\PageClassificationRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\PageClassification;
use Thedevkitchen\CmsSearch\Model\PageClassification\FormFields;
use Thedevkitchen\CmsSearch\Plugin\Cms\PageFormDataPlugin;

class PageFormDataPluginTest extends TestCase
{
    private function plugin(): PageFormDataPlugin
    {
        $links = $this->createMock(PageClassificationRepositoryInterface::class);
        $links->method('getByPageId')->willReturnCallback(function (int $pageId) {
            if ($pageId !== 7) {
                throw new NoSuchEntityException();
            }
            return new PageClassification(7, 3, 10);
        });
        return new PageFormDataPlugin($links, $this->createMock(LoggerInterface::class));
    }

    public function testAddsTheCurrentTypeAndTermAsStrings(): void
    {
        $result = $this->plugin()->afterGetData(
            $this->createMock(PageDataProvider::class),
            [7 => ['page_id' => '7', 'title' => 'Home'], 8 => ['page_id' => '8']]
        );
        $this->assertSame(['3', '10'], [$result[7][FormFields::TYPE], $result[7][FormFields::TERM]]);
        $this->assertSame(['', ''], [$result[8][FormFields::TYPE], $result[8][FormFields::TERM]]);
        $this->assertSame('Home', $result[7]['title']);
    }

    public function testValuesKeptAfterAFailedSaveWin(): void
    {
        $row = ['page_id' => '7', FormFields::TYPE => '5', FormFields::TERM => ''];
        $result = $this->plugin()->afterGetData($this->createMock(PageDataProvider::class), [7 => $row]);
        $this->assertSame($row, $result[7]);
    }

    public function testANewPageGetsEmptyValues(): void
    {
        $result = $this->plugin()->afterGetData($this->createMock(PageDataProvider::class), ['' => ['title' => 'x']]);
        $this->assertSame('', $result[''][FormFields::TYPE]);
    }

    public function testTheStagingUpdateFormIsLeftAlone(): void
    {
        $data = [7 => ['page_id' => '7']];
        $this->assertSame($data, $this->plugin()->afterGetData($this->createMock(StagingPageDataProvider::class), $data));
    }

    public function testNonArrayResultsAreReturnedAsIs(): void
    {
        $this->assertNull($this->plugin()->afterGetData($this->createMock(PageDataProvider::class), null));
    }

    /**
     * The fieldset is an add-on: a failure while reading the link must not break the native Page form, and the
     * form must not be able to clear a link it could not read (marker "0" = not a submission, see SubmittedValues).
     */
    public function testAFailureWhileReadingTheLinkIsLoggedAndCannotClearIt(): void
    {
        $links = $this->createMock(PageClassificationRepositoryInterface::class);
        $failure = new \RuntimeException('SQLSTATE[HY000]: General error');
        $links->method('getByPageId')->willReturnCallback(function (int $pageId) use ($failure) {
            if ($pageId === 7) {
                throw $failure;
            }
            return new PageClassification($pageId, 3, 10);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->anything(), $this->callback(fn (array $context): bool => ($context['exception'] ?? null) === $failure));

        $result = (new PageFormDataPlugin($links, $logger))->afterGetData(
            $this->createMock(PageDataProvider::class),
            [7 => ['page_id' => '7', 'title' => 'Home'], 8 => ['page_id' => '8']]
        );

        $this->assertSame(
            ['page_id' => '7', 'title' => 'Home', FormFields::TYPE => '', FormFields::TERM => '', FormFields::MARKER => '0'],
            $result[7]
        );
        $this->assertSame(['3', '10'], [$result[8][FormFields::TYPE], $result[8][FormFields::TERM]]);
        $this->assertArrayNotHasKey(FormFields::MARKER, $result[8], 'a readable link keeps the marker default "1"');
    }
}
