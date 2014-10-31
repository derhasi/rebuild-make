#!/usr/bin/env php
<?php

/**
 * @file
 * Script for rebuilding the docroot.
 */

define('REBUILD_FILEPERM', 0755);

// Make sure we are acting from the scripts directory.
chdir(__DIR__);

/**
 * Files and folders to save on the rebuild.
 */
$custom = [
  'docroot/sites/all/modules/custom',
  'docroot/sites/all/themes/custom',
  'docroot/sites/all/libraries/composer',
  'docroot/.htaccess',
  'docroot/sites/default/files',
  'docroot/sites/default/settings.php'
];

/**
 * Files that shall be removed after the make build.
 */
$remove = [
  'docroot/.gitignore',
];

$path = __DIR__ . '/make-backup_' . time();

// Create the temp path.
mkdir($path);

// Move files and folders.
$mapping = [];
$chmods = [];
foreach ($custom as $from) {

  if (file_exists($from)) {

    // If the file or folder itself is not writable, we try to change that, so
    // we can move it.
    if (!is_writable($from)) {
      $perms = fileperms($from);
      chmod($from, REBUILD_FILEPERM);
      // Store so we can reset it later.
      $chmods[$from] = $perms;
    }
    // In the case the parent is not writable, we cannot move anything, so we
    // need to make sure this works out too.
    $parent_dir = dirname($from);
    if (!is_writable($parent_dir)) {
      $perms = fileperms($parent_dir);
      chmod($parent_dir, REBUILD_FILEPERM);
      // Store so we can reset it later.
      $chmods[$parent_dir] = $perms;

    }

    print "Backing up $from ...\n";

    // Build the path to temporary move to.
    $to = $path . '/'. str_replace('/', '-', $from);
    // ... and finally move to temp.
    shell_exec("mv $from $to");

    $mapping[$from] = $to;
  }
}

/**
 * Rebuilding with make file.
 */
print "Remove docroot ...\n";
print shell_exec('rm -rf docroot');
print "====> Rebuilding with make file <====\n";
print shell_exec('drush make erpaul.make docroot');
print "=====================================\n";


// Move files back.
foreach ($mapping as $from => $to) {
  // In some cases we have to make sure the parent directories exist.
  @mkdir(dirname($from), 0755, true);

  print "Restoring {$from} ...\n";
  shell_exec("mv $to $from");
}


// Reset permissions.
foreach ($chmods as $file => $perm) {
  chmod($file, $perm);
}

//// Remove the temp file again.
rmdir($path);

// Remove files, that are created by the build, but are not needed.
foreach ($remove as $rm) {
  if (file_exists($rm)) {
    unlink($rm);
  }
}
