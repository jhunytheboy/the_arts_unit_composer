<?php

/**
 * @file
 * Repoints field_enquiries references from a duplicate contact node to another.
 *
 * Usage (dry run by default – reports only, changes nothing):
 *   drush php:script private/scripts/merge_contact_refs.php -- 14593 1996
 *   drush php:script private/scripts/merge_contact_refs.php -- 14593 1996 apply
 *
 * Updates live field data plus each node's current revision only; older
 * revisions keep their history. Writes directly to the field tables rather
 * than saving entities, because a published -> published save counts as the
 * "publish" transition and would send content_moderation_notifications emails
 * and create a new revision for every affected node.
 */

use Drupal\Core\Cache\Cache;

[$from, $to, $mode] = array_pad($extra ?? [], 3, NULL);
$from = (int) $from;
$to = (int) $to;
$apply = $mode === 'apply';

if (!$from || !$to || $from === $to) {
  echo "Usage: drush php:script merge_contact_refs.php -- <from_nid> <to_nid> [apply]\n";
  return;
}

$db = \Drupal::database();
$titles = $db->query("SELECT nid, CONCAT(type, ': ', title) FROM {node_field_data} WHERE nid IN (:ids[])", [':ids[]' => [$from, $to]])->fetchAllKeyed();
if (count($titles) !== 2) {
  echo "Both nodes must exist.\n";
  return;
}
echo "From $from ({$titles[$from]})\n  to $to ({$titles[$to]})\n";

$nids = $db->query("SELECT DISTINCT entity_id FROM {node__field_enquiries} WHERE field_enquiries_target_id = :f ORDER BY entity_id", [':f' => $from])->fetchCol();
$term_refs = $db->query("SELECT entity_id FROM {taxonomy_term__field_enquiries} WHERE field_enquiries_target_id = :f", [':f' => $from])->fetchCol();
$user_refs = $db->query("SELECT entity_id FROM {user__field_related_contact} WHERE field_related_contact_target_id = :f", [':f' => $from])->fetchCol();
if ($term_refs || $user_refs) {
  // Not handled here – these would need their own update.
  echo "WARNING: also referenced by terms [" . implode(',', $term_refs) . "] / users [" . implode(',', $user_refs) . "]\n";
}

// A node referencing both would end up listing the contact twice.
$both = $db->query("SELECT DISTINCT a.entity_id FROM {node__field_enquiries} a JOIN {node__field_enquiries} b ON a.entity_id = b.entity_id WHERE a.field_enquiries_target_id = :f AND b.field_enquiries_target_id = :t", [':f' => $from, ':t' => $to])->fetchCol();
if ($both) {
  echo "Aborting: nodes reference both contacts: " . implode(',', $both) . "\n";
  return;
}

echo count($nids) . " nodes reference $from: " . implode(',', $nids) . "\n";
if (!$nids) {
  return;
}
if (!$apply) {
  echo "Dry run – append 'apply' to make changes.\n";
  return;
}

$tx = $db->startTransaction();
try {
  $data = $db->update('node__field_enquiries')
    ->fields(['field_enquiries_target_id' => $to])
    ->condition('field_enquiries_target_id', $from)
    ->execute();
  // Raw UPDATE ... JOIN needs $allow_row_count to report affected rows.
  $stmt = $db->prepareStatement("UPDATE {node_revision__field_enquiries} r INNER JOIN {node} n ON n.vid = r.revision_id SET r.field_enquiries_target_id = :t WHERE r.field_enquiries_target_id = :f", [], TRUE);
  $stmt->execute([':t' => $to, ':f' => $from]);
  $rev = $stmt->rowCount();
}
catch (\Throwable $e) {
  // Drupal transactions commit on destruct, so roll back explicitly.
  $tx->rollBack();
  throw $e;
}
unset($tx);
echo "Updated $data data rows, $rev current-revision rows.\n";

Cache::invalidateTags(array_merge(['node_list', "node:$from", "node:$to"], array_map(fn($n) => "node:$n", $nids)));
\Drupal::entityTypeManager()->getStorage('node')->resetCache($nids);

if (\Drupal::moduleHandler()->moduleExists('search_api')) {
  $langcodes = $db->query("SELECT nid, langcode FROM {node_field_data} WHERE nid IN (:ids[])", [':ids[]' => $nids])->fetchAll();
  $items = array_map(fn($r) => "{$r->nid}:{$r->langcode}", $langcodes);
  foreach (\Drupal::entityTypeManager()->getStorage('search_api_index')->loadMultiple() as $index) {
    if ($index->isValidDatasource('entity:node')) {
      $index->trackItemsUpdated('entity:node', $items);
      echo "Queued " . count($items) . " items for reindex on {$index->id()}\n";
    }
  }
}
