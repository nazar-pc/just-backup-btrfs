### Just backup btrfs
Script that does just that - creates backups using snapshot of btrfs filesystem. Also it makes rotation of snapshots by removing old ones and keeping as many snapshots as you want.


### Why?
I wanted simple thing: store snapshots of several subvolumes in one place and keep specific number of snapshots for each time interval (more recent and less elder snapshots).

I was wondered that no existing solutions fit my needs, so I decided to write it:)

### Requirements
* php5-cli (version 5.4+)
* php5-sqlite

You can get them both on Ubuntu:
```bash
sudo apt-get install php5-cli php5-sqlite
```

Also enable sqlite3 extension since it may be disabled by default:
```bash
sudo php5enmod sqlite3
```

### Usage
Script expects configuration file to be present at location `/etc/just-backup-btrfs.json`, example of such file you can find in this repository.

There are few ways to run script.
```bash
sudo php just-backup-btrfs.php
```

or mark file as executable and just
```bash
sudo ./just-backup-btrfs.php
```

or mark as executable, rename it to `just-backup-btrfs` (since file can't contain dots in that place) and put into `/etc/cron.daily` to make backups every day.

Also you can call it with cron or in some other way:)

### What it actually does?
* reads configuration from `/etc/just-backup-btrfs.json`
* creates `destination/history.db` SQLite database if it doesn't exists yet
* creates snapshot with date as the name inside `destination`
* store snapshot name, date and how long snapshot should be kept in `destination/history.db`
* removes old snapshots stored in `destination/history.db` and remove them from it

### Configuration
Configuration options are especially made self-explanatory:
```json
[
	{
		"source_mounted_volume"			: "/",
		"destination_within_partition"	: "/backup/root",
		"date_format"					: "Y-m-d_H:i:s",
		"keep_snapshots"				: {
			"hour"	: 60,
			"day"	: 24,
			"month"	: 30,
			"year"	: 48
		}
	},
	{
		"source_mounted_volume"			: "/home",
		"destination_within_partition"	: "/backup/home",
		"date_format"					: "Y-m-d_H:i:s",
		"keep_snapshots"				: {
			"hour"	: 120,
			"day"	: 48,
			"month"	: 60,
			"year"	: 96
		}
	}
]
```
The only thing that may not be obvious here - you can use `-1` as value for `keep_snapshots` elements to allow storing of all created snapshots.

Save this config as `/etc/just-backup-btrfs.json` and customize as you like.

### License
MIT, feel free to hack it and share!
