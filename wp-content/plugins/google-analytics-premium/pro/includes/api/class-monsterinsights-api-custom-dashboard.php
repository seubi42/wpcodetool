<?php
/**
 * Custom Dashboard API Client class for MonsterInsights.
 *
 * @package MonsterInsights
 */

class MonsterInsights_API_Custom_Dashboard extends MonsterInsights_API_Client {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// The API design doc specifies a different URL structure than the base client expects.
		// We'll set the base_url to the root and include the full path in the request.
		$this->base_url = apply_filters( 'monsterinsights_api_url_custom_dashboard', 'https://app.monsterinsights.com/' );
		parent::__construct();
	}

	/**
	 * Get report data from the Custom View API.
	 *
	 * @param string $start   The start date.
	 * @param string $end     The end date.
	 * @param array  $queries The queries to run.
	 * @return array|WP_Error The response or a WP_Error if there was an error.
	 */
	public function query( $start, $end, $queries ) {
		$body = array(
			'start'          => $start,
			'end'            => $end,
			'queries'        => $queries,
			'plugin_version' => MONSTERINSIGHTS_VERSION,
		);

		return $this->request( 'api/v3/reporting/query', $body, 'POST' );
	}

	/**
	 * Override the parent request method to handle the specific header requirements
	 * of the new reporting API.
	 */
	protected function request( $endpoint, $params = array(), $method = 'POST' ) {
		$url  = trailingslashit( $this->base_url ) . $endpoint;

		$args = array(
			'method'  => $method,
			'timeout'     => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Intentional timeout for API requests.
			'headers' => array(
				'X-Relay-License'  => $this->license,
				'X-Relay-Site-Key' => $this->key,
				'X-Relay-Token'    => $this->token,
				'X-Relay-Site-URL' => $this->site_url,
				'Content-Type'     => 'application/json',
			),
			'body'        => wp_json_encode($params),
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded_body  = json_decode( $response_body, true );

		// Check for successful response
		if ( $response_code >= 200 && $response_code < 300 ) {
			if ( isset( $decoded_body['success'] ) && $decoded_body['success'] ) {
				return isset( $decoded_body['data'] ) ? $decoded_body['data']['data'] : array();
			}
		}

		// Handle error responses
		if ( isset( $decoded_body['error'] ) && is_array( $decoded_body['error'] ) ) {
			// Check if the error structure matches what MonsterInsights_API_Error expects
			if ( isset( $decoded_body['error']['code'] ) && isset( $decoded_body['error']['message'] ) ) {
				return new MonsterInsights_API_Error( $decoded_body );
			}
		}

		// Fallback error handling
		$error_message = __( 'An unknown error occurred.', 'google-analytics-premium' );
		if ( isset( $decoded_body['message'] ) ) {
			$error_message = $decoded_body['message'];
		} elseif ( isset( $decoded_body['error'] ) && is_string( $decoded_body['error'] ) ) {
			$error_message = $decoded_body['error'];
		}

		return new WP_Error( 'api_error', $error_message, array( 'response_code' => $response_code, 'response_body' => $response_body ) );
	}
} 