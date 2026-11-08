<?php
/**
 * Export Queue Store (Options)
 *
 * Stores export jobs in a single wp_options key (serialized), behaving like a table.
 *
 * @package MonsterInsights
 * @subpackage Pro/Export
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MonsterInsights_Export_Queue_Store
 */
final class MonsterInsights_Export_Queue_Store {

	/**
	 * Option name containing the export queue.
	 * @var string
	 */
	private $option_name = 'monsterinsights_export_queue';

	/**
	 * Lock key used to avoid concurrent option write races.
	 * @var string
	 */
	private $lock_key = 'monsterinsights_export_queue_lock';

	/**
	 * Lock ttl in seconds.
	 * @var int
	 */
	private $lock_ttl = 15;

	/**
	 * Singleton instance.
	 * @var MonsterInsights_Export_Queue_Store
	 */
	private static $instance;

	/**
	 * Get singleton instance.
	 * @return MonsterInsights_Export_Queue_Store
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
	}

	/**
	 * Insert a new export row.
	 *
	 * @param array $row Flat export row data.
	 * @return int|false New export ID on success, false on failure.
	 */
	public function insert( $row ) {
		return $this->with_lock( function( $data ) use ( $row ) {
			$data = $this->ensure_schema( $data );

			$next_id = isset( $data['next_id'] ) ? absint( $data['next_id'] ) : 1;
			if ( $next_id < 1 ) {
				$next_id = 1;
			}

			$row            = is_array( $row ) ? $row : array();
			$row['id']      = $next_id;
			$row['status']  = isset( $row['status'] ) ? (string) $row['status'] : 'pending';

			if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
				$data['items'] = array();
			}

			$data['items'][ (string) $next_id ] = $this->normalize_row( $row );
			$data['next_id']                    = $next_id + 1;

			$saved = $this->save_data( $data );
			if ( ! $saved ) {
				return false;
			}

			return $next_id;
		} );
	}

	/**
	 * Get an export by ID.
	 *
	 * @param int $export_id Export ID.
	 * @return object|null
	 */
	public function get( $export_id ) {
		$export_id = absint( $export_id );
		if ( ! $export_id ) {
			return null;
		}

		$data = $this->ensure_schema( $this->load_data() );
		$key  = (string) $export_id;

		if ( empty( $data['items'][ $key ] ) || ! is_array( $data['items'][ $key ] ) ) {
			return null;
		}

		return (object) $data['items'][ $key ];
	}

	/**
	 * Get exports for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Max results.
	 * @return array Array of objects.
	 */
	public function get_user_exports( $user_id, $limit = 50 ) {
		$user_id = absint( $user_id );
		$limit   = absint( $limit );
		if ( $limit < 1 ) {
			$limit = 50;
		}

		$data = $this->ensure_schema( $this->load_data() );
		$rows = array();

		foreach ( $data['items'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( absint( $row['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$rows[] = $row;
		}

		usort( $rows, function( $a, $b ) {
			$ad = isset( $a['export_date'] ) ? strtotime( (string) $a['export_date'] ) : 0;
			$bd = isset( $b['export_date'] ) ? strtotime( (string) $b['export_date'] ) : 0;
			if ( $ad === $bd ) {
				return 0;
			}
			// Desc.
			return ( $ad < $bd ) ? 1 : -1;
		} );

		$rows = array_slice( $rows, 0, $limit );
		return array_map( function( $r ) { return (object) $r; }, $rows );
	}

	/**
	 * Update an export by ID.
	 *
	 * @param int   $export_id Export ID.
	 * @param array $patch     Fields to set.
	 * @return bool
	 */
	public function update( $export_id, $patch ) {
		$export_id = absint( $export_id );
		if ( ! $export_id ) {
			return false;
		}
		$patch = is_array( $patch ) ? $patch : array();

		return (bool) $this->with_lock( function( $data ) use ( $export_id, $patch ) {
			$data = $this->ensure_schema( $data );
			$key  = (string) $export_id;

			if ( empty( $data['items'][ $key ] ) || ! is_array( $data['items'][ $key ] ) ) {
				return false;
			}

			$row = $data['items'][ $key ];

			// Normalize legacy keys.
			if ( isset( $patch['error'] ) && ! isset( $patch['error_message'] ) ) {
				$patch['error_message'] = $patch['error'];
				unset( $patch['error'] );
			}

			foreach ( $patch as $k => $v ) {
				$row[ (string) $k ] = $v;
			}

			$data['items'][ $key ] = $this->normalize_row( $row );
			return $this->save_data( $data );
		} );
	}

	/**
	 * Delete an export by ID.
	 *
	 * @param int $export_id Export ID.
	 * @return bool
	 */
	public function delete( $export_id ) {
		$export_id = absint( $export_id );
		if ( ! $export_id ) {
			return false;
		}

		return (bool) $this->with_lock( function( $data ) use ( $export_id ) {
			$data = $this->ensure_schema( $data );
			$key  = (string) $export_id;

			if ( empty( $data['items'][ $key ] ) ) {
				return false;
			}

			unset( $data['items'][ $key ] );
			return $this->save_data( $data );
		} );
	}

	/**
	 * Get next pending export ready to process (oldest first).
	 *
	 * @param string $now_mysql Current time in mysql format.
	 * @return object|null
	 */
	public function get_next_pending_ready( $now_mysql ) {
		$data = $this->ensure_schema( $this->load_data() );

		$now_ts = strtotime( (string) $now_mysql );
		if ( ! $now_ts ) {
			$now_ts = time();
		}

		$candidates = array();

		foreach ( $data['items'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ( $row['status'] ?? '' ) !== 'pending' ) {
				continue;
			}

			$retry_at = $row['retry_at'] ?? null;
			if ( ! empty( $retry_at ) ) {
				$retry_ts = strtotime( (string) $retry_at );
				if ( $retry_ts && $retry_ts > $now_ts ) {
					continue;
				}
			}

			$candidates[] = $row;
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort( $candidates, function( $a, $b ) {
			$ad = isset( $a['export_date'] ) ? strtotime( (string) $a['export_date'] ) : 0;
			$bd = isset( $b['export_date'] ) ? strtotime( (string) $b['export_date'] ) : 0;
			if ( $ad === $bd ) {
				$aid = absint( $a['id'] ?? 0 );
				$bid = absint( $b['id'] ?? 0 );
				return ( $aid < $bid ) ? -1 : 1;
			}
			// Asc.
			return ( $ad < $bd ) ? -1 : 1;
		} );

		return (object) $candidates[0];
	}

	/**
	 * Count user's exports in last 24 hours.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function count_user_exports_last_24h( $user_id ) {
		$user_id = absint( $user_id );
		$data    = $this->ensure_schema( $this->load_data() );
		$cutoff  = time() - DAY_IN_SECONDS;
		$count   = 0;

		foreach ( $data['items'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( absint( $row['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$ts = isset( $row['export_date'] ) ? strtotime( (string) $row['export_date'] ) : 0;
			if ( $ts && $ts > $cutoff ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Count user's active exports (pending + in_progress).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function count_user_active( $user_id ) {
		$user_id = absint( $user_id );
		$data    = $this->ensure_schema( $this->load_data() );
		$count   = 0;

		foreach ( $data['items'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( absint( $row['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$status = (string) ( $row['status'] ?? '' );
			if ( 'pending' === $status || 'in_progress' === $status ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Get expired exports (expires_at < now).
	 *
	 * @param string $now_mysql Now in mysql format.
	 * @return array Array of objects.
	 */
	public function get_expired( $now_mysql ) {
		$data   = $this->ensure_schema( $this->load_data() );
		$now_ts = strtotime( (string) $now_mysql );
		if ( ! $now_ts ) {
			$now_ts = time();
		}

		$expired = array();
		foreach ( $data['items'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$expires_at = $row['expires_at'] ?? '';
			if ( ! $expires_at ) {
				continue;
			}
			$exp_ts = strtotime( (string) $expires_at );
			if ( $exp_ts && $exp_ts < $now_ts ) {
				$expired[] = $row;
			}
		}

		return array_map( function( $r ) { return (object) $r; }, $expired );
	}

	/**
	 * Get basic queue stats.
	 *
	 * @return array
	 */
	public function get_queue_stats() {
		$data = $this->ensure_schema( $this->load_data() );

		$stats = array(
			'total'       => 0,
			'pending'     => 0,
			'in_progress' => 0,
			'complete'    => 0,
			'failed'      => 0,
		);

		foreach ( $data['items'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$stats['total']++;
			$status = (string) ( $row['status'] ?? '' );
			if ( isset( $stats[ $status ] ) ) {
				$stats[ $status ]++;
			}
		}

		return $stats;
	}

	/**
	 * Get recent exports (sorted desc by export_date).
	 *
	 * @param int $limit Limit.
	 * @return array Array of objects.
	 */
	public function get_recent( $limit = 10 ) {
		$limit = absint( $limit );
		if ( $limit < 1 ) {
			$limit = 10;
		}
		$data = $this->ensure_schema( $this->load_data() );

		$rows = array();
		foreach ( $data['items'] as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		usort( $rows, function( $a, $b ) {
			$ad = isset( $a['export_date'] ) ? strtotime( (string) $a['export_date'] ) : 0;
			$bd = isset( $b['export_date'] ) ? strtotime( (string) $b['export_date'] ) : 0;
			if ( $ad === $bd ) {
				return 0;
			}
			return ( $ad < $bd ) ? 1 : -1;
		} );

		$rows = array_slice( $rows, 0, $limit );
		return array_map( function( $r ) { return (object) $r; }, $rows );
	}

	/**
	 * Normalize row (ensure expected keys exist).
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function normalize_row( $row ) {
		$defaults = array(
			'id'                => 0,
			'user_id'           => 0,
			'report_type'       => '',
			'export_date'       => '',
			'start_date'        => '',
			'end_date'          => '',
			'date_range_display'=> '',
			'export_format'     => 'csv',
			'status'            => 'pending',
			'file_name'         => '',
			'file_subdir'       => '',
			'file_size'         => null,
			'started_at'        => null,
			'completed_at'      => null,
			'retry_count'       => 0,
			'retry_at'          => null,
			'error_message'     => null,
			'expires_at'        => '',
			'download_token'    => '',
			'filters'           => '',
		);

		$row = array_merge( $defaults, is_array( $row ) ? $row : array() );

		$row['id']        = absint( $row['id'] );
		$row['user_id']   = absint( $row['user_id'] );
		$row['report_type'] = (string) $row['report_type'];
		$row['export_format'] = (string) $row['export_format'];
		$row['status']    = (string) $row['status'];

		return $row;
	}

	/**
	 * Load option data.
	 *
	 * @return array
	 */
	private function load_data() {
		$data = get_option( $this->option_name, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save option data.
	 *
	 * @param array $data Data.
	 * @return bool
	 */
	private function save_data( $data ) {
		// Autoload off - keep this option from loading on every request.
		return update_option( $this->option_name, $data, false );
	}

	/**
	 * Ensure schema is present.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private function ensure_schema( $data ) {
		$data = is_array( $data ) ? $data : array();

		if ( ! isset( $data['next_id'] ) ) {
			$data['next_id'] = 1;
		}

		if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			$data['items'] = array();
		}

		return $data;
	}

	/**
	 * Execute a read/modify/write under a transient lock.
	 *
	 * @param callable $fn Callback (receives current data).
	 * @return mixed
	 */
	private function with_lock( $fn ) {
		$acquired = $this->acquire_lock();
		if ( ! $acquired ) {
			// Best-effort: if lock isn't acquired, still attempt without it to avoid breaking UX.
			$data = $this->load_data();
			return $fn( $data );
		}

		try {
			$data = $this->load_data();
			return $fn( $data );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Acquire lock.
	 * @return bool
	 */
	private function acquire_lock() {
		if ( get_transient( $this->lock_key ) ) {
			return false;
		}
		return (bool) set_transient( $this->lock_key, time(), $this->lock_ttl );
	}

	/**
	 * Release lock.
	 * @return void
	 */
	private function release_lock() {
		delete_transient( $this->lock_key );
	}
}

/**
 * Convenience accessor.
 * @return MonsterInsights_Export_Queue_Store
 */
function monsterinsights_export_queue_store() {
	return MonsterInsights_Export_Queue_Store::get_instance();
}
