#!/usr/bin/php
<?php
namespace nazarpc;
use
	SQLite3;
/**
 * @package   Just backup btrfs
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2014-2015, Nazar Mokrynskyi
 * @license   http://opensource.org/licenses/MIT
 * @version   0.5
 */
class Just_backup_btrfs {
	/**
	 * @var array
	 */
	protected $config;
	/**
	 * @var string
	 */
	protected $binary;
	/**
	 * @param array $config
	 */
	function __construct ($config) {
		if (!$config) {
			exit("Incorrect configuration, aborted\n");
		}
		$this->config = $config;
		$this->binary = file_exists('/usr/sbin/btrfs') ? '/usr/sbin/btrfs' : '/bin/btrfs';
	}
	function backup () {
		foreach ($this->config as $local_config) {
			$this->do_backup($local_config);
		}
	}
	/**
	 * @param array $config
	 */
	protected function do_backup ($config) {
		$source      = $config['source_mounted_volume'];
		$destination = $config['destination_within_partition'];

		$history_db = $this->init_database($destination);
		if (!$history_db) {
			return;
		}

		list($keep_year, $keep_month, $keep_day, $keep_hour) = $this->how_long_to_keep($history_db, $config['keep_snapshots']);
		if (!$keep_hour) {
			return;
		}

		$date     = time();
		$snapshot = date($config['date_format'], $date);
		shell_exec("$this->binary subvolume snapshot -r \"$source\" \"$destination/$snapshot\"");
		shell_exec("sync"); // To actually write snapshot to disk
		if (!file_exists("$destination/$snapshot")) {
			echo "Snapshot creation for $source failed\n";
			return;
		}

		$snapshot_escaped = $history_db->escapeString($snapshot);
		$comment          = ''; //TODO Comments support
		if (!$history_db->exec(
			"INSERT INTO `history` (
				`snapshot_name`,
				`comment`,
				`date`,
				`keep_hour`,
				`keep_day`,
				`keep_month`,
				`keep_year`
			) VALUES (
				'$snapshot_escaped',
				'$comment',
				'$date',
				'$keep_hour',
				'$keep_day',
				'$keep_month',
				'$keep_year'
			)"
		)
		) {
			echo "Snapshot $snapshot for $source created successfully, but not added to history because of database error\n";
			return;
		}
		echo "Snapshot $snapshot for $source created successfully\n";

		$this->do_backup_external($config, $history_db, $snapshot, $comment, $date);

		$this->cleanup($history_db, $destination);
		$history_db->close();
	}
	/**
	 * @param string $destination
	 *
	 * @return false|SQLite3
	 */
	protected function init_database ($destination) {
		if (!file_exists($destination) && !mkdir($destination, 0755, true)) {
			echo "Creating backup destination $destination failed, check permissions\n";
			return false;
		}

		$history_db = new SQLite3("$destination/history.db");
		if (!$history_db) {
			echo "Opening database $destination/history.db failed, check permissions\n";
			return false;
		}

		$history_db->exec(
			"CREATE TABLE IF NOT EXISTS `history` (
				`snapshot_name` TEXT,
				`comment` TEXT,
				`date` INTEGER,
				`keep_hour` INTEGER,
				`keep_day` INTEGER,
				`keep_month` INTEGER,
				`keep_year` INTEGER
			)"
		);
		return $history_db;
	}
	/**
	 * @param array   $config
	 * @param SQLite3 $history_db
	 * @param string  $snapshot
	 * @param string  $comment
	 * @param int     $date
	 */
	protected function do_backup_external ($config, $history_db, $snapshot, $comment, $date) {
		if (!isset($config['destination_other_partition']) || !$config['destination_other_partition']) {
			return;
		}
		$source               = $config['source_mounted_volume'];
		$destination          = $config['destination_within_partition'];
		$destination_external = $config['destination_other_partition'];
		$history_db_external  = $this->init_database($destination_external);
		if (!$history_db_external) {
			return;
		}
		list($keep_year, $keep_month, $keep_day, $keep_hour) = $this->how_long_to_keep($history_db_external, $config['keep_snapshots']);
		if (!$keep_hour) {
			return;
		}
		/**
		 * Next block is because of BTRFS limitations - we can't receive incremental snapshots diff into path which is mounted as subvolume, not root of the partition.
		 * To overcome this we determine partition which was mounted, subvolume path inside partition, mount partition root to temporary path and determine full path of our destination inside this new mount point.
		 * This new mount point will be stored as $destination_external_fixed, mount point will be stored in $target_mount_point variables
		 */
		$mount_point_options = $this->determine_mount_point($destination_external);
		if (!$mount_point_options) {
			echo "Can't find where and how $destination_external is mounted, probably it is not on BTRFS partition?\n";
			echo "Creating backup $snapshot of $source to $destination_external failed\n";
			return;
		}
		list($partition, $mount_point, $mount_options) = $mount_point_options;

		/**
		 * Set fixed destination as path inside subvolume, just remove mount point from the whole destination path
		 */
		$destination_external_fixed = str_replace($mount_point, '', $destination_external);
		/**
		 * Now detect whether partition subvolume was mounted, $m[1] will contain subvolume path inside partition
		 */
		if (preg_match("#$partition\[(.+)\]#", exec("findmnt $mount_point"), $m)) {
			$destination_external_fixed = $m[1].$destination_external_fixed;
		}

		$target_mount_point         = '/tmp/'.uniqid('just_backup_btrfs_');
		$destination_external_fixed = $target_mount_point.$destination_external_fixed;
		mkdir($target_mount_point);
		shell_exec("mount -o subvol=/,$mount_options /$partition $target_mount_point");
		unset($mount_point_options, $partition, $mount_point, $mount_options);

		if (!isset($destination_external_fixed, $target_mount_point)) {
			echo "Can't find where and how $destination_external is mounted, probably it is not on BTRFS partition?\n";
			echo "Creating backup $snapshot of $source to $destination_external failed\n";
			return;
		}
		$common_snapshot = $this->get_last_common_snapshot($history_db, $history_db_external);
		if ($common_snapshot) {
			shell_exec(
				"$this->binary send -p \"$destination/$common_snapshot\" \"$destination/$snapshot\" | $this->binary receive $destination_external_fixed"
			);
			$type = 'incremental backup';
		} else {
			shell_exec("$this->binary send  \"$destination/$snapshot\" | $this->binary receive $destination_external_fixed");
			$type = 'backup';
		}
		if (!file_exists("$destination_external/$snapshot")) {
			echo "Creating $type $snapshot of $source to $destination_external failed\n";
		} else {
			$snapshot_escaped = $history_db->escapeString($snapshot);
			if (!$history_db_external->exec(
				"INSERT INTO `history` (
					`snapshot_name`,
					`comment`,
					`date`,
					`keep_hour`,
					`keep_day`,
					`keep_month`,
					`keep_year`
				) VALUES (
					'$snapshot_escaped',
					'$comment',
					'$date',
					'$keep_hour',
					'$keep_day',
					'$keep_month',
					'$keep_year'
				)"
			)
			) {
				echo "Creating $type $snapshot of $source to $destination_external finished successfully, but not added to history because of database error\n";
			} else {
				$this->cleanup($history_db_external, $destination_external);
				echo "Creating $type $snapshot of $source to $destination_external finished successfully\n";
			}
		}
		shell_exec("umount $target_mount_point");
		rmdir($target_mount_point);
	}
	protected function determine_mount_point ($destination_external) {
		$mount_point_options = [];
		foreach (explode("\n", shell_exec('mount')) as $mount_string) {
			/**
			 * Choose only BTRFS filesystems
			 */
			preg_match("#^(.+) on (.+) type btrfs \((.+)\)$#", $mount_string, $m);
			/**
			 * If our destination is inside current mount point - this is what we need
			 */
			if (isset($m[2]) && strpos($destination_external, $m[2]) === 0) {
				/**
				 * Partition in form of /dev/sdXY
				 */
				$partition = $m[1];
				/**
				 * Mount point
				 */
				$mount_point = $m[2];
				/**
				 * Mount options
				 */
				$mount_options = $m[3];
				if (!isset($mount_point_options[1]) || strlen($mount_point_options[1]) < strlen($mount_point)) {
					$mount_point_options = [$partition, $mount_point, $mount_options];
				}
			}
		}
		return $mount_point_options ?: false;
	}
	/**
	 * @param SQLite3 $history_db
	 * @param SQLite3 $history_db_external
	 *
	 * @return bool
	 */
	protected function get_last_common_snapshot ($history_db, $history_db_external) {
		$snapshots = $history_db_external->query(
			"SELECT `snapshot_name`
			FROM `history`"
		);
		while ($snapshot = $snapshots->fetchArray(SQLITE3_ASSOC)['snapshot_name']) {
			$snapshot_escaped = $history_db->escapeString($snapshot);
			$snapshot_found   = $history_db
				->query(
					"SELECT `snapshot_name`
						FROM `history`
						WHERE `snapshot_name` = '$snapshot_escaped'"
				)
				->fetchArray();
			if ($snapshot_found) {
				return $snapshot;
			}
		}
		return false;
	}
	/**
	 * @param SQLite3 $history_db
	 * @param array   $keep_snapshots
	 *
	 * @return array
	 */
	protected function how_long_to_keep ($history_db, $keep_snapshots) {
		return [
			$keep_year = $this->keep_or_not($history_db, $keep_snapshots['year'], 'year'),
			$keep_month = $keep_year ? 1 : $this->keep_or_not($history_db, $keep_snapshots['month'], 'month'),
			$keep_day = $keep_month ? 1 : $this->keep_or_not($history_db, $keep_snapshots['day'], 'day'),
			$keep_hour = $keep_day ? 1 : $this->keep_or_not($history_db, $keep_snapshots['hour'], 'hour')
		];
	}
	/**
	 * @param SQLite3 $history_db
	 * @param int     $keep
	 * @param string  $interval
	 *
	 * @return int
	 */
	protected function keep_or_not ($history_db, $keep, $interval) {
		if ($keep == -1) {
			return 1;
		} elseif ($keep == 0) {
			return 0;
		}
		$offset    = 3600 / $keep;
		$condition = [
			'`keep_year` = 1',
			'`keep_month` = 1',
			'`keep_day` = 1',
			'`keep_hour` = 1'
		];
		/**
		 * Not an error it should go through all further cases
		 */
		switch ($interval) {
			case 'year':
				$offset *= 365 / 30; // We divide by 30 because of next condition which represents days as well
				array_pop($condition);
			case 'month':
				$offset *= 30;
				array_pop($condition);
			case 'day':
				$offset *= 24;
				array_pop($condition);
		}
		$condition = implode(' OR ', $condition);;
		return $history_db->querySingle(
			"SELECT `snapshot_name`
			FROM `history`
			WHERE
				($condition) AND
				`date`	> ".(time() - $offset)
		) ? 0 : 1;
	}
	/**
	 * @param SQLite3 $history_db
	 * @param string  $destination
	 */
	protected function cleanup ($history_db, $destination) {
		foreach ($this->snapshots_for_removal($history_db) as $snapshot_for_removal) {
			shell_exec("$this->binary subvolume delete \"$destination/$snapshot_for_removal\"");
			if (file_exists("$destination/$snapshot_for_removal")) {
				echo "Removing old snapshot $snapshot_for_removal from $destination failed\n";
				continue;
			}
			$snapshot_for_removal_escaped = $history_db->escapeString($snapshot_for_removal);
			if (!$history_db->exec(
				"DELETE FROM `history`
				WHERE `snapshot_name` = '$snapshot_for_removal_escaped'"
			)
			) {
				echo "Old snapshot $snapshot_for_removal removed successfully from $destination, but not removed from history because of database error\n";
				continue;
			}
			echo "Old snapshot $snapshot_for_removal removed successfully from $destination\n";
		}
	}
	/**
	 * @param SQLite3 $history_db
	 *
	 * @return string[]
	 */
	protected function snapshots_for_removal ($history_db) {
		$snapshots_for_removal = [];
		$date                  = time();
		$hour_ago              = 3600;
		$day_ago               = $hour_ago * 24;
		$month_ago             = $day_ago * 30;
		$snapshots             = $history_db->query(
			"SELECT `snapshot_name`
			FROM `history`
			WHERE
				(
					`keep_day`	= 0 AND
					`date`		< ($date - $hour_ago)
				) OR
				(
					`keep_month`	= 0 AND
					`date`			< ($date - $day_ago)
				) OR
				(
					`keep_year`	= 0 AND
					`date`		< ($date - $month_ago)
				)"
		);
		while ($snapshots_for_removal[] = $snapshots->fetchArray(SQLITE3_ASSOC)['snapshot_name']) {
			// Empty
		}
		return array_filter(array_unique($snapshots_for_removal));
	}
}

echo "Just backup btrfs started...\n";

$Just_backup_btrfs = new Just_backup_btrfs(
	json_decode(file_get_contents(isset($argv[1]) ? $argv[1] : '/etc/just-backup-btrfs.json'), true)
);
$Just_backup_btrfs->backup();

echo "Just backup btrfs finished!\n";
