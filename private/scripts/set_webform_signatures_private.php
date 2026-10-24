<?php

/**
 * @file
 * Sets '#uri_scheme': private on every webform signature element without one.
 *
 * Usage (dry run by default – reports only, changes nothing):
 *   drush php:script private/scripts/set_webform_signatures_private.php
 *   drush php:script private/scripts/set_webform_signatures_private.php -- apply
 *
 * Webform's signature element defaults to public://, so signature images
 * were publicly accessible under sites/default/files/webform. The convention
 * from now on is private for every signature (new elements get private
 * preselected by PrivateWebformSignature in the_arts_unit_webforms); this
 * brings existing elements into line. Public stays available per element.
 *
 * The line is inserted straight after "'#type': webform_signature" in the raw
 * elements YAML rather than re-encoding the elements, so the config diff is
 * one added line per element. Each webform's decoded elements are compared
 * before saving to make sure nothing but #uri_scheme changed.
 *
 * Elements that already set #uri_scheme (private or public) are left alone.
 * Run this before move_webform_signatures_private.php, then drush cex.
 */

use Drupal\Core\Serialization\Yaml;
use Drupal\webform\Entity\Webform;

$apply = ($extra[0] ?? NULL) === 'apply';

$counts = ['webforms' => 0, 'elements' => 0, 'already_set' => 0];
foreach (Webform::loadMultiple() as $webform) {
  $raw = $webform->get('elements');
  if (!$raw || !str_contains($raw, 'webform_signature')) {
    continue;
  }

  $lines = explode("\n", $raw);
  $output = [];
  $added = 0;
  foreach ($lines as $i => $line) {
    $output[] = $line;
    if (!preg_match("/^(\s*)'#type': '?webform_signature'?\s*$/", $line, $matches)) {
      continue;
    }

    // Scan the rest of this element's properties (same indent) for
    // an existing #uri_scheme.
    $indent = $matches[1];
    $has_scheme = FALSE;
    for ($j = $i + 1; $j < count($lines); $j++) {
      $next = $lines[$j];
      if (trim($next) === '') {
        continue;
      }
      if (strlen($next) - strlen(ltrim($next)) < strlen($indent)) {
        break;
      }
      if (preg_match("/^" . preg_quote($indent, '/') . "'#uri_scheme':/", $next)) {
        $has_scheme = TRUE;
        break;
      }
    }

    if ($has_scheme) {
      $counts['already_set']++;
      continue;
    }
    $output[] = $indent . "'#uri_scheme': private";
    $added++;
  }

  if (!$added) {
    continue;
  }

  $new_raw = implode("\n", $output);

  // Make sure the only change is the added #uri_scheme properties.
  $before = Yaml::decode($raw);
  $after = Yaml::decode($new_raw);
  $strip = function (array $elements) use (&$strip) {
    foreach ($elements as $key => &$value) {
      if (is_array($value)) {
        if (($value['#type'] ?? NULL) === 'webform_signature') {
          unset($value['#uri_scheme']);
        }
        $value = $strip($value);
      }
    }
    return $elements;
  };
  if ($strip($before) !== $strip($after)) {
    throw new \RuntimeException("Unexpected change to elements of {$webform->id()} – nothing saved for it or later webforms.");
  }

  $counts['webforms']++;
  $counts['elements'] += $added;
  print "{$webform->id()}: $added\n";
  if ($apply) {
    $webform->set('elements', $new_raw)->save();
  }
}

// Verify: every signature element on every webform now has a scheme.
if ($apply) {
  $missing = [];
  foreach (Webform::loadMultiple() as $webform) {
    foreach ($webform->getElementsDecodedAndFlattened() as $key => $element) {
      if (($element['#type'] ?? NULL) === 'webform_signature' && empty($element['#uri_scheme'])) {
        $missing[] = "{$webform->id()}:$key";
      }
    }
  }
  if ($missing) {
    print "\nSTILL WITHOUT #uri_scheme: " . implode(', ', $missing) . "\n";
  }
}

print "\n" . ($apply ? 'APPLIED' : 'DRY RUN') . "\n";
print ($apply ? 'Updated' : 'Would update') . ": {$counts['elements']} elements on {$counts['webforms']} webforms\n";
print "Already had #uri_scheme (left alone): {$counts['already_set']}\n";
