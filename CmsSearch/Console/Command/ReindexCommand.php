<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;

/**
 * bin/magento thedevkitchen:cmssearch:reindex [--entity=page|all] [--store=ID]
 *
 * Initial load and recovery (e.g. after the broker was down, TS-37): publishes one "page save" message per CMS
 * page; the thedevkitchen.cmssearch.reindex consumer does the work. Nothing is indexed in this process.
 * Plan 4 adds the index rebuild options (--sync); categories arrive with Plan 6.
 */
class ReindexCommand extends Command
{
    public const NAME = 'thedevkitchen:cmssearch:reindex';
    private const SUPPORTED_ENTITIES = ['page', 'all'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly MessageSender $sender,
        private readonly int $batchSize = 500
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Publishes CMS Search reindex messages for CMS pages (processed asynchronously by the thedevkitchen.cmssearch.reindex consumer)')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'page or all (all = every supported entity: page)', 'all')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view id to limit the reindex to (default: every store view)');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entity = (string) $input->getOption('entity');
        if (!in_array($entity, self::SUPPORTED_ENTITIES, true)) {
            $output->writeln(sprintf(
                '<error>Unsupported --entity "%s". Supported: %s.</error>',
                $entity,
                implode(', ', self::SUPPORTED_ENTITIES)
            ));
            return Cli::RETURN_FAILURE;
        }

        $storeId = null;
        $store = $input->getOption('store');
        if ($store !== null) {
            if (!is_string($store) || preg_match('/^[1-9][0-9]{0,4}$/', $store) !== 1) {
                $output->writeln('<error>The --store option must be a store view id (a positive whole number).</error>');
                return Cli::RETURN_FAILURE;
            }
            try {
                $storeId = (int) $this->storeManager->getStore((int) $store)->getId();
            } catch (NoSuchEntityException) {
                $output->writeln(sprintf('<error>Store view "%s" does not exist.</error>', $store));
                return Cli::RETURN_FAILURE;
            }
        }

        $published = 0;
        $failed = 0;
        $lastId = 0;
        do {
            $pageIds = $this->nextPageIds($lastId);
            foreach ($pageIds as $pageId) {
                $message = new ReindexMessage(
                    ReindexMessageInterface::ENTITY_PAGE,
                    $pageId,
                    ReindexMessageInterface::ACTION_SAVE,
                    time(),
                    $storeId
                );
                if ($this->sender->send($message)) {
                    $published++;
                } else {
                    $failed++;
                }
                $lastId = $pageId;
            }
            if ($pageIds !== []) {
                $output->writeln(
                    sprintf('Batch done: %d published, %d failed so far (last page id %d).', $published, $failed, $lastId),
                    OutputInterface::VERBOSITY_VERBOSE
                );
            }
        } while (count($pageIds) === $this->batchSize);

        $output->writeln(sprintf(
            'Published %d reindex message(s) for CMS pages (store: %s).',
            $published,
            $storeId === null ? 'all' : (string) $storeId
        ));
        if ($failed > 0) {
            $output->writeln(sprintf(
                '<error>%d message(s) could not be published; see var/log/cmssearch_reindex.log.</error>',
                $failed
            ));
            return Cli::RETURN_FAILURE;
        }
        return Cli::RETURN_SUCCESS;
    }

    /**
     * @return int[] next batch of distinct page ids (keyset pagination; page_id is stable under Staging)
     */
    private function nextPageIds(int $afterId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from($this->resource->getTableName('cms_page'), 'page_id')
            ->where('page_id > ?', $afterId)
            ->order('page_id ASC')
            ->limit($this->batchSize);
        return array_map('intval', $connection->fetchCol($select));
    }
}
