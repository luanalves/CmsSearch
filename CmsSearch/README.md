# Thedevkitchen_CmsSearch

Indexes CMS content in OpenSearch and shows it in tabs on the search result page. Specs: `specs/2026-10-11-cms-search/`. Plans: `docs/superpowers/plans/2026-10-11-cms-search-*.md`.

## Status

Plan 1 delivered: editorial content type registry.

- Declarative schema (`thedevkitchen_cmssearch_content_type`, `_label` per store view, `_page_classification`), repository/service contracts, validation (code unique, immutable, format; default label required).
- Admin CRUD under Content > CMS Search > Content Types: grid (filters, sort, keyword, inline edit, mass delete/status, linked-pages column) and form with per-store-view labels.
- Granular ACL and per-controller `ADMIN_RESOURCE`.
- Delete blocked while CMS Pages are linked (repository guard, delete controller and mass action).
- Dropdown option source (active types only) ready for the CMS Page form (Plan 2).
- Test fixtures: ACL role/user fixture used by the ACL curl script; Cypress spec for the admin flows.
- i18n: `en_US.csv` and `pt_BR.csv`.

Not delivered yet: classification tree, CMS Page form fields, queue, indexer, search UI (Plans 2-7).

## Content types

- Admin: Content > CMS Search > Content Types.
- ACL: `Thedevkitchen_CmsSearch::content_types` (view), `::content_types_create`, `::content_types_edit`, `::content_types_delete`.
- A type linked to CMS Pages cannot be deleted; deactivate it instead. No type is seeded: create them in the admin.
- The `page_classification` foreign key to the content type is `NO ACTION` (InnoDB enforces it like RESTRICT; `schema.xsd` rejects the `RESTRICT` keyword). It is the database safety net behind the application-level guard.

## Pending items (backlog)

- Dynamic Blocks restricted by a Customer Segment are excluded from the index in v1 (spec option A). The segment ids will be stored so option B (filter by the logged-in customer's segments at query time) can be enabled later; that needs a decision on the Varnish impact (customer-dependent results) and on cart/catalog price rules tied to Dynamic Blocks.

## Tests

- Unit: `vendor/bin/phpunit` (repo root).
- ACL / endpoints: `bash integration/test_content_type_acl.sh` (also run by `./integration/run.sh`).
- Admin flows: `cd e2e && env -u ELECTRON_RUN_AS_NODE npx cypress run --spec cypress/e2e/content-type-admin.cy.js`.
