<?php

/**
 * @file
 * Renames the office on contact nodes (field_office), e.g. for the
 * The Arts Unit → Arts and Initiatives rename.
 *
 * Usage (dry run by default – reports only, changes nothing):
 *   drush php:script private/scripts/rename_contact_office.php
 *   drush php:script private/scripts/rename_contact_office.php -- apply
 *
 * Only contacts whose office is exactly OLD_OFFICE change. Each one is saved
 * through the entity API as a new revision, so auto_entitylabel regenerates
 * the "[name] | [role] | [office]" title, caches clear and search reindexes.
 * Moderation state is left as it was.
 *
 * The contact_publishing moderation notification emails on every publish
 * transition, so it is disabled for the run and restored afterwards (even on
 * failure) – otherwise each published contact would send an email.
 *
 * 2026-10-09: 75 contacts, "The Arts Unit" → "Arts and Initiatives".
 */

const OLD_OFFICE = 'The Arts Unit';
const NEW_OFFICE = 'Arts and Initiatives';
const NOTIFICATION = 'contact_publishing';

$apply = ($extra[0] ?? NULL) === 'apply';

$storage = \Drupal::entityTypeManager()->getStorage('node');
$nids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'contact')
  ->condition('field_office', OLD_OFFICE)
  ->sort('nid')
  ->execute();

echo count($nids) . " contacts with office '" . OLD_OFFICE . "' → '" . NEW_OFFICE . "'\n";
if (!$nids) {
  return;
}

foreach ($storage->loadMultiple($nids) as $node) {
  // The entity query is case-insensitive on MySQL; only touch exact matches.
  if ($node->get('field_office')->value !== OLD_OFFICE) {
    echo "  skip {$node->id()} (office is '{$node->get('field_office')->value}')\n";
    unset($nids[array_search($node->id(), $nids)]);
    continue;
  }
  $state = $node->hasField('moderation_state') ? ($node->get('moderation_state')->value ?? '-') : '-';
  echo "  {$node->id()} [$state] {$node->label()}\n";
}

if (!$apply) {
  echo "Dry run – append 'apply' to make changes.\n";
  return;
}

$notification = \Drupal::entityTypeManager()
  ->getStorage('content_moderation_notification')
  ->load(NOTIFICATION);
$was_enabled = $notification && $notification->status();
if ($was_enabled) {
  $notification->disable()->save();
  echo "Disabled notification " . NOTIFICATION . " for the run.\n";
}

$saved = 0;
try {
  foreach ($storage->loadMultiple($nids) as $node) {
    $node->set('field_office', NEW_OFFICE);
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId(1);
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->setRevisionLogMessage('Office renamed from "' . OLD_OFFICE . '" to "' . NEW_OFFICE . '" (rename_contact_office.php).');
    $node->save();
    $saved++;
    echo "  saved {$node->id()} → {$node->label()}\n";
  }
}
finally {
  if ($was_enabled) {
    $notification->enable()->save();
    echo "Re-enabled notification " . NOTIFICATION . ".\n";
  }
}

echo "Saved $saved of " . count($nids) . " contacts.\n";
