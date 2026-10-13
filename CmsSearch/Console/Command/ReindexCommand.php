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
use Thedevkitchen\CmsSearch\Model\Indexer\CatalogIdSource;
use Thedevkitchen\CmsSearch\Model\Indexer\FullReindexer;
use Thedevkitchen\CmsSearch\Model\Indexer\PageIdKeysetTrait;
use Thedevkitchen\CmsSearch\Model\Queue\MessageSender;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexMessage;

/**
 * bin/magento thedevkitchen:cmssearch:reindex [--entity=page|category|product|all] [--store=ID] [--sync]
 *
 * Default (asynchronous): initial load and recovery (e.g. after the broker was down, TS-37): publishes one "save"
 * message per CMS page, category and/or product (all = the three, in that order); the thedevkitchen.cmssearch.reindex
 * consumer does the work (page and category documents with their block text, product block text for the native
 * product index). Ids are read in keyset batches of $batchSize and each batch is published before the next one is
 * read, so memory stays bounded however large the catalog is. Publishing stops at the first message the broker does
 * not take (exit 1, details in var/log/cmssearch_reindex.log): re-run the command once the broker is back.
 * --sync (operators only, CLI only): rebuilds the CMS search index in this process - pages AND categories into a new
 * index per store view, then an atomic alias switch - and publishes nothing; without --store it also drops the indices
 * of deleted store views. --entity=page|category|all all rebuild the whole CMS index; products live in the native
 * index and are refreshed asynchronously only (--sync --entity=product is refused). Stop the consumer first, or
 * publish again afterwards (see FullReindexer).
 */
class ReindexCommand extends Command
{
    use PageIdKeysetTrait;

    public const NAME = 'thedevkitchen:cmssearch:reindex';
    private const SUPPORTED_ENTITIES = ['page', 'category', 'product', 'all'];
    private const LABELS = [
        ReindexMessageInterface::ENTITY_PAGE => 'CMS pages',
        ReindexMessageInterface::ENTITY_CATEGORY => 'categories',
        ReindexMessageInterface::ENTITY_PRODUCT => 'products',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly MessageSender $sender,
        private readonly FullReindexer $fullReindexer,
        private readonly CatalogIdSource $catalogIdSource,
        private readonly int $batchSize = 500
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Publishes CMS Search reindex messages for CMS pages, categories and products (processed asynchronously by the thedevkitchen.cmssearch.reindex consumer), or rebuilds the CMS index with --sync')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'page, category, product or all (all = page, category and product)', 'all')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view id to limit the reindex to (default: every store view)')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Rebuild the CMS index (pages and categories) in this process: new index per store view + atomic alias switch (stop the consumer first, or run again without --sync afterwards)');
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
            if ($entity === ReindexMessageInterface::ENTITY_PRODUCT) {
                $output->writeln('<error>--sync rebuilds the CMS search index (pages and categories). Products are refreshed asynchronously: run --entity=product without --sync.</error>');
                return Cli::RETURN_FAILURE;
            }
            return $this->sync($storeId, $output);
        }

        $types = $entity === 'all' ? array_keys(self::LABELS) : [$entity];
        foreach ($types as $type) {
            [$published, $failed] = $this->publish($type, $storeId, $output);
            $output->writeln(sprintf(
                'Published %d reindex message(s) for %s (store: %s).',
                $published,
                self::LABELS[$type],
                $storeId === null ? 'all' : (string) $storeId
            ));
            if ($failed > 0) {
                $output->writeln(sprintf(
                    '<error>%d message(s) could not be published; publishing stopped at the first failure (run the command again once the broker is reachable); see var/log/cmssearch_reindex.log.</error>',
                    $failed
                ));
                return Cli::RETURN_FAILURE;
            }
        }
        return Cli::RETURN_SUCCESS;
    }

    /**
     * Publishes one save message per id of $type, batch by batch; stops at the first failed send.
     *
     * @return array{0: int, 1: int} published, failed (0 or 1)
     */
    private function publish(string $type, ?int $storeId, OutputInterface $output): array
    {
        $published = 0;
        $lastId = 0;
        do {
            $ids = $this->nextIds($type, $lastId);
            foreach ($ids as $id) {
                $message = new ReindexMessage($type, $id, ReindexMessageInterface::ACTION_SAVE, time(), $storeId);
                if (!$this->sender->send($message)) {
                    return [$published, 1];
                }
                $published++;
                $lastId = $id;
            }
            if ($ids !== []) {
                $output->writeln(
                    sprintf('Batch done: %d %s message(s) published so far (last id %d).', $published, $type, $lastId),
                    OutputInterface::VERBOSITY_VERBOSE
                );
            }
        } while (count($ids) === $this->batchSize);
        return [$published, 0];
    }

    /**
     * @return int[] next keyset batch of ids of $type after $afterId, ascending
     */
    private function nextIds(string $type, int $afterId): array
    {
        return match ($type) {
            ReindexMessageInterface::ENTITY_PAGE => $this->nextPageIds($afterId),
            ReindexMessageInterface::ENTITY_CATEGORY => $this->catalogIdSource->nextCategoryIds($afterId, $this->batchSize),
            ReindexMessageInterface::ENTITY_PRODUCT => $this->catalogIdSource->nextProductIds($afterId, $this->batchSize),
        };
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
            if (isset($store['categories'])) {
                $output->writeln(sprintf(
                    'Store %d: %d category document(s) indexed into %s.',
                    $store['store_id'],
                    $store['categories'],
                    $store['index']
                ));
            }
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
