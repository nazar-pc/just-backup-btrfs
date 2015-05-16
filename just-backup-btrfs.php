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
 * @version   0.3
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
	 * @param array|null $config
	 */
	function __construct ($config = null) {
		$this->config = $config ?: json_decode(file_get_contents('/etc/just-backup-btrfs.json'), true);
		$this->binary = file_exists('/usr/sbin/btrfs') ? '/usr/sbin/btrfs' : '/bin/btrfs';
	}
	function backup () {
		foreach ($this->config as $local_config) {
			$this->do_backup($local_config);
		}
	}
	protected function do_backup ($config) {
		$source      = $config['source_mounted_volume'];
		$destination = $config['destination_within_partition'];

		if (!file_exists($destination) && !mkdir($destination, 0755, true)) {
			echo "Creating backup destination $destination failed, skip backing up $source, check permissions\n";
			return;
		}

		$history_db = $this->init_database($source, $destination);
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

		$this->cleanup($history_db, $source, $destination);
		$history_db->close();
	}
	/**
	 * @param string $source
	 * @param string $destination
	 *
	 * @return false|SQLite3
	 */
	protected function init_database ($source, $destination) {
		$history_db = new SQLite3("$destination/history.db");
		if (!$history_db) {
			echo "Opening database $destination/history.db failed, skip backing up $source, check permissions\n";
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
	 * @param string  $source
	 * @param string  $destination
	 */
	protected function cleanup ($history_db, $source, $destination) {
		foreach ($this->snapshots_for_removal($history_db) as $snapshot_for_removal) {
			shell_exec("$this->binary subvolume delete \"$destination/$snapshot_for_removal\"");
			if (file_exists("$destination/$snapshot_for_removal")) {
				echo "Removing old snapshot $snapshot_for_removal for $source failed\n";
				continue;
			}
			$snapshot_for_removal_escaped = $history_db->escapeString($snapshot_for_removal);
			if (!$history_db->exec(
				"DELETE FROM `history`
				WHERE `snapshot_name` = '$snapshot_for_removal_escaped'"
			)
			) {
				echo "Old snapshot $snapshot_for_removal for $source removed successfully, but not removed from history because of database error\n";
				continue;
			}
			echo "Old snapshot $snapshot_for_removal for $source removed successfully\n";
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

(new Just_backup_btrfs)->backup();

echo "Just backup btrfs finished!\n";
