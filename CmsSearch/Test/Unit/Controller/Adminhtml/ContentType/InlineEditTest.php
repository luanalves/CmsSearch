<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Controller\Adminhtml\ContentType;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\AbstractContentType;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\ContentType\InlineEdit;
use Thedevkitchen\CmsSearch\Test\Unit\Support\AdminContextTrait;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ContentTypeBuilder;

class InlineEditTest extends TestCase
{
    use AdminContextTrait;

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    /** @param array<string, mixed> $params */
    private function controller(array $params, ContentTypeRepositoryInterface $repository): InlineEdit
    {
        $this->setUpContext($params);
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->payload = $data;
            return $json;
        });
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        return new InlineEdit($this->context, $factory, $repository);
    }

    public function testOnlyTheEditRightGrantsAccess(): void
    {
        $controller = $this->controller([], $this->createMock(ContentTypeRepositoryInterface::class));
        $this->assertSame(
            [AbstractContentType::RESOURCE_EDIT],
            $this->consultedResources($controller, AbstractContentType::RESOURCE_EDIT)
        );
        $this->granted = [AbstractContentType::RESOURCE_CREATE => true, AbstractContentType::RESOURCE_DELETE => true];
        $this->assertFalse((new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badRequests(): array
    {
        return [
            'not ajax' => [['items' => [1 => ['sort_order' => '3']]]],
            'no items' => [['isAjax' => 'true']],
            'empty items' => [['isAjax' => 'true', 'items' => []]],
            'items not an array' => [['isAjax' => 'true', 'items' => 'x']],
        ];
    }

    /** @param array<string, mixed> $params */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRequests')]
    public function testInvalidRequestReturnsErrorJsonAndSavesNothing(array $params): void
    {
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->expects($this->never())->method('save');
        $this->controller($params, $repository)->execute();

        $this->assertTrue($this->payload['error']);
        $this->assertCount(1, $this->payload['messages']);
    }

    public function testChangesOnlyTheSortOrderAndTheStatus(): void
    {
        $type = ContentTypeBuilder::build(
            fn (string $c) => $this->createMock($c),
            4,
            'news',
            [0 => 'News', 1 => 'Notícias']
        )->setSortOrder(1)->setIsActive(true);
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('getById')->with(4)->willReturn($type);
        $repository->expects($this->once())->method('save')->with($type)->willReturnArgument(0);
        $controller = $this->controller([
            'isAjax' => 'true',
            'items' => [4 => ['sort_order' => '9', 'is_active' => '0', 'code' => 'hacked', 'labels' => ['x']]],
        ], $repository);

        $controller->execute();

        $this->assertSame(9, $type->getSortOrder());
        $this->assertFalse($type->getIsActive());
        $this->assertSame('news', $type->getCode());
        $this->assertSame([0 => 'News', 1 => 'Notícias'], $type->getLabels());
        $this->assertSame(['messages' => [], 'error' => false], $this->payload);
    }

    public function testFieldsNotSentStayUntouched(): void
    {
        $type = ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 4, 'news')
            ->setSortOrder(2)->setIsActive(true);
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('getById')->willReturn($type);
        $repository->method('save')->willReturnArgument(0);
        $this->controller(['isAjax' => 'true', 'items' => [4 => ['sort_order' => '5']]], $repository)->execute();

        $this->assertSame(5, $type->getSortOrder());
        $this->assertTrue($type->getIsActive());
    }

    public function testCollectsOneMessagePerFailedItemAndStillSavesTheOthers(): void
    {
        $good = ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 1, 'a');
        $bad = ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 2, 'b');
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(fn (int $id) => $id === 1 ? $good : $bad);
        $saved = [];
        $repository->method('save')->willReturnCallback(function ($type) use (&$saved) {
            if ($type->getTypeId() === 2) {
                throw new LocalizedException(__('Cannot save 2'));
            }
            $saved[] = $type->getTypeId();
            return $type;
        });
        $this->controller([
            'isAjax' => 'true',
            'items' => [2 => ['sort_order' => '1'], 1 => ['sort_order' => '2']],
        ], $repository)->execute();

        $this->assertSame([1], $saved);
        $this->assertTrue($this->payload['error']);
        $this->assertSame(['[ID: 2] Cannot save 2'], $this->payload['messages']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidRows(): array
    {
        return [
            'negative order' => [['sort_order' => '-1']],
            'order too big' => [['sort_order' => '65536']],
            'order text' => [['sort_order' => 'abc']],
            'order decimal' => [['sort_order' => '1.5']],
            'status 2' => [['is_active' => '2']],
            'status text' => [['is_active' => 'yes']],
        ];
    }

    /** @param array<string, mixed> $row */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRows')]
    public function testRejectsInvalidInlineValuesPerItemWithoutSaving(array $row): void
    {
        $type = ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), 4, 'news')
            ->setSortOrder(2)->setIsActive(true);
        $repository = $this->createMock(ContentTypeRepositoryInterface::class);
        $repository->method('getById')->willReturn($type);
        $repository->expects($this->never())->method('save');
        $this->controller(['isAjax' => 'true', 'items' => [4 => $row]], $repository)->execute();

        $this->assertTrue($this->payload['error']);
        $this->assertCount(1, $this->payload['messages']);
        $this->assertStringStartsWith('[ID: 4] ', $this->payload['messages'][0]);
    }
}
