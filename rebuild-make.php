#!/usr/bin/env php
<?php

/**
 * @file
 * Script for rebuilding the docroot.
 */

// We switch off error reporting, but print out any error in the first place.
//error_reporting(0);

try {
  $rebuild = new RebuildMake(__DIR__ . '/rebuild-make.json');
  $rebuild->execute();
}
catch (Exception $e) {
  print $e->getMessage();
}

/**
 * Class RebuildMake.
 */
class RebuildMake {

  const REBUILD_FILEPERM = 0755;

  /**
   * @var string
   */
  protected $root;

  /**
   * @var string
   */
  protected $configFile;

  /**
   * @var stdClass
   */
  protected $config;

  protected $buildPath;
  protected $makeFile;
  protected $tmpPath;

  protected $customMap;
  protected $rebuildFilePermissions;

  /**
   * Constructor.
   *
   * @param string $configFile
   *   Absolute path to config file.
   *
   * @throws Exception
   */
  public function __construct($configFile) {
    if (strpos($configFile, '/') !== 0) {
      throw new Exception('Path to config file must be absolute.');
    }

    $this->configFile = $configFile;
    $this->root = dirname($configFile);
    $this->getConfig();
  }

  /**
   * Loads configuration from file.
   *
   * @throws Exception
   */
  protected function getConfig() {
    if (!file_exists($this->configFile)) {
      throw new Exception('No config file given.');
    }

    $data = file_get_contents($this->configFile);
    $json = json_decode($data);

    if (empty($json->makefile)) {
      throw new Exception('No makefile specified.');
    }

    if (empty($json->build_path)) {
      throw new Exception('No build path specified.');
    }

    if (!isset($json->custom)) {
      $json->custom = array();
    }
    if (!isset($json->exclude)) {
      $json->exclude = array();
    }

    $this->config = $json;
    print_r($this->config);
  }

  protected function getBuildPath() {
    if (!isset($this->buildPath)) {
      $this->buildPath = $this->root . '/' . $this->config->build_path;
    }
    return $this->buildPath;
  }

  protected function getMakeFile() {
    if (!isset($this->makeFile)) {
      $this->makeFile = $this->root . '/' . $this->config->makefile;
    }
    return $this->makeFile;
  }

  /**
   * Main remake functionality.
   */
  public function execute() {
    $this->secureCustomData();
    $this->remake();
    $this->removeExcludes();
    $this->recoverCustomData();
    $this->cleanup();
  }

  public function cleanup() {
    $this->removeTempDirectory();
  }

  protected function getTempDirectory() {
    if (!isset($this->tmpPath)) {
      $this->tmpPath = $this->root . '/make-backup_' . time();
      mkdir($this->tmpPath);
    }
    return $this->tmpPath;
  }

  protected function removeTempDirectory() {
    if (!empty($this->tmpPath)) {
      rmdir($this->tmpPath);
    }
    unset($this->tmpPath);
  }

  protected function secureCustomData() {
    $build_path = $this->getBuildPath();

    foreach ($this->config->custom as $from_relative) {
      $from = $build_path . '/' . $from_relative;

      if (file_exists($from)) {

        // If the file or folder itself is not writable, we try to change that, so
        // we can move it.
        if (!is_writable($from)) {
          $perms = fileperms($from);
          chmod($from, self::REBUILD_FILEPERM);
          // Store so we can reset it later.
          $this->rebuildFilePermissions[$from] = $perms;
        }

        // In the case the parent is not writable, we cannot move anything, so we
        // need to make sure this works out too.
        $parent_dir = dirname($from);
        if (!is_writable($parent_dir)) {
          $perms = fileperms($parent_dir);
          chmod($parent_dir, self::REBUILD_FILEPERM);
          // Store so we can reset it later.
          $this->rebuildFilePermissions[$parent_dir] = $perms;

        }

        print "Backing up $from ...\n";

        // Build the path to temporary move to.
        $to = $this->getTempDirectory() . '/'. str_replace('/', '--', $from_relative);
        // ... and finally move to temp.
        shell_exec("mv $from $to");

        $this->customMap[$from] = $to;
      }
    }
  }
  protected function recoverCustomData() {

    // Move files back.
    foreach ($this->customMap as $from => $to) {
      // In some cases we have to make sure the parent directories exist.
      @mkdir(dirname($from), self::REBUILD_FILEPERM, true);

      print "Restoring {$from} ...\n";
      shell_exec("mv $to $from");
    }

    // Reset permissions.
    foreach ($this->rebuildFilePermissions as $file => $perm) {
      chmod($file, $perm);
    }
  }

  protected function remake() {
    return;
    /**
     * Rebuilding with make file.
     */
    print "Remove docroot ...\n";
    print shell_exec('rm -rf docroot');
    print "====> Rebuilding with make file <====\n";
    print shell_exec('drush make erpaul.make docroot');
    print "=====================================\n";

  }

  protected function removeExcludes() {
    $build_path = $this->getBuildPath();

    // Remove files, that are created by the build, but are not needed.
    foreach ($this->config->exclude as $rm) {
      $path = "$build_path/$rm";
      if (file_exists($path)) {
        unlink($path);
      }
    }
  }



}
