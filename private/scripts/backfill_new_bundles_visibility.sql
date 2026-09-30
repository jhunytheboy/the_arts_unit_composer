-- Backfills field_exclude_book_nav and field_exclude_listings with an
-- explicit 0 for article/layout_page/video nodes, which just got these
-- field instances added and so currently have NULL (not 0) for every
-- existing node - same pattern/reasoning as the original backfill.
--
-- Writes directly to each node's EXISTING current revision (no new
-- revisions created, no node_revision_delete queue impact).

-- field_exclude_book_nav
INSERT INTO node__field_exclude_book_nav (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_book_nav_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('article','layout_page','video')
  AND NOT EXISTS (SELECT 1 FROM node__field_exclude_book_nav e WHERE e.entity_id = nfd.nid AND e.langcode = nfd.langcode AND e.deleted = 0);

INSERT INTO node_revision__field_exclude_book_nav (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_book_nav_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('article','layout_page','video')
  AND NOT EXISTS (SELECT 1 FROM node_revision__field_exclude_book_nav e WHERE e.entity_id = nfd.nid AND e.revision_id = nfd.vid AND e.langcode = nfd.langcode AND e.deleted = 0);

-- field_exclude_listings
INSERT INTO node__field_exclude_listings (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_listings_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('article','layout_page','video')
  AND NOT EXISTS (SELECT 1 FROM node__field_exclude_listings e WHERE e.entity_id = nfd.nid AND e.langcode = nfd.langcode AND e.deleted = 0);

INSERT INTO node_revision__field_exclude_listings (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_listings_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('article','layout_page','video')
  AND NOT EXISTS (SELECT 1 FROM node_revision__field_exclude_listings e WHERE e.entity_id = nfd.nid AND e.revision_id = nfd.vid AND e.langcode = nfd.langcode AND e.deleted = 0);

-- Same gap, different fields/bundles: event, local_arts_programs and
-- webform got field_exclude_search_engines/field_exclude_site_search
-- added in a later batch than the original 7 bundles, so they were never
-- covered by the original backfill and are still NULL for most existing
-- content. Any node that already has an explicit value (e.g. real
-- editorial use since the field went live) is left untouched.

-- field_exclude_search_engines
INSERT INTO node__field_exclude_search_engines (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_search_engines_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('event','local_arts_programs','webform')
  AND NOT EXISTS (SELECT 1 FROM node__field_exclude_search_engines e WHERE e.entity_id = nfd.nid AND e.langcode = nfd.langcode AND e.deleted = 0);

INSERT INTO node_revision__field_exclude_search_engines (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_search_engines_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('event','local_arts_programs','webform')
  AND NOT EXISTS (SELECT 1 FROM node_revision__field_exclude_search_engines e WHERE e.entity_id = nfd.nid AND e.revision_id = nfd.vid AND e.langcode = nfd.langcode AND e.deleted = 0);

-- field_exclude_site_search
INSERT INTO node__field_exclude_site_search (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_site_search_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('event','local_arts_programs','webform')
  AND NOT EXISTS (SELECT 1 FROM node__field_exclude_site_search e WHERE e.entity_id = nfd.nid AND e.langcode = nfd.langcode AND e.deleted = 0);

INSERT INTO node_revision__field_exclude_site_search (bundle, deleted, entity_id, revision_id, langcode, delta, field_exclude_site_search_value)
SELECT nfd.type, 0, nfd.nid, nfd.vid, nfd.langcode, 0, 0
FROM node_field_data nfd
WHERE nfd.type IN ('event','local_arts_programs','webform')
  AND NOT EXISTS (SELECT 1 FROM node_revision__field_exclude_site_search e WHERE e.entity_id = nfd.nid AND e.revision_id = nfd.vid AND e.langcode = nfd.langcode AND e.deleted = 0);
