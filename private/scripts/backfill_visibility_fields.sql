-- Backfills field_exclude_listings, field_exclude_book_nav,
-- field_exclude_search_engines, field_exclude_site_search with an
-- explicit 0 for every existing node that currently has no value at all
-- (NULL, not 0) - these fields were added via config, which only sets
-- defaults for entities created afterward, never backfills existing
-- content.
--
-- Writes directly to each node's EXISTING current revision (no new
-- revisions created - no node_revision_delete queue impact). Validated
-- against a full production data copy on 2026-09-11: correct counts,
-- zero duplicates, pre-existing values preserved, readable via the
-- entity API after cache rebuild, no side effects, ~2 seconds runtime.
--
-- Run with: ddev mysql < backfill_visibility_fields.sql (or drush sql:cli
-- < this file on production). Then run `drush cr` before verifying
-- anything through Drupal - this bypasses Drupal's entity cache
-- entirely, so cached reads will be stale until cache is rebuilt.

-- field_exclude_listings
INSERT INTO node__field_exclude_listings (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_listings_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('book','event','local_arts_programs','page','program','resource','webform')
  AND NOT EXISTS (SELECT 1 FROM node__field_exclude_listings e WHERE e.entity_id = nfd.nid AND e.langcode = nfd.langcode AND e.deleted = 0);

INSERT INTO node_revision__field_exclude_listings (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_listings_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('book','event','local_arts_programs','page','program','resource','webform')
  AND NOT EXISTS (SELECT 1 FROM node_revision__field_exclude_listings e WHERE e.entity_id = nfd.nid AND e.revision_id = nfd.vid AND e.langcode = nfd.langcode AND e.deleted = 0);

-- field_exclude_book_nav
INSERT INTO node__field_exclude_book_nav (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_book_nav_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('book','event','local_arts_programs','page','program','resource','webform')
  AND NOT EXISTS (SELECT 1 FROM node__field_exclude_book_nav e WHERE e.entity_id = nfd.nid AND e.langcode = nfd.langcode AND e.deleted = 0);

INSERT INTO node_revision__field_exclude_book_nav (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_book_nav_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('book','event','local_arts_programs','page','program','resource','webform')
  AND NOT EXISTS (SELECT 1 FROM node_revision__field_exclude_book_nav e WHERE e.entity_id = nfd.nid AND e.revision_id = nfd.vid AND e.langcode = nfd.langcode AND e.deleted = 0);

-- field_exclude_search_engines
INSERT INTO node__field_exclude_search_engines (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_search_engines_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('article','book','layout_page','page','program','resource','video')
  AND NOT EXISTS (SELECT 1 FROM node__field_exclude_search_engines e WHERE e.entity_id = nfd.nid AND e.langcode = nfd.langcode AND e.deleted = 0);

INSERT INTO node_revision__field_exclude_search_engines (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_search_engines_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('article','book','layout_page','page','program','resource','video')
  AND NOT EXISTS (SELECT 1 FROM node_revision__field_exclude_search_engines e WHERE e.entity_id = nfd.nid AND e.revision_id = nfd.vid AND e.langcode = nfd.langcode AND e.deleted = 0);

-- field_exclude_site_search
INSERT INTO node__field_exclude_site_search (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_site_search_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('article','book','layout_page','page','program','resource','video')
  AND NOT EXISTS (SELECT 1 FROM node__field_exclude_site_search e WHERE e.entity_id = nfd.nid AND e.langcode = nfd.langcode AND e.deleted = 0);

INSERT INTO node_revision__field_exclude_site_search (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_site_search_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('article','book','layout_page','page','program','resource','video')
  AND NOT EXISTS (SELECT 1 FROM node_revision__field_exclude_site_search e WHERE e.entity_id = nfd.nid AND e.revision_id = nfd.vid AND e.langcode = nfd.langcode AND e.deleted = 0);
