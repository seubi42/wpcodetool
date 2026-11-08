<?php
/**
 * Backfill cache group allowlist.
 *
 * Defines which cache groups the backfill AJAX handlers accept when the Vue
 * reports read from or write to the internal cache.
 *
 * @since 9.11.0
 * @package MonsterInsights
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'monsterinsights_backfill_cache_allowed_groups' ) ) {
	/**
	 * Get the cache groups the backfill AJAX handlers accept.
	 *
	 * @since 9.11.0
	 *
	 * @return array List of allowed cache group slugs.
	 */
	function monsterinsights_backfill_cache_allowed_groups() {
		$groups = array(
			'overview',
			'custom_dashboard',
			'custom_dimensions',
			'traffic',
			'ecommerce',
			'publishers',
			'dimensions',
			'forms',
			'media',
		);

		/**
		 * Filter the cache groups the Vue reports may read from and write to.
		 *
		 * Lets Pro and addon reports register their own cache group without
		 * editing core.
		 *
		 * @since 9.11.0
		 *
		 * @param array $groups List of allowed cache group slugs.
		 */
		return apply_filters( 'monsterinsights_backfill_cache_allowed_groups', $groups );
	}
}

if ( ! function_exists( 'monsterinsights_report_view_slug_for_cache_group' ) ) {
	/**
	 * Maps a backfill cache group to the report-view telemetry slug it represents.
	 *
	 * Kept as its own map rather than recording the cache group name directly so
	 * a future cache-group rename doesn't silently rename or reset a telemetry
	 * bucket, and so 'custom_dashboard' (the cache group written by the Vue 3
	 * custom dashboard) aligns with 'custom-dashboard', the slug the Pro
	 * custom-dashboard AJAX handler already records, instead of splitting one
	 * feature's views into two buckets.
	 *
	 * @since 11.2.0
	 *
	 * @param string $cache_group Cache group slug.
	 * @return string|null The report-view slug, or null if the group isn't mapped.
	 */
	function monsterinsights_report_view_slug_for_cache_group( $cache_group ) {
		$map = array(
			'overview'          => 'overview',
			'custom_dashboard'  => 'custom-dashboard',
			'custom_dimensions' => 'custom_dimensions',
			'traffic'           => 'traffic',
			'ecommerce'         => 'ecommerce',
			'publishers'        => 'publishers',
			'dimensions'        => 'dimensions',
			'forms'             => 'forms',
			'media'             => 'media',
		);

		return isset( $map[ $cache_group ] ) ? $map[ $cache_group ] : null;
	}
}
