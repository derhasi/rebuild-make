# drush Rebuild Make

This is a php tool for rebuilding a drush make file based project and
additionally leaving custom code intact.

## Configuration in JSON

You have to configure the script with a JSON file. An example is available as
[default.rebuild-make.json](default.rebuild-make.json) in this repository.

* `makefile`: location of the drush make file (path relative to JSON file)
* `build_path`: location of path to build make file to (path relative to JSON file)
* `custom`: array of locations within the build directory to save from being removed
  (paths are relative to the build path)
* `exclude`: array of files within the build directory that shall be removed 
   after the make build finished (paths are relative to the build path).

## Command

```
./rebuild-make.php [path to JSON]
# or
php rebuild-make.php [path to JSON]
```

* `[path to JSON]`: this may either be absolute or relative to the current 
  working directory
* Make sure that `rebuild-make.php` is executable (`chmod u+x`) when you use the
  command without `php ...`

## @Todos

Have a look in the [issues](https://github.com/derhasi/rebuild-make/issues).
