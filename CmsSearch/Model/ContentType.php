<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model;

use Magento\Framework\Model\AbstractModel;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;
use Thedevkitchen\CmsSearch\Model\ResourceModel\ContentType as ContentTypeResource;

class ContentType extends AbstractModel implements ContentTypeInterface
{
    protected $_idFieldName = self::TYPE_ID;

    protected function _construct(): void
    {
        $this->_init(ContentTypeResource::class);
    }

    public function getTypeId(): ?int
    {
        $id = $this->getData(self::TYPE_ID);
        return $id === null || $id === '' ? null : (int) $id;
    }

    public function setTypeId(int $typeId): static
    {
        return $this->setData(self::TYPE_ID, $typeId);
    }

    public function getCode(): string
    {
        return (string) $this->getData(self::CODE);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::CODE, $code);
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
}
