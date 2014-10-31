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

  protected $customMap = array();
  protected $rebuildFilePermissions = array();

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
  }

  /**
   * Retrieve absolute build path.
   *
   * @return string
   */
  protected function getBuildPath() {
    if (!isset($this->buildPath)) {
      $this->buildPath = $this->root . '/' . $this->config->build_path;
    }
    return $this->buildPath;
  }

  /**
   * Retrieve absolute path to makefile.
   *
   * @return string
   */
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

  /**
   * Cleanup at the end of execution.
   */
  protected function cleanup() {
    $this->removeTempDirectory();
  }

  /**
   * Retrieve and/or build temporary directory for current process.
   * @return string
   */
  protected function getTempDirectory() {
    if (!isset($this->tmpPath)) {
      $this->tmpPath = $this->root . '/make-backup_' . time();
      RebuildMakeFileSystem::makeDirectory($this->tmpPath, RebuildMake::REBUILD_FILEPERM);
    }
    return $this->tmpPath;
  }

  /**
   * Remove a temp directory for the given process.
   */
  protected function removeTempDirectory() {
    if (!empty($this->tmpPath)) {
      RebuildMakeFileSystem::removeDirectory($this->tmpPath);
    }
    unset($this->tmpPath);
  }

  /**
   * Back up custom files and folders.
   */
  protected function secureCustomData() {
    $build_path = $this->getBuildPath();

    foreach ($this->config->custom as $from_relative) {
      $from = $build_path . '/' . $from_relative;

      if (file_exists($from)) {

        // If the file or folder itself is not writable, we try to change that, so
        // we can move it.
        if (!is_writable($from)) {
          $perms = fileperms($from);
          RebuildMakeFileSystem::changeFileMode($from, self::REBUILD_FILEPERM);
          // Store so we can reset it later.
          $this->rebuildFilePermissions[$from] = $perms;
        }

        // In the case the parent is not writable, we cannot move anything, so we
        // need to make sure this works out too.
        $parent_dir = dirname($from);
        if (!is_writable($parent_dir)) {
          $perms = fileperms($parent_dir);
          RebuildMakeFileSystem::changeFileMode($parent_dir, self::REBUILD_FILEPERM);
          // Store so we can reset it later.
          $this->rebuildFilePermissions[$parent_dir] = $perms;

        }

        print "Backing up $from ...\n";

        // Build the path to temporary move to.
        $to = $this->getTempDirectory() . '/'. str_replace('/', '--', $from_relative);
        // ... and finally move to temp.
        RebuildMakeFileSystem::move($from, $to);

        $this->customMap[$from] = $to;
      }
    }
  }

  /**
   * Restore the backup of custom files and folders.
   */
  protected function recoverCustomData() {

    // Move files back.
    foreach ($this->customMap as $from => $to) {
      // In some cases we have to make sure the parent directories exist.
      RebuildMakeFileSystem::makeDirectory(dirname($from), self::REBUILD_FILEPERM, true);

      print "Restoring {$from} ...\n";
      RebuildMakeFileSystem::move($to, $from);
    }

    // Reset permissions.
    foreach ($this->rebuildFilePermissions as $file => $perm) {
      RebuildMakeFileSystem::changeFileMode($file, $perm);
    }
  }

  /**
   * Rebuild the drupal installation with the given drush make file.
   */
  protected function remake() {
    RebuildMakeFileSystem::removeRecursive($this->getBuildPath());
    $this->drushMake($this->getMakeFile(), $this->getBuildPath());
  }

  /**
   * Remove excluded files provided by the make process.
   */
  protected function removeExcludes() {
    $build_path = $this->getBuildPath();

    // Remove files, that are created by the build, but are not needed.
    foreach ($this->config->exclude as $rm) {
      $path = "$build_path/$rm";
      if (file_exists($path)) {
        RebuildMakeFileSystem::removeRecursive($path);
      }
    }
  }

  /**
   * Wrapper for calling drush make.
   *
   * @param $makefile
   * @param $buildpath
   */
  protected function drushMake($makefile, $buildpath) {
    print "====> Rebuilding with make file <====\n";
    print shell_exec('drush make ' . escapeshellarg($makefile) . ' ' . escapeshellarg($buildpath));
    print "=====================================\n";
  }

}

/**
 * Class RebuildMakeFileSystem
 */
class RebuildMakeFileSystem {

  /**
   * Wrapper for creating a directory.
   *
   * @param $dir
   * @param $perm
   * @param bool $recursive
   */
  public static function makeDirectory($dir, $perm = 0777, $recursive = FALSE) {
    @mkdir($dir, $perm, $recursive);
  }

  /**
   * Wrapper for changing file permissions.
   *
   * @param $path
   * @param $mode
   */
  public static function changeFileMode($path, $mode) {
    chmod($path, $mode);
  }

  /**
   * Wrapper for moving a file or folder.
   *
   * @param $from
   * @param $to
   */
  public static function move($from, $to) {
    rename($from, $to);
  }

  /**
   * Wrapper for removing an empty directory.
   *
   * @param $folder
   */
  public static function removeDirectory($folder) {
    rmdir($folder);
  }

  /**
   * This function recursively deletes all files and folders under the given
   * directory, and then the directory itself.
   *
   * equivalent to Bash: rm -r $dir
   * @param $dir
   *
   * @see https://github.com/perchten/php-rmrdir
   */
  public static function removeRecursive($dir) {
    $it = new RecursiveDirectoryIterator($dir);
    $it = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file) {
      if ('.' === $file->getBasename() || '..' ===  $file->getBasename()) continue;
      if ($file->isDir()) rmdir($file->getPathname());
      else unlink($file->getPathname());
    }
    return rmdir($dir);
  }
}
