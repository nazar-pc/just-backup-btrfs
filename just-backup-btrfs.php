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
 * @version   0.2
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
	function do_backup () {
		foreach ($this->config as $local_config) {
			$source         = $local_config['source_mounted_volume'];
			$destination    = $local_config['destination_within_partition'];
			$keep_snapshots = $local_config['keep_snapshots'];

			if (!file_exists($destination) && !mkdir($destination, 0755, true)) {
				echo "Creating backup destination $destination failed, skip backing up $source, check permissions\n";
				continue;
			}

			if (isset($history_db)) {
				$history_db->close();
			}
			$history_db = new SQLite3("$destination/history.db");
			if (!$history_db) {
				echo "Opening database $destination/history.db failed, skip backing up $source, check permissions\n";
				continue;
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

			$keep_year  = $this->keep_or_not($history_db, $keep_snapshots['year'], 'year');
			$keep_month = $keep_year ? 1 : $this->keep_or_not($history_db, $keep_snapshots['month'], 'month');
			$keep_day   = $keep_month ? 1 : $this->keep_or_not($history_db, $keep_snapshots['day'], 'day');
			$keep_hour  = $keep_day ? 1 : $this->keep_or_not($history_db, $keep_snapshots['hour'], 'hour');
			if (!$keep_hour) {
				continue;
			}

			$date     = time();
			$snapshot = date($local_config['date_format'], $date);
			shell_exec("$this->binary subvolume snapshot -r \"$source\" \"$destination/$snapshot\"");
			if (!file_exists("$destination/$snapshot")) {
				echo "Snapshot creation for $source failed\n";
				continue;
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
				continue;
			}
			echo "Snapshot $snapshot for $source created successfully\n";

			$snapshots_for_removal = [];
			$snapshots             = $history_db->query(
				"SELECT `snapshot_name`
				FROM `history`
				WHERE
					`keep_day`	= 0 AND
					`date`		< ".(time() - 3600)
			);
			while ($snapshots_for_removal[] = $snapshots->fetchArray(SQLITE3_ASSOC)['snapshot_name']) {
				// Empty
			}
			$snapshots = $history_db->query(
				"SELECT `snapshot_name`
				FROM `history`
				WHERE
					`keep_month`	= 0 AND
					`date`			< ".(time() - 3600 * 24)
			);
			while ($snapshots_for_removal[] = $snapshots->fetchArray(SQLITE3_ASSOC)['snapshot_name']) {
				// Empty
			}
			$snapshots = $history_db->query(
				"SELECT `snapshot_name`
				FROM `history`
				WHERE
					`keep_year`	= 0 AND
					`date`		< ".(time() - 3600 * 24 * 30)
			);
			while ($snapshots_for_removal[] = $snapshots->fetchArray(SQLITE3_ASSOC)['snapshot_name']) {
				// Empty
			}
			$snapshots_for_removal = array_unique($snapshots_for_removal);
			$snapshots_for_removal = array_filter($snapshots_for_removal);
			foreach ($snapshots_for_removal as $snapshot_for_removal) {
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
			$history_db->close();
		}
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
		}
		switch ($interval) {
			case 'hour':
				$offset    = 3600 / $keep;
				$condition = '`keep_year` = 1 OR `keep_month` = 1 OR `keep_day` = 1 OR `keep_hour` = 1';
				break;
			case 'day':
				$offset    = 3600 * 24 / $keep;
				$condition = '`keep_year` = 1 OR `keep_month` = 1 OR `keep_day` = 1';
				break;
			case 'month':
				$offset    = 3600 * 24 * 30 / $keep;
				$condition = '`keep_year` = 1 OR `keep_month` = 1';
				break;
			case 'year':
				$offset    = 3600 * 24 * 365 / $keep;
				$condition = '`keep_year` = 1';
				break;
			default:
				return 1;
		}
		return $history_db->querySingle(
			"SELECT `snapshot_name`
			FROM `history`
			WHERE
				($condition) AND
				`date`		> ".(time() - $offset)
		) ? 0 : 1;
	}
}

echo "Just backup btrfs started...\n";

(new Just_backup_btrfs)->do_backup();

echo "Just backup btrfs finished!\n";
