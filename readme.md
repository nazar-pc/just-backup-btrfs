### Just backup btrfs
Script that does just that - creates backups using snapshot of btrfs filesystem. Also it makes rotation of snapshots by removing old ones and keeping as many snapshots as you want.


### Why?
I wanted simple thing: store snapshots of several subvolumes in one place and keep specific number of snapshots for each time interval (more recent and less elder snapshots).

I was wondered that no existing solutions fit my needs, so I decided to write it:)

### Requirements
* php-cli (version 5.4+)
* php-sqlite3

You can get them both on Ubuntu 15.10-:
```bash
sudo apt-get install php5-cli php5-sqlite
```
Ubuntu 16.04+:
```bash
sudo apt-get install php-cli php-sqlite3
```

Also enable sqlite3 extension since it may be disabled by default.
Ubuntu 15.10-:
```bash
sudo php5enmod sqlite3
```
Ubuntu 16.04+
```bash
sudo phpenmod sqlite3
```

### Installation
The whole thing is a single file, so first way is just to copy file `just-backup-btrfs` somewhere.

Alternatively you can install it globally using Composer like this:
```bash
sudo COMPOSER_BIN_DIR=/usr/local/bin composer global require nazar-pc/just-backup-btrfs
```
`COMPOSER_BIN_DIR=/usr/local/bin` will instruct Composer to install binary to `/usr/local/bin` so that you'll be able to call `just-backup-btrfs` right after installation.
Alternatively you can skip it and add `~/.composer/vendor/bin/` to your PATH.

### Removal
If installed manually - just remove file, if installed with Composer - remove it with:
```bash
sudo COMPOSER_BIN_DIR=/usr/local/bin composer global remove nazar-pc/just-backup-btrfs
```
Or without `COMPOSER_BIN_DIR=/usr/local/bin` if you didn't use it during installation.

### Usage
Script expects configuration file to be present at location `/etc/just-backup-btrfs.json`, example of such file you can find in next section. Also, if you want to use other path to config file - specify it as argument.

There are few ways to run script.
```bash
sudo php just-backup-btrfs
```

or mark file as executable and just
```bash
sudo ./just-backup-btrfs
```

or mark as executable and put into `/etc/cron.daily` to make backups every day.

With custom path to config file:
```bash
sudo ./just-backup-btrfs /path/to/config.json
```

Output will be like this:
```
Just backup btrfs started...
CMD: /bin/btrfs subvolume snapshot -r "/" "/backup/root/2016-03-12_16:29:52"
CMD: sync
Snapshot 2016-03-12_16:29:52 for / created successfully
CMD: mount
Mounting root subvolume skipped, since already mounted
Making incremental backup
CMD: /bin/btrfs send -p "/backup/root/2016-03-12_16:26:48" "/backup/root/2016-03-12_16:29:52" | /bin/btrfs receive /tmp/just_backup_btrfs_45e177f34dddc33a5b1537ce8f4913ba/root
At subvol /backup/root/2016-03-12_16:29:52
CMD: /bin/btrfs subvolume delete "/backup_hdd/root/2016-03-11_16:30:01"
Old snapshot 2016-03-11_16:30:01 removed successfully from /backup_hdd/root
Creating incremental backup 2016-03-12_16:29:52 of / to /backup_hdd/root finished successfully
Unmounting root subvolume skipped
CMD: /bin/btrfs subvolume delete "/backup/root/2016-03-12_15:30:01"
Old snapshot 2016-03-12_15:30:01 removed successfully from /backup/root
CMD: /bin/btrfs subvolume snapshot -r "/home" "/backup/home/2016-03-12_16:30:04"
CMD: sync
Snapshot 2016-03-12_16:30:04 for /home created successfully
CMD: mount
Mounting root subvolume skipped, since already mounted
Making incremental backup
CMD: /bin/btrfs send -p "/backup/home/2016-03-12_16:26:51" "/backup/home/2016-03-12_16:30:04" | /bin/btrfs receive /tmp/just_backup_btrfs_45e177f34dddc33a5b1537ce8f4913ba/home
At subvol /backup/home/2016-03-12_16:30:04
CMD: /bin/btrfs subvolume delete "/backup_hdd/home/2016-03-11_16:30:08"
Old snapshot 2016-03-11_16:30:08 removed successfully from /backup_hdd/home
Creating incremental backup 2016-03-12_16:30:04 of /home to /backup_hdd/home finished successfully
Unmounting root subvolume skipped
CMD: /bin/btrfs subvolume delete "/backup/home/2016-03-12_15:30:05"
Old snapshot 2016-03-12_15:30:05 removed successfully from /backup/home
CMD: /bin/btrfs subvolume snapshot -r "/web" "/backup/web/2016-03-12_16:30:30"
CMD: sync
Snapshot 2016-03-12_16:30:30 for /web created successfully
CMD: mount
Mounting root subvolume skipped, since already mounted
Making incremental backup
CMD: /bin/btrfs send -p "/backup/web/2016-03-12_16:26:58" "/backup/web/2016-03-12_16:30:30" | /bin/btrfs receive /tmp/just_backup_btrfs_45e177f34dddc33a5b1537ce8f4913ba/web
At subvol /backup/web/2016-03-12_16:30:30
CMD: /bin/btrfs subvolume delete "/backup_hdd/web/2016-03-11_16:30:26"
Old snapshot 2016-03-11_16:30:26 removed successfully from /backup_hdd/web
Creating incremental backup 2016-03-12_16:30:30 of /web to /backup_hdd/web finished successfully
Unmounting root subvolume skipped
CMD: /bin/btrfs subvolume delete "/backup/web/2016-03-12_15:30:15"
Old snapshot 2016-03-12_15:30:15 removed successfully from /backup/web
Just backup btrfs finished!
```

Also you can call it with cron or in some other way:)

### What it actually does?
* reads configuration from `/etc/just-backup-btrfs.json`
* creates `destination_within_partition/history.db` SQLite database if it doesn't exists yet
* creates snapshot with date as the name inside `destination_within_partition`
* creates backups (copies of snapshots inside `destination_within_partition`) inside `destination_other_partition` for the case when source filesystem crashes
* store snapshot name, date and how long snapshot should be kept in `destination_within_partition/history.db` (and `destination_other_partition/history.db`, since backups might have own retention settings)
* removes old snapshots stored in `destination_within_partition/history.db` (and `destination_other_partition/history.db`) and remove them from it and from filesystem

### Configuration
Configuration options are especially made self-explanatory:
```json
[
	{
		"source_mounted_volume"        : "/",
		"destination_within_partition" : "/backup/root",
		"destination_other_partition"  : null,
		"date_format"                  : "Y-m-d_H:i:s",
		"keep_snapshots"               : {
			"hour"  : 60,
			"day"   : 24,
			"month" : 30,
			"year"  : 48
		}
	},
	{
		"source_mounted_volume"        : "/home",
		"destination_within_partition" : "/backup/home",
		"destination_other_partition"  : "/backup_external/home",
		"date_format"                  : "Y-m-d_H:i:s",
		"optimize_mounts"              : false,
		"keep_snapshots"               : {
			"hour"  : 120,
			"day"   : 48,
			"month" : 60,
			"year"  : 96
		},
		"keep_other_snapshots"         : {
			"hour"  : -1,
			"day"   : 96,
			"month" : 120,
			"year"  : 192
		},
		"minimum_delete_count"         : 10,
		"minimum_delete_count_other"   : 10
	}
]
```

* `source_mounted_volume` - string, absolute path, subvolume to backup/create snapshot of
* `destination_within_partition` - string, absolute path, where to store snapshots, should be the same partition as `source_mounted_volume`
* `destination_other_partition` - string, absolute path (or `null` if not needed), where to store actual backup, expected to be another BTRFS partition
* `date_format` - string, date format as for PHP [date() function](https://secure.php.net/manual/en/function.date.php)
* `keep_snapshots` - array with keys `hour`, `day`, `month` and `year`, each key contains number of snapshots that must be kept within corresponding time interval (`-1` means unlimited)
* `keep_other_snapshots` - the same as `keep_snapshots`, but for backups, `keep_snapshots` by default
* `optimize_mounts` - allows to avoid constant remounting root during external backups since it might be slow; `true` by default, might be disabled if necessary
* `minimum_delete_count` - minimum number of snapshots to remove, is used to decrease fragmentation and thus improve performance, `1` by default
* `minimum_delete_count_other` - the same as `minimum_delete_count`, but for backups, `minimum_delete_count` by default

Save this config as `/etc/just-backup-btrfs.json` and customize as you like.

### License
MIT, feel free to hack it and share!
