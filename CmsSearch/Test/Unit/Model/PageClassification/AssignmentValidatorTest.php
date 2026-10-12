<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\PageClassification;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\ClassificationTermRepositoryInterface;
use Thedevkitchen\CmsSearch\Api\ContentTypeRepositoryInterface;
use Thedevkitchen\CmsSearch\Model\Classification\TreeBuilder;
use Thedevkitchen\CmsSearch\Model\PageClassification;
use Thedevkitchen\CmsSearch\Model\PageClassification\AssignmentValidator;
use Thedevkitchen\CmsSearch\Model\PageClassification\PageWebsites;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ClassificationTermBuilder;
use Thedevkitchen\CmsSearch\Test\Unit\Support\ContentTypeBuilder;

class AssignmentValidatorTest extends TestCase
{
    /** @var array<int, bool> type id => active */
    private array $types = [3 => true, 4 => false];
    /** @var array<int, array{0: int, 1: string, 2: bool}> term id => [website, path, active] */
    private array $terms = [
        10 => [2, '10', true],
        11 => [2, '10/11', true],
        12 => [2, '12', false],
        13 => [2, '12/13', true],
        20 => [3, '20', true],
    ];

    private function validator(): AssignmentValidator
    {
        $types = $this->createMock(ContentTypeRepositoryInterface::class);
        $types->method('getById')->willReturnCallback(function (int $id) {
            if (!isset($this->types[$id])) {
                throw new NoSuchEntityException(__('no type'));
            }
            return ContentTypeBuilder::build(fn (string $c) => $this->createMock($c), $id, 'type' . $id, [0 => 'Type ' . $id])
                ->setIsActive($this->types[$id]);
        });
        $terms = $this->createMock(ClassificationTermRepositoryInterface::class);
        $terms->method('getById')->willReturnCallback(function (int $id) {
            if (!isset($this->terms[$id])) {
                throw new NoSuchEntityException(__('no term'));
            }
            [$websiteId, $path, $active] = $this->terms[$id];
            return ClassificationTermBuilder::build(fn (string $c) => $this->createMock($c), $id, $websiteId, 'term' . $id, null, [0 => 'Term ' . $id])
                ->setIsActive($active)->setData('path', $path);
        });

        $websites = [];
        foreach ([2, 3] as $id) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $websites[] = $website;
        }
        $stores = [];
        foreach ([2 => 2, 5 => 3] as $storeId => $websiteId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getStores')->willReturn($stores);

        return new AssignmentValidator(
            $types,
            $terms,
            new PageWebsites($storeManager),
            new TreeBuilder($this->createMock(TermResource::class))
        );
    }

    private function rejected(?int $type, ?int $term, array $stores, ?PageClassification $current, string $message): void
    {
        try {
            $this->validator()->validate($type, $term, $stores, $current);
            $this->fail('expected InputException');
        } catch (InputException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function testBothEmptyIsValid(): void
    {
        $this->validator()->validate(null, null, [2], null);
        $this->addToAssertionCount(1);
    }

    public function testAValidPairPasses(): void
    {
        $this->validator()->validate(3, 11, [2], null);
        $this->addToAssertionCount(1);
    }

    public function testOnlyOneOfTheTwoIsRejected(): void
    {
        $this->rejected(3, null, [2], null, 'Select both a content type and a classification term');
        $this->rejected(null, 10, [2], null, 'Select both a content type and a classification term');
    }

    public function testUnknownTypeOrTermIsRejected(): void
    {
        $this->rejected(99, 10, [2], null, 'content type does not exist');
        $this->rejected(3, 99, [2], null, 'classification term does not exist');
    }

    public function testATermOfAnotherWebsiteIsRejected(): void
    {
        $this->rejected(3, 20, [2], null, "must belong to a website of the page's store views");
    }

    public function testAllStoreViewsAcceptsATermOfAnyWebsite(): void
    {
        $this->validator()->validate(3, 20, [0], null);
        $this->validator()->validate(3, 10, [0], null);
        $this->addToAssertionCount(2);
    }

    public function testAPageInTwoWebsitesAcceptsTermsOfBoth(): void
    {
        $this->validator()->validate(3, 20, [2, 5], null);
        $this->validator()->validate(3, 10, [2, 5], null);
        $this->addToAssertionCount(2);
    }

    public function testAnInactiveTypeIsRejectedUnlessItIsTheCurrentOne(): void
    {
        $this->rejected(4, 10, [2], new PageClassification(7, 3, 10), 'content type "Type 4" is inactive');
        $this->validator()->validate(4, 10, [2], new PageClassification(7, 4, 10));
    }

    public function testAnInactiveTermIsRejectedUnlessItIsTheCurrentOne(): void
    {
        $this->rejected(3, 12, [2], new PageClassification(7, 3, 10), 'classification term "Term 12" is inactive');
        $this->validator()->validate(3, 12, [2], new PageClassification(7, 3, 12));
    }

    public function testATermUnderAnInactiveAncestorIsInactive(): void
    {
        $this->rejected(3, 13, [2], null, 'classification term "Term 13" is inactive');
    }
}
