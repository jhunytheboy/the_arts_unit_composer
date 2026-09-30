<?php

/**
 * @file
 * Reverts merge_contact_refs.php for a known list of nodes.
 *
 * Usage (dry run by default – reports only, changes nothing):
 *   drush php:script private/scripts/revert_contact_merge.php -- <orig_nid> <merged_into_nid> <nid,nid,...>
 *   drush php:script private/scripts/revert_contact_merge.php -- <orig_nid> <merged_into_nid> <nid,nid,...> apply
 *
 * The nid list must be the nodes that referenced <orig_nid> before the merge
 * (the list merge_contact_refs.php printed). Revision history alone can't be
 * trusted: the merge rewrote each node's current revision in place, so nodes
 * with only one revision keep no record of the original reference. History is
 * still used as a cross-check to flag merged nodes missing from the list.
 *
 * Only rows on listed nodes currently pointing at <merged_into_nid> change, in
 * live data and the current revision – mirroring what the merge touched. This
 * assumes none of the listed nodes referenced <merged_into_nid> before the
 * merge (the merge script aborts in that case, so it holds).
 *
 * 2026-09-30 Alison Buckley revert:
 *   -- 14593 1996 2086,2171,2418,2430,7787,14182,14298,14300,14366,14367,14393,14394,14395,14404,14405,14406,14416,14418,14421,14422,14468,14479,14667,14668,14669,15459,15460,15590,15591,15592,15758,18415,18416
 */

use Drupal\Core\Cache\Cache;

[$orig, $merged, $list, $mode] = array_pad($extra ?? [], 4, NULL);
$orig = (int) $orig;
$merged = (int) $merged;
$nids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $list)))));
$apply = $mode === 'apply';

if (!$orig || !$merged || $orig === $merged || !$nids) {
  echo "Usage: drush php:script revert_contact_merge.php -- <orig_nid> <merged_into_nid> <nid,nid,...> [apply]\n";
  return;
}

$db = \Drupal::database();
$titles = $db->query("SELECT nid, CONCAT(type, ': ', title) FROM {node_field_data} WHERE nid IN (:ids[])", [':ids[]' => [$orig, $merged]])->fetchAllKeyed();
if (count($titles) !== 2) {
  echo "Both contact nodes must exist.\n";
  return;
}
echo "Restore $orig ({$titles[$orig]})\n   from $merged ({$titles[$merged]})\n";

$missing = array_diff($nids, $db->query("SELECT nid FROM {node} WHERE nid IN (:ids[])", [':ids[]' => $nids])->fetchCol());
if ($missing) {
  echo "Listed nodes that don't exist (skipped): " . implode(',', $missing) . "\n";
}

// Rows to restore: listed nodes, current revision, pointing at $merged.
$rows = $db->query("SELECT c.entity_id, c.langcode, c.delta FROM {node_revision__field_enquiries} c INNER JOIN {node} n ON n.nid = c.entity_id AND n.vid = c.revision_id WHERE c.entity_id IN (:ids[]) AND c.field_enquiries_target_id = :m ORDER BY c.entity_id, c.delta", [':ids[]' => $nids, ':m' => $merged])->fetchAll();
$found = array_values(array_unique(array_map(fn($r) => (int) $r->entity_id, $rows)));
echo count($rows) . " references on " . count($found) . " nodes will go back to $orig: " . implode(',', $found) . "\n";

$not_merged = array_diff($nids, $found, $missing);
if ($not_merged) {
  // Already reverted, edited since, or never merged – left alone.
  echo "Listed nodes not currently pointing at $merged (left alone): " . implode(',', $not_merged) . "\n";
}

// Cross-check: current revision points at $merged but the previous revision
// had $orig at the same delta – looks merged, yet isn't in the list.
$history = $db->query("SELECT DISTINCT c.entity_id FROM {node_revision__field_enquiries} c INNER JOIN {node} n ON n.nid = c.entity_id AND n.vid = c.revision_id INNER JOIN {node_revision__field_enquiries} p ON p.entity_id = c.entity_id AND p.langcode = c.langcode AND p.delta = c.delta AND p.revision_id = (SELECT MAX(r.vid) FROM {node_revision} r WHERE r.nid = c.entity_id AND r.vid < c.revision_id) WHERE c.field_enquiries_target_id = :m AND p.field_enquiries_target_id = :o", [':m' => $merged, ':o' => $orig])->fetchCol();
$unlisted = array_diff($history, $nids);
if ($unlisted) {
  echo "CHECK MANUALLY – history suggests these were merged too, but they're not in the list: " . implode(',', $unlisted) . "\n";
}

if (!$rows) {
  return;
}
if (!$apply) {
  echo "Dry run – append 'apply' to make changes.\n";
  return;
}

$tx = $db->startTransaction();
try {
  $data = $db->update('node__field_enquiries')
    ->fields(['field_enquiries_target_id' => $orig])
    ->condition('entity_id', $found, 'IN')
    ->condition('field_enquiries_target_id', $merged)
    ->execute();
  // Raw UPDATE ... JOIN needs $allow_row_count to report affected rows.
  $stmt = $db->prepareStatement("UPDATE {node_revision__field_enquiries} r INNER JOIN {node} n ON n.nid = r.entity_id AND n.vid = r.revision_id SET r.field_enquiries_target_id = :o WHERE r.entity_id IN (" . implode(',', $found) . ") AND r.field_enquiries_target_id = :m", [], TRUE);
  $stmt->execute([':o' => $orig, ':m' => $merged]);
  $rev = $stmt->rowCount();
}
catch (\Throwable $e) {
  // Drupal transactions commit on destruct, so roll back explicitly.
  $tx->rollBack();
  throw $e;
}
unset($tx);
echo "Updated $data data rows, $rev current-revision rows.\n";

Cache::invalidateTags(array_merge(['node_list', "node:$orig", "node:$merged"], array_map(fn($n) => "node:$n", $found)));
\Drupal::entityTypeManager()->getStorage('node')->resetCache($found);

if (\Drupal::moduleHandler()->moduleExists('search_api')) {
  $langcodes = $db->query("SELECT nid, langcode FROM {node_field_data} WHERE nid IN (:ids[])", [':ids[]' => $found])->fetchAll();
  $items = array_map(fn($r) => "{$r->nid}:{$r->langcode}", $langcodes);
  foreach (\Drupal::entityTypeManager()->getStorage('search_api_index')->loadMultiple() as $index) {
    if ($index->isValidDatasource('entity:node')) {
      $index->trackItemsUpdated('entity:node', $items);
      echo "Queued " . count($items) . " items for reindex on {$index->id()}\n";
    }
  }
}
