# Thedevkitchen_CmsSearch

Indexes CMS content in OpenSearch and shows it in tabs on the search result page. Specs: `specs/2026-10-11-cms-search/`. Plans: `docs/superpowers/plans/2026-10-11-cms-search-*.md`.

## Status

- Plan 1 delivered: editorial content type registry (declarative schema, repository/service contracts and validation, admin CRUD with per-store-view labels, granular ACL, delete blocked while CMS Pages are linked, dropdown option source).
- Plan 2 delivered: classification tree per website (admin tree, term form, move, delete rules, ACL), and the "Search" fieldset of the CMS Page form (content type + classification term), validated and persisted server-side by an observer inside the page-save transaction.
- Plan 3 delivered: asynchronous reindex queue (exclusive topic/queue/consumer `thedevkitchen.cmssearch.reindex` with a dead-letter queue), after-commit publishing from every write path, consumer handler with an attempt counter (cap 3), reindex command, configuration section.
- Plan 4 delivered: CMS page indexer - one OpenSearch index per store view behind a stable alias, documents built from the Staging-active page version (Page Builder content reduced to text), visibility rules, idempotent upserts/deletes from the reindex consumer, and `--sync` full rebuild with an atomic alias switch.
- i18n: `en_US.csv` and `pt_BR.csv` cover Plans 1-4 (Plan 4 adds no translatable storefront/admin phrase; console descriptions are not translated).

Not delivered yet: search UI (Plan 5), block references and enrichment (Plan 6), hardening/release (Plan 7).

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
Entity types and actions: `page` save|delete|staging; `content_type` save|delete; `classification_term` save|delete (a term save means "the term or its place in the tree changed": the consumer reindexes the pages of the term's CURRENT subtree, so a move sends one message). The action is a hint; the consumer reads the current state.

### Who publishes (always after the outermost commit)

- `ContentTypeRepository` save/delete; `ClassificationTermRepository` save (create, label, sort order, active, move) and delete; `PageClassificationRepository` assign/unassign.
- `Observer\PublishPageReindex` on `cms_page_save_after` / `cms_page_delete_after`: admin form, REST, grid inline edit, mass actions, Save & Duplicate, Staging version schedule/update/remove.
- `Model\Staging\PostProcessor\PageVersionApplied`, a post-processor of `Magento\Staging\Model\StagingApplier` (cron `staging_apply_version`): scheduled page content that starts or ends -> `page`/`staging` per `page_id`.
- Inside a database transaction the request waits in `Model\Queue\PendingMessages`; `Plugin\Framework\DB\FlushReindexMessagesPlugin` (on `Magento\Framework\DB\Adapter\AdapterInterface`, like core's `execute_commit_callbacks`) sends it after the outermost commit and drops it on rollback. Identical requests of one transaction are merged: a page save with the Search fieldset sends one message. Outside a transaction the request is sent at once.
- The publisher never throws: if the broker is down the save still succeeds and `publish failed {...}` is logged; recover with the reindex command.
- Not published, by design: store view/website deletion (index lifecycle, see the runbooks); the test fixture's raw-SQL commands (`type-purge`, `term-purge`, `seed-link`, `link-page`, `unlink-page`) and the Cypress cleanup request, which bypass the repositories.

### Consumer, retry and DLQ

- Handler `Model\Queue\ReindexHandler::process`; the work is delegated to `Model\Queue\ReindexProcessorInterface`, bound in `etc/di.xml` to `Model\Queue\PageReindexProcessor` (Plan 4): it resolves the affected pages (`AffectedPages`, streamed in chunks of 1000), the store views (the message store view or all, restricted to the message website for terms), adds the store-0 page a changed page shadows (same URL key), and calls `PageIndexer` (incremental upserts/deletes per store view). Missing page/type/term/store view = success. Only the consumer (and the CLI) may use the processor or the index writers (`Test/Unit/Queue/AsyncOnlyContractTest`).
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
- Runbook after deleting a store view or a website (nothing is published for them): run `bin/magento thedevkitchen:cmssearch:reindex --sync` WITHOUT `--store`; it drops the indices of store views that no longer exist (`IndexManager::dropOrphans`). A new store view gets its index at its first message; fill it with `bin/magento thedevkitchen:cmssearch:reindex --store=<id>`. The consumer reads the store list once per process (restart it, or wait for the cron restart, after adding or deleting a store view).

### Reindex command

`bin/magento thedevkitchen:cmssearch:reindex [--entity=page|all] [--store=ID]` publishes one `page`/`save` message per CMS page (keyset batches of 500 page ids) and exits; the consumer does the work. Exit code 1 for an unsupported entity, an invalid or unknown store view, or when a message could not be published. Categories are Plan 6.

`--sync` (operators only, never from a web request) rebuilds instead of publishing: per store view a new index version is filled with every visible page (written through a `{version}_build` alias), refreshed once, the alias is switched atomically and the previous versions are deleted; without `--store` it also drops the indices of store views that no longer exist (`dropOrphans`). A per-store-view database lock (`thedevkitchen_cmssearch_rebuild_<id>`) makes a second `--sync` of the same store view fail fast without touching anything. Output per store: `Store <id>: <n> page document(s) indexed into <index> (removed: <names|none>).`; `-v` prints one progress line per batch. If a store view fails, the live alias of that store view is untouched, the half-built index is dropped, and the message lists the store views already rebuilt.

Which one to use: the plain command (asynchronous messages) is cheap and safe at any time but only as fast as the consumer; `--sync` is for first fills, mapping changes, orphan cleanup and recovery. Examples:

```
bin/magento thedevkitchen:cmssearch:reindex                        # one page message per CMS page, every store view
bin/magento thedevkitchen:cmssearch:reindex --entity=page --store=3
bin/magento thedevkitchen:cmssearch:reindex --sync                 # rebuild every store view + drop orphans
bin/magento thedevkitchen:cmssearch:reindex --sync --store=2 -v
```

Messages processed by the consumer while a rebuild runs are applied to the previous version and are lost at the switch (no catch-up by design: every change is a new queue request). Run `--sync` with the consumer stopped (cron `consumers_runner` included), or run `reindex --entity=page` afterwards.

DEPLOY NOTE: after a constructor change of a console command (this release changed `ReindexCommand`) the generated interceptor is stale and breaks EVERY application command of `bin/magento` (only `setup:*`, `admin:*`, `info:*`, `i18n:*` are listed). Developer mode: delete `generated/code/Thedevkitchen/CmsSearch/Console/Command/ReindexCommand/`, run `bin/magento cache:flush` and any `bin/magento` command (it is regenerated). Production: `setup:di:compile` as in the normal deploy.

### Changing the topology

`bin/magento setup:upgrade` declares exchanges, queues and bindings. RabbitMQ refuses to redeclare an existing queue with different arguments (`PRECONDITION_FAILED`): changing the dead-letter arguments requires deleting the queue first (development only) or a planned migration (production).

### Known limits

- Saving a content type or a classification term publishes one message for the entity; the consumer expands it to every linked page (content type) or to the pages of the term's current subtree, so one save can fan out to many pages.
- Content type and term saves publish on every save, including unchanged ones.
- Store view and website deletion publish nothing (see the runbook above: `--sync` without `--store`). Blocks, dynamic blocks, products and categories are Plan 6.
- Shadowed store-0 pages (Magento serves a store-view page before the store-0 page with the same URL key): a page save reindexes the store-0 page of its current key (consumer side); a rename away from a key or a delete also publishes `page`/`save` for the active store-0 pages of the key it left (`Observer\PublishPageReindex`, one indexed query only on a rename or delete).
- Test fixtures use raw SQL and bypass events (no messages); so does the Cypress cleanup request.
- A consumer that is killed hard (OOM, SIGKILL, container restart, timeout kill) silently loses the message it was handling: Magento writes the `queue_lock` row before the handler runs, so the redelivered message is acknowledged as already locked and dropped. The DLQ does not see it. Recovery: `bin/magento thedevkitchen:cmssearch:reindex`.
- The reindex command does not stop at the first failed send: with the broker down every send is attempted (and counted), so a large site waits for the connection timeouts before the command exits 1.

### Staging

- Hooks: `cms_page_save_after`/`cms_page_delete_after` (every Staging operation on a page fires them) plus the `StagingApplier` post-processor `PageVersionApplied`. When the applier (cron `staging_apply_version`) activates a version it saves the page (`page`/`save`, merged per transaction) and the post-processor then sends `page`/`staging`: two messages per page, harmless (a redundant reindex).
- Verified by `integration/test_cms_search_queue.sh`: scheduling and removing a version publish page messages, and the post-processor is registered once on the DI-built `StagingApplier` and publishes `staging` messages.
- Not run by the suite because it would apply every due update of the shared database and move the global Staging version: the real time-based activation. Manual check on a disposable environment with cron running: schedule an update two minutes ahead on a test page, wait for `staging_apply_version`, then `rabbitmqadmin get queue=thedevkitchen.cmssearch.reindex ackmode=ack_requeue_true` shows `"action":"staging"` for that page.

## CMS page index (Plan 4)

### Index layout

- One index per store view: alias `{prefix}_thedevkitchen_cmssearch_{store_id}`, versions `{alias}_v{n}` (n = 1 + highest existing version). `{prefix}` = `catalog/search/opensearch_index_prefix` (here `magento2`). The search side (Plan 5) reads the alias only. The module only creates, changes or deletes indices matching `{prefix}_thedevkitchen_cmssearch_<store>_v<n>`; native catalog indices (`{prefix}_product_*`) are never touched.
- Connection: `Model\Indexer\Engine\ClientProvider` builds its own OpenSearch client through Magento's `ClientResolver::create('opensearch')` with Magento's configured host/credentials (the module reads none itself), so the client can be reset after a node is marked dead, and it adds per-request timeouts derived from `catalog/search/opensearch_server_timeout` (Magento's shared connection has none). The engine must be OpenSearch, otherwise an `IndexingException` is thrown.
- The first message for a store view creates `_v1` with its alias in one atomic call. Incremental writes use `require_alias=true` (a write never auto-creates an index) and no `refresh` parameter (see Refresh). Baseline of this install: 7 aliases (`magento2_thedevkitchen_cmssearch_1` ... `_7`), each on one index; a version number above 1 only means a `--sync` ran.
- Document id `page_<page_id>`: every write is an idempotent upsert or delete (deleting a missing document is a no-op).

### Mapping and upgrade path

- `Model\Indexer\IndexDefinition`, `dynamic: strict`, `_meta.mapping_version` = `IndexDefinition::MAPPING_VERSION` (1). Fields: `entity_type`, `entity_id`, `source_types` (`["cms"]`), `content_type_code`, `classification_term_id`, `classification_ancestor_ids` (root first, the term included), `classification_path`, `title` (+ `title.keyword`), `text`, `summary`, `url`, `image`, `store_view_id`, `website_id`, `segment_ids` (empty until Plan 6), `updated_at`. `url`, `image` and `summary` are stored, not searchable.
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

`Model\Indexer\TextExtractor` works on the raw content, never rendered: `<style>`, `<script>`, `<noscript>`, `<template>`, embeds and Page Builder image/video/map are removed; Page Builder `html` content is decoded (at most 2 levels); every `{{...}}` directive is removed from the text (block/widget text arrives with Plan 6; the parsed directives are already exposed by `ExtractedContent::getDirectives()`). `text` = content heading + text, at most 65 536 characters; `summary` = meta description, else the text, cut to 300 characters at a word boundary; `image` = first `<img>` when it is `{{media url=...}}` or an absolute http(s) URL, else null. Fixtures: `Test/Unit/_files/pagebuilder/*.html` (anonymized Page Builder storage format, legacy WYSIWYG directives, UTF-8, style/script). The real pages of this install contain no Page Builder markup (only legacy HTML and `{{block}}`/`{{store}}`/`{{view}}` directives).

### Refresh semantics

The consumer never waits for an OpenSearch refresh. A change is searchable at the next periodic refresh (default `refresh_interval` 1 s), i.e. eventual consistency of about 1 s after the message is acknowledged, not at that moment. An index with no search for 30 s is "search-idle": its refreshes pause and the first search on it triggers a refresh. No refresh interval is configured by the module. Only `--sync` refreshes explicitly (once, before the alias switch). Tests that search right after consuming use the explicit helper `php integration/support/cmssearch_fixture.php index-refresh <store_id|all>` (`IndexManager::refresh()`).

### Logs

`var/log/cmssearch_reindex.log`: per message a DEBUG `reindexed <type> <id> (<action>): N page(s), S store view(s), U upserted, D deleted` and `processed {json}` (counts and ids only, never content); retries and failures are WARNING/ERROR. Magento does not rotate it: configure logrotate (see "Monitoring and DLQ operations") or raise the level of the log handler.

### Runbooks

- After deleting a store view or a website: `bin/magento thedevkitchen:cmssearch:reindex --sync` WITHOUT `--store` (drops the orphan indices). A new store view: `--store=<id>`; restart the consumer, which caches the store list.
- Outage (OpenSearch or broker down, messages in the DLQ): fix the cause, then DLQ -> `bin/magento thedevkitchen:cmssearch:reindex --entity=page` -> purge the DLQ (`rabbitmqadmin purge queue name=thedevkitchen.cmssearch.reindex.dlq`).
- Hard-killed consumer (the in-flight message is lost): `bin/magento thedevkitchen:cmssearch:reindex --entity=page`.
- Values baked into documents at index time (`web/*/base_url`, `web/default/cms_home_page`, store code / `web/url/use_store`, locale) publish nothing when they change: run `bin/magento thedevkitchen:cmssearch:reindex --sync` afterwards.
- `--sync`: run with the consumer stopped, or follow with `reindex --entity=page` (messages processed between the start of a build and the alias swap are applied to the old index).

### Known limits

- The consumer reads the store list once per process (restart it after adding or deleting a store view).
- Two DEBUG log lines per message.
- The first search on an idle index triggers a refresh (see Refresh semantics).
- No catch-up after the `--sync` swap (see Reindex command).

### Plan 5/6 handoff

- Plan 5 reads the alias `{prefix}_thedevkitchen_cmssearch_{store_id}` (`Model\Indexer\IndexNameResolver::getAlias()`), filters on `store_view_id`/`website_id`, aggregates `content_type_code` and `classification_ancestor_ids`, and shortens `summary` to `thedevkitchen_cmssearch/results/summary_length` for display. `title`, `text` and `summary` are PLAIN TEXT after entity decoding and may legitimately contain `<`: Plan 5 must escape them on output. Allow-list new readers in `Test/Unit/Queue/AsyncOnlyContractTest` only if they write.
- Plan 6 adds block text to `text` from `ExtractedContent::getDirectives()` (widget instances of page group `all_pages` are NOT indexed on any host: user decision), fills `segment_ids`, and adds category documents (`entity_type=category`, `_id` `category_<id>`) and new entity types to `AffectedPages`. Any new field needs `IndexDefinition` + `MAPPING_VERSION` + the upgrade path above. Documents already carry `entity_type` (`page`), `content_type_code` and the classification fields for aggregations.

## Pending items (backlog)

- Dynamic Blocks restricted by a Customer Segment are excluded from the index in v1 (spec option A). The segment ids will be stored so option B (filter by the logged-in customer's segments at query time) can be enabled later; that needs a decision on the Varnish impact (customer-dependent results) and on cart/catalog price rules tied to Dynamic Blocks.

## Tests

Run sequentially, never concurrently (shared admin/DB/broker state).

- Unit: `php vendor/bin/phpunit -c phpunit.xml` (repo root).
- ACL / data rules: `bash integration/test_content_type_acl.sh`, `bash integration/test_classification.sh`.
- Reindex queue against the real RabbitMQ: `bash integration/test_cms_search_queue.sh` (144 checks, about 4.5 minutes against the real processor). OPT-IN: without `CMSSEARCH_QUEUE_SUITE=1` it prints SKIP and exits 0, so `./integration/run.sh` and the Stop hook do not run it. `CMSSEARCH_QUEUE_BACKLOG=200` shortens the backlog case. It purges both module queues at start and end, refuses to start when the main queue already holds messages unless `CMSSEARCH_QUEUE_PURGE_OK=1` (then run `bin/magento thedevkitchen:cmssearch:reindex` afterwards), and skips when a consumer of the queue is running.
- CMS page index against the real OpenSearch and the real consumer: `CMSSEARCH_INDEX_SUITE=1 bash integration/test_cms_search_index.sh` (130 checks, about 3.5 minutes). OPT-IN: without the variable it prints SKIP and exits 0 (so `./integration/run.sh` and the Stop hook skip it). It refuses to start when the main queue holds messages unless `CMSSEARCH_QUEUE_PURGE_OK=1`, purges the module's two queues, skips when a consumer runs, writes only `cmssearch-` pages/`cmssearch_` codes and removes their documents; it rebuilds the index of store view 2 with `--sync` (zero failed searches during the alias switch).
- Opt-in switches: `CMSSEARCH_QUEUE_SUITE=1` (queue suite), `CMSSEARCH_INDEX_SUITE=1` (index suite), `CMSSEARCH_QUEUE_PURGE_OK=1` (allow purging a non-empty main queue; then run `bin/magento thedevkitchen:cmssearch:reindex --entity=page` afterwards).
- The two data-rule suites run from `./integration/run.sh` (the queue suite skips there); they need the Warden php-fpm (and rabbitmq) containers and clean up after themselves.
- Admin flows (Cypress), one spec at a time: `cd e2e && env -u ELECTRON_RUN_AS_NODE npx cypress run --spec cypress/e2e/<spec>` with `content-type-admin.cy.js`, `classification-admin.cy.js`, `cms-page-search-fieldset.cy.js`, `cms-page-search-fieldset-existing.cy.js`. They share `e2e/cypress/support/cmssearch.js` (login, helpers and the single `use_form_key` baseline file, so the config flag is restored once).
- Test-only helper: `integration/support/cmssearch_fixture.php` (CLI only; writes only `cmssearch_` codes/users and `cmssearch-` pages/Staging updates; `link-page`/`unlink-page` save and restore the link a page had before; backups live in `var/cmssearch-fixture`; queue commands run the real consumer in-process with a probe processor and can make the broker unreachable for their own process only).
