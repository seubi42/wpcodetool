<?php
/**
 * Cache Helper Functions
 *
 * Global helper functions for the MonsterInsights cache system.
 * Provides a simple, clean API for caching throughout the plugin.
 *
 * @since 9.11.0
 * @package MonsterInsights
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load cache cleanup class
require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/cache/class-cache-cleanup.php';

/**
 * Get the cache wrapper instance.
 *
 * Returns a singleton instance of the cache wrapper.
 *
 * @since 9.11.0
 * @return MonsterInsights_Cache_Wrapper Cache wrapper instance.
 */
function monsterinsights_get_cache() {
	static $instance = null;

	if ( $instance === null ) {
		// Load cache wrapper if not already loaded
		if ( ! class_exists( 'MonsterInsights_Cache_Wrapper' ) ) {
			require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/cache/class-cache-wrapper.php';
		}

		$instance = new MonsterInsights_Cache_Wrapper();
	}

	return $instance;
}

/**
 * Get cached data.
 *
 * Retrieves data from cache. Tries object cache (Redis/Memcached) first,
 * then falls back to custom cache table.
 *
 * @since 9.11.0
 * @param string $key   Cache key.
 * @param string $group Cache group (default 'reports').
 * @return mixed|false Cached data or false if not found.
 */
function monsterinsights_cache_get( $key, $group = 'reports' ) {
	return monsterinsights_get_cache()->get( $key, $group );
}

/**
 * Set cached data.
 *
 * Stores data in cache. Uses object cache (Redis/Memcached) if available,
 * and always stores in custom cache table for persistence.
 *
 * @since 9.11.0
 * @param string $key        Cache key.
 * @param mixed  $value      Value to cache.
 * @param string $group      Cache group (default 'reports').
 * @param int    $expiration Expiration in seconds (default 3600).
 * @return bool True on success, false on failure.
 */
function monsterinsights_cache_set( $key, $value, $group = 'reports', $expiration = 3600 ) {
	return monsterinsights_get_cache()->set( $key, $value, $group, $expiration );
}

/**
 * Delete cached data.
 *
 * Removes data from both object cache and cache table.
 *
 * @since 9.11.0
 * @param string $key   Cache key.
 * @param string $group Cache group (default 'reports').
 * @return bool True on success, false on failure.
 */
function monsterinsights_cache_delete( $key, $group = 'reports' ) {
	return monsterinsights_get_cache()->delete( $key, $group );
}

/**
 * Flush all cached data in a group.
 *
 * @since 9.11.0
 * @param string $group Cache group (default 'reports').
 * @return bool True on success, false on failure.
 */
function monsterinsights_cache_flush_group( $group = 'reports' ) {
	return monsterinsights_get_cache()->flush_group( $group );
}

/**
 * Flush all cached data.
 *
 * @since 9.11.0
 * @return bool True on success, false on failure.
 */
function monsterinsights_cache_flush_all() {
	return monsterinsights_get_cache()->flush_all();
}

/**
 * Check if a cache key exists.
 *
 * @since 9.11.0
 * @param string $key   Cache key.
 * @param string $group Cache group (default 'reports').
 * @return bool True if exists, false otherwise.
 */
function monsterinsights_cache_exists( $key, $group = 'reports' ) {
	return monsterinsights_get_cache()->exists( $key, $group );
}

/**
 * Get cache statistics.
 *
 * Returns information about cache usage, including:
 * - Total entries
 * - Valid vs expired entries
 * - Total size
 * - Breakdown by group
 * - Whether object cache is available
 *
 * @since 9.11.0
 * @return array Cache statistics.
 */
function monsterinsights_cache_get_stats() {
	return monsterinsights_get_cache()->get_stats();
}

/**
 * Clean up expired cache entries.
 *
 * Removes expired entries from the cache table.
 * Object cache handles its own expiration automatically.
 *
 * @since 9.11.0
 * @return int Number of entries deleted.
 */
function monsterinsights_cache_cleanup() {
	return monsterinsights_get_cache()->cleanup_expired();
}

/**
 * Get the remaining time to live for a cache entry.
 *
 * @since 9.11.0
 * @param string $key   Cache key.
 * @param string $group Cache group (default 'reports').
 * @return int|false Time to live in seconds, or false if not found/expired.
 */
function monsterinsights_cache_get_ttl( $key, $group = 'reports' ) {
	return monsterinsights_get_cache()->get_ttl( $key, $group );
}

/**
 * Check if object cache (Redis/Memcached) is available.
 *
 * @since 9.11.0
 * @return bool True if object cache available, false otherwise.
 */
function monsterinsights_has_object_cache() {
	return monsterinsights_get_cache()->has_object_cache();
}

/**
 * Schedule daily cache cleanup.
 *
 * Schedules automatic cleanup of expired cache entries.
 * Call this on plugin activation.
 *
 * @since 9.11.0
 * @return bool True if scheduled, false if already scheduled.
 */
function monsterinsights_schedule_cache_cleanup() {
	return MonsterInsights_Cache_Cleanup::schedule_cleanup();
}

/**
 * Unschedule cache cleanup.
 *
 * Removes the scheduled cleanup event.
 * Call this on plugin deactivation.
 *
 * @since 9.11.0
 * @return bool True if unscheduled, false otherwise.
 */
function monsterinsights_unschedule_cache_cleanup() {
	return MonsterInsights_Cache_Cleanup::unschedule_cleanup();
}

/**
 * Manually trigger cache cleanup.
 *
 * Deletes all expired cache entries immediately.
 * Useful for testing or manual cleanup.
 *
 * @since 9.11.0
 * @return int|false Number of entries deleted, or false on error.
 */
function monsterinsights_cleanup_expired_cache() {
	return MonsterInsights_Cache_Cleanup::cleanup_expired_entries();
}

/**
 * Get cache cleanup statistics.
 *
 * Returns information about cache health and expired entries.
 *
 * @since 9.11.0
 * @return array Cache statistics including total, valid, and expired entry counts.
 */
function monsterinsights_get_cache_cleanup_stats() {
	return MonsterInsights_Cache_Cleanup::get_cleanup_stats();
}

/**
 * Flush every cache that describes the currently connected GA4 property.
 *
 * Call whenever the connected property can have changed -- connect, reconnect
 * or disconnect.
 *
 * `MonsterInsights_Reporting::delete_aggregate_data()` is NOT a substitute. It
 * loops registered reports into `MonsterInsights_Report::delete_cache()`, which
 * removes exactly one key per report -- the DEFAULT date range, with no
 * comparison range and no `get_cache_key_suffix()` variant -- so custom ranges
 * and comparisons survive it. And it never touches the backfill cache groups at
 * all, which is where the Vue reports actually read from: those keys are a hash
 * of {start, end, filters} with no property id and no viewid check on read, so
 * left behind they still describe the previous property and are served verbatim
 * until the TTL lapses (MI-247).
 *
 * Lives here, rather than on MonsterInsights_API_Auth, because that object is
 * only instantiated for admin/cron requests -- the onboarding REST route needs
 * this too. includes/cache/functions.php is loaded unconditionally.
 *
 * @since 11.2.0
 *
 * @param bool $network Whether the network-level auth is the one that changed.
 * @return void
 */
function monsterinsights_flush_property_scoped_caches( $network = false ) {
	// The allowlist lives in includes/cache/allowed-groups.php, required by
	// includes/admin/ajax.php -- which is admin/cron only. Load it on demand so
	// this works from REST as well. It is filterable, so groups registered by
	// addons come along too.
	if ( ! function_exists( 'monsterinsights_backfill_cache_allowed_groups' ) ) {
		require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/cache/allowed-groups.php';
	}

	// 'reports' is deliberately NOT in that allowlist (it is the legacy
	// per-report group, not one the Vue reports may write to), so name it here.
	$groups = array_merge( array( 'reports' ), (array) monsterinsights_backfill_cache_allowed_groups() );

	foreach ( $groups as $group ) {
		monsterinsights_cache_flush_group( $group );
	}

	// The cached Relay Bearer token is encrypted with the relay token from the
	// credentials being replaced, so it cannot outlive them.
	if ( ! class_exists( 'MonsterInsights_API_Token' ) ) {
		require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/api/class-monsterinsights-api-token.php';
	}
	MonsterInsights_API_Token::invalidate( $network );

	// Clear the Vue client's localStorage cache registry on the next admin load.
	monsterinsights_flag_flush_cache_registry();
}
