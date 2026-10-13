<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Reference;

use Magento\Framework\App\ResourceConnection;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetInstance;
use Thedevkitchen\CmsSearch\Model\Reference\Data\WidgetPageGroup;

/**
 * Widget instances that publish block content (CMS Block and Dynamic Blocks widgets) with their page groups.
 * Read fresh on every call: the consumer is long-running and instances change in the admin.
 */
class WidgetInstanceReader
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return list<WidgetInstance> by instance id (2 queries)
     */
    public function all(): array
    {
        return $this->load(null);
    }

    public function get(int $instanceId): ?WidgetInstance
    {
        return $instanceId > 0 ? ($this->load($instanceId)[0] ?? null) : null;
    }

    /**
     * @return list<WidgetInstance>
     */
    private function load(?int $instanceId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('widget_instance'),
                ['instance_id', 'instance_type', 'theme_id', 'store_ids', 'widget_parameters']
            )
            ->order('instance_id ASC');
        if ($instanceId !== null) {
            $select->where('instance_id = ?', $instanceId);
        }
        $instances = [];
        foreach ($connection->fetchAll($select) as $row) {
            $type = DirectiveParser::normalizeClass((string) $row['instance_type']);
            if (in_array($type, WidgetInstanceResolver::SUPPORTED_TYPES, true)) {
                $instances[(int) $row['instance_id']] = ['type' => $type] + $row;
            }
        }
        if ($instances === []) {
            return [];
        }
        $groups = [];
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName('widget_instance_page'),
                    ['instance_id', 'page_group', 'layout_handle', 'page_for', 'entities']
                )
                ->where('instance_id IN (?)', array_keys($instances))
                ->order('page_id ASC')
        );
        foreach ($rows as $row) {
            $groups[(int) $row['instance_id']][] = new WidgetPageGroup(
                (string) $row['page_group'],
                (string) $row['layout_handle'],
                (string) $row['page_for'],
                self::ids((string) $row['entities'], 1)
            );
        }
        $result = [];
        foreach ($instances as $id => $row) {
            $parameters = json_decode((string) $row['widget_parameters'], true);
            $result[] = new WidgetInstance(
                $id,
                $row['type'],
                (int) $row['theme_id'],
                self::ids((string) $row['store_ids'], 0),
                is_array($parameters) ? $parameters : [],
                $groups[$id] ?? []
            );
        }
        return $result;
    }

    /**
     * @return list<int> unique ids >= $min of a comma separated list (anything else is ignored)
     */
    private static function ids(string $list, int $min): array
    {
        $ids = [];
        foreach (explode(',', $list) as $part) {
            $part = trim($part);
            if (preg_match('/^[0-9]{1,10}$/', $part) === 1 && (int) $part >= $min && (int) $part <= ReindexMessage::MAX_ENTITY_ID) {
                $ids[(int) $part] = true;
            }
        }
        return array_keys($ids);
    }
}
