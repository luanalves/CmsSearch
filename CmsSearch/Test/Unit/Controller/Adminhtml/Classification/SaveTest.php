<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Controller\Adminhtml\Classification;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterfaceFactory;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\AbstractClassification;
use Thedevkitchen\CmsSearch\Controller\Adminhtml\Classification\Save;
use Thedevkitchen\CmsSearch\Model\Classification\FormDataMapper;
use Thedevkitchen\CmsSearch\Model\ClassificationTerm;
use Thedevkitchen\CmsSearch\Model\ContentType\FormDataMapper as LabelsMapper;
use Thedevkitchen\CmsSearch\Model\Input\StrictInput;
use Thedevkitchen\CmsSearch\Test\Unit\Support\AdminContextTrait;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ClassificationTermBuilder;

class SaveTest extends TestCase
{
    use AdminContextTrait;

    private ClassificationTermRepositoryInterface&MockObject $repository;
    private ClassificationTermInterfaceFactory&MockObject $factory;
    private DataPersistorInterface&MockObject $persistor;
    private LoggerInterface&MockObject $logger;

    private function controller(array $post, array $query = []): Save
    {
        $this->setUpContext($query);
        $this->granted = [AbstractClassification::RESOURCE_MANAGE => true];
        $this->request->method('getPostValue')->willReturn($post);
        $this->repository = $this->createMock(ClassificationTermRepositoryInterface::class);
        $this->factory = $this->createMock(ClassificationTermInterfaceFactory::class);
        $this->persistor = $this->createMock(DataPersistorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        return new Save(
            $this->context,
            $this->repository,
            $this->factory,
            new FormDataMapper(new LabelsMapper()),
            new StrictInput(),
            $this->persistor,
            $this->logger
        );
    }

    private function term(?int $id, int $websiteId = 2, string $code = 'agro'): ClassificationTerm
    {
        return ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), $id, $websiteId, $code);
    }

    private function validNew(array $override = []): array
    {
        return $override + ['code' => ' milho ', 'website_id' => '2', 'parent_id' => '4', 'sort_order' => '3',
            'is_active' => '1', 'default_label' => 'Milho'];
    }

    public function testOnlyTheManageRightGrantsAccess(): void
    {
        $controller = $this->controller([]);
        $this->assertSame(
            [AbstractClassification::RESOURCE_MANAGE],
            $this->consultedResources($controller, AbstractClassification::RESOURCE_MANAGE)
        );
        $this->granted = [AbstractClassification::RESOURCE_VIEW => true];
        $this->assertFalse((new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller));
    }

    public function testWithoutTheManageRightNothingIsSaved(): void
    {
        $controller = $this->controller($this->validNew());
        $this->granted = [];
        $this->factory->method('create')->willReturn($this->term(null, 0, ''));
        $this->repository->method('save')->willReturnCallback(fn (ClassificationTerm $t) => $t->setTermId(15));
        $this->repository->expects($this->never())->method('save');
        $this->messages->expects($this->once())->method('addErrorMessage');
        $controller->execute();
    }

    public function testCreatesATermWithParsedValues(): void
    {
        $controller = $this->controller($this->validNew());
        $term = $this->term(null, 0, '');
        $this->factory->method('create')->willReturn($term);
        $this->repository->expects($this->once())->method('save')->willReturnCallback(
            function (ClassificationTerm $saved): ClassificationTerm {
                $this->assertSame(['milho', 2, 4, 3, true, [0 => 'Milho']], [
                    $saved->getCode(), $saved->getWebsiteId(), $saved->getParentId(), $saved->getSortOrder(),
                    $saved->getIsActive(), $saved->getLabels(),
                ]);
                return $saved->setTermId(15);
            }
        );
        $this->messages->expects($this->once())->method('addSuccessMessage');
        $this->persistor->expects($this->once())->method('clear')->with(Save::PERSISTOR_KEY);
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/index', ['website_id' => 2]);

        $controller->execute();
    }

    public function testAnEmptyParentCreatesARootTerm(): void
    {
        $controller = $this->controller($this->validNew(['parent_id' => '']));
        $this->factory->method('create')->willReturn($this->term(null, 0, ''));
        $this->repository->expects($this->once())->method('save')->willReturnCallback(
            function (ClassificationTerm $saved): ClassificationTerm {
                $this->assertNull($saved->getParentId());
                return $saved->setTermId(15);
            }
        );
        $controller->execute();
    }

    public function testCodeAndWebsiteOfAnExistingTermAreNeverTakenFromThePost(): void
    {
        $controller = $this->controller($this->validNew(['term_id' => '9', 'code' => 'hacked', 'website_id' => '3']));
        $existing = $this->term(9, 2, 'milho');
        $this->repository->method('getById')->with(9)->willReturn($existing);
        $this->factory->expects($this->never())->method('create');
        $this->repository->expects($this->once())->method('save')->willReturnCallback(
            function (ClassificationTerm $saved): ClassificationTerm {
                $this->assertSame(['milho', 2], [$saved->getCode(), $saved->getWebsiteId()]);
                return $saved;
            }
        );
        $controller->execute();
    }

    public function testTheTermIdComesFromThePostBodyOnly(): void
    {
        $controller = $this->controller($this->validNew(['term_id' => '9']), ['term_id' => '5']);
        $this->repository->expects($this->once())->method('getById')->with(9)->willReturn($this->term(9));
        $this->repository->method('save')->willReturnArgument(0);
        $controller->execute();
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function invalidPosts(): array
    {
        return [
            'flag yes' => [['is_active' => 'yes']],
            'missing flag' => [['is_active' => '']],
            'parent text' => [['parent_id' => 'abc']],
            'negative sort order' => [['sort_order' => '-1']],
            'website zero' => [['website_id' => '0']],
            'term id garbage' => [['term_id' => '5abc']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPosts')]
    public function testMalformedInputIsRejectedBeforeSaving(array $override): void
    {
        $post = $this->validNew($override);
        $controller = $this->controller($post);
        $this->factory->method('create')->willReturn($this->term(null, 0, ''));
        $this->repository->expects($this->never())->method('save');
        $this->messages->expects($this->once())->method('addErrorMessage')->with('Please correct the data sent.');
        $this->persistor->expects($this->once())->method('set')->with(Save::PERSISTOR_KEY, $post);
        $controller->execute();
    }

    public function testRepositoryValidationMessageIsShownAndTheFormDataKept(): void
    {
        $post = $this->validNew();
        $controller = $this->controller($post);
        $this->factory->method('create')->willReturn($this->term(null, 0, ''));
        $this->repository->method('save')->willThrowException(new LocalizedException(__('The parent term must belong to the same website.')));
        $this->messages->expects($this->once())->method('addErrorMessage')->with('The parent term must belong to the same website.');
        $this->persistor->expects($this->once())->method('set')->with(Save::PERSISTOR_KEY, $post);
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/new', ['website_id' => 2]);
        $controller->execute();
    }

    public function testUnexpectedErrorsAreLoggedAndHidden(): void
    {
        $controller = $this->controller($this->validNew(['term_id' => '9']));
        $this->repository->method('getById')->willThrowException(new \RuntimeException('SQLSTATE secret'));
        $this->logger->expects($this->once())->method('error');
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with($this->callback(fn ($message): bool => !str_contains((string) $message, 'SQLSTATE')));
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/edit', ['term_id' => 9]);
        $controller->execute();
    }

    public function testSaveAndContinueGoesBackToTheForm(): void
    {
        $controller = $this->controller($this->validNew(), ['back' => 'continue']);
        $this->factory->method('create')->willReturn($this->term(null, 0, ''));
        $this->repository->method('save')->willReturnCallback(fn (ClassificationTerm $t) => $t->setTermId(15));
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/edit', ['term_id' => 15]);
        $controller->execute();
    }

    public function testAnExistingTermWithoutParentKeyKeepsItsParent(): void
    {
        $post = $this->validNew(['term_id' => '9']);
        unset($post['parent_id']);
        $controller = $this->controller($post);
        $existing = $this->term(9, 2, 'milho')->setParentId(7);
        $this->repository->method('getById')->with(9)->willReturn($existing);
        $seen = 'not saved';
        $this->repository->expects($this->once())->method('save')->willReturnCallback(
            function (ClassificationTerm $saved) use (&$seen): ClassificationTerm {
                $seen = $saved->getParentId(); // asserted after execute(): the controller swallows Throwables
                return $saved;
            }
        );
        $controller->execute();
        $this->assertSame(7, $seen);
    }

    public function testAnExplicitEmptyParentMovesAnExistingTermToTheRoot(): void
    {
        $controller = $this->controller($this->validNew(['term_id' => '9', 'parent_id' => '']));
        $this->repository->method('getById')->with(9)->willReturn($this->term(9, 2, 'milho')->setParentId(7));
        $seen = 'not saved';
        $this->repository->expects($this->once())->method('save')->willReturnCallback(
            function (ClassificationTerm $saved) use (&$seen): ClassificationTerm {
                $seen = $saved->getParentId();
                return $saved;
            }
        );
        $controller->execute();
        $this->assertNull($seen);
    }

    public function testOnlyKnownScalarFieldsAreKeptForTheNextFormOpen(): void
    {
        $post = $this->validNew(['term_id' => '9', 'form_key' => 'secret', 'evil' => 'x', 'code' => ['a'], 'sort_order' => '-1']);
        $controller = $this->controller($post);
        $this->repository->method('getById')->willThrowException(new LocalizedException(__('gone')));
        $this->persistor->expects($this->once())->method('set')->with(
            Save::PERSISTOR_KEY,
            ['term_id' => '9', 'website_id' => '2', 'parent_id' => '4', 'sort_order' => '-1', 'is_active' => '1', 'default_label' => 'Milho']
        );
        $controller->execute();
    }
}
