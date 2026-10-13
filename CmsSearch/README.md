# Thedevkitchen_CmsSearch

Indexes CMS content in OpenSearch and shows it in tabs on the search result page. Specs: `specs/2026-10-11-cms-search/`. Plans: `docs/superpowers/plans/2026-10-11-cms-search-*.md`.

## Status

- Plan 1 delivered: editorial content type registry (declarative schema, repository/service contracts and validation, admin CRUD with per-store-view labels, granular ACL, delete blocked while CMS Pages are linked, dropdown option source).
- Plan 2 delivered: classification tree per website (admin tree, term form, move, delete rules, ACL), and the "Search" fieldset of the CMS Page form (content type + classification term), validated and persisted server-side by an observer inside the page-save transaction.
- Plan 3 delivered: asynchronous reindex queue (exclusive topic/queue/consumer `thedevkitchen.cmssearch.reindex` with a dead-letter queue), after-commit publishing from every write path, consumer handler with an attempt counter (cap 3), reindex command, configuration section.
- Plan 4 delivered: CMS page indexer - one OpenSearch index per store view behind a stable alias, documents built from the Staging-active page version (Page Builder content reduced to text), visibility rules, idempotent upserts/deletes from the reindex consumer, and `--sync` full rebuild with an atomic alias switch.
- Plan 5 delivered: search UI - tab bar with counters on `/catalogsearch/result/` (Products, Categories when category documents exist, one tab per active content type), cards with pagination and a hierarchical classification filter on CMS tabs, graceful fallbacks, six themes.
- Plan 6 delivered: block reference graph (Blocks and Dynamic Blocks published by CMS pages, product pages and category pages, through content directives, category landing pages and widget instances), block text in page documents, category documents (Categories tab), block text of product pages in the native product index (`cmssearch_block_text`, schedule mode through the `catalogsearch_fulltext` mview), reindex requests from block, Dynamic Block, widget instance, product and category writes, Staging post-processor for blocks, products and categories.
- Plan 7 delivered (hardening and release): the consumer re-reads a changed store list (database fingerprint) and checks each index alias once per process; page content is extracted once per message for all store views; `--sync` reads only the pages of the store view it rebuilds; block and widget instance messages stream their hosts in keyset pages and bounded chunks; Dynamic Block links edited from cart/catalog price rules ask for a reindex (only the links that changed); one message per applied block/page in a Staging run; request-scoped storefront services implement `ResetAfterRequestInterface` (defensive, contract test); a real full-page cache/Varnish check of the search result page; deploy checklist and release notes.
- i18n: `en_US.csv` and `pt_BR.csv` cover Plans 1-5 (Plans 4, 6 and 7 add no translatable phrase; console descriptions and log lines are not translated). Test-only phrases are not in the CSVs.

All seven plans are delivered. See "Deploy checklist" and "Release notes (v1)" before "Pending items (backlog)".

## Content types

- Admin: Content > CMS Search > Content Types.
- ACL: `Thedevkitchen_CmsSearch::content_types` (view), `::content_types_create`, `::content_types_edit`, `::content_types_delete`.
- A type linked to CMS Pages cannot be deleted; deactivate it instead. No type is seeded: create them in the admin.
- The `page_classification` foreign key to the content type is `NO ACTION` (InnoDB enforces it like RESTRICT; `schema.xsd` rejects the `RESTRICT` keyword). It is the database safety net behind the application-level guard.

## Classification

- Admin: Content > CMS Search > Classification. Pick a website, then "Add Root Term", "Add child", "Edit".
- One tree per website. A term has a code (lowercase letters, digits, underscore; unique in the website; immutable), a default label plus optional labels for the store views of its website, a sort order among its siblings and an active flag. Saving an existing term with no `parent_id` in the post keeps its parent; an explicit empty `parent_id` moves it to the root.
- Move: change "Parent term" in the term form. The term and all of its descendants get a new `path`/`level` in one transaction. A term cannot move under itself or a descendant, nor to another website; the tree has at most 10 levels.
- Delete is blocked while the term has child terms or linked CMS Pages (the message lists up to 5 pages). Deactivation is always allowed; a deactivated term hides its whole branch from the CMS Page form. The tree shows descendants of a disabled term as "Enabled (inactive parent)" (`effective_active` = the term and all ancestors are active).
- ACL: `Thedevkitchen_CmsSearch::classification` (read-only tree and forms) and `::classification_manage` (create, edit, move, activate, delete).
- Database: `thedevkitchen_cmssearch_classification_term` (`website_id` FK CASCADE, `parent_id` self FK NO ACTION) and `..._term_label`; `page_classification.term_id` has an FK NO ACTION. Consequence of the NO ACTION self FK: deleting a whole website whose tree has more than one level fails at the database level; delete or move its terms first (website deletion is also blocked while pages are linked to its terms).

## CMS Page form: "Search" fieldset

- Two single-select fields: "Content Type" (active types; a page linked to a type that was later deactivated keeps it, shown as "(inactive)") and "Search Classification" (active terms of the websites of the page's store views; All Store Views = every website).
- Both filled or both empty. A page without type or term stays saveable; it is simply not indexed.
- Validated on the server (`Model\PageClassification\AssignmentValidator`): type and term must exist, the term must belong to a website of the page's store views, inactive values are accepted only when they are the page's current values.
- Persisted by an observer on `cms_page_save_after` through `PageClassificationRepositoryInterface` (keyed by `page_id`, stable under Staging). The event fires inside the page-save transaction, so an invalid pair rolls the page save back and the admin sees the message. The fieldset counts as submitted only when the form marker is exactly `1`; saves without it (REST, grid inline edit, mass actions, Staging versions, stray `type`/`term` keys) leave the link untouched.
- "Save & Duplicate" (`Magento\Cms\Controller\Adminhtml\Page\Save::processResultRedirect`) builds the copy from the same posted data, marker included, so the copy gets the same type/term pair (verified in the core source; no automated test).
- Wiring: the fieldset is added by its own modifier pool passed to `Magento\Cms\Model\Page\DataProvider`, plus an `afterGetData` plugin for the values. A third-party module that configures the same `pool` argument would conflict.
- `events.xml` changes need `bin/magento cache:flush`; `cache:clean config` is not enough.
- The Staging "Schedule New Update" form has no "Search" fieldset on purpose: type and term belong to the page, not to a version.
- Any admin with `Magento_Cms::save` can set the two fields; no extra ACL resource.

## Reindex queue

Every write that can change what the search shows asks for an asynchronous reindex (D10). Nothing is indexed during the admin/REST request; the consumer does the work later. No refresh interval or SLA is imposed.

| Item | Value |
|---|---|
| Topic = queue = consumer | `thedevkitchen.cmssearch.reindex` |
| Exchange | `thedevkitchen.cmssearch` (topic) |
| Dead-letter exchange / routing key | `thedevkitchen.cmssearch.dlx` / `thedevkitchen.cmssearch.reindex.dead` (the main queue's `x-dead-letter-*` arguments, declared in the binding arguments of `etc/queue_topology.xml`) |
| Dead-letter queue (DLQ) | `thedevkitchen.cmssearch.reindex.dlq` (nothing consumes it) |
| Log | `var/log/cmssearch_reindex.log`: per message (DEBUG) `reindexed <type> <id> (<action>): N page(s), S store view(s), U upserted, D deleted` and `processed {json}`; `retry scheduled`, `dead-lettered ...`, `publish failed`, `reindex request dropped`, `shadowed store-0 pages of page N not looked up` (ids and counts only, never content) |
| Setting | Stores > Configuration > Thedevkitchen > CMS Search > Reindex Queue > Maximum Attempts (`thedevkitchen_cmssearch/queue/max_attempts`, default 3, whole number 1-10, global); ACL `Thedevkitchen_CmsSearch::config` |

Exclusive: nothing else binds, publishes or consumes these names (unit contract `Test/Unit/Queue/QueueTopologyContractTest` scans `vendor` and `app/code`; `integration/test_cms_search_queue.sh` checks the broker).

### Message

The topic's request type is `string`; the body is one JSON object, scalars only, no content:
`{"entity_type":"page","entity_id":12,"action":"save","store_id":null,"website_id":null,"timestamp":1760000000,"attempt":0}`.
Entity types and actions: `page` save|delete|staging; `content_type` save|delete; `classification_term` save|delete (a term save means "the term or its place in the tree changed": the consumer reindexes the pages of the term's CURRENT subtree, so a move sends one message). Plan 6 adds `block`, `dynamic_block`, `widget_instance`, `product` and `category` (save|delete; the consumer expands blocks and widget instances to their hosts). The action is a hint; the consumer reads the current state.

### Who publishes (always after the outermost commit)

- `ContentTypeRepository` save/delete; `ClassificationTermRepository` save (create, label, sort order, active, move) and delete; `PageClassificationRepository` assign/unassign.
- `Observer\PublishPageReindex` on `cms_page_save_after` / `cms_page_delete_after`: admin form, REST, grid inline edit, mass actions, Save & Duplicate, Staging version schedule/update/remove.
- `Model\Staging\PostProcessor\PageVersionApplied`, a post-processor of `Magento\Staging\Model\StagingApplier` (cron `staging_apply_version`): scheduled page content that starts or ends -> `page`/`staging` per `page_id`.
- Inside a database transaction the request waits in `Model\Queue\PendingMessages`; `Plugin\Framework\DB\FlushReindexMessagesPlugin` (on `Magento\Framework\DB\Adapter\AdapterInterface`, like core's `execute_commit_callbacks`) sends it after the outermost commit and drops it on rollback. Identical requests of one transaction are merged: a page save with the Search fieldset sends one message. Outside a transaction the request is sent at once.
- The publisher never throws: if the broker is down the save still succeeds and `publish failed {...}` is logged; recover with the reindex command.
- Not published, by design: store view/website deletion (index lifecycle, see the runbooks); the test fixture's raw-SQL commands (`type-purge`, `term-purge`, `seed-link`, `link-page`, `unlink-page`) and the Cypress cleanup request, which bypass the repositories.

### Consumer, retry and DLQ

- Handler `Model\Queue\ReindexHandler::process`; the work is delegated to `Model\Queue\ReindexProcessorInterface`, bound in `etc/di.xml` to `Model\Queue\HostReindexProcessor` (Plan 4): it resolves the affected hosts (`AffectedHosts`: pages streamed in chunks of 1000 by `AffectedPages`, plus the hosts of blocks, widget instances, products and categories through the reference graph), the store views (the message store view or all, restricted to the message website for terms), adds the store-0 page a changed page shadows (same URL key), and calls `HostReindexer`, which writes the pages of a batch to ALL their store views with ONE `PageIndexer` call (a page's content is extracted once per message, not once per store view) and, per store view, host type and batch, refreshes the reference graph and then writes the page documents (`PageIndexer`), the category documents (`CategoryIndexer`) or the product block text (`ProductBlockTextWriter`), graph refresh and write in ONE database transaction (a failed write rolls the new graph back, so the retry still finds the old hosts of a deleted or re-placed widget or block). Missing page/type/term/store view = success. Only the consumer (and the CLI) may use the processor or the index writers (`Test/Unit/Queue/AsyncOnlyContractTest`).
- Per process: before each message `Model\Indexer\StoreListRefresher` compares the store list (store views, groups, active flag, root category) with the database (one small query) and calls `StoreManager::reinitStores()` only on a difference. The fingerprint of the last handled database state is kept in the process and in the Magento cache (key `thedevkitchen_cmssearch_store_fingerprint`, no tags), so a consumer process spawned later by cron does not repeat the reinit or the warning for a state already handled; `IndexManager` checks each index alias once per process and forgets it after a failed write or an orphan cleanup.
- AMQP has no automatic retry (the framework consumer rejects with `requeue=false`). The handler republishes a failed message with `attempt + 1` until Maximum Attempts tries (default 3 = first try + 2 retries), then throws and RabbitMQ dead-letters it into the DLQ. Payloads that can never succeed go straight to the DLQ. PHP errors in the processor count as transient failures. Retries are immediate (republished at once, no backoff).
- Framework behaviour to know: a message without a `message_id` property is acknowledged and dropped (our publisher always sets one); the same `message_id` delivered twice is processed once (`queue_lock`).
- Idempotent: duplicate and out-of-order messages are safe; a second instance of the consumer is safe (do not configure `multiple_processes` for it anyway).
- Cost of a retry: a failed message is replayed WHOLE. A content type or term message fans out to all its pages (up to 32 767), so a failure late in a large fan-out rewrites every page again on each retry (idempotent, but up to Maximum Attempts times the work).
- Refresh (no freshness SLA): the consumer NEVER waits for an OpenSearch refresh (incremental bulk requests carry no `refresh` parameter; `require_alias=true` keeps a write from auto-creating an index). A change becomes searchable at the next periodic refresh (`refresh_interval` default 1 s; an index idle for 30 s is refreshed by its next search). Only `--sync` (FullReindexer) refreshes, once, before the alias switch. Tests that search right after consuming call `php integration/support/cmssearch_fixture.php index-refresh <store_id|all>` (or `POST {alias}/_refresh`). Measured with 7 store views: about 3.9 s per page message while it waited for refreshes, about 0.02-0.03 s per page message without.

### Running the consumer

- Production: the native cron job `consumers_runner` (group `consumers`) starts it. This project's `app/etc/env.php` has no `cron_consumers_runner` key, so every consumer runs by cron with the defaults (`cron_run=true`, `max_messages=10000`; this consumer declares `maxMessages="1000"`, so it exits after 1000 messages and the next cron run restarts it). `bin/magento cron:run` must be scheduled.
- Deploy step when an environment restricts consumers: add `'thedevkitchen.cmssearch.reindex'` to `cron_consumers_runner/consumers` (edit `app/etc/env.php`, or the deployment's env template; there is no `setup:config:set` option for it; never from the module):
  ```php
  'cron_consumers_runner' => [
      'cron_run' => true,
      'max_messages' => 10000,
      'consumers' => [
          // ...consumers already listed...
          'thedevkitchen.cmssearch.reindex',
      ],
  ],
  ```
- Manual (development, tests, recovery): `bin/magento queue:consumers:start thedevkitchen.cmssearch.reindex [--max-messages=N]`. With `queue/consumers_wait_for_messages = 1` (this project) `--max-messages=N` waits until N messages were handled; without it the consumer runs until stopped.
- Local Warden: the php-fpm container has no crontab, so no consumer runs by itself. Messages published by admin work, Cypress and the curl suites wait in the queue until you start the consumer.

### Monitoring and DLQ operations (Warden: prefix `warden env exec -T rabbitmq`)

- Depth and consumers: `rabbitmqctl list_queues name messages consumers | grep thedevkitchen.cmssearch`
- DLX arguments: `rabbitmqctl list_queues name arguments | grep thedevkitchen.cmssearch`
- Inspect the DLQ without removing messages: `rabbitmqadmin get queue=thedevkitchen.cmssearch.reindex.dlq ackmode=ack_requeue_true count=20`
- Replay after fixing the cause: `bin/magento thedevkitchen:cmssearch:reindex --entity=page [--store=ID]` (one message per page), then empty the DLQ: `rabbitmqadmin purge queue name=thedevkitchen.cmssearch.reindex.dlq`
- Magento's view: `bin/magento queue:consumers:list | grep thedevkitchen`
- Errors and retries: `var/log/cmssearch_reindex.log`
- Log rotation: Magento does not rotate `var/log/*.log` and this file gets two DEBUG lines per message. Rotate it with the host's logrotate, e.g. `/var/www/html/var/log/cmssearch_reindex.log { daily rotate 14 compress missingok notifempty copytruncate }` (`copytruncate`: the consumer keeps the file open), or raise the level of the `Thedevkitchen\CmsSearch\Model\Queue\Log\Handler` virtual type to drop the DEBUG lines.
- Runbook after deleting a store view or a website (nothing is published for them): run `bin/magento thedevkitchen:cmssearch:reindex --sync` WITHOUT `--store`; it drops the indices of store views that no longer exist (`IndexManager::dropOrphans`). A new store view gets its index at its first message; fill it with `bin/magento thedevkitchen:cmssearch:reindex --store=<id>`. Before every message the consumer compares the store list (store views and the root category of their group) with the database and reinitialises the StoreManager when it changed, so no restart is needed after adding or deleting a store view. If the store scopes are dumped to `app/etc/config.php` (`app:config:dump scopes`) the file wins over the database: the first process to see a database change reinitialises once and logs a warning in `cmssearch_reindex.log`; later processes find the fingerprint in the cache and stay quiet (if the cache entry expires or the cache is flushed, one more reinit and warning follow); update the file and restart the consumer.

### Reindex command

`bin/magento thedevkitchen:cmssearch:reindex [--entity=page|category|product|all] [--store=ID]` publishes one `page`/`save` message per CMS page (keyset batches of 500 page ids) and exits; the consumer does the work. Exit code 1 for an unsupported entity, an invalid or unknown store view, or when a message could not be published. `--entity=category` and `--entity=product` publish one message per category / product (the consumer expands blocks and widget instances to their hosts); `--entity=all` (the default) publishes pages, categories and products.

`--sync` (operators only, never from a web request) rebuilds instead of publishing: per store view a new index version is filled with every visible page (written through a `{version}_build` alias), refreshed once, the alias is switched atomically and the previous versions are deleted; without `--store` it also drops the indices of store views that no longer exist (`dropOrphans`). A per-store-view database lock (`thedevkitchen_cmssearch_rebuild_<id>`) makes a second `--sync` of the same store view fail fast without touching anything. `--sync` rebuilds pages AND categories (and refreshes their reference graph) into the new version, so the alias switch never drops category documents; `--sync --entity=product` is refused (products live in the native index and are refreshed asynchronously only). Output per store: `Store <id>: <n> page document(s) indexed into <index> (removed: <names|none>).` followed by `Store <id>: <n> category document(s) indexed into <index>.`; `-v` prints one progress line per batch. If a store view fails, the live alias of that store view is untouched, the half-built index is dropped, and the message lists the store views already rebuilt.

`--sync` reads only the pages assigned to the store view being rebuilt (or to All Store Views), in the current Staging version: its cost grows with the pages of that store view, not with every page of the install.

Which one to use: the plain command (asynchronous messages) is cheap and safe at any time but only as fast as the consumer; `--sync` is for first fills, mapping changes, orphan cleanup and recovery. Examples:

```
bin/magento thedevkitchen:cmssearch:reindex                        # one page message per CMS page, every store view
bin/magento thedevkitchen:cmssearch:reindex --entity=page --store=3
bin/magento thedevkitchen:cmssearch:reindex --sync                 # rebuild every store view + drop orphans
bin/magento thedevkitchen:cmssearch:reindex --entity=category    # one message per category
bin/magento thedevkitchen:cmssearch:reindex --entity=product     # one message per product (block text of product pages)
bin/magento thedevkitchen:cmssearch:reindex --sync --store=2 -v
```

Deploy order of Plan 6: `setup:upgrade --keep-generated`, `cache:flush`, `thedevkitchen:cmssearch:reindex --sync` (consumer stopped), then `thedevkitchen:cmssearch:reindex --entity=product` and let the consumer and the `index` cron group run (details in "Release gate and deployment order").

Messages processed by the consumer while a rebuild runs are applied to the previous version and are lost at the switch (no catch-up by design: every change is a new queue request). Run `--sync` with the consumer stopped (cron `consumers_runner` included), or run `reindex --entity=page` afterwards.

DEPLOY NOTE: after a constructor change of a console command (this release changed `ReindexCommand`) the generated interceptor is stale and breaks EVERY application command of `bin/magento` (only `setup:*`, `admin:*`, `info:*`, `i18n:*` are listed). Developer mode: delete `generated/code/Thedevkitchen/CmsSearch/Console/Command/ReindexCommand/`, run `bin/magento cache:flush` and any `bin/magento` command (it is regenerated). Production: `setup:di:compile` as in the normal deploy.

### Changing the topology

`bin/magento setup:upgrade` declares exchanges, queues and bindings. RabbitMQ refuses to redeclare an existing queue with different arguments (`PRECONDITION_FAILED`): changing the dead-letter arguments requires deleting the queue first (development only) or a planned migration (production).

### Known limits

- Saving a content type or a classification term publishes one message for the entity; the consumer expands it to every linked page (content type) or to the pages of the term's current subtree, so one save can fan out to many pages.
- Content type and term saves publish on every save, including unchanged ones.
- Store view and website deletion publish nothing (see the runbook above: `--sync` without `--store`). Blocks, dynamic blocks, widget instances, products and categories publish through the Plan 6 hooks (see "Block references and enrichment").
- Shadowed store-0 pages (Magento serves a store-view page before the store-0 page with the same URL key): a page save reindexes the store-0 page of its current key (consumer side); a rename away from a key or a delete also publishes `page`/`save` for the active store-0 pages of the key it left (`Observer\PublishPageReindex`, one indexed query only on a rename or delete).
- Test fixtures use raw SQL and bypass events (no messages); so does the Cypress cleanup request.
- A consumer that is killed hard (OOM, SIGKILL, container restart, timeout kill) silently loses the message it was handling: Magento writes the `queue_lock` row before the handler runs, so the redelivered message is acknowledged as already locked and dropped. The DLQ does not see it. Recovery: `bin/magento thedevkitchen:cmssearch:reindex`.
- The reindex command does not stop at the first failed send: with the broker down every send is attempted (and counted), so a large site waits for the connection timeouts before the command exits 1.

### Staging

- Hooks: `cms_page_save_after`/`cms_page_delete_after` (every Staging operation on a page fires them) plus the `StagingApplier` post-processor `PageVersionApplied`. When the applier (cron `staging_apply_version`) activates a version it re-saves the page or block (`page`/`save` or `block`/`save`, merged per transaction); the post-processors `PageVersionApplied` and `ReferenceVersionApplied` skip an entity whose re-save in the same applier run already sent a message (`Model\Queue\SentMessageLog`, opened by `Plugin\Staging\OpenSentMessageLog` around `StagingApplier::execute()`, nested runs counted by depth, closed in a `finally`): one message per applied page or block. Only unscoped messages (no store view or website) are remembered. Products and categories re-saved without changes publish nothing, so their `staging` message stays. The real time-based `aroundExecute` plugin and the page path are covered by unit tests and `dev:di:info`; the references suite simulates the block path.
- Verified by `integration/test_cms_search_queue.sh`: scheduling and removing a version publish page messages, and the post-processor is registered once on the DI-built `StagingApplier` and publishes `staging` messages.
- Not run by the suite because it would apply every due update of the shared database and move the global Staging version: the real time-based activation. Manual check on a disposable environment with cron running: schedule an update two minutes ahead on a test page, wait for `staging_apply_version`, then `rabbitmqadmin get queue=thedevkitchen.cmssearch.reindex ackmode=ack_requeue_true` shows `"action":"staging"` for that page.

## CMS page index (Plan 4)

### Index layout

- One index per store view: alias `{prefix}_thedevkitchen_cmssearch_{store_id}`, versions `{alias}_v{n}` (n = 1 + highest existing version). `{prefix}` = `catalog/search/opensearch_index_prefix` (here `magento2`). The search side (Plan 5) reads the alias only. The module only creates, changes or deletes indices matching `{prefix}_thedevkitchen_cmssearch_<store>_v<n>`; native catalog indices (`{prefix}_product_*`) are never touched.
- Connection: `Model\Indexer\Engine\ClientProvider` builds its own OpenSearch client through Magento's `ClientResolver::create('opensearch')` with Magento's configured host/credentials (the module reads none itself), so the client can be reset after a node is marked dead, and it adds per-request timeouts derived from `catalog/search/opensearch_server_timeout` (Magento's shared connection has none). The engine must be OpenSearch, otherwise an `IndexingException` is thrown.
- The first message for a store view creates `_v1` with its alias in one atomic call. Incremental writes use `require_alias=true` (a write never auto-creates an index) and no `refresh` parameter (see Refresh). Baseline of this install: 7 aliases (`magento2_thedevkitchen_cmssearch_1` ... `_7`), each on one index; a version number above 1 only means a `--sync` ran.
- Document id `page_<page_id>`: every write is an idempotent upsert or delete (deleting a missing document is a no-op).

### Mapping and upgrade path

- `Model\Indexer\IndexDefinition`, `dynamic: strict`, `_meta.mapping_version` = `IndexDefinition::MAPPING_VERSION` (1). Fields: `entity_type`, `entity_id`, `source_types` (`["cms"]`), `content_type_code`, `classification_term_id`, `classification_ancestor_ids` (root first, the term included), `classification_path`, `title` (+ `title.keyword`), `text`, `summary`, `url`, `image`, `store_view_id`, `website_id`, `segment_ids` (the segments of the excluded Dynamic Blocks of the page, Plan 6), `updated_at`. `url`, `image` and `summary` are stored, not searchable.
- Analyzer per store view locale (`general/locale/code`): pt_BR `brazilian`, other pt `portuguese`, en `english`, es `spanish`, fr `french`, de `german`, it `italian`, nl `dutch`, otherwise `standard` (OpenSearch built-ins). Here: store 1 english, stores 2-7 brazilian. A locale change needs `--sync` (the analyzer is fixed at index creation).
- Upgrade path for a new (additive) field: (1) stop the consumers; (2) deploy the code with `MAPPING_VERSION` + 1 and the new field in `IndexDefinition`; (3) either add the field to the live indices by hand with `PUT {alias}/_mapping` (additive changes only; the request must also send the complete `_meta`: `module`, `mapping_version`, `store_id`, `locale`; the module has no automatic `ensureMapping()`) or, preferred, run `bin/magento thedevkitchen:cmssearch:reindex --sync`, which builds new versions with the new mapping (any non-additive change, e.g. a type or analyzer change, needs `--sync`); (4) start the consumers; (5) run `bin/magento thedevkitchen:cmssearch:reindex --entity=page` so documents carry the new field. With `dynamic: strict`, a document with a field missing from the live mapping is rejected: never deploy the writer before the mapping.

### Visibility rules

A page is indexed in a store view only when ALL of these hold; otherwise its document is deleted:

- The page exists. The CURRENT Staging version is read through `PageRepositoryInterface` by `page_id`; a scheduled version is not indexed before it starts.
- The page is active.
- The page is assigned to that store view or to All Store Views (store 0), and is not a shadowed store-0 page: Magento serves the store-view page before the store-0 page with the same URL key, so a store-0 page whose identifier also exists for the store view is not indexed there (this install: page 2 `home` store 0 vs pages 11-16 `home`).
- Its content type exists and is active.
- Its term exists, is effectively active (the term and every ancestor active) and belongs to the website of the store view (links can go stale after store view changes; this is checked at index time).

Affected pages per message: `page` = the page (plus the store-0 page it shadows); `content_type` = its linked pages; `classification_term` = pages linked to any term of the term's current subtree. A store id in the message limits the work to that store view; a deleted store view or page is a successful no-op.

### Document text

`Model\Indexer\TextExtractor` works on the raw content, never rendered: `<style>`, `<script>`, `<noscript>`, `<template>`, embeds and Page Builder image/video/map are removed; Page Builder `html` content is decoded (at most 2 levels); every `{{...}}` directive is removed from the text (block/widget text is added by the Plan 6 resolvers from these directives; the parsed directives are already exposed by `ExtractedContent::getDirectives()`). `text` = content heading + text, at most 65 536 characters; `summary` = meta description, else the text, cut to 300 characters at a word boundary; `image` = first `<img>` when it is `{{media url=...}}` or an absolute http(s) URL, else null. Fixtures: `Test/Unit/_files/pagebuilder/*.html` (anonymized Page Builder storage format, legacy WYSIWYG directives, UTF-8, style/script). The real pages of this install contain no Page Builder markup (only legacy HTML and `{{block}}`/`{{store}}`/`{{view}}` directives).

### Refresh semantics

The consumer never waits for an OpenSearch refresh. A change is searchable at the next periodic refresh (default `refresh_interval` 1 s), i.e. eventual consistency of about 1 s after the message is acknowledged, not at that moment. An index with no search for 30 s is "search-idle": its refreshes pause and the first search on it triggers a refresh. No refresh interval is configured by the module. Only `--sync` refreshes explicitly (once, before the alias switch). Tests that search right after consuming use the explicit helper `php integration/support/cmssearch_fixture.php index-refresh <store_id|all>` (`IndexManager::refresh()`).

### Logs

`var/log/cmssearch_reindex.log`: per message a DEBUG `reindexed <type> <id> (<action>): N page(s), S store view(s), U upserted, D deleted` and `processed {json}` (counts and ids only, never content); retries and failures are WARNING/ERROR. Magento does not rotate it: configure logrotate (see "Monitoring and DLQ operations") or raise the level of the log handler.

### Runbooks

- After deleting a store view or a website: `bin/magento thedevkitchen:cmssearch:reindex --sync` WITHOUT `--store` (drops the orphan indices). A new store view: `--store=<id>` (the consumer picks the new store view up from the database on its next message; with dumped scopes see Known limits).
- Outage (OpenSearch or broker down, messages in the DLQ): fix the cause, then DLQ -> `bin/magento thedevkitchen:cmssearch:reindex --entity=page` -> purge the DLQ (`rabbitmqadmin purge queue name=thedevkitchen.cmssearch.reindex.dlq`).
- Hard-killed consumer (the in-flight message is lost): `bin/magento thedevkitchen:cmssearch:reindex --entity=page`.
- Values baked into documents at index time (`web/*/base_url`, `web/default/cms_home_page`, store code / `web/url/use_store`, locale) publish nothing when they change: run `bin/magento thedevkitchen:cmssearch:reindex --sync` afterwards.
- `--sync`: run with the consumer stopped, or follow with `reindex --entity=page` (messages processed between the start of a build and the alias swap are applied to the old index).

### Known limits

- The consumer re-reads the store list per message when the database differs. With `app:config:dump scopes` the config file overrides the database, the in-memory list can stay different, and a warning is logged once per database change (the handled fingerprint is shared between consumer processes through the Magento cache; a flushed or expired entry costs one more reinit and warning); restart the consumer after updating the dumped scopes.
- Block, widget and banner changes scan the reference graph by keyset; rows re-inserted by `replace()` in the same message are met again by the scan (de-duplicated, so correct), which costs about 2x graph reads for a block used by very many hosts.
- Two DEBUG log lines per message.
- The first search on an idle index triggers a refresh (see Refresh semantics).
- No catch-up after the `--sync` swap (see Reindex command).

### Plan 6 handoff (delivered)

- Plan 6 (delivered, see "Block references and enrichment") adds block text to `text` from `ExtractedContent::getDirectives()` (widget instances of page group `all_pages` are NOT indexed on any host: user decision), fills `segment_ids`, and adds category documents (`entity_type=category`, `_id` `category_<id>`) and new host types to `AffectedHosts`. Any new field needs `IndexDefinition` + `MAPPING_VERSION` + the upgrade path above. Documents already carry `entity_type` (`page`), `content_type_code` and the classification fields for aggregations.

## Block references and enrichment (Plan 6)

- Hosts (D3): CMS page (`page`), product page (`product`), category page (`category`). Blocks, Dynamic Blocks and widgets have no content type, no classification and never a document of their own (D2); their text only enriches the host.
- Graph `thedevkitchen_cmssearch_block_reference` (store view, host, block kind/id/identifier, source `content`|`widget` + widget instance id, depth, segment ids), rebuilt per host and store view with delete + insert in one transaction, before every reindex of that host. Columns beyond the spec: `block_identifier` (a block created or renamed later still finds the hosts that name it), `source`/`source_id` (a deleted widget instance still finds its old hosts), `depth` (nested blocks; text is resolved from depth 0).
- Sources: `{{widget type="Magento\Cms\Block\Widget\Block" block_id=...}}`, `{{block id=...}}`, `{{block class="Magento\Cms\Block\Block" block_id=...}}`, `{{widget type="Magento\Banner\Block\Widget\Banner" banner_ids=... display_mode="fixed"}}` in page content, product description / short description and category description; the category landing page block when the display mode is `PAGE` or `PRODUCTS_AND_PAGE`; widget instances of the store view's theme (no theme inheritance) and store views. Placements (user decisions): see below. Everything else (cart, checkout, account, contact, page layouts, leaf-only handles) is ignored without error.
- Text: raw content, never rendered (same extractor as pages); a block shows in a store view when it is active and assigned to it or to All Store Views (its own block first), a Dynamic Block when it is enabled and has content for the store view or All Store Views; nested blocks are followed up to 5 levels, each block once per host (cycle-safe). The current Staging version is read (scheduled versions never before they start).
- Products: field `cmssearch_block_text` is added to the NATIVE product document by `Model\Indexer\ProductBlockTextProvider`, declared in `additionalFieldsProviderForElasticsearch` and again in `categoryPermissionsAdditionalFieldsForElasticsearchDataMapper` (`Magento_ElasticsearchCatalogPermissions` replaces the first). In this install the second declaration is redundant (the typeless virtualType inherits the item; removing it keeps the real suite green) and harmless. The consumer writes `thedevkitchen_cmssearch_product_block_text` only when the sha1 of the text changes; `etc/mview.xml` subscribes that table to `catalogsearch_fulltext`, so the native cron `indexer_update_all_views` (group `index`) reindexes the product. Consistency is eventual in two steps (consumer, then the mview cron); nothing reindexes a product synchronously. The native mapping maps the field as text copied to `_search` (no mapping change). Removing `etc/mview.xml` later does NOT drop the three triggers `trg_thedevkitchen_cmssearch_product_block_text_after_*`: they stay until dropped by hand (`DROP TRIGGER`), otherwise they keep writing to `catalogsearch_fulltext_cl`.
- Reindex requests (one message per changed entity; the consumer expands blocks and widget instances to their hosts): `cms_block_save_after`/`_delete_after`, `magento_banner_save_after`/`_delete_after`, `widget_widget_instance_save_after`/`_delete_after` (CMS Block and Dynamic Blocks widgets), `catalog_product_save_after` (new product, description, short description or type changed) / `_delete_after`, `catalog_category_save_after` (name, description, landing page, display mode, active, anchor or URL changed; a URL change also asks for the descendants) / `_delete_after` (with the deleted children) / `_move_after` (the subtree); Staging post-processor `ReferenceVersionApplied` (blocks, products, categories; pages keep `PageVersionApplied`). Plan 7: `salesrule_rule_*` and `catalogrule_rule_*` save/delete (before and after) publish `dynamic_block`/`save` for the banners whose rule link changed (`Observer\PublishRuleBannerReindex` remembers the links before the save and publishes the difference with the links after it: a save that does not change the links, such as a coupon-usage save, publishes nothing).
- Product mass attribute updates (`Plugin\Catalog\PublishProductMassActionReindex`) and product imports (`PublishProductImportReindex`) ARE covered. NOT covered by events (run the asynchronous command afterwards, `--entity=category` / `--entity=product`, or `--sync`): category imports, direct SQL changes, customer segment deletions, hand-edited URL rewrites (see also the uncovered write paths below).
- Categories tab (Plan 5 handoff): category documents make the Categories tab appear (it is feature-detected: shown when category documents exist for the store view). No facet on that tab (D4).

- What a page document gets: `text` = content heading + page text + the text of the CMS Blocks and Dynamic Blocks the page publishes in that store view (nested up to 5 levels, each block once), cut at 65 536 characters as a whole (page text first, so block text is cut first). No new field: the strict mapping stays at version 1. Blocks never get documents of their own (D2).
- Placements (user decisions): widget instances of page group `all_pages`, and `pages` with the `default` handle, are site chrome and are indexed on NO host. Placements on ALL hosts of a type (`all_products`, `<type>_products`, `anchor_categories`/`notanchor_categories` with "all", `cms_page_view`, `catalog_category_view`...) ARE indexed as they are.
- Excluded Dynamic Blocks (no text, not followed, graph row kept): restricted by a Customer Segment (D7), and also, BEYOND the literal D7, linked to a cart or catalog price rule (`magento_banner_salesrule` / `magento_banner_catalogrule`). The storefront shows those only when the rule applies to the visitor (`Magento\Banner\Model\Banner\Data::getFixedBanners`), and they may show coupon codes. Rotator display modes (`salesrule`, `catalogrule`) publish nothing; an empty `display_mode` means `fixed` and the comparison is exact (case-sensitive), as on the storefront.
- `segment_ids` on a page document = the segment ids of the EXCLUDED segment-restricted Dynamic Blocks of that page. It is NOT an access filter: the page is public and its indexed text holds no restricted content, so the search UI must NOT filter pages by `segment_ids` (it is kept for a possible future option B).
- Identifiers (`{{block id="..."}}`) are matched like the database collation `utf8mb4_general_ci` (case and accents ignored, ß = s); two spellings the collation considers equal give one graph row (the insert merges them, keeping the smallest depth).
- A block whose content cannot be extracted is skipped (no text, children not followed) and logged by kind and id only to `var/log/cmssearch_reindex.log`; the other blocks and hosts of the batch are processed.
- Interface: `Api\BlockReferenceResolverInterface` (`refreshForHost`, `hostsForBlock`, `hostsForWidgetInstance`); consumers depend on it, not on the class.
- Category documents (Task 10): `entity_type=category`, `_id` `category_<entity_id>`, `source_types` `["pdc"]`, same strict mapping v1 (no classification, `image` null). `title` = name, `text` = description text + the text of the blocks the category page publishes (capped like pages), `url` = store base URL + the store view's `url_rewrite` request path, or `catalog/category/view/id/<id>` when the store view has none. A category gets a document in store view S only when it is active in S, below S's root category (level >= 2) and a visitor who is NOT logged in may open it: `Model\Indexer\CategoryPermissionReader` mirrors the storefront's EE catalog permissions (disabled => public; the permission index row of group 0 for the website: deny hides, allow shows; no row => the "Allow Browsing Category" default, "specified groups" must list NOT LOGGED IN). `--sync` (FullReindexer) refreshes the graph of every page and category and rebuilds BOTH into the new index version, so the alias switch never drops categories.

### Known limits (references and category documents)

- Block / widget instance messages are bounded in memory: the reference graph is read in keyset pages of 1000 rows (`ReferenceStore::HOST_PAGE_SIZE`), hosts are deduplicated with one int per host and store view and processed in chunks of 1000. Time stays linear in the number of hosts: measured 2 s for a widget instance placed on all products of store view 1 (2042 products); extrapolated about 98 s at 100 000 products, far below RabbitMQ's `consumer_timeout` (1800 s). Above that scale, split such placements or raise `consumer_timeout`.
- M5: the `types` filter of the Dynamic Blocks (banner) widget is ignored; a banner whose type does not match the widget's filter still counts, a small over-index.
- M7: the theme of a store view is resolved per store view only (`design/theme/theme_id`); per-entity custom themes and `design_change` are ignored, so a widget instance bound to such a theme is not seen (and one bound to the store view's theme is seen even where an entity overrides it).

- B2B visibility rules (shared catalog, company, customer group) are NOT considered (D1). A shared catalog deny counts only after the B2B consumer `sharedCatalogUpdateCategoryPermissions` has copied it into the catalog permissions, and then only from the next `--sync`: a later application of shared-catalog denies requires `bin/magento thedevkitchen:cmssearch:reindex --sync`.
- Only the NOT LOGGED IN group is checked: a category that guests may open but one logged-in group may not is indexed (and found by that group).
- Runs of the EE catalog permission indexer now republish: `Plugin\CatalogPermissions\PublishCategoryPermissionReindex` (global scope, after `executeRow`, `executeList` and `execute`, the mview entry point) publishes a `category/save` for each processed id (it applies to the category permission indexer only: the product permission indexer extends it but receives product ids, so the plugin skips that subclass), because the permission index is written AFTER `Category::save` commits (later still in "Update by Schedule" mode), so a new or moved category could otherwise be indexed as public. `executeFull` publishes nothing (one message per category would be unbounded): a full permission reindex requires `bin/magento thedevkitchen:cmssearch:reindex --sync`. The ids are expanded with every descendant first (by path, `CategorySubtreeReader::withDescendants`), because the permission indexer reindexes the whole subtree of each id it receives (`Category\Action\Rows::removeObsoleteIndexData()`): a deny on a parent also changes the guest permission of its children, and without the expansion their documents would stay public. If the subtree lookup fails, the failure is logged (class only) and the original ids are still published. Permission changes that never reach the permission indexer (shared catalog assignments before the B2B consumer has run) still need `--sync`.
- Widget instances of page group `all_pages` (footer, header, menus) are NOT indexed on any host (user decision); the footer text therefore never reaches pages, products or categories.
- Products placed under an `all_products` / `<type>_products` widget placement match EVERY term of that block in the native quick search (a recall effect; the effective boost is 2, the description weight). Product decision to confirm with the business.
- Uncovered write paths (no message): customer segment deletions and hand-edited URL rewrites; the documents catch up on the next `--sync`.
- `--entity` defaults to `all`, which now also publishes every product and every category (not only pages). A URL key change or a move of a high category publishes one message per descendant, synchronously inside the admin request.

### Release gate and deployment order (Plan 6)

- Ship Tasks 10-13 TOGETHER (full rebuild with the graph, product block text, `HostReindexProcessor`, the new publishers): a partial release would publish messages the processor cannot handle or index without refreshing the graph.
- Order: (1) stop the consumers on every node -> (2) deploy (`setup:upgrade --keep-generated`, which creates the mview triggers; `cache:flush`, needed for `search_request.xml`; production: `setup:di:compile`) -> (3) `bin/magento thedevkitchen:cmssearch:reindex --sync` while the consumers are STILL stopped (producer messages published meanwhile wait in the queue; safe because every message is idempotent) -> (4) start the consumers on every node -> (5) `bin/magento thedevkitchen:cmssearch:reindex --entity=product` to publish the product block text. Nothing is enabled "later": producers run from the deploy on.
- `catalogsearch_fulltext` must be "Update by Schedule": in realtime mode no mview trigger exists, so block text reaches the native product index only on a full reindex.
- A message of a new entity type that reaches a node still running old code is rejected as invalid and goes to the DLQ: after the deploy, replay through the reindex command and purge the DLQ.

### Runbook (Plan 6)

- Catalog permission CONFIGURATION changes (`catalog/magento_catalogpermissions/enabled`, `grant_catalog_category_view`, `grant_catalog_category_view_groups`) publish nothing and do not run the permission indexer per category: after changing them run `bin/magento thedevkitchen:cmssearch:reindex --sync`.
- Changes to `design/theme/theme_id` (which widget instances apply to a store view), a store group's root category, or `web/default/cms_home_page` publish no message: the widget rows of the graph stay stale until `bin/magento thedevkitchen:cmssearch:reindex --sync`.

## Search result page (Plan 5)

### Mechanism (no controller plugin)

- The module's `catalogsearch_result_index.xml` adds the tab bar (`Block\Result\Tabs` + `ViewModel\SearchTabs`) and moves the native `search.result` into a `role="tabpanel"` wrapper. The observer `AddCmsTabHandle` (`layout_load_before`, storefront only, lazy `SearchState\Proxy`) adds the handle `thedevkitchen_cmssearch_tab` on CMS tabs. That layout removes `search.result`, the layered navigation (`catalogsearch.leftnav`) and the GTM impressions, and adds the cards (`ViewModel\ResultCards`, pagination) and the classification filter (`ViewModel\ClassificationFilter`, sidebar).
- `Model\Search\SearchState` (shared, per request) decides everything: it reads `q` from Magento's `QueryFactory`, sanitizes `tab`/`cls`/`p` (`TabResolver`), loads the active content types and the effective-active classification tree and makes ONE read-only OpenSearch request (`Api\CmsSearchQueryInterface` -> `Model\Search\CmsSearchQuery`, alias of the current store view only, `multi_match`, never raw input). Search classes never write to the index (`Test/Unit/Search/SearchReadOnlyContractTest`).

### URL parameters

- `/catalogsearch/result/?q=<text>&tab=<code>&cls=<term code>&p=<page>`. The URLs the module builds list the parameters in alphabetical order (`cls`, `p`, `q`, `tab`); the order is not significant for reading.
- `tab`: `produtos` (default, Products), `categorias`, or the code of an active content type (the codes `produtos`/`categorias` are reserved for the fixed tabs). `cls`: a term code of the store view's website, effectively active (an inactive ancestor hides its branch), only on content tabs. `p`: 1-9999, clamped to 10000 / page size. `q` is capped at 256 characters on top of Magento's `catalog/search/max_query_length`. Anything invalid falls back silently (Products tab, no filter, page 1); never a 500.

### Tabs and counters

- Products always (native layout and count); Categories only when the store view's index holds `entity_type=category` documents (feature detection by a `global` aggregation; Plan 6 adds those documents); one tab per ACTIVE content type (sort order, then id; store view label, then default label, then code). The bar is rendered only when at least two tabs exist.
- Counters come from the single aggregation request and ignore `cls` and `p`. Facet counts are per tab, include descendants and are not scoped to `cls`.

### Settings and module switch

- Stores > Configuration > Thedevkitchen > CMS Search > three groups, store view scope: "Search Result Page" (Show CMS Tabs), "Fixed Tabs" (Products/Categories labels) and "CMS Results" (results per page, summary length): "Show CMS Tabs" (`thedevkitchen_cmssearch/general/enabled`, website/store view, default yes), tab labels for Products/Categories (empty = translated name), results per page (`results/page_size`, 1-60, default 12), summary length (`results/summary_length`, 50-300, default 200: the index stores at most 300 characters of summary, so a larger value would have no effect). Out-of-range values are rejected by the backend model; a stored value outside the bounds reads as the default.
- `thedevkitchen_cmssearch/results/search_timeout_ms` (1-10000, default 1500) has no form field: `bin/magento config:set thedevkitchen_cmssearch/results/search_timeout_ms <ms>`.
- Switch off for a store view = the native page: no tab bar, no CMS results (listing identical to the native one). Indexing is not affected. The queue group stays global.

### Fallbacks and logging

- A failing or slow index request (timeout above) shows the native Products page without tab bar and writes ONE WARNING line per request to `var/log/cmssearch_search.log` (store id, alias and engine error class; never the searched text, editorial content or exception messages). Unexpected errors (a failing query/state resolution, the tab-handle observer) are logged at ERROR level, class name only, and also give the native page; a failing tree reader only hides the filter (ERROR); a failing Products count logs a WARNING and the tab renders without counter. A store view whose alias does not exist yet shows the native page without logging. A log file that cannot be written never breaks the page.
- Recommended: add `cmssearch_search.log` to logrotate next to `cmssearch_reindex.log`.

### Products count (decision 2)

- Calling `getSize()` on the layer collection before the listing renders would run the product search before the toolbar sets order and page size, and a second search collection mixes pending filters through the shared `SearchCriteriaBuilder`. Therefore, on the Products tab the tab bar first renders `search.result` through `LayoutInterface::renderElement()` (cached by the layout, rendered once) and then reads the block's `result_count`; on a CMS tab the layer collection's `getSize()` is the count (one product search; layered filters are ignored there, switching tabs drops them anyway). Verified on the real pages: the counter equals the native toolbar total (also with sort, limit, page 2 and layered filter).
- Seam: `Model\Search\ProductCount` is a non-final class with a non-final `count()`. If the pre-render ever misbehaves, replace it with a `<preference for="Thedevkitchen\CmsSearch\Model\Search\ProductCount" type="...">` in a di.xml that reads `search_query.num_results` instead (a test pins the non-finality).
- Failure modes: a failing render is logged by class only and the count is null (the Products tab renders without a counter); the count never throws. Side effect of the layout order: the hidden `form_key` input and `#authenticationPopup` come after the listing inside `content` (the listing markup itself is identical; the page is not byte-identical).

### search_query side effects (native)

- `tab`/`cls`/`p` take Magento's non-cacheable path (`setNoCacheHeaders()`): every CMS tab view runs `saveIncrementalPopularity()` (popularity +1) and term redirects still apply. `num_results` is written only by the Products tab (the native result block); a CMS tab never changes it. A `?q=`-only request increments popularity only when the term is not a "top search term". Synonyms apply to products only.

### Full-page cache and Varnish (validated in Plan 7)

- Magento takes the cacheable path for `/catalogsearch/result/?q=<term>` ONLY (no other parameter) when the term is a top search term (`catalog/search/max_count_cacheable_search_terms` most popular terms with results). Every URL with `tab`, `cls` or `p` (and the native `p`, sort or filter parameters) answers with `Cache-Control` `no-store, no-cache, must-revalidate, max-age=0` (directive order differs from this text). The Products tab link is `?q=` only. No block uses `cacheable="false"`.
- Magento's VCL (`bin/magento varnish:vcl:generate`, template `module-page-cache/etc/varnish7.vcl`) has no `/catalogsearch` exception and caches `?q=` pages; the key is the full URL with its query string plus the `X-Magento-Vary` cookie. Warden's own VCL in this environment never caches `/catalogsearch` (two public GETs both MISS), so locally Varnish shows no caching of the search page; the behaviour above is the one to expect with Magento's generated VCL.
- No CMS Search cache tag (decision pending, see "Open product decisions"): the tab bar does not implement `IdentityInterface` and the consumer purges nothing. A cached `?q=` Products page keeps the CMS tab list and CMS counters of the moment it was cached until a product purge hits its tags or the TTL expires (`system/full_page_cache/ttl`, 604800 s = 7 days in this install). The page holds no per-visitor content (checked).
- Purge recipe after a large CMS change (content types, classification, many pages): purge the search pages by URL or ban the search URL pattern. With Varnish: `varnishadm 'ban req.http.host ~ <store host> && req.url ~ ^/catalogsearch/result/'` (Magento's own `PURGE` method accepts only `X-Magento-Tags-Pattern` / `X-Pool`, so URL-based invalidation uses `varnishadm ban`, or a `BAN`/`PURGE` rule of your own VCL). With the built-in cache: `bin/magento cache:clean full_page` (purges the whole page cache). Or accept the TTL.
- How to test with `full_page` on (local only): `CMSSEARCH_CACHE_SUITE=1 bash integration/test_cms_search_cache.sh` (27 checks) enables it with `bin/magento cache:enable full_page` (this rewrites `app/etc/env.php`; the suite copies the file first and restores the exact bytes in its trap), checks the headers straight from nginx (php-fpm container -> `http://nginx`, `Host` + `X-Forwarded-Proto: https`) and disables `full_page` again. By hand: enable it, `curl -s -D - -o /dev/null -H 'Host: app.adobecommerce.test' -H 'X-Forwarded-Proto: https' 'http://nginx/catalogsearch/result/?q=bag'` from the php-fpm container (twice), then `bin/magento cache:disable full_page && bin/magento cache:clean full_page`.
- Test data helpers (`integration/support/cmssearch_ui_data.sh`) clean only the `config` cache: with `full_page` enabled permanently, also clean `full_page` (and Varnish) after changing content types, config or documents.

### Accessibility

- Tabs are links with `role="tab"` inside `role="tablist"`, `aria-selected`, `aria-controls` on the active tab only, and one `role="tabpanel"` labelled by the active tab (the products wrapper or the cards block). Keyboard model (`Thedevkitchen_CmsSearch/js/tabs`): roving `tabindex`, Arrow keys/Home/End move focus only, Space or Enter activates (manual activation; without JS every tab is a normal link). Text colour is fixed (`#333`), brand colour only on the active-tab bar and the current page border; focus ring 2px. Pagination, filter and empty states use translated `aria-label`s (pt_BR included). cypress-axe (WCAG 2 A/AA) passes in all six themes, desktop and 375px. Plain text from the index (title, summary) is escaped on output; card URLs and images are shown only when `https?://`.

### Themes

- Templates and `view/frontend/web/css/source/_module.less` live in the module (base frontend area, picked up by Luma's `_module.less` import); no theme overrides them. The six `Local/*` themes inherit from `Magento/luma`. The module paints its own white surface for the tab bar, results and filter (Local/gaming has a dark page background; fixed #333 text on it failed contrast) and removes the 60px top gap of `.columns` on CMS tabs at mobile widths. Per-theme checks: `cms-search-themes.cy.js` (7 tests, TS-42).
- After changing LESS remove the materialised CSS inside the container (`rm -rf pub/static/frontend/Local pub/static/frontend/Magento/luma var/view_preprocessed/pub/static/frontend`; never `sri-hashes.json`, never `setup:static-content:deploy`/`setup:di:compile` in developer mode).

### Known environment defects (found while testing; fix left to the team)

1. nginx bind-mounts the HOST `pub/static` while php-fpm writes generated files (including `requirejs-config.js`) into the container volume (mutagen ignores `/pub/static/**`). The `Local/*` theme hosts therefore answer 404 for `requirejs-config.js` and no RequireJS JavaScript runs in a browser there (the Cypress specs work around it with `cy.intercept`). Recommended: make nginx and php-fpm share one `pub/static` (mount the same volume in nginx or generate on the host) and then drop the intercepts.
2. Store 1 (Luma): the host still holds a stale static build from 2026-06-15, so `styles-m.css` lacks the module rules and store 1 shows the tab bar and cards unstyled. `static.php?resource=frontend/Magento/luma/en_US/css/styles-m.css` has them. Recommended: remove or move aside the host's `pub/static/frontend/Magento/luma/*/css/styles-{m,l}.css` (or the whole stale directory; never `sri-hashes.json`).
3. macOS resolves `.local` hosts through multicast DNS first (about 5 s per lookup), which makes Cypress time out on the website1 hosts. The specs use the CONNECT-only proxy `e2e/cypress/support/hostmap-proxy.js` (`node e2e/cypress/support/hostmap-proxy.js &`, port 18443) and run with `HTTPS_PROXY=http://127.0.0.1:18443 NO_PROXY=`; without it they skip. Alternative: use `.localhost` names for the Local store views.

### Open decisions (left to the user)

- Zero-count tabs: today every ACTIVE content type gets a tab on every store view (content types are global), even when the store view has no document of that type (count 0 and an empty-state message). Options: keep; hide tabs with count 0 (except the active one); a per-type "visible store views" setting.
- Recall of the CMS query: terms are combined with the operator AND (every word must match). Queries made of emoji or zero-width/format characters produce tokens that never match and therefore 0 CMS hits. Options: keep AND; `minimum_should_match` (e.g. 75%); strip the characters before the query.
- Gaming theme surface: the module paints a white surface for the tab bar, results and filter (fixed #333 text) because `Local/gaming` has a dark page background. Options: keep the white card on all themes; give `Local/gaming` its own dark palette for the module (theme-level LESS override).
- Environment defects (see above): share one `pub/static` between nginx and php-fpm, rebuild or remove the stale June Luma static build on the host, move the Local store views from `.local` to `.localhost` (or add a DNS workaround for mDNS).

### Follow-ups status

- Done in Plan 7: single render of the native listing (`ProductCount` memo per layout, real-page check); `ResetAfterRequestInterface` on `SearchState`, `ContentTypeTabs`, `ClassificationTerms`, `ClassificationFilter` and `ProductCount` (`Test/Unit/Di/StatefulServicesContractTest`). Only `Magento_ApplicationServer` calls those resets (not installed here), so they are defensive, not exercised by a real worker. `SearchState` stays a shared per-request service.
- Still open (see release notes): skip the OpenSearch request when only Products can exist, `http://` card images on https stores, Unicode `Cf` characters in `q`.
- Logrotate for `cmssearch_search.log` is part of the deploy checklist.

## Deploy checklist

Production deploy of this module, adapted to the delivered code. Run on every node unless noted.

0. `bin/magento maintenance:enable` (and `bin/magento maintenance:disable` after step 8). `vendor/bin/phpunit` and the opt-in suites run in staging or CI, never in production (dev dependencies are not installed there).
1. Stop the CMS consumer everywhere: set `cron_consumers_runner/cron_run` to `false` in `app/etc/env.php` (or remove `thedevkitchen.cmssearch.reindex` from its `consumers` list), then end running processes (`pkill -f 'queue:consumers:start thedevkitchen.cmssearch.reindex'`, or `supervisorctl stop cmssearch-reindex` when supervised). Messages published meanwhile wait in the queue (idempotent).
2. Deploy the code: `bin/magento module:enable Thedevkitchen_CmsSearch` (first release), `bin/magento setup:upgrade` (tables, mview triggers, queue topology with the DLQ), then in production mode `bin/magento setup:di:compile` and `bin/magento setup:static-content:deploy -f` (six themes, en_US and pt_BR). In developer mode use `setup:upgrade --keep-generated`, never `setup:di:compile`, and delete the stale generated interceptor or proxy of a class whose constructor or public methods changed (e.g. `generated/code/Thedevkitchen/CmsSearch/Console/Command/ReindexCommand/`). Sandbox note: `setup:upgrade` also runs a full reindex of every native indexer because `Magento_ConfigurableSampleData` reindexes in its recurring data (slow; sample data is not present in production).
3. `bin/magento cache:flush` (needed for `events.xml`, `di.xml`, `search_request.xml`).
4. `bin/magento thedevkitchen:cmssearch:reindex --sync` with the consumers STILL stopped (one node; rebuilds pages and categories per store view behind the aliases and drops orphan indices).
5. Start the consumers: restore `cron_consumers_runner` (or `supervisorctl start cmssearch-reindex`) on every node; `bin/magento queue:consumers:list | grep thedevkitchen` and `rabbitmqctl list_queues name messages consumers | grep thedevkitchen.cmssearch` show the queue being consumed.
6. `bin/magento thedevkitchen:cmssearch:reindex --entity=product` (one node; block text of product pages, asynchronous) and let the `index` cron group run (`catalogsearch_fulltext` must be "Update by Schedule").
7. Verify: both queues drain to 0 (the DLQ stays 0), `var/log/cmssearch_reindex.log` shows `reindexed ...` lines, the search page shows the CMS tabs, `vendor/bin/phpunit`, and in staging the opt-in suites of "Tests".
8. Full-page cache / Varnish: if the cache is on, purge the search pages once after the first `--sync` (see "Full-page cache and Varnish"), then `bin/magento maintenance:disable`.

Consumer supervision (choose ONE per environment):
- Native cron (default of this project): `bin/magento cron:run` scheduled; `app/etc/env.php` without `cron_consumers_runner` runs every consumer; when the key restricts consumers, add `'thedevkitchen.cmssearch.reindex'` to `consumers` (see "Running the consumer").
- Supervisor: remove the consumer from the cron list and run `[program:cmssearch-reindex] command=php /var/www/html/bin/magento queue:consumers:start thedevkitchen.cmssearch.reindex --single-thread --max-messages=1000`, `autorestart=true`, `stopwaitsecs=600` (one process per cluster is enough; a second one is safe, but never use `multiple_processes`).

Operations to set up with the release: logrotate for `var/log/cmssearch_reindex.log` and `var/log/cmssearch_search.log` (`copytruncate`); queue depth and DLQ monitoring; the lock provider must be `db` (or another shared provider) on multi-node installs (`--sync` locks per store view).

## Release notes (v1)

Delivered: the seven plans listed under "Status" (content types, classification, queue/consumer, CMS page index, search UI, block references and enrichment, hardening).

Known limits:
- Eventual consistency, no SLA (D10): a change is searchable after the consumer processed its message and the next index refresh (about 1 s); product block text needs the `index` cron group as well.
- Retries are immediate (no backoff); a hard-killed consumer loses its in-flight message (`queue_lock`); a store view/website deletion publishes nothing; values baked into documents (base URLs, home page, locale, theme, root category) and catalog permission configuration need `--sync`; a database reconnect inside a long `--sync` may lose its per-store lock (do not run two rebuilds of one store view); messages processed during a `--sync` build are lost at the switch (run it with the consumers stopped).
- Customer-segment and price-rule Dynamic Blocks are excluded (D7 option A, see Pending items); B2B shared catalogs and per-group category permissions are not considered (D1; guests only).
- `all_pages` and `pages/default` widget placements are not indexed (user decision); M5 banner `types` filter ignored; M7 theme per store view only.
- A category save publishes twice (save observer + permission indexer plugin): kept on purpose, both are needed (schedule-mode permission indexer, permission written after commit), both idempotent. The permission-indexer plugin also publishes the descendants of each processed category. `executeFull` of the permission indexer publishes nothing: run `--sync`.
- Block/widget messages: bounded memory (keyset pages), time linear in the hosts (see "Known limits (references and category documents)").
- The consumer follows store list changes by itself, except when store scopes are dumped to `app/etc/config.php` (the file wins over the database; a warning is logged once per database change, across consumer processes).
- Search page: one OpenSearch request per search page view even when only Products can exist; `http://` card images are accepted (mixed content on https stores); Unicode `Cf` characters in `q` are not stripped.
- Cached Products pages: with a full-page cache or Varnish in front, a cached `?q=` Products page keeps its CMS tab list and counters up to the TTL (604800 s here). Purge recipe in "Full-page cache and Varnish"; the module does not purge by itself.
- Native indexers: after the opt-in suites (and after catalog permission changes) `catalogsearch_fulltext`, `catalog_category_product` and `catalogpermissions_category` can show "invalid"; run `bin/magento indexer:reindex` for them.
- Suites: the references suite takes about 4-5 minutes (measured; it can take longer on a slow clock). Fixture cleanup of pages leaves page-delete messages in the main queue when no consumer runs (harmless: a missing page is a success); the next opt-in suite refuses a non-empty queue unless `CMSSEARCH_QUEUE_PURGE_OK=1`.

Open product decisions (left to the business):
- Zero-count tabs: show every active content type (today), hide tabs with 0 results except the active one, or a per-type store-view setting.
- Recall: CMS query terms are combined with AND (every word must match); option `minimum_should_match` (e.g. 75 %) or OR like the native product search.
- Gaming theme palette: white module surface on the dark `Local/gaming` theme (today) or a dark palette for that theme.
- `summary_length`: the index stores at most 300 characters (the setting accepts 50-300); a longer summary needs a mapping/document change and `--sync`.
- `all_products` / `<type>_products` widget placements: the block text makes EVERY product of the placement match the block's words in the native quick search (recall effect, boost 2); keep, or restrict such placements to CMS pages/categories (`WidgetInstanceResolver::selectorsOf()`).
- Tag CMS results: tag the tab bar for the full-page cache (`IdentityInterface`, e.g. `cmssearch_tabs`) and purge that tag from the consumer (debounced), shorten `system/full_page_cache/ttl`, or hide the CMS counters on the cacheable Products page; today the TTL applies.
- B2B shared catalog visibility is out of scope (D1); revisit with a B2B-aware option.
- Customer-segment Dynamic Blocks (option B): excluded in v1, see Pending items. `all_pages` widgets stay not indexed unless the business reverses that decision.

Environment defects found while testing (this sandbox, not the module):
- nginx serves the host `pub/static` while php-fpm writes into the container volume (Local theme hosts 404 `requirejs-config.js`); a stale June Luma static build on store 1 (see "Known environment defects").
- `.local` hosts resolve through mDNS on macOS (~5 s per lookup): the storefront Cypress specs need the hostmap proxy (`node e2e/cypress/support/hostmap-proxy.js &`, `HTTPS_PROXY=http://127.0.0.1:18443 NO_PROXY=`) and skip without it.
- `setup:upgrade` runs a full reindex of every native indexer (sample-data module `Magento_ConfigurableSampleData`).
- The storefront URL `gear/fitness-equipment.html` answers 500 (duplicate product id 33 in the sample data; exists before this module).
- Native indexers may show invalid after the suites: `bin/magento indexer:reindex`.

## Pending items (backlog)

- Dynamic Blocks restricted by a Customer Segment are excluded from the index in v1 (spec option A). The segment ids will be stored so option B (filter by the logged-in customer's segments at query time) can be enabled later; that needs a decision on the Varnish impact (customer-dependent results). Dynamic Blocks linked to cart/catalog price rules are excluded the same way (Plan 6 review decision).

## Tests

Run sequentially, never concurrently (shared admin/DB/broker state).

- Block references and enrichment against the real consumer, OpenSearch and the native product search: `CMSSEARCH_REFERENCES_SUITE=1 bash integration/test_cms_search_references.sh` (142 checks, about 4-5 minutes, longer on a slow clock). OPT-IN: without `CMSSEARCH_REFERENCES_SUITE=1` it prints SKIP and exits 0, so `./integration/run.sh` and the Stop hook do not run it; it refuses to start when the main queue or the DLQ holds messages unless `CMSSEARCH_QUEUE_PURGE_OK=1` (then run `bin/magento thedevkitchen:cmssearch:reindex` afterwards). It purges the module's two queues, skips when a consumer runs, writes only `cmssearch-` blocks, Dynamic Blocks, widget instances, pages, one category and one product, runs the `indexer_update_all_views` job itself, and removes everything it created).

- Unit: `php vendor/bin/phpunit -c phpunit.xml` (repo root).
- ACL / data rules: `bash integration/test_content_type_acl.sh`, `bash integration/test_classification.sh`.
- Reindex queue against the real RabbitMQ: `CMSSEARCH_QUEUE_SUITE=1 bash integration/test_cms_search_queue.sh` (145 checks, about 4.5 minutes against the real processor). OPT-IN: without `CMSSEARCH_QUEUE_SUITE=1` it prints SKIP and exits 0, so `./integration/run.sh` and the Stop hook do not run it. `CMSSEARCH_QUEUE_BACKLOG=200` shortens the backlog case. It purges both module queues at start and end, refuses to start when the main queue already holds messages unless `CMSSEARCH_QUEUE_PURGE_OK=1` (then run `bin/magento thedevkitchen:cmssearch:reindex` afterwards), and skips when a consumer of the queue is running.
- CMS page index against the real OpenSearch and the real consumer: `CMSSEARCH_INDEX_SUITE=1 bash integration/test_cms_search_index.sh` (130 checks, about 3.5 minutes). OPT-IN: without the variable it prints SKIP and exits 0 (so `./integration/run.sh` and the Stop hook skip it). It refuses to start when the main queue holds messages unless `CMSSEARCH_QUEUE_PURGE_OK=1`, purges the module's two queues, skips when a consumer runs, writes only `cmssearch-` pages/`cmssearch_` codes and removes their documents; it rebuilds the index of store view 2 with `--sync` (zero failed searches during the alias switch).
- Opt-in switches: `CMSSEARCH_QUEUE_SUITE=1` (queue suite), `CMSSEARCH_REFERENCES_SUITE=1` (references suite), `CMSSEARCH_INDEX_SUITE=1` (index suite), `CMSSEARCH_UI_SUITE=1` (search UI suite), `CMSSEARCH_CACHE_SUITE=1` (full-page cache suite), `CMSSEARCH_QUEUE_PURGE_OK=1` (allow purging a non-empty main queue; then run `bin/magento thedevkitchen:cmssearch:reindex --entity=page` afterwards).
- The two data-rule suites run from `./integration/run.sh` (the queue suite skips there); they need the Warden php-fpm (and rabbitmq) containers and clean up after themselves.
- Admin flows (Cypress), one spec at a time: `cd e2e && env -u ELECTRON_RUN_AS_NODE npx cypress run --spec cypress/e2e/<spec>` with `content-type-admin.cy.js`, `classification-admin.cy.js`, `cms-page-search-fieldset.cy.js`, `cms-page-search-fieldset-existing.cy.js`. They share `e2e/cypress/support/cmssearch.js` (login, helpers and the single `use_form_key` baseline file, so the config flag is restored once).
- Search result page against the real storefront, index and consumer: `CMSSEARCH_UI_SUITE=1 CMSSEARCH_QUEUE_PURGE_OK=1 bash integration/test_cms_search_ui.sh` (98 checks, a few minutes). OPT-IN: without `CMSSEARCH_UI_SUITE=1` it prints SKIP and exits 0. It seeds and purges its own data with `integration/support/cmssearch_ui_data.sh` (refuses a non-empty main queue unless `CMSSEARCH_QUEUE_PURGE_OK=1`) and needs the six store view hosts in `/etc/hosts`.
- Storefront Cypress specs, one at a time and with the proxy (see "Known environment defects"): `cd e2e && HTTPS_PROXY=http://127.0.0.1:18443 NO_PROXY= env -u ELECTRON_RUN_AS_NODE npx cypress run --spec cypress/e2e/cms-search-storefront.cy.js` (20 tests, about 5 min) and `cms-search-themes.cy.js` (7 tests, TS-42, six themes plus Luma, about 4 min). Export `CMSSEARCH_QUEUE_PURGE_OK=1` if the main queue holds messages.
- Full-page cache and Varnish of the search result page: `CMSSEARCH_CACHE_SUITE=1 bash integration/test_cms_search_cache.sh` (27 checks, a few minutes). OPT-IN: without the variable it prints SKIP and exits 0. It enables `full_page` TEMPORARILY (`bin/magento cache:enable full_page` rewrites `app/etc/env.php`, local configuration; the suite restores the file's exact bytes and disables `full_page` in its trap), seeds/purges the UI data set and talks to nginx from the php-fpm container.
- Test-only helper: `integration/support/cmssearch_fixture.php` (CLI only; writes only `cmssearch_` codes/users and `cmssearch-` pages/Staging updates; `link-page`/`unlink-page` save and restore the link a page had before; backups live in `var/cmssearch-fixture`; queue commands run the real consumer in-process with a probe processor and can make the broker unreachable for their own process only).
