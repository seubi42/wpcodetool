<?php
/**
 * Tracks local report-view counts for the Customer360 usage-tracking payload.
 *
 * @package     MonsterInsights
 * @subpackage  Admin
 * @since       11.2.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records a lightweight local log of report views and aggregates it for check-in.
 *
 * Static-only rather than a singleton: unlike other admin helper classes here,
 * this one holds no instance state and registers no hooks of its own -- callers
 * invoke record()/get_aggregated_counts() directly, so an instance would add
 * nothing.
 *
 * Storage is a day-bucketed count -- { 'YYYY-MM-DD' => { report => count } } --
 * rather than a raw {report, ts} event log. A per-request log needs an entry cap
 * to bound option size, and any fixed cap is reachable well inside the 90-day
 * window on an active site, after which count_90d silently means "count over
 * however many days fit". Bucketing by day is bounded by construction (registered
 * report slugs x 90 days) and needs no cap, at the cost of per-day rather than
 * per-request granularity -- acceptable since only aggregate counts are ever read.
 * Worst case is ~36 report slugs x 90 days = 3,240 entries, ~87 KB serialized,
 * read-modify-written in full on every recorded view; real sites see far less.
 *
 * The option is read-modify-written without a lock, so two concurrent requests can
 * each increment and the later write wins. Accepted: the cost is one undercounted
 * view in telemetry, which is not worth serialising every report request for.
 *
 * @since 11.2.0
 * @package MonsterInsights
 * @subpackage Admin
 */
class MonsterInsights_Report_Views {

	/**
	 * Option name storing the { date => { report => count } } buckets.
	 *
	 * @since 11.2.0
	 */
	const OPTION = 'monsterinsights_report_views';

	/**
	 * Prune day buckets older than this on every write.
	 *
	 * @since 11.2.0
	 */
	const MAX_AGE = 90 * DAY_IN_SECONDS;

	/**
	 * Bound on distinct report entries sent in the check-in payload.
	 *
	 * @since 11.2.0
	 */
	const MAX_PAYLOAD_REPORTS = 50;

	/**
	 * De-dupe window: a single dashboard load fires several data requests, so only
	 * the first counts as a view per report.
	 *
	 * @since 11.2.0
	 */
	const DEDUPE_WINDOW = 30 * MINUTE_IN_SECONDS;

	/**
	 * Records a view for the given report in today's bucket.
	 *
	 * @since 11.2.0
	 * @access public
	 *
	 * @param string $report The report slug.
	 * @return void
	 */
	public static function maybe_record( $report ) {
		// Gated on the same condition as transmission rather than on transmission
		// alone: a Lite site that never opted in would otherwise build up 90 days of
		// history locally, and its first check-in on opt-in ships the whole log.
		if ( ! MonsterInsights_Usage_Tracking::tracking_allowed() ) {
			return;
		}

		// Today's callers pass either a literal or a registered report's own $name, so
		// nothing request-derived reaches this. The shape check is the single
		// enforcement point that keeps that true if a future caller is less careful --
		// the log feeds an outbound payload, so arbitrary strings must not enter it.
		if ( ! is_string( $report ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $report ) ) {
			return;
		}

		// A transient rather than a field in the option itself: it self-expires, so
		// there's nothing to prune, and it never touches the option's read-modify-write
		// (which is where two concurrent requests can already race -- see class docblock).
		$dedupe_key = 'monsterinsights_report_view_' . $report . '_' . get_current_user_id();
		if ( false !== get_transient( $dedupe_key ) ) {
			return;
		}
		set_transient( $dedupe_key, 1, self::DEDUPE_WINDOW );

		$buckets = get_option( self::OPTION, array() );
		if ( ! is_array( $buckets ) ) {
			$buckets = array();
		}

		$today = gmdate( 'Y-m-d' );

		if ( ! isset( $buckets[ $today ] ) || ! is_array( $buckets[ $today ] ) ) {
			$buckets[ $today ] = array();
		}

		if ( empty( $buckets[ $today ][ $report ] ) ) {
			$buckets[ $today ][ $report ] = 0;
		}
		$buckets[ $today ][ $report ]++;

		update_option( self::OPTION, self::prune( $buckets ), false );
	}

	/**
	 * Prunes day buckets older than 90 days.
	 *
	 * @since 11.2.0
	 * @access private
	 *
	 * @param array $buckets The { date => { report => count } } buckets.
	 * @return array Pruned buckets.
	 */
	private static function prune( $buckets ) {
		$cutoff_date = gmdate( 'Y-m-d', time() - self::MAX_AGE );

		return array_filter(
			$buckets,
			function ( $date ) use ( $cutoff_date ) {
				return $date > $cutoff_date;
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Aggregates the day buckets into 30d/90d counts per report for the check-in payload.
	 *
	 * @since 11.2.0
	 * @access public
	 *
	 * @return array List of { report, count_30d, count_90d }, empty when nothing recorded.
	 */
	public static function get_aggregated_counts() {
		$buckets = get_option( self::OPTION, array() );
		if ( ! is_array( $buckets ) || empty( $buckets ) ) {
			return array();
		}

		// record() prunes on write, so a site that stopped viewing reports would keep
		// its expired buckets forever. This runs weekly on check-in, which is the only
		// other moment the log is read -- prune here too and persist if anything went.
		$pruned = self::prune( $buckets );
		if ( count( $pruned ) !== count( $buckets ) ) {
			update_option( self::OPTION, $pruned, false );
			$buckets = $pruned;
		}

		$cutoff_30 = gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) );
		$cutoff_90 = gmdate( 'Y-m-d', time() - ( 90 * DAY_IN_SECONDS ) );

		$counts = array();
		foreach ( $buckets as $date => $reports ) {
			if ( $date <= $cutoff_90 || ! is_array( $reports ) ) {
				continue;
			}

			foreach ( $reports as $report => $count ) {
				if ( ! isset( $counts[ $report ] ) ) {
					$counts[ $report ] = array(
						'count_30d' => 0,
						'count_90d' => 0,
					);
				}

				$count = is_numeric( $count ) ? (int) $count : 0;

				$counts[ $report ]['count_90d'] += $count;
				if ( $date > $cutoff_30 ) {
					$counts[ $report ]['count_30d'] += $count;
				}
			}
		}

		$aggregated = array();
		foreach ( $counts as $report => $count ) {
			$aggregated[] = array(
				'report'    => $report,
				'count_30d' => $count['count_30d'],
				'count_90d' => $count['count_90d'],
			);
		}

		// $counts is keyed in first-seen order, so slicing it directly would keep the
		// oldest report slugs and drop the ones the customer actually uses. Sort by
		// volume first so the cap sheds the least interesting rows.
		usort( $aggregated, function ( $a, $b ) {
			if ( $a['count_90d'] === $b['count_90d'] ) {
				return $b['count_30d'] - $a['count_30d'];
			}

			return $b['count_90d'] - $a['count_90d'];
		} );

		return array_slice( $aggregated, 0, self::MAX_PAYLOAD_REPORTS );
	}
}
