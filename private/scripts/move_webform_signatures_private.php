<?php

/**
 * @file
 * Moves webform signature images from public://webform to private://webform.
 *
 * Usage (dry run by default – reports only, changes nothing):
 *   drush php:script private/scripts/move_webform_signatures_private.php
 *   drush php:script private/scripts/move_webform_signatures_private.php -- apply
 *
 * Webform's signature element defaults to public storage, so signature PNGs
 * ended up under sites/default/files/webform. Run
 * set_webform_signatures_private.php -- apply BEFORE this, otherwise any
 * submission view in between writes the PNG straight back to public:// (the
 * script refuses to run while any signature element is still public).
 *
 * Signature PNGs are not file entities – the submission stores the base64
 * value and the PNG is (re)generated on display at
 * {scheme}://webform/{webform_id}/{element_key}[/{sid}]/signature-{hash}.png,
 * so moving only means renaming files; there is nothing in the database to
 * update. Paths are kept identical under private://, which matches what
 * WebformSignature::getImageUrl() looks up (including legacy "unsafe hash"
 * names) and lets postDelete() clean them up when a submission is deleted.
 *
 * Anything under public://webform that isn't a signature-*.png is reported
 * and left alone. If the private target already exists with the same content
 * the public copy is deleted; if it differs, both are left and reported.
 * Empty public directories are removed at the end.
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

$apply = ($extra[0] ?? NULL) === 'apply';

/** @var \Drupal\webform\Plugin\WebformElementManagerInterface $element_manager */
$element_manager = \Drupal::service('plugin.manager.webform.element');
$public = [];
foreach (\Drupal\webform\Entity\Webform::loadMultiple() as $webform) {
  foreach ($webform->getElementsDecodedAndFlattened() as $key => $element) {
    if (($element['#type'] ?? NULL) === 'webform_signature'
      && $element_manager->getElementInstance($element)->getElementProperty($element, 'uri_scheme') !== 'private') {
      $public[] = "{$webform->id()}:$key";
    }
  }
}
if ($public) {
  throw new \RuntimeException(count($public) . ' signature elements are still public (' . implode(', ', array_slice($public, 0, 5)) . '…) – run set_webform_signatures_private.php -- apply first.');
}

/** @var \Drupal\Core\File\FileSystemInterface $file_system */
$file_system = \Drupal::service('file_system');
$public_base = $file_system->realpath('public://webform');
if (!$public_base || !is_dir($public_base)) {
  print "Nothing to do: public://webform does not exist.\n";
  return;
}

$counts = ['moved' => 0, 'duplicate' => 0, 'conflict' => 0, 'skipped' => 0, 'failed' => 0];
$iterator = new \RecursiveIteratorIterator(
  new \RecursiveDirectoryIterator($public_base, \FilesystemIterator::SKIP_DOTS),
  \RecursiveIteratorIterator::CHILD_FIRST
);
$dirs = [];
foreach ($iterator as $info) {
  $relative = substr($info->getPathname(), strlen($public_base) + 1);
  if ($info->isDir()) {
    $dirs[] = $info->getPathname();
    continue;
  }

  if (!preg_match('/^signature-.*\.png$/', $info->getFilename())) {
    $counts['skipped']++;
    print "SKIP (not a signature): webform/$relative\n";
    continue;
  }

  $source = "public://webform/$relative";
  $target = "private://webform/$relative";

  if (file_exists($target)) {
    if (md5_file($target) === md5_file($source)) {
      $counts['duplicate']++;
      if ($apply) {
        $file_system->delete($source);
      }
    }
    else {
      $counts['conflict']++;
      print "CONFLICT (different file already in private): webform/$relative\n";
    }
    continue;
  }

  $counts['moved']++;
  if ($apply) {
    try {
      $directory = dirname($target);
      $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $file_system->move($source, $target, FileExists::Error);
    }
    catch (\Exception $e) {
      $counts['moved']--;
      $counts['failed']++;
      print "FAILED: webform/$relative – " . $e->getMessage() . "\n";
    }
  }
}

// CHILD_FIRST order means subdirectories come before their parents.
$removed_dirs = 0;
if ($apply) {
  foreach (array_merge($dirs, [$public_base]) as $dir) {
    if (count(scandir($dir)) === 2 && @rmdir($dir)) {
      $removed_dirs++;
    }
  }
}

print "\n" . ($apply ? 'APPLIED' : 'DRY RUN') . "\n";
print ($apply ? 'Moved' : 'Would move') . ": {$counts['moved']}\n";
print 'Already in private (identical' . ($apply ? ', public copy deleted' : '') . "): {$counts['duplicate']}\n";
print "Conflicts (left in place): {$counts['conflict']}\n";
print "Non-signature files (left in place): {$counts['skipped']}\n";
if ($apply) {
  print "Failed: {$counts['failed']}\n";
  print "Empty public directories removed: $removed_dirs\n";
}
