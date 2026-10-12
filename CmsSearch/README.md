# Thedevkitchen_CmsSearch

Indexes CMS content in OpenSearch and shows it in tabs on the search result page. Specs: `specs/2026-10-11-cms-search/`. Plans: `docs/superpowers/plans/2026-10-11-cms-search-*.md`.

## Status

- Plan 1 delivered: editorial content type registry (declarative schema, repository/service contracts and validation, admin CRUD with per-store-view labels, granular ACL, delete blocked while CMS Pages are linked, dropdown option source).
- Plan 2 delivered: classification tree per website (admin tree, term form, move, delete rules, ACL), and the "Search" fieldset of the CMS Page form (content type + classification term), validated and persisted server-side by an observer inside the page-save transaction.
- Plan 3 delivered: asynchronous reindex queue (exclusive topic/queue/consumer `thedevkitchen.cmssearch.reindex` with a dead-letter queue), after-commit publishing from every write path, consumer handler with an attempt counter (cap 3), reindex command, configuration section. Nothing is indexed yet: the processor is a placeholder.
- i18n: `en_US.csv` and `pt_BR.csv` cover the three plans.

Not delivered yet: indexer (Plan 4), search UI (Plan 5), block references and enrichment (Plan 6).

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
| Log | `var/log/cmssearch_reindex.log`: `processed`, `retry scheduled`, `dead-lettered ...`, `publish failed`, `reindex request dropped` |
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
- Not published, by design: store view/website deletion (index lifecycle, Plan 4); the test fixture's raw-SQL commands (`type-purge`, `term-purge`, `seed-link`, `link-page`, `unlink-page`) and the Cypress cleanup request, which bypass the repositories.

### Consumer, retry and DLQ

- Handler `Model\Queue\ReindexHandler::process`; the work is delegated to `Model\Queue\ReindexProcessorInterface` (Plan 3 binds `NullReindexProcessor`, which does nothing; Plan 4 replaces the preference in `etc/di.xml`).
- AMQP has no automatic retry (the framework consumer rejects with `requeue=false`). The handler republishes a failed message with `attempt + 1` until Maximum Attempts tries (default 3 = first try + 2 retries), then throws and RabbitMQ dead-letters it into the DLQ. Payloads that can never succeed go straight to the DLQ. PHP errors in the processor count as transient failures. Retries are immediate (republished at once, no backoff).
- Framework behaviour to know: a message without a `message_id` property is acknowledged and dropped (our publisher always sets one); the same `message_id` delivered twice is processed once (`queue_lock`).
- Idempotent: duplicate and out-of-order messages are safe; a second instance of the consumer is safe (do not configure `multiple_processes` for it anyway).

### Running the consumer

- Production: the native cron job `consumers_runner` (group `consumers`) starts it. This project's `app/etc/env.php` has no `cron_consumers_runner` key, so every consumer runs by cron with the defaults (`cron_run=true`, `max_messages=10000`; this consumer declares `maxMessages="1000"`, so it exits after 1000 messages and the next cron run restarts it). `bin/magento cron:run` must be scheduled.
- Deploy step when an environment restricts consumers: add `'thedevkitchen.cmssearch.reindex'` to `cron_consumers_runner/consumers` (with `bin/magento setup:config:set` or by editing `app/etc/env.php`; never from the module):
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

### Reindex command

`bin/magento thedevkitchen:cmssearch:reindex [--entity=page|all] [--store=ID]` publishes one `page`/`save` message per CMS page (keyset batches of 500 page ids) and exits; the consumer does the work. Exit code 1 for an unsupported entity, an invalid or unknown store view, or when a message could not be published. `--sync` (Plan 4) and categories (Plan 6) are not available yet.

### Changing the topology

`bin/magento setup:upgrade` declares exchanges, queues and bindings. RabbitMQ refuses to redeclare an existing queue with different arguments (`PRECONDITION_FAILED`): changing the dead-letter arguments requires deleting the queue first (development only) or a planned migration (production).

### Known limits

- Saving a content type or a classification term publishes one message for the entity; the consumer (Plan 4) expands it to every linked page (content type) or to the pages of the term's current subtree, so one save can fan out to many pages.
- Content type and term saves publish on every save, including unchanged ones.
- Store view and website deletion publish nothing (per-store-view index lifecycle: Plan 4). Blocks, dynamic blocks, products and categories are Plan 6.
- Test fixtures use raw SQL and bypass events (no messages); so does the Cypress cleanup request.
- The reindex command does not stop at the first failed send: with the broker down every send is attempted (and counted), so a large site waits for the connection timeouts before the command exits 1.

### Staging

- Hooks: `cms_page_save_after`/`cms_page_delete_after` (every Staging operation on a page fires them) plus the `StagingApplier` post-processor `PageVersionApplied`. When the applier (cron `staging_apply_version`) activates a version it saves the page (`page`/`save`, merged per transaction) and the post-processor then sends `page`/`staging`: two messages per page, harmless (a redundant reindex).
- Verified by `integration/test_cms_search_queue.sh`: scheduling and removing a version publish page messages, and the post-processor is registered once on the DI-built `StagingApplier` and publishes `staging` messages.
- Not run by the suite because it would apply every due update of the shared database and move the global Staging version: the real time-based activation. Manual check on a disposable environment with cron running: schedule an update two minutes ahead on a test page, wait for `staging_apply_version`, then `rabbitmqadmin get queue=thedevkitchen.cmssearch.reindex ackmode=ack_requeue_true` shows `"action":"staging"` for that page.

### Plan 4 handoff

- Implement `Model\Queue\ReindexProcessorInterface` and replace the `NullReindexProcessor` preference; add the class to the allow-list of `Test/Unit/Queue/AsyncOnlyContractTest`.
- Re-read the current state for every message (action is a hint); a deleted or invisible page removes its document; intersect the term's website with the page's websites at index time (links can go stale); effective-active term (ancestors) and active type decide indexability.
- `classification_term`/`save`: reindex the pages linked to the term's current subtree (`fetchSubtree` by the term's path); `content_type`: the pages of the type; `store_id` in a message limits the work to that store view.
- A page, content type or term that no longer exists is SUCCESS, not an error (it is a normal race: the entity was deleted after the message was published).
- Throw `InvalidReindexMessageException` only for requests that can never succeed; everything else is retried.
- Store view/website deletion is not published by Plan 3: handle the per-store-view index lifecycle.

## Pending items (backlog)

- Dynamic Blocks restricted by a Customer Segment are excluded from the index in v1 (spec option A). The segment ids will be stored so option B (filter by the logged-in customer's segments at query time) can be enabled later; that needs a decision on the Varnish impact (customer-dependent results) and on cart/catalog price rules tied to Dynamic Blocks.

## Tests

Run sequentially, never concurrently (shared admin/DB/broker state).

- Unit: `php vendor/bin/phpunit -c phpunit.xml` (repo root).
- ACL / data rules: `bash integration/test_content_type_acl.sh`, `bash integration/test_classification.sh`.
- Reindex queue against the real RabbitMQ: `bash integration/test_cms_search_queue.sh` (144 checks, several minutes; `CMSSEARCH_QUEUE_BACKLOG=200` shortens the backlog case). It purges both module queues at start and end and skips when a consumer of the queue is running.
- All three run from `./integration/run.sh`; they need the Warden php-fpm (and rabbitmq) containers and clean up after themselves.
- Admin flows (Cypress), one spec at a time: `cd e2e && env -u ELECTRON_RUN_AS_NODE npx cypress run --spec cypress/e2e/<spec>` with `content-type-admin.cy.js`, `classification-admin.cy.js`, `cms-page-search-fieldset.cy.js`, `cms-page-search-fieldset-existing.cy.js`. They share `e2e/cypress/support/cmssearch.js` (login, helpers and the single `use_form_key` baseline file, so the config flag is restored once).
- Test-only helper: `integration/support/cmssearch_fixture.php` (CLI only; writes only `cmssearch_` codes/users and `cmssearch-` pages/Staging updates; `link-page`/`unlink-page` save and restore the link a page had before; backups live in `var/cmssearch-fixture`; queue commands run the real consumer in-process with a probe processor and can make the broker unreachable for their own process only).
