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
use Thedevkitchen\CmsSearch\Model\Indexer\FullReindexer;
use Thedevkitchen\CmsSearch\Model\Indexer\PageIdKeysetTrait;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;

/**
 * bin/magento thedevkitchen:cmssearch:reindex [--entity=page|all] [--store=ID] [--sync]
 *
 * Default (asynchronous): initial load and recovery (e.g. after the broker was down, TS-37): publishes one "page save"
 * message per CMS page; the thedevkitchen.cmssearch.reindex consumer does the work. Nothing is indexed in this process.
 * --sync (operators only, CLI only): rebuilds the index in this process - a new index per store view, filled, refreshed,
 * then an atomic alias switch (zero downtime) - and publishes nothing; without --store it also drops the indices of
 * deleted store views. Stop the consumer first, or publish the pages again afterwards (see FullReindexer).
 * Categories arrive with Plan 6.
 */
class ReindexCommand extends Command
{
    use PageIdKeysetTrait;

    public const NAME = 'thedevkitchen:cmssearch:reindex';
    private const SUPPORTED_ENTITIES = ['page', 'all'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly MessageSender $sender,
        private readonly FullReindexer $fullReindexer,
        private readonly int $batchSize = 500
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Publishes CMS Search reindex messages for CMS pages (processed asynchronously by the thedevkitchen.cmssearch.reindex consumer), or rebuilds the index with --sync')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'page or all (all = every supported entity: page)', 'all')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view id to limit the reindex to (default: every store view)')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Rebuild in this process: new index per store view + atomic alias switch (stop the consumer first, or run again without --sync afterwards)');
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

        if ($input->getOption('sync')) {
            return $this->sync($storeId, $output);
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

    private function sync(?int $storeId, OutputInterface $output): int
    {
        try {
            $result = $this->fullReindexer->rebuild($storeId, static function (string $line) use ($output): void {
                $output->writeln($line, OutputInterface::VERBOSITY_VERBOSE);
            });
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        foreach ($result['stores'] as $store) {
            $output->writeln(sprintf(
                'Store %d: %d page document(s) indexed into %s (removed: %s).',
                $store['store_id'],
                $store['documents'],
                $store['index'],
                $store['removed'] === [] ? 'none' : implode(', ', $store['removed'])
            ));
            if ($store['leftovers'] !== []) {
                $output->writeln(sprintf(
                    '<comment>Store %d: the old version(s) %s could not be deleted (logged); the next --sync removes them.</comment>',
                    $store['store_id'],
                    implode(', ', $store['leftovers'])
                ));
            }
        }
        if ($result['orphans'] !== []) {
            $output->writeln('Dropped the index(es) of deleted store views: ' . implode(', ', $result['orphans']) . '.');
        }
        return Cli::RETURN_SUCCESS;
    }
}
