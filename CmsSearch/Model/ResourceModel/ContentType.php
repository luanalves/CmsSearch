<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Thedevkitchen\CmsSearch\Api\Data\ContentTypeInterface;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

class ContentType extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init(Tables::CONTENT_TYPE, ContentTypeInterface::TYPE_ID);
    }

    public function codeExists(string $code, ?int $exceptTypeId): bool
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ContentTypeInterface::TYPE_ID)
            ->where('code = ?', $code);
        if ($exceptTypeId !== null) {
            $select->where('type_id <> ?', $exceptTypeId);
        }
        return $connection->fetchOne($select) !== false;
    }

    public function getCodeById(int $typeId): ?string
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'code')
            ->where('type_id = ?', $typeId);
        $code = $connection->fetchOne($select);
        return $code === false ? null : (string) $code;
    }

    protected function _afterLoad(AbstractModel $object)
    {
        $object->setData(ContentTypeInterface::LABELS, $this->loadLabels((int) $object->getId()));
        return parent::_afterLoad($object);
    }

    protected function _afterSave(AbstractModel $object)
    {
        $labels = $object->getData(ContentTypeInterface::LABELS);
        if (is_array($labels)) {
            $this->replaceLabels((int) $object->getId(), $labels);
        }
        return parent::_afterSave($object);
    }

    /**
     * @return array<int, string>
     */
    private function loadLabels(int $typeId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(Tables::CONTENT_TYPE_LABEL), ['store_id', 'label'])
            ->where('type_id = ?', $typeId);
        $labels = [];
        foreach ($connection->fetchPairs($select) as $storeId => $label) {
            $labels[(int) $storeId] = (string) $label;
        }
        return $labels;
    }

    /**
     * @param array<int, string> $labels
     */
    private function replaceLabels(int $typeId, array $labels): void
    {
        $connection = $this->getConnection();
        $table = $this->getTable(Tables::CONTENT_TYPE_LABEL);
        $connection->delete($table, ['type_id = ?' => $typeId]);

        $rows = [];
        foreach ($labels as $storeId => $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $rows[] = ['type_id' => $typeId, 'store_id' => (int) $storeId, 'label' => $label];
            }
        }
        if ($rows) {
            $connection->insertMultiple($table, $rows);
        }
    }
}
