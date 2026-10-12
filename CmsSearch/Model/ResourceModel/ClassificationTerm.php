<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Thedevkitchen\CmsSearch\Api\Data\ClassificationTermInterface as Term;
use Thedevkitchen\CmsSearch\Model\Db\Tables;

class ClassificationTerm extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init(Tables::CLASSIFICATION_TERM, Term::TERM_ID);
    }

    public function codeExists(int $websiteId, string $code, ?int $exceptTermId): bool
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), Term::TERM_ID)
            ->where('website_id = ?', $websiteId)
            ->where('code = ?', $code);
        if ($exceptTermId !== null) {
            $select->where('term_id <> ?', $exceptTermId);
        }
        return $connection->fetchOne($select) !== false;
    }

    public function getIdByCode(int $websiteId, string $code): ?int
    {
        $connection = $this->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), Term::TERM_ID)
                ->where('website_id = ?', $websiteId)
                ->where('code = ?', $code)
        );
        return $id === false ? null : (int) $id;
    }

    /**
     * Current database state of a term (not the object in memory).
     *
     * @return array{term_id:int, website_id:int, parent_id:?int, code:string, path:string, level:int, is_active:bool}|null
     */
    public function getStoredState(int $termId): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->getMainTable(), ['term_id', 'website_id', 'parent_id', 'code', 'path', 'level', 'is_active'])
                ->where('term_id = ?', $termId)
        );
        if (!$row) {
            return null;
        }
        return [
            'term_id' => (int) $row['term_id'],
            'website_id' => (int) $row['website_id'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'code' => (string) $row['code'],
            'path' => (string) $row['path'],
            'level' => (int) $row['level'],
            'is_active' => (bool) (int) $row['is_active'],
        ];
    }

    /**
     * The term stored with $path and all of its descendants, parents first.
     *
     * @return list<array{term_id:int, path:string, level:int}>
     */
    public function fetchSubtree(string $path): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['term_id', 'path', 'level'])
            ->where('path = ?', $path)
            // paths contain only digits and '/', so no LIKE wildcard escaping is needed
            ->orWhere('path LIKE ?', $path . '/%')
            ->order('level ASC');
        return array_map(
            static fn (array $row): array => [
                'term_id' => (int) $row['term_id'],
                'path' => (string) $row['path'],
                'level' => (int) $row['level'],
            ],
            $connection->fetchAll($select)
        );
    }

    public function updatePathLevel(int $termId, string $path, int $level): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            ['path' => $path, 'level' => $level],
            ['term_id = ?' => $termId]
        );
    }

    /**
     * @return list<array{term_id:int, parent_id:?int, code:string, path:string, level:int, sort_order:int, is_active:bool}>
     */
    public function fetchTreeRows(int $websiteId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['term_id', 'parent_id', 'code', 'path', 'level', 'sort_order', 'is_active'])
            ->where('website_id = ?', $websiteId)
            ->order(['level ASC', 'sort_order ASC', 'term_id ASC']);
        return array_map(
            static fn (array $row): array => [
                'term_id' => (int) $row['term_id'],
                'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'code' => (string) $row['code'],
                'path' => (string) $row['path'],
                'level' => (int) $row['level'],
                'sort_order' => (int) $row['sort_order'],
                'is_active' => (bool) (int) $row['is_active'],
            ],
            $connection->fetchAll($select)
        );
    }

    /**
     * @param int[] $termIds
     * @return array<int, array<int, string>> term id => store id => label
     */
    public function fetchLabels(array $termIds): array
    {
        if (!$termIds) {
            return [];
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(Tables::CLASSIFICATION_TERM_LABEL), ['term_id', 'store_id', 'label'])
            ->where('term_id IN (?)', array_map('intval', $termIds));
        $labels = [];
        foreach ($connection->fetchAll($select) as $row) {
            $labels[(int) $row['term_id']][(int) $row['store_id']] = (string) $row['label'];
        }
        return $labels;
    }

    protected function _afterLoad(AbstractModel $object)
    {
        if ($object->getId()) {
            $termId = (int) $object->getId();
            $object->setData(Term::LABELS, $this->fetchLabels([$termId])[$termId] ?? []);
        }
        return parent::_afterLoad($object);
    }

    protected function _afterSave(AbstractModel $object)
    {
        $labels = $object->getData(Term::LABELS);
        if (is_array($labels)) {
            $this->replaceLabels((int) $object->getId(), $labels);
        }
        return parent::_afterSave($object);
    }

    /**
     * @param array<int, string> $labels
     */
    private function replaceLabels(int $termId, array $labels): void
    {
        $connection = $this->getConnection();
        $table = $this->getTable(Tables::CLASSIFICATION_TERM_LABEL);
        $connection->delete($table, ['term_id = ?' => $termId]);
        $rows = [];
        foreach ($labels as $storeId => $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $rows[] = ['term_id' => $termId, 'store_id' => (int) $storeId, 'label' => $label];
            }
        }
        if ($rows) {
            $connection->insertMultiple($table, $rows);
        }
    }
}
