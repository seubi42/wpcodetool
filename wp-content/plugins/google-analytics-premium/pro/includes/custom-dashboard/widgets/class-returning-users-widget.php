<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Returning_Users_Widget extends MonsterInsights_Abstract_Widget {

	public function __construct() {
		$this->type = 'returning-users';
		$this->title = __( 'Returning Users', 'google-analytics-premium' );
		$this->category = __( 'Key Metrics', 'google-analytics-premium' );
		$this->icon = 'user-check';
		$this->support = array(
			'metrics' => array(
				'infobox' => array( 'totalUsers' ),
				'line-chart' => array( 'totalUsers', 'newUsers' ), // Need both to calculate returning
			),
			'dimensions' => array(
				'infobox' => array( 'newVsReturning' ),
				'line-chart' => array( 'date' ),
			),
			'types' => array( 'infobox', 'line-chart' ),
		);
		$this->defaults = array(
			'metrics' => array( 'totalUsers' ),
			'type' => 'infobox',
			'compare' => true,
		);
	}

	/**
	 * Build API query for this widget based on configuration.
	 *
	 * @param array $config Widget configuration from the frontend.
	 * @return array API query parameters.
	 */
	public function build_api_query( $config ) {
		$query = parent::build_api_query( $config );

		// Determine the display type
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		// Set metrics and dimensions based on display type
		if ( 'line-chart' === $display_type ) {
			// Line chart: request both totalUsers and newUsers metrics to calculate returning
			// GA4 API doesn't return 'returning' reliably when date + newVsReturning dimensions are combined
			$query['metrics'] = array( 'totalUsers', 'newUsers' );
			$query['dimensions'] = array( 'date' );
		} else {
			// Infobox: use newVsReturning dimension and filter for returning users
			$query['metrics'] = array( 'totalUsers' );
			$query['dimensions'] = array( 'newVsReturning' );
		}

		return $query;
	}

	/**
	 * Process the API data to calculate returning users.
	 *
	 * For infobox: filters rows with newVsReturning dimension for "returning" value
	 * For line-chart: calculates returning = totalUsers - newUsers for each date
	 *
	 * @param array $api_data The raw API response data.
	 * @param array $config   The widget configuration from the frontend.
	 * @return array The processed widget data with only returning users.
	 */
	public function process_data( $api_data, $config ) {
		if ( empty( $api_data['rows'] ) ) {
			return $api_data;
		}

		// Determine the display type to know data structure
		$display_type = ! empty( $config['displayType'] ) ? $config['displayType'] : $this->defaults['type'];

		$filtered_rows = array();

		if ( 'line-chart' === $display_type ) {
			// For line chart: we have totalUsers and newUsers metrics
			// Calculate returning = totalUsers - newUsers for each date
			foreach ( $api_data['rows'] as $row ) {
				if ( empty( $row['d'] ) || empty( $row['m'] ) ) {
					continue;
				}

				// Extract metrics: m[0] = totalUsers, m[1] = newUsers
				$total_users = isset( $row['m'][0] ) ? $row['m'][0] : array( 0 );
				$new_users = isset( $row['m'][1] ) ? $row['m'][1] : array( 0 );

				// Calculate returning users for each comparison period
				$returning_users = array();
				$metric_count = max( count( $total_users ), count( $new_users ) );

				for ( $i = 0; $i < $metric_count; $i++ ) {
					$total = isset( $total_users[ $i ] ) ? floatval( $total_users[ $i ] ) : 0;
					$new = isset( $new_users[ $i ] ) ? floatval( $new_users[ $i ] ) : 0;
					$returning = max( 0, $total - $new ); // Ensure non-negative
					$returning_users[] = strval( $returning );
				}

				// Create new row with calculated returning users
				$filtered_rows[] = array(
					'd' => $row['d'], // Keep the date dimension
					'm' => array( $returning_users ), // Single metric with returning users
				);
			}
		} else {
			// For infobox: dimension is [newVsReturning]
			// Filter rows where first dimension (index 0) is "returning"
			foreach ( $api_data['rows'] as $row ) {
				if ( ! empty( $row['d'] ) && is_array( $row['d'] ) && isset( $row['d'][0] ) ) {
					if ( $row['d'][0] === 'returning' ) {
						// Remove the dimension entirely for infobox
						$filtered_row = $row;
						$filtered_row['d'] = array();
						$filtered_rows[] = $filtered_row;
						break; // Only need one row for infobox
					}
				}
			}
		}

		// Return processed data with structure preserved
		$result = array(
			'rows' => $filtered_rows,
		);

		// Copy over dateRange and other meta properties
		if ( ! empty( $api_data['dateRange'] ) ) {
			$result['dateRange'] = $api_data['dateRange'];
		}

		return $result;
	}
}