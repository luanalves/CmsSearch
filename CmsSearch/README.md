# Thedevkitchen_CmsSearch

Indexes CMS content in OpenSearch and shows it in tabs on the search result page. Specs: `specs/2026-10-11-cms-search/`. Plans: `docs/superpowers/plans/2026-10-11-cms-search-*.md`.

## Status

- Plan 1 delivered: editorial content type registry (declarative schema, repository/service contracts and validation, admin CRUD with per-store-view labels, granular ACL, delete blocked while CMS Pages are linked, dropdown option source).
- Plan 2 delivered: classification tree per website (admin tree, term form, move, delete rules, ACL), and the "Search" fieldset of the CMS Page form (content type + classification term), validated and persisted server-side by an observer inside the page-save transaction.
- i18n: `en_US.csv` and `pt_BR.csv` cover both plans.

Not delivered yet: queue and reindex messages (Plan 3), indexer (Plan 4), search UI (Plan 5), block references and enrichment (Plan 6).

## Content types

- Admin: Content > CMS Search > Content Types.
- ACL: `Thedevkitchen_CmsSearch::content_types` (view), `::content_types_create`, `::content_types_edit`, `::content_types_delete`.
- A type linked to CMS Pages cannot be deleted; deactivate it instead. No type is seeded: create them in the admin.
- The `page_classification` foreign key to the content type is `NO ACTION` (InnoDB enforces it like RESTRICT; `schema.xsd` rejects the `RESTRICT` keyword). It is the database safety net behind the application-level guard.

## Classification

- Admin: Content > CMS Search > Classification. Pick a website, then "Add Root Term", "Add child", "Edit".
- One tree per website. A term has a code (lowercase letters, digits, underscore; unique in the website; immutable), a default label plus optional labels for the store views of its website, a sort order among its siblings and an active flag.
- Move: change "Parent term" in the term form. The term and all of its descendants get a new `path`/`level` in one transaction. A term cannot move under itself or a descendant, nor to another website; the tree has at most 10 levels.
- Delete is blocked while the term has child terms or linked CMS Pages (the message lists up to 5 pages). Deactivation is always allowed; a deactivated term hides its whole branch from the CMS Page form.
- ACL: `Thedevkitchen_CmsSearch::classification` (read-only tree and forms) and `::classification_manage` (create, edit, move, activate, delete).
- Database: `thedevkitchen_cmssearch_classification_term` (`website_id` FK CASCADE, `parent_id` self FK NO ACTION) and `..._term_label`; `page_classification.term_id` has an FK NO ACTION. Consequence of the NO ACTION self FK: deleting a whole website whose tree has more than one level fails at the database level; delete or move its terms first (website deletion is also blocked while pages are linked to its terms).

## CMS Page form: "Search" fieldset

- Two single-select fields: "Content Type" (active types; a page linked to a type that was later deactivated keeps it, shown as "(inactive)") and "Search Classification" (active terms of the websites of the page's store views; All Store Views = every website).
- Both filled or both empty. A page without type or term stays saveable; it is simply not indexed.
- Validated on the server (`Model\PageClassification\AssignmentValidator`): type and term must exist, the term must belong to a website of the page's store views, inactive values are accepted only when they are the page's current values.
- Persisted by an observer on `cms_page_save_after` through `PageClassificationRepositoryInterface` (keyed by `page_id`, stable under Staging). The event fires inside the page-save transaction, so an invalid pair rolls the page save back and the admin sees the message. Saves that do not carry the fieldset (REST, grid inline edit, mass actions, Staging versions) leave the link untouched.
- Wiring: the fieldset is added by its own modifier pool passed to `Magento\Cms\Model\Page\DataProvider`, plus an `afterGetData` plugin for the values. A third-party module that configures the same `pool` argument would conflict.
- `events.xml` changes need `bin/magento cache:flush`; `cache:clean config` is not enough.
- The Staging "Schedule New Update" form has no "Search" fieldset on purpose: type and term belong to the page, not to a version.
- Any admin with `Magento_Cms::save` can set the two fields; no extra ACL resource.

## Pending items (backlog)

- Dynamic Blocks restricted by a Customer Segment are excluded from the index in v1 (spec option A). The segment ids will be stored so option B (filter by the logged-in customer's segments at query time) can be enabled later; that needs a decision on the Varnish impact (customer-dependent results) and on cart/catalog price rules tied to Dynamic Blocks.
- Plan 3 hooks reindex messages on the two repositories (`ClassificationTermRepository`, `PageClassificationRepository`): moving or deactivating a term will then reindex the pages of its subtree.

## Tests

Run sequentially, never concurrently (shared admin/DB state).

- Unit: `php vendor/bin/phpunit -c phpunit.xml` (repo root).
- ACL / data rules: `bash integration/test_content_type_acl.sh`, `bash integration/test_classification.sh` (both also run by `./integration/run.sh`; they need the Warden php-fpm container and clean up after themselves).
- Admin flows (Cypress), one spec at a time: `cd e2e && env -u ELECTRON_RUN_AS_NODE npx cypress run --spec cypress/e2e/<spec>` with `content-type-admin.cy.js`, `classification-admin.cy.js`, `cms-page-search-fieldset.cy.js`, `cms-page-search-fieldset-existing.cy.js`. They share `e2e/cypress/support/cmssearch.js` (login, helpers and the single `use_form_key` baseline file, so the config flag is restored once).
- Test-only helper: `integration/support/cmssearch_fixture.php` (CLI only; writes only `cmssearch_` codes/users and `cmssearch-` pages; `link-page`/`unlink-page` save and restore the link a page had before; backups live in `var/cmssearch-fixture`).
