<?php

/**
 * @file
 * Exports webform submissions as self-contained consent record PDFs.
 *
 * Read-only: it never changes or deletes submissions. Purging stays a
 * separate, manual step once the exports are filed.
 *
 * Usage:
 *   drush php:script private/scripts/export_webform_consent_pdfs.php -- WEBFORM_ID [options]
 *
 * Options (key=value, any order):
 *   nid=1,2,3      Only these source nodes (default: every source node).
 *   from=Y-m-d     Only submissions created on or after this date.
 *   to=Y-m-d       Only submissions created on or before this date.
 *   limit=N        Stop after N submissions per node (for test runs).
 *   name=a,b       Element key(s) used for the person's name in PDF
 *                  filenames (default: first match of talent_name,
 *                  student_name, student_full_name, participant_name,
 *                  full_name, name, first+last name pairs, then
 *                  student/student_details composites).
 *   out=URI|PATH   Output folder (default:
 *                  private://consent_exports/WEBFORM_ID_YYYYmmdd-HHMMSS).
 *
 * Example:
 *   drush php:script private/scripts/export_webform_consent_pdfs.php -- auth_film_record from=2026-01-01 to=2026-12-31
 *   (Live: /usr/local/bin/php -d memory_limit=1G vendor/drush/drush/drush.php
 *    php:script ... --uri=https://LIVE-DOMAIN)
 *
 * Each PDF contains:
 *   1. The submission, rendered by Webform Entity Print (the same output as
 *      the "PDF documents" export): consent wording, answers, signature,
 *      submitted time and IP address.
 *   2. "Program description at time of signing": the node revision that was
 *      published when the person submitted, so wording like "Project as
 *      described above" can still be read once the page changes.
 *
 * The output folder has one sub-folder per source node, plus:
 *   index.csv          One row per submission, every answer (signatures shown
 *                      as [signed]), node revision used and the PDF filename.
 *                      This is the lookup file for withdrawal requests.
 *   refusals.csv       Rows where an answer reads like a refusal ("do not
 *                      agree", "do not consent", ...). Check it by eye.
 *   errors.csv         Submissions that failed to export (only if any).
 *   SHA256SUMS         Checksum of every PDF, so the filed copies can be
 *                      shown to be unchanged.
 *   summary.txt        Run details and the per-node count check.
 *
 * CAVEAT: the submission part uses the webform's CURRENT elements and
 * wording, not necessarily what the person saw. Export before the webform is
 * edited for a new year. summary.txt records a hash of the current elements;
 * the config_sync git history shows the wording at any earlier date.
 *
 * Signature and other private images are embedded straight from disk, so the
 * PDFs don't rely on Dompdf fetching URLs from the site.
 */

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

// ---------------------------------------------------------------------------
// Arguments.
// ---------------------------------------------------------------------------
$webform_id = $extra[0] ?? NULL;
$options = [];
foreach (array_slice($extra, 1) as $arg) {
  [$k, $v] = array_pad(explode('=', $arg, 2), 2, '');
  $options[$k] = $v;
}
if (!$webform_id || !($webform = Webform::load($webform_id))) {
  throw new \InvalidArgumentException('Usage: -- WEBFORM_ID [nid=1,2] [from=Y-m-d] [to=Y-m-d] [limit=N] [name=key,key] [out=URI]');
}
$unknown = array_diff(array_keys($options), ['nid', 'from', 'to', 'limit', 'name', 'out']);
if ($unknown) {
  throw new \InvalidArgumentException('Unknown option(s): ' . implode(', ', $unknown));
}
$nids = isset($options['nid']) ? array_filter(array_map('intval', explode(',', $options['nid']))) : [];
$from = isset($options['from']) ? strtotime($options['from'] . ' 00:00:00') : NULL;
$to = isset($options['to']) ? strtotime($options['to'] . ' 23:59:59') : NULL;
if ($from === FALSE || $to === FALSE) {
  throw new \InvalidArgumentException('from/to must be Y-m-d dates.');
}
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : NULL;

/** @var \Drupal\Core\File\FileSystemInterface $file_system */
$file_system = \Drupal::service('file_system');
$out_uri = $options['out'] ?? 'private://consent_exports/' . $webform_id . '_' . date('Ymd-His');
$file_system->prepareDirectory($out_uri, $file_system::CREATE_DIRECTORY | $file_system::MODIFY_PERMISSIONS);
$out = $file_system->realpath($out_uri) ?: $out_uri;
if (!is_dir($out) || !is_writable($out)) {
  throw new \RuntimeException("Cannot write to $out_uri");
}
if (glob("$out/*")) {
  throw new \RuntimeException("$out is not empty – pick a new out= folder.");
}

// ---------------------------------------------------------------------------
// Environment: render as admin, in Webform Entity Print mode.
// ---------------------------------------------------------------------------
\Drupal::service('account_switcher')->switchTo(User::load(1));
$request = \Drupal::request();
$request->request->set('_webform_entity_print', TRUE);
$request->request->set('_webform_submissions_view_mode', 'html');

/** @var \Drupal\entity_print\PrintBuilderInterface $print_builder */
$print_builder = \Drupal::service('entity_print.print_builder');
/** @var \Drupal\entity_print\Plugin\EntityPrintPluginManagerInterface $engine_manager */
$engine_manager = \Drupal::service('plugin.manager.entity_print.print_engine');
$renderer = \Drupal::service('renderer');
$db = \Drupal::database();
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$submission_storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
$render = static fn(array $build) => (string) (method_exists($renderer, 'renderInIsolation') ? $renderer->renderInIsolation($build) : $renderer->renderPlain($build));

// Node fields shown in the "program description" section, in this order.
// Fields a node doesn't have, or has empty, are skipped.
$description_fields = [
  'body',
  'field_details',
  'field_event_dates_text',
  'field_event_dates',
  'field_venue',
  'field_sections',
  'field_enquiries',
];

// Element keys/types whose values are not written to the CSVs.
$elements = $webform->getElementsDecodedAndFlattened();
$signature_keys = array_keys(array_filter($elements, fn($e) => ($e['#type'] ?? '') === 'webform_signature'));
$refusal_pattern = '/\b(do not|don\'t|does not|not)\s+(agree|consent|accept|give)/i';

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/**
 * Turns site-relative image URLs (private/public files) into data: URIs.
 */
$inline_images = static function (string $html) use ($file_system): string {
  return preg_replace_callback('/(<img\b[^>]*\bsrc=")([^"]+)(")/i', static function ($m) use ($file_system) {
    $src = html_entity_decode($m[2]);
    $path = parse_url($src, PHP_URL_PATH) ?? '';
    $uri = NULL;
    if (preg_match('#/system/files/(.+)$#', $path, $p)) {
      $uri = 'private://' . rawurldecode($p[1]);
    }
    elseif (preg_match('#/sites/[^/]+/files/(.+)$#', $path, $p)) {
      $uri = 'public://' . rawurldecode($p[1]);
    }
    $real = $uri ? $file_system->realpath($uri) : FALSE;
    if (!$real || !is_file($real)) {
      return $m[0];
    }
    $mime = mime_content_type($real) ?: 'image/png';
    return $m[1] . 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($real)) . $m[3];
  }, $html);
};

/**
 * Published node revision in effect at $timestamp (falls back to the first
 * published revision, then the default revision).
 */
$revision_at = static function (int $nid, int $timestamp) use ($db, $node_storage) {
  $q = 'SELECT r.vid FROM {node_revision} r JOIN {node_field_revision} f ON f.vid = r.vid AND f.default_langcode = 1 WHERE r.nid = :nid AND f.status = 1';
  $vid = $db->query("$q AND r.revision_timestamp <= :ts ORDER BY r.revision_timestamp DESC, r.vid DESC LIMIT 1", [':nid' => $nid, ':ts' => $timestamp])->fetchField()
    ?: $db->query("$q ORDER BY r.revision_timestamp ASC, r.vid ASC LIMIT 1", [':nid' => $nid])->fetchField();
  return $vid ? $node_storage->loadRevision($vid) : $node_storage->load($nid);
};

$description_cache = [];
$description_html = static function ($revision) use (&$description_cache, $description_fields, $render): string {
  $vid = $revision->getRevisionId();
  if (isset($description_cache[$vid])) {
    return $description_cache[$vid];
  }
  $parts = [];
  foreach ($description_fields as $field) {
    if ($revision->hasField($field) && !$revision->get($field)->isEmpty()) {
      $parts[] = $render($revision->get($field)->view(['label' => $field === 'body' ? 'hidden' : 'above']));
    }
  }
  $when = \Drupal::service('date.formatter')->format($revision->getRevisionCreationTime(), 'custom', 'j F Y g:ia');
  $url = $revision->toUrl('canonical', ['absolute' => TRUE])->toString();
  $html = '<div style="page-break-before: always;"></div>'
    . '<h2>Program description at time of signing</h2>'
    . '<p><em>This is the web page the form was submitted from, as published when the person signed. '
    . 'It explains phrases such as "Project as described above" and "Venues as specified above".</em></p>'
    . '<p><strong>Page:</strong> ' . Html::escape($revision->label()) . '<br>'
    . '<strong>URL:</strong> ' . Html::escape($url) . '<br>'
    . '<strong>Node ' . $revision->id() . ', revision ' . $vid . ', published ' . $when . '</strong></p><hr>'
    . implode("\n", $parts);
  return $description_cache[$vid] = $html;
};

$slug = static fn(string $s, int $max = 60) => trim(substr(preg_replace('/[^a-z0-9]+/', '-', strtolower(\Drupal::transliteration()->transliterate($s, 'en'))), 0, $max), '-') ?: 'untitled';

$csv_value = static function ($value): string {
  if (is_array($value)) {
    $flat = [];
    array_walk_recursive($value, function ($v, $k) use (&$flat) {
      $flat[] = is_int($k) ? (string) $v : "$k: $v";
    });
    return implode('; ', $flat);
  }
  return (string) $value;
};

/**
 * The person's name for the PDF filename ('' if none found).
 */
$name_keys = isset($options['name']) ? [array_filter(explode(',', $options['name']))] : [
  ['talent_name'], ['student_name'], ['student_full_name'], ['participant_name'], ['full_name'], ['name'],
  ['student_first_name', 'student_surname'], ['student_first_name', 'student_last_name'],
  ['customStudentFirstName', 'customStudentSurname'], ['first_name', 'surname'], ['first_name', 'last_name'],
  ['student_first_name'], ['first_name'],
  // Composites holding first/last sub-values (e.g. student_details).
  ['student'], ['student_details'], ['talent_details'], ['participant'],
];
$person_name = static function (array $data) use ($name_keys): string {
  foreach ($name_keys as $keys) {
    $parts = [];
    foreach ($keys as $key) {
      $value = $data[$key] ?? '';
      if (is_array($value)) {
        // Composites: only their name parts, never IDs, ages etc.
        $value = array_intersect_key($value, array_flip(['first', 'first_name', 'last', 'last_name', 'surname']));
        $value = implode(' ', array_filter(array_map('strval', array_filter($value, 'is_scalar'))));
      }
      $parts[] = (string) $value;
    }
    $name = trim(implode(' ', $parts));
    if ($name !== '') {
      return $name;
    }
  }
  return '';
};

// ---------------------------------------------------------------------------
// Select submissions.
// ---------------------------------------------------------------------------
$query = $db->select('webform_submission', 's')
  ->fields('s', ['sid', 'entity_type', 'entity_id', 'created'])
  ->condition('s.webform_id', $webform_id)
  ->condition('s.in_draft', 0)
  ->orderBy('s.entity_id')
  ->orderBy('s.sid');
if ($nids) {
  $query->condition('s.entity_type', 'node')->condition('s.entity_id', $nids, 'IN');
}
if ($from) {
  $query->condition('s.created', $from, '>=');
}
if ($to) {
  $query->condition('s.created', $to, '<=');
}
$groups = [];
foreach ($query->execute() as $row) {
  $groups[$row->entity_type . ':' . ($row->entity_id ?? '')][] = $row;
}
if (!$groups) {
  print "No submissions match.\n";
  return;
}

// ---------------------------------------------------------------------------
// Export.
// ---------------------------------------------------------------------------
$data_keys = [];
foreach ($elements as $key => $element) {
  if (!empty($element['#webform_composite']) || isset($element['#type']) && \Drupal::service('plugin.manager.webform.element')->getElementInstance($element)->isInput($element)) {
    $data_keys[] = $key;
  }
}
$index_header = array_merge(['sid', 'serial', 'created', 'completed', 'remote_addr', 'source', 'source_title', 'page_nid', 'node_revision', 'node_revision_published', 'refusal', 'pdf'], $data_keys);
$index = fopen("$out/index.csv", 'w');
fputcsv($index, $index_header);
$refusals = fopen("$out/refusals.csv", 'w');
fputcsv($refusals, ['sid', 'created', 'source', 'source_title', 'matched_element', 'matched_value', 'pdf']);
$errors = [];
$sums = [];
$counts = [];
$fmt = static fn($ts) => $ts ? date('Y-m-d H:i:s', $ts) : '';
$started = time();

foreach ($groups as $group => $rows) {
  [$entity_type, $entity_id] = explode(':', $group, 2);
  // The page the form sat on: the source node itself, or the node a source
  // paragraph belongs to. Other sources (terms, none) get no description.
  $source = $entity_type && $entity_id && \Drupal::entityTypeManager()->hasDefinition($entity_type)
    ? \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id) : NULL;
  $node = $source;
  while ($node && !$node instanceof NodeInterface && method_exists($node, 'getParentEntity')) {
    $node = $node->getParentEntity();
  }
  $node = $node instanceof NodeInterface ? $node : NULL;
  if (!$entity_type) {
    $title = 'No source page (form used at its own URL)';
    $folder = 'no-source-page';
  }
  elseif ($entity_type === 'node' && $source) {
    $title = $source->label();
    $folder = $entity_id . '_' . $slug($title);
  }
  else {
    $title = ($source ? $source->label() : 'deleted') . " ($entity_type $entity_id" . ($node ? ', on node ' . $node->id() . ': ' . $node->label() : '') . ')';
    $folder = "$entity_type-$entity_id" . '_' . $slug($source ? $source->label() : 'deleted');
  }
  $dir = "$out/$folder";
  $file_system->prepareDirectory($dir, $file_system::CREATE_DIRECTORY);
  $selected = $limit ? array_slice($rows, 0, $limit) : $rows;
  $counts[$group] = ['title' => $title, 'folder' => $folder, 'expected' => count($selected), 'written' => 0];
  print sprintf("%s (%d submissions)\n", $title, count($selected));

  foreach ($selected as $i => $row) {
    $pdf_name = '';
    try {
      /** @var \Drupal\webform\WebformSubmissionInterface $submission */
      $submission = WebformSubmission::load($row->sid);
      $data = $submission->getData();
      $person = $person_name($data);
      $pdf_name = 'sid-' . $row->sid . ($person !== '' ? '_' . $slug($person, 40) : '') . '.pdf';

      $html = $print_builder->printHtml($submission, TRUE, FALSE);
      $revision = $node ? $revision_at((int) $node->id(), (int) $row->created) : NULL;
      if ($revision) {
        $html = preg_replace('#</body>#i', $description_html($revision) . '</body>', $html, 1);
      }
      $details = '<div style="border:1px solid #999; padding:6px 10px; margin-bottom:12px; font-size:0.85em;">'
        . '<strong>Record details</strong><br>'
        . 'Submission ID ' . $row->sid . ' (serial #' . $submission->serial() . ')'
        . ' &middot; submitted ' . $fmt($submission->getCompletedTime() ?: $submission->getCreatedTime()) . ' ' . date('T')
        . ' &middot; IP ' . Html::escape((string) $submission->getRemoteAddr()) . '<br>'
        . 'Form: ' . Html::escape($webform->label()) . ' (' . $webform_id . ')'
        . ' &middot; source page: ' . Html::escape($title) . ($node ? ' (node ' . $node->id() . ')' : '') . '<br>'
        . 'Exported ' . date('Y-m-d H:i T', $started) . ' from ' . Html::escape(\Drupal::request()->getSchemeAndHttpHost())
        . '</div>';
      $html = preg_replace('#(<body[^>]*>)#i', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $details), $html, 1);
      $html = $inline_images($html);

      $engine = $engine_manager->createSelectedInstance('pdf');
      $engine->addPage(Markup::create($html));
      $blob = $engine->getBlob();
      if (!$blob || strncmp($blob, '%PDF', 4) !== 0) {
        throw new \RuntimeException('Print engine returned no PDF.');
      }
      file_put_contents("$dir/$pdf_name", $blob);
      $sums[] = hash('sha256', $blob) . "  $folder/$pdf_name";
      $counts[$group]['written']++;

      // CSV rows.
      $refusal = '';
      $values = [];
      foreach ($data_keys as $key) {
        $value = in_array($key, $signature_keys, TRUE) ? (empty($data[$key]) ? '' : '[signed]') : $csv_value($data[$key] ?? '');
        $values[] = $value;
        if (!$refusal && preg_match($refusal_pattern, $value)) {
          $refusal = $key;
          fputcsv($refusals, [$row->sid, $fmt($row->created), $group === ':' ? '' : $group, $title, $key, $value, "$folder/$pdf_name"]);
        }
      }
      fputcsv($index, array_merge([
        $row->sid,
        $submission->serial(),
        $fmt($submission->getCreatedTime()),
        $fmt($submission->getCompletedTime()),
        $submission->getRemoteAddr(),
        $group === ':' ? '' : $group,
        $title,
        $node ? $node->id() : '',
        $revision ? $revision->getRevisionId() : '',
        $revision ? $fmt($revision->getRevisionCreationTime()) : '',
        $refusal ? 'yes' : '',
        "$folder/$pdf_name",
      ], $values));
    }
    catch (\Throwable $e) {
      $errors[] = [$row->sid, $group, $pdf_name, $e->getMessage()];
      print "  ! sid {$row->sid}: {$e->getMessage()}\n";
    }
    $submission_storage->resetCache([$row->sid]);
    unset($engine, $blob, $html, $submission);
    if (($i + 1) % 25 === 0) {
      print sprintf("  %d/%d\n", $i + 1, count($selected));
      gc_collect_cycles();
    }
  }
  $node_storage->resetCache();
}
fclose($index);
fclose($refusals);
file_put_contents("$out/SHA256SUMS", implode("\n", $sums) . "\n");
if ($errors) {
  $fh = fopen("$out/errors.csv", 'w');
  fputcsv($fh, ['sid', 'source', 'pdf', 'error']);
  array_walk($errors, fn($r) => fputcsv($fh, $r));
  fclose($fh);
}

// ---------------------------------------------------------------------------
// Summary and count check.
// ---------------------------------------------------------------------------
$total_expected = array_sum(array_column($counts, 'expected'));
$total_written = array_sum(array_column($counts, 'written'));
$refusal_count = max(0, count(file("$out/refusals.csv")) - 1);
$lines = [
  'Consent record export',
  '=====================',
  'Webform:        ' . $webform->label() . " ($webform_id)",
  'Site:           ' . \Drupal::request()->getSchemeAndHttpHost(),
  'Exported:       ' . date('Y-m-d H:i:s T', $started) . ' (took ' . (time() - $started) . 's)',
  'Filters:        ' . (trim(($nids ? 'nid=' . implode(',', $nids) . ' ' : '') . ($from ? 'from=' . date('Y-m-d', $from) . ' ' : '') . ($to ? 'to=' . date('Y-m-d', $to) . ' ' : '') . ($limit ? "limit=$limit" : '')) ?: 'none'),
  'Form elements:  sha256 ' . hash('sha256', $webform->get('elements')) . ' (current wording used for the submission part)',
  'Refusals:       ' . $refusal_count . ' (see refusals.csv – check by eye)',
  '',
  'Count check (submissions selected vs PDFs written):',
];
foreach ($counts as $c) {
  $lines[] = sprintf('  %s %4d / %4d  %s', $c['expected'] === $c['written'] ? 'OK  ' : 'FAIL', $c['written'], $c['expected'], $c['folder']);
}
$lines[] = sprintf('  %s %4d / %4d  TOTAL', $total_expected === $total_written ? 'OK  ' : 'FAIL', $total_written, $total_expected);
if ($errors) {
  $lines[] = '';
  $lines[] = count($errors) . ' submission(s) failed – see errors.csv.';
}
file_put_contents("$out/summary.txt", implode("\n", $lines) . "\n");

print "\n" . implode("\n", $lines) . "\n\nOutput: $out\n";
\Drupal::service('account_switcher')->switchBack();
if ($total_expected !== $total_written) {
  exit(1);
}
