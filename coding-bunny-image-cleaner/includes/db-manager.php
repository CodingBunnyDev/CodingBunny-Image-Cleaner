<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBIC_DB_Manager {

	const DB_VERSION = '1.1.0';

	private static $instance = null;

	private $wpdb;

	private $latest_run_cache = array();

	private function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function maybe_upgrade() {
		$installed = get_option( 'cbic_db_version', '' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();
		$prefix          = $this->wpdb->prefix;

		$scan_runs = "CREATE TABLE {$prefix}cbic_scan_runs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(50) NOT NULL,
			status ENUM('running','completed','failed','canceled') NOT NULL DEFAULT 'running',
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
			batch_size INT UNSIGNED NULL,
			meta LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY type_status_finished (type, status, finished_at),
			KEY started_at (started_at)
		) $charset_collate;";

		$scan_items = "CREATE TABLE {$prefix}cbic_scan_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED NOT NULL,
			object_id BIGINT NULL,
			object_type VARCHAR(50) NULL,
			issue_type VARCHAR(50) NOT NULL,
			group_key VARCHAR(191) NULL,
			data LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY run_id (run_id),
			KEY issue_type (issue_type),
			KEY object_idx (object_type, object_id),
			KEY group_key (group_key)
		) $charset_collate;";

		$usage = "CREATE TABLE {$prefix}cbic_attachment_usage (
			attachment_id BIGINT UNSIGNED NOT NULL,
			is_used TINYINT(1) NOT NULL DEFAULT 0,
			used_in LONGTEXT NULL,
			last_scan_run_id BIGINT UNSIGNED NULL,
			PRIMARY KEY (attachment_id),
			KEY is_used (is_used),
			KEY last_scan_run_id (last_scan_run_id)
		) $charset_collate;";

		dbDelta( $scan_runs );
		dbDelta( $scan_items );
		dbDelta( $usage );

		update_option( 'cbic_db_version', self::DB_VERSION );
	}

	private function table( $short ) {
		return $this->wpdb->prefix . $short;
	}

	private function sql_value( $value, $format ) {
		if ( is_null( $value ) ) {
			return 'NULL';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->prepare( $format, $value );
	}

	private function get_latest_run_id( $type ) {
		if ( isset( $this->latest_run_cache[ $type ] ) ) {
			return $this->latest_run_cache[ $type ];
		}

		$run_id = $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$this->table('cbic_scan_runs')}
				WHERE type = %s AND status = 'completed'
				ORDER BY finished_at DESC LIMIT 1",
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$type
			)
		);

		$this->latest_run_cache[ $type ] = $run_id ? (int) $run_id : 0;
		return $this->latest_run_cache[ $type ];
	}

	public function start_run( $type, $meta = array(), $batch_size = null ) {
		$this->wpdb->insert(
			$this->table( 'cbic_scan_runs' ),
			array(
				'type'       => $type,
				'status'     => 'running',
				'started_at' => current_time( 'mysql' ),
				'progress'   => 0,
				'batch_size' => $batch_size,
				'meta'       => wp_json_encode( $meta ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	public function update_run_progress( $run_id, $progress ) {
		$this->wpdb->update(
			$this->table( 'cbic_scan_runs' ),
			array( 'progress' => max( 0, min( 100, (int) $progress ) ) ),
			array( 'id' => (int) $run_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	public function complete_run( $run_id, $status = 'completed' ) {
		$this->wpdb->update(
			$this->table( 'cbic_scan_runs' ),
			array(
				'status'      => $status,
				'progress'    => 100,
				'finished_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $run_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		$this->latest_run_cache = array();
	}

	public function fail_run( $run_id, $message = '' ) {
		$this->wpdb->update(
			$this->table( 'cbic_scan_runs' ),
			array(
				'status'      => 'failed',
				'finished_at' => current_time( 'mysql' ),
				'meta'        => wp_json_encode( array( 'error' => (string) $message ) ),
			),
			array( 'id' => (int) $run_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->latest_run_cache = array();
	}

	public function bulk_insert_items( array $items, $chunk_size = 200 ) {
		if ( empty( $items ) ) {
			return;
		}

		$table = $this->table( 'cbic_scan_items' );

		foreach ( array_chunk( $items, $chunk_size ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $item ) {
				$run_id_val = array_key_exists( 'run_id', $item ) ? ( $item['run_id'] ) : 0;
				$object_id_val = array_key_exists( 'object_id', $item ) ? $item['object_id'] : null;
				$object_type_val = array_key_exists( 'object_type', $item ) ? $item['object_type'] : null;
				$issue_type_val = array_key_exists( 'issue_type', $item ) ? $item['issue_type'] : '';
				$group_key_val = array_key_exists( 'group_key', $item ) ? $item['group_key'] : null;
				$data_val = array_key_exists( 'data', $item ) ? $item['data'] : null;

				$v_run_id     = $this->sql_value( (int) $run_id_val, '%d' );
				$v_object_id  = $this->sql_value( is_null( $object_id_val ) ? null : (int) $object_id_val, '%d' );
				$v_object_tp  = $this->sql_value( is_null( $object_type_val ) ? null : (string) $object_type_val, '%s' );
				$v_issue_type = $this->sql_value( (string) $issue_type_val, '%s' );
				$v_group_key  = $this->sql_value( is_null( $group_key_val ) ? null : (string) $group_key_val, '%s' );

				if ( is_null( $data_val ) ) {
					$v_data = 'NULL';
				} else {
					$data_string = is_string( $data_val ) ? $data_val : wp_json_encode( $data_val );
					$v_data = $this->sql_value( $data_string, '%s' );
				}

				$values[] = "( {$v_run_id}, {$v_object_id}, {$v_object_tp}, {$v_issue_type}, {$v_group_key}, {$v_data} )";
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$this->wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"INSERT INTO {$table} (run_id, object_id, object_type, issue_type, group_key, data) VALUES " . implode( ',', $values )
			);
		}
	}

	public function count_items( $type ) {
		$run_id = $this->get_latest_run_id( (string) $type );
		if ( ! $run_id ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"SELECT COUNT(*) FROM {$this->table('cbic_scan_items')} WHERE run_id = %d",
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$run_id
			)
		);
	}

	public function get_items( $type, $limit = 500, $offset = 0 ) {
		$run_id = $this->get_latest_run_id( (string) $type );
		if ( ! $run_id ) {
			return array();
		}

		$sql = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id, object_id, object_type, issue_type, group_key, data FROM {$this->table('cbic_scan_items')}
			WHERE run_id = %d
			ORDER BY id ASC
			LIMIT %d OFFSET %d",
			$run_id,
			$limit,
			$offset
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			function ( $row ) {
				$row['data'] = $this->maybe_json_decode( $row['data'] );
				return $row;
			},
			$rows ?: array()
		);
	}

	public function get_latest_run_finished_at( $type ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$val = $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT finished_at FROM {$this->table('cbic_scan_runs')}
				WHERE type = %s AND status = 'completed'
				ORDER BY finished_at DESC LIMIT 1",
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$type
			)
		);

		return $val ? strtotime( $val ) : false;
	}

	public function upsert_attachment_usage( $attachment_id, $is_used, $used_in = null, $run_id = null ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		$table = $this->table( 'cbic_attachment_usage' );

		$v_attachment = $this->sql_value( $attachment_id, '%d' );
		$v_is_used    = $this->sql_value( $is_used ? 1 : 0, '%d' );
		$v_used_in    = is_null( $used_in ) ? 'NULL' : $this->sql_value( is_string( $used_in ) ? $used_in : wp_json_encode( $used_in ), '%s' );
		$v_run_id     = is_null( $run_id ) ? 'NULL' : $this->sql_value( (int) $run_id, '%d' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"REPLACE INTO {$table} (attachment_id, is_used, used_in, last_scan_run_id) VALUES ( {$v_attachment}, {$v_is_used}, {$v_used_in}, {$v_run_id} )"
		);
	}

	public function get_unused_from_usage( $limit = 500, $offset = 0 ) {
		$sql = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT attachment_id, used_in FROM {$this->table('cbic_attachment_usage')}
			WHERE is_used = 0
			ORDER BY attachment_id ASC
			LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			function ( $row ) {
				return array(
					'ID'      => (int) $row['attachment_id'],
					'used_in' => $this->maybe_json_decode( $row['used_in'] ),
				);
			},
			$rows ?: array()
		);
	}

	private function maybe_json_decode( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}
		$trim = trim( $data );
		if ( '' === $trim ) {
			return $data;
		}
		$decoded = json_decode( $trim, true );
		return ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $data;
	}
}