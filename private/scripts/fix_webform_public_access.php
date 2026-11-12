<?php

/**
 * @file
 * Audits (and optionally fixes) webform access rules that give the anonymous
 * or authenticated role access to other people's submissions or to the
 * webform's administration.
 *
 * Usage (dry run by default – reports only, changes nothing):
 *   drush php:script private/scripts/fix_webform_public_access.php
 *   drush php:script private/scripts/fix_webform_public_access.php -- apply
 *
 * Background: three 2022 State Dance Festival webforms had anonymous +
 * authenticated ticked under "Administer webform & submissions", making their
 * results publicly readable/editable (found Nov 2026 via a flood of
 * sticky/locked CSRF access-denied entries in dblog). Google sign-in makes
 * every DoE user "authenticated", so authenticated in these rules means any
 * department staff member, not just our editors.
 *
 * On live: drush cex + commit + push BEFORE running with "apply", then cex
 * again afterwards to capture the fix.
 *
 * Fixed operations (roles removed on apply):
 *   administer, configuration, view_any, update_any, delete_any, purge_any
 * Reported only (left as is):
 *   test – lets the role open the Test form; low risk, review by hand.
 * Ignored (correct for public forms):
 *   create, view_own, update_own, delete_own
 *
 * Specific users and permissions in the rules are never touched. Global role
 * permissions for anonymous/authenticated that grant webform access are
 * listed at the end too (report only).
 */

use Drupal\user\Entity\Role;
use Drupal\webform\Entity\Webform;

$apply = ($extra[0] ?? NULL) === 'apply';

$public_roles = ['anonymous', 'authenticated'];
$fix_ops = ['administer', 'configuration', 'view_any', 'update_any', 'delete_any', 'purge_any'];
$report_ops = ['test'];

$db = \Drupal::database();
$rows = [];
$changed = 0;

foreach (Webform::loadMultiple() as $id => $webform) {
  $rules = $webform->getAccessRules();
  $dirty = FALSE;

  foreach (array_merge($fix_ops, $report_ops) as $op) {
    $bad = array_values(array_intersect($rules[$op]['roles'] ?? [], $public_roles));
    if (!$bad) {
      continue;
    }
    $fix = in_array($op, $fix_ops, TRUE);
    $rows[$id]['ops'][] = $op . '=' . implode('+', $bad) . ($fix ? '' : ' (report only)');
    if ($fix) {
      $rules[$op]['roles'] = array_values(array_diff($rules[$op]['roles'], $public_roles));
      $dirty = TRUE;
    }
  }

  if (!isset($rows[$id])) {
    continue;
  }

  $stats = $db->query('SELECT COUNT(*) AS n, MAX(created) AS last FROM {webform_submission} WHERE webform_id = :id', [':id' => $id])->fetchObject();
  $rows[$id] += [
    'status' => $webform->isArchived() ? 'archived' : $webform->get('status'),
    'subs' => (int) $stats->n,
    'last' => $stats->last ? date('Y-m-d', $stats->last) : '-',
    'owner' => $webform->getOwnerId(),
    'fix' => $dirty,
  ];

  if ($dirty && $apply) {
    $webform->setAccessRules($rules)->save();
    $changed++;
  }
}

// Exposed submissions first, then by id.
uksort($rows, fn($a, $b) => [$rows[$b]['subs'] > 0, $a] <=> [$rows[$a]['subs'] > 0, $b]);

printf("%-40s %-9s %5s %-10s %5s  %s\n", 'WEBFORM', 'STATUS', 'SUBS', 'LAST SUB', 'OWNER', 'PUBLIC ACCESS');
foreach ($rows as $id => $r) {
  printf("%-40s %-9s %5d %-10s %5s  %s\n", $id, $r['status'], $r['subs'], $r['last'], $r['owner'], implode(', ', $r['ops']));
}

$to_fix = count(array_filter($rows, fn($r) => $r['fix']));
echo "\n" . count($rows) . " webforms flagged, $to_fix with roles to remove.\n";
echo $apply
  ? "APPLIED: $changed webforms saved. Now run drush cex and commit.\n"
  : "Dry run – nothing changed. Re-run with -- apply to remove the roles above (except report-only).\n";

// Global permissions that bypass per-webform rules.
$risky = '/webform|submission/';
$safe = ['access webform overview', 'view any webform content'];
foreach ($public_roles as $rid) {
  $perms = array_filter(Role::load($rid)?->getPermissions() ?? [], fn($p) => preg_match($risky, $p) && !in_array($p, $safe, TRUE)
    && !preg_match('/^((view|edit|view own|edit own) field_|use text format )/', $p));
  if ($perms) {
    echo "\nWARNING: role '$rid' has global webform permissions (not changed by this script): " . implode(', ', $perms) . "\n";
  }
}
