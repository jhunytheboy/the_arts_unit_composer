<?php

// One save per node (not one save per field) - touching a node once per
// field would create extra revisions and run the full hook stack multiple
// times for no reason.
$fields_by_bundle_set = [
  'field_exclude_listings' => ['book', 'event', 'local_arts_programs', 'page', 'program', 'resource', 'webform'],
  'field_exclude_book_nav' => ['book', 'event', 'local_arts_programs', 'page', 'program', 'resource', 'webform'],
  'field_exclude_search_engines' => ['article', 'book', 'layout_page', 'page', 'program', 'resource', 'video'],
  'field_exclude_site_search' => ['article', 'book', 'layout_page', 'page', 'program', 'resource', 'video'],
];

$storage = \Drupal::entityTypeManager()->getStorage('node');
$all_bundles = array_unique(array_merge(...array_values($fields_by_bundle_set)));

$nids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', $all_bundles, 'IN')
  ->execute();

echo 'Nodes to check: ' . count($nids) . "\n";

$touched = 0;
$field_backfill_counts = array_fill_keys(array_keys($fields_by_bundle_set), 0);

foreach (array_chunk($nids, 200) as $chunk) {
  foreach ($storage->loadMultiple($chunk) as $node) {
    $needs_save = FALSE;
    foreach ($fields_by_bundle_set as $field => $bundles) {
      if (in_array($node->bundle(), $bundles, TRUE) && $node->hasField($field) && $node->get($field)->isEmpty()) {
        $node->set($field, 0);
        $field_backfill_counts[$field]++;
        $needs_save = TRUE;
      }
    }
    if ($needs_save) {
      $node->save();
      $touched++;
    }
  }
}

echo "Nodes saved: $touched\n";
foreach ($field_backfill_counts as $field => $count) {
  echo "  $field: backfilled $count\n";
}
echo "Done.\n";
