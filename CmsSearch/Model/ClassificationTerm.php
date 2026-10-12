<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model;

use Magento\Framework\Model\AbstractModel;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ClassificationTerm as TermResource;

class ClassificationTerm extends AbstractModel implements ClassificationTermInterface
{
    protected $_idFieldName = self::TERM_ID;

    protected function _construct(): void
    {
        $this->_init(TermResource::class);
    }

    public function getTermId(): ?int
    {
        return $this->nullableInt(self::TERM_ID);
    }

    public function setTermId(int $termId): static
    {
        return $this->setData(self::TERM_ID, $termId);
    }

    public function getWebsiteId(): int
    {
        return (int) $this->getData(self::WEBSITE_ID);
    }

    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::WEBSITE_ID, $websiteId);
    }

    public function getParentId(): ?int
    {
        return $this->nullableInt(self::PARENT_ID);
    }

    public function setParentId(?int $parentId): static
    {
        return $this->setData(self::PARENT_ID, $parentId);
    }

    public function getCode(): string
    {
        return (string) $this->getData(self::CODE);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::CODE, $code);
    }

    public function getPath(): string
    {
        return (string) $this->getData(self::PATH);
    }

    public function getLevel(): int
    {
        return (int) $this->getData(self::LEVEL);
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData(self::SORT_ORDER);
    }

    public function setSortOrder(int $sortOrder): static
    {
        return $this->setData(self::SORT_ORDER, $sortOrder);
    }

    public function getIsActive(): bool
    {
        return (bool) (int) $this->getData(self::IS_ACTIVE);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getLabels(): array
    {
        return (array) ($this->getData(self::LABELS) ?? []);
    }

    public function setLabels(array $labels): static
    {
        return $this->setData(self::LABELS, $labels);
    }

    public function getLabel(int $storeId): string
    {
        $labels = $this->getLabels();
        $label = trim((string) ($labels[$storeId] ?? ''));
        if ($label === '') {
            $label = trim((string) ($labels[0] ?? ''));
        }
        return $label !== '' ? $label : $this->getCode();
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->getData($key);
        return $value === null || $value === '' ? null : (int) $value;
    }
}
