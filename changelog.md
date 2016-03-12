# 0.7 (12 March, 2016)
* Optimized mounts to speed-up everything, `optimize_mounts : false` might be used to restore default behavior
* `composer.json` added alongside with instructions for installing globally using Composer
* Add snapshot to database before actual creation (to avoid having snapshots that are not tracked anywhere)

# 0.6 (08 June, 2015)
* Radically improve speed of incremental backups (check recent snapshots first)
* Support for separate retention settings for external backups (to other partition/disk)

# 0.5 (17 May, 2015)
* Code moved into class
* Fix for Ubuntu 15.10 (binaries moved from `/usr/sbin` to `/bin`, now both cases are supported)
* Refactoring into multiple methods
* Fix for `0` as count of keeping snapshots
* Real backups to other partition/drive added, not just snapshots
* Some changes in output messages
* Custom path to config file can be specified as argument

# 0.1 (Nov 20, 2014)
* initial release
