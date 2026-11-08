<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class MonsterInsights_Custom_Dashboard_Ajax {

	/**
	 * Constructor - Initialize AJAX hooks for Custom View endpoints.
	 *
	 * Registers all AJAX handlers for both authenticated users. The Custom View
	 * provides endpoints for widget metadata discovery, data fetching, and view management.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Register AJAX hooks
		add_action( 'wp_ajax_monsterinsights_get_custom_view_metadata', array( $this, 'get_custom_view_metadata' ) );
		add_action( 'wp_ajax_monsterinsights_get_custom_dashboard_data', array( $this, 'get_custom_dashboard_data' ) );
		add_action( 'wp_ajax_monsterinsights_save_custom_view', array( $this, 'save_custom_view' ) );
		add_action( 'wp_ajax_monsterinsights_get_custom_views', array( $this, 'get_custom_views' ) );
		add_action( 'wp_ajax_monsterinsights_delete_custom_view', array( $this, 'delete_custom_view' ) );
		add_action( 'wp_ajax_monsterinsights_update_views_order', array( $this, 'update_views_order' ) );
	}

	/**
	 * AJAX handler for fetching custom view metadata.
	 * 
	 * Returns a flat list of all available widgets with their configuration metadata,
	 * including supported metrics, dimensions, visualization types, and default values.
	 * Used by the frontend to populate the widget selection panel.
	 *
	 * @since 1.0.0
	 * 
	 * @global string $_POST['nonce'] Security nonce for verification
	 * 
	 * @return void Outputs JSON response via wp_send_json_success() or wp_send_json_error()
	 * 
	 * @example Success response:
	 * {
	 *   "success": true,
	 *   "data": {
	 *     "sessions": {
	 *       "type": "sessions",
	 *       "title": "Sessions",
	 *       "category": "Key Metrics",
	 *       "support": {
	 *         "metrics": ["sessions"],
	 *         "dimensions": [],
	 *         "types": ["infobox", "line-chart"]
	 *       },
	 *       "defaults": {
	 *         "metrics": ["sessions"],
	 *         "type": "infobox"
	 *       },
	 *       "is_local": false
	 *     }
	 *   }
	 * }
	 */
	public function get_custom_view_metadata() {
		check_ajax_referer( 'mi-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this data.', 'google-analytics-premium' ) ) );
		}

		$widgets = MonsterInsights_Custom_Dashboard_Widgets::get_widgets();

		wp_send_json_success( $widgets );
	}

	/**
	 * AJAX handler for fetching custom dashboard data from API.
	 * 
	 * Processes widget requests using a 3-step efficient architecture:
	 * 1. Filter remote widgets and compile API queries (lazy-load widget classes)
	 * 2. Execute single batch API request for all remote widgets
	 * 3. Process all widgets - combine remote (with API data) and local widgets
	 *
	 * @since 1.0.0
	 * 
	 * @global array $_POST['widgets']    JSON-encoded array of widget configurations
	 * @global array $_POST['date_range'] JSON-encoded date range with 'start' and 'end' keys
	 * @global string $_POST['nonce']     Security nonce for verification
	 * 
	 * @return void Outputs JSON response via wp_send_json_success() or wp_send_json_error()
	 * 
	 * @example POST data structure:
	 * {
	 *   "widgets": [
	 *     {
	 *       "id": "sessions-1",
	 *       "type": "sessions",
	 *       "metrics": ["sessions"],
	 *       "type": "infobox"
	 *     }
	 *   ],
	 *   "date_range": {
	 *     "start": "2025-06-24",
	 *     "end": "2025-07-24"
	 *   }
	 * }
	 * 
	 * @example Success response:
	 * {
	 *   "success": true,
	 *   "data": {
	 *     "date_range": {"start": "2025-06-24", "end": "2025-07-24"},
	 *     "widgets": {
	 *       "sessions-1": {
	 *         "rows": [...],
	 *         "totals": {...}
	 *       }
	 *     }
	 *   }
	 * }
	 */
	public function get_custom_dashboard_data() {
		check_ajax_referer( 'mi-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this data.', 'google-analytics-premium' ) ) );
		}

		$widgets = ! empty( $_POST['widgets'] ) ? json_decode( stripslashes( $_POST['widgets'] ), true ) : array();
		$date_range = ! empty( $_POST['date_range'] ) ? json_decode( stripslashes( $_POST['date_range'] ), true ) : array();

		if ( empty( $widgets ) ) {
			wp_send_json_error( array( 'message' => __( 'No widgets specified.', 'google-analytics-premium' ) ) );
		}

		// Custom Dashboard has its own AJAX endpoint rather than going through the
		// report handlers in routes.php, so it needs its own view-count entry --
		// treated as a single opaque report slug, same as the named reports. Recorded
		// only after the widgets check so a malformed request isn't counted as a view.
		MonsterInsights_Report_Views::maybe_record( 'custom-dashboard' );

		// Get valid date range
		if ( empty( $date_range['start'] ) || empty( $date_range['end'] ) ) {
			$date_range = $this->get_default_date_range( 30 );
		} else {
			// Validate end date is not in the future
			$end_date = strtotime( $date_range['end'] );
			$today = strtotime( 'today' );
			if ( $end_date > $today ) {
				$date_range['end'] = gmdate( 'Y-m-d', $today );
			}
		}

		// Step 1: Filter remote widgets and compile API queries
		$api_queries = array();
		
		foreach ( $widgets as $widget_config ) {
			if ( empty( $widget_config['type'] ) ) {
				continue;
			}

			// Load widget class only when needed
			$widget_instance = $this->load_widget_instance( $widget_config['type'] );
			if ( ! $widget_instance || $widget_instance->is_local() ) {
				continue; // Skip local widgets for now
			}

			$widget_id = $widget_config['id'] ?? $widget_config['type'];

			// Build API query using widget's own method
			$query = $widget_instance->build_api_query( $widget_config );
			if ( ! empty( $query['metrics'] ) ) { // Must have at least metrics to be valid
				$query['id'] = $widget_id; // Add ID for API
				$api_queries[] = $query;
			}
		}

		// Step 2: Execute single API request for all remote widgets
		$api_response = array();
		if ( ! empty( $api_queries ) ) {
			$api = new MonsterInsights_API_Custom_Dashboard();
			$api_response = $api->query( $date_range['start'], $date_range['end'], $api_queries );

			if ( is_wp_error( $api_response ) ) {
				wp_send_json_error( array( 'message' => $api_response->get_error_message() ) );
			}

			if ( $api_response instanceof MonsterInsights_API_Error ) {
				wp_send_json_error( array( 'message' => $api_response->get_error_message() ) );
			}
		}

		// Step 3: Process all widgets - combine remote (with API data) and local widgets
		$widget_data = array();
		
		foreach ( $widgets as $widget_config ) {
			if ( empty( $widget_config['type'] ) ) {
				continue;
			}

			$widget_id = $widget_config['id'] ?? $widget_config['type'];
			$widget_instance = $this->load_widget_instance( $widget_config['type'] );

			if ( ! $widget_instance ) {
				continue;
			}

			// Add date range to widget config so widgets can filter by date if needed
			$widget_config['date_range'] = $date_range;

			if ( $widget_instance->is_local() ) {
				// Process local widget
				$widget_data[ $widget_id ] = $widget_instance->process_data( array(), $widget_config );
			} else {
				// Process remote widget with API data
				$api_data = isset( $api_response[ $widget_id ] ) ? $api_response[ $widget_id ] : array();
				$widget_data[ $widget_id ] = $widget_instance->process_data( $api_data, $widget_config );
			}
		}

		wp_send_json_success( array(
			'date_range' => $date_range,
			'widgets' => $widget_data,
		) );
	}

	/**
	 * Build a default date range when none is provided.
	 *
	 * @param int $days Number of days to include (inclusive of today).
	 *
	 * @return array {
	 *     @type string $start Start date (Y-m-d).
	 *     @type string $end   End date (Y-m-d).
	 * }
	 */
	private function get_default_date_range( $days = 30 ) {
		$days       = max( 1, (int) $days );
		$end_ts     = strtotime( 'today' );
		$start_ts   = strtotime( '-' . ( $days - 1 ) . ' days', $end_ts );

		return array(
			'start' => gmdate( 'Y-m-d', $start_ts ),
			'end'   => gmdate( 'Y-m-d', $end_ts ),
		);
	}

	/**
	 * Load widget instance by type, with efficient class loading.
	 * 
	 * Dynamically loads widget classes only when needed (lazy loading) to improve performance.
	 * Converts widget type to class name, loads the PHP file, instantiates the class,
	 * and validates it implements the required interface.
	 *
	 * @since 1.0.0
	 * 
	 * @param string $widget_type The widget type identifier (e.g., 'sessions', 'total-users')
	 * 
	 * @return MonsterInsights_Widget_Interface|false Widget instance if successful, false on failure
	 * 
	 * @example Usage:
	 * $widget = $this->load_widget_instance('sessions');
	 * if ($widget) {
	 *     $query = $widget->build_api_query($config);
	 * }
	 * 
	 * @example Class name conversion:
	 * 'sessions' -> 'MonsterInsights_Sessions_Widget'
	 * 'total-users' -> 'MonsterInsights_Total_Users_Widget'
	 * 'utm-source' -> 'MonsterInsights_Utm_Source_Widget'
	 */
	private function load_widget_instance( $widget_type ) {
		$widgets_dir = plugin_dir_path( __FILE__ ) . 'widgets/';
		
		// Convert widget type to class name and file path
		$class_name = 'MonsterInsights_' . str_replace( '-', '_', ucwords( $widget_type, '-' ) ) . '_Widget';
		$file_path = $widgets_dir . 'class-' . $widget_type . '-widget.php';
		
		// Check if file exists
		if ( ! file_exists( $file_path ) ) {
			return false;
		}
		
		// Load class file only once
		// phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- File path is validated with file_exists() above.
		require_once $file_path;
		
		// Check if class exists and create instance
		if ( ! class_exists( $class_name ) ) {
			return false;
		}
		
		$widget_instance = new $class_name();
		
		// Verify it implements the interface
		if ( ! $widget_instance instanceof MonsterInsights_Widget_Interface ) {
			return false;
		}
		
		return $widget_instance;
	}


	/**
	 * AJAX handler for saving a custom view.
	 * 
	 * Creates or updates a custom dashboard view, storing the widget layout configuration
	 * as a WordPress custom post type. Handles both new views (insert) and existing views (update).
	 * The layout is stored as JSON in the post content field.
	 *
	 * @since 1.0.0
	 * 
	 * @global int    $_POST['view_id'] Optional. Post ID for updating existing view, 0 for new view
	 * @global string $_POST['title']   View title, defaults to 'My Custom View' if not provided
	 * @global string $_POST['layout']  JSON-encoded widget layout configuration
	 * @global string $_POST['nonce']   Security nonce for verification
	 * 
	 * @return void Outputs JSON response via wp_send_json_success() or wp_send_json_error()
	 * 
	 * @example POST data for new view:
	 * {
	 *   "title": "My Sales Dashboard",
	 *   "layout": "[{\"id\":\"sessions-1\",\"type\":\"sessions\",\"position\":{\"x\":0,\"y\":0}}]"
	 * }
	 * 
	 * @example POST data for updating existing view:
	 * {
	 *   "view_id": 123,
	 *   "title": "Updated Dashboard",
	 *   "layout": "[{\"id\":\"revenue-1\",\"type\":\"revenue\"}]"
	 * }
	 * 
	 * @example Success response:
	 * {
	 *   "success": true,
	 *   "data": {
	 *     "view_id": 123,
	 *     "message": "View saved successfully."
	 *   }
	 * }
	 */
	public function save_custom_view() {
		check_ajax_referer( 'mi-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'monsterinsights_save_settings' ) ) { // Note: using a more specific capability
			wp_send_json_error( array( 'message' => __( 'You do not have permission to save this data.', 'google-analytics-premium' ) ) );
		}

		$view_id    = isset( $_POST['view_id'] ) ? absint( $_POST['view_id'] ) : 0;
		$title      = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : __( 'My Custom View', 'google-analytics-premium' );
		$layout_raw = isset( $_POST['layout'] ) ? wp_unslash( $_POST['layout'] ) : '[]';
		$menu_order = isset( $_POST['menu_order'] ) ? absint( $_POST['menu_order'] ) : null;

		// Decode JSON and validate - wp_kses_post would corrupt JSON by escaping quotes
		$layout_decoded = json_decode( $layout_raw, true );
		if ( $layout_decoded === null && json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( array( 'message' => __( 'Invalid layout format.', 'google-analytics-premium' ) ) );
		}

		// Sanitize the decoded layout data recursively
		$layout_decoded = $this->sanitize_layout_data( $layout_decoded );

		// Re-encode for storage
		$layout = wp_json_encode( $layout_decoded );

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $layout,
			'post_status'  => 'publish',
			'post_type'    => 'mi_custom_view',
		);

		if ( $view_id > 0 ) {
			if ( 'mi_custom_view' !== get_post_type( $view_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid custom view.', 'google-analytics-premium' ) ) );
			}
			// Verify the user is the author of this view.
			if ( get_post_field( 'post_author', $view_id ) != get_current_user_id() ) {
				wp_send_json_error( array( 'message' => __( 'Invalid custom view.', 'google-analytics-premium' ) ) );
			}
			$post_data['ID'] = $view_id;
		} else {
			// New view: default to menu_order = 0 (appears first)
			$post_data['menu_order'] = 0;
		}

		// Allow explicit menu_order override (for reordering)
		if ( $menu_order !== null ) {
			$post_data['menu_order'] = $menu_order;
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		wp_send_json_success( array( 'view_id' => $post_id, 'message' => __( 'View saved successfully.', 'google-analytics-premium' ) ) );
	}

	/**
	 * AJAX handler for retrieving a list of saved views.
	 * 
	 * Fetches all custom dashboard views belonging to the current user.
	 * Returns view metadata including ID, title, and parsed layout configuration.
	 * Views are stored as 'mi_custom_view' custom post type with JSON layout in post_content.
	 *
	 * @since 1.0.0
	 * 
	 * @global string $_POST['nonce'] Security nonce for verification
	 * 
	 * @return void Outputs JSON response via wp_send_json_success() or wp_send_json_error()
	 * 
	 * @example Success response:
	 * {
	 *   "success": true,
	 *   "data": [
	 *     {
	 *       "id": 123,
	 *       "title": "My Sales Dashboard",
	 *       "layout": [
	 *         {
	 *           "id": "sessions-1",
	 *           "type": "sessions",
	 *           "position": {"x": 0, "y": 0}
	 *         }
	 *       ]
	 *     },
	 *     {
	 *       "id": 124,
	 *       "title": "Traffic Overview",
	 *       "layout": [...]
	 *     }
	 *   ]
	 * }
	 */
	public function get_custom_views() {
		check_ajax_referer( 'mi-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'monsterinsights_view_dashboard' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this data.', 'google-analytics-premium' ) ) );
		}

		$args = array(
			'post_type'      => 'mi_custom_view',
			'posts_per_page' => -1,
			'author'         => get_current_user_id(),
			'post_status'    => 'publish',
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		);

		$views_query = get_posts( $args );
		$views       = array();

		if ( ! empty( $views_query ) ) {
			foreach ( $views_query as $view_post ) {
				$layout = json_decode( $view_post->post_content, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					// Skip this post if the layout is not valid JSON
					continue;
				}

				$views[] = array(
					'id'         => $view_post->ID,
					'title'      => $view_post->post_title,
					'layout'     => $layout,
					'menu_order' => (int) $view_post->menu_order,
				);
			}
		}

		wp_send_json_success( $views );
	}

	/**
	 * AJAX handler for deleting a custom view.
	 * 
	 * Deletes a custom dashboard view by moving it to trash (soft delete).
	 * Includes security checks to ensure the user owns the view and has proper permissions.
	 * Only the author of the view can delete it.
	 *
	 * @since 1.0.0
	 * 
	 * @global int    $_POST['view_id'] Required. Post ID of the view to delete
	 * @global string $_POST['nonce']   Security nonce for verification
	 * 
	 * @return void Outputs JSON response via wp_send_json_success() or wp_send_json_error()
	 * 
	 * @example POST data:
	 * {
	 *   "view_id": 123
	 * }
	 * 
	 * @example Success response:
	 * {
	 *   "success": true,
	 *   "data": {
	 *     "message": "View deleted successfully."
	 *   }
	 * }
	 * 
	 * @example Error response (permission denied):
	 * {
	 *   "success": false,
	 *   "data": {
	 *     "message": "You do not have permission to delete this view."
	 *   }
	 * }
	 */
	public function delete_custom_view() {
		check_ajax_referer( 'mi-admin-nonce', 'nonce' );

		$view_id = isset( $_POST['view_id'] ) ? absint( $_POST['view_id'] ) : 0;

		if ( empty( $view_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No view ID provided.', 'google-analytics-premium' ) ) );
		}

		if ( 'mi_custom_view' !== get_post_type( $view_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid custom view.', 'google-analytics-premium' ) ) );
		}

		// Verify the user has permission and is the author of the post.
		if ( ! current_user_can( 'monsterinsights_save_settings' ) || get_post_field( 'post_author', $view_id ) != get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to delete this view.', 'google-analytics-premium' ) ) );
		}

		$result = wp_delete_post( $view_id, false ); // Set to false to move to trash instead of permanent deletion.

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Error deleting view.', 'google-analytics-premium' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'View deleted successfully.', 'google-analytics-premium' ) ) );
	}

	/**
	 * AJAX handler for updating view order (drag-and-drop reordering).
	 *
	 * @since 1.0.0
	 */
	public function update_views_order() {
		check_ajax_referer( 'mi-admin-nonce', 'nonce' );

		if ( ! current_user_can( 'monsterinsights_save_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to reorder views.', 'google-analytics-premium' ) ) );
		}

		$view_orders = isset( $_POST['view_orders'] ) ? json_decode( stripslashes( $_POST['view_orders'] ), true ) : array();

		if ( empty( $view_orders ) || ! is_array( $view_orders ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid view orders provided.', 'google-analytics-premium' ) ) );
		}

		$updated_count = 0;
		foreach ( $view_orders as $view_order ) {
			if ( ! isset( $view_order['id'] ) || ! isset( $view_order['order'] ) ) {
				continue;
			}

			$view_id = absint( $view_order['id'] );
			$order   = absint( $view_order['order'] );

			if ( 'mi_custom_view' !== get_post_type( $view_id ) ) {
				continue;
			}

			// Verify the user is the author of this view
			if ( get_post_field( 'post_author', $view_id ) != get_current_user_id() ) {
				continue;
			}

			// Update the menu_order
			$result = wp_update_post( array(
				'ID'         => $view_id,
				'menu_order' => $order,
			) );

			if ( ! is_wp_error( $result ) ) {
				$updated_count++;
			}
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: Number of views reordered */
				__( '%d view(s) reordered successfully.', 'google-analytics-premium' ),
				$updated_count
			),
			'updated_count' => $updated_count,
		) );
	}

	/**
	 * Recursively sanitize layout data.
	 *
	 * Sanitizes the decoded JSON layout array by applying appropriate
	 * sanitization to each value based on its type.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data The data to sanitize (can be array, string, int, etc.).
	 * @return mixed The sanitized data.
	 */
	private function sanitize_layout_data( $data ) {
		if ( is_array( $data ) ) {
			$sanitized = array();
			foreach ( $data as $key => $value ) {
				// Sanitize the key if it's a string
				$clean_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;
				// Recursively sanitize the value
				$sanitized[ $clean_key ] = $this->sanitize_layout_data( $value );
			}
			return $sanitized;
		}

		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}

		if ( is_int( $data ) || is_float( $data ) ) {
			return $data;
		}

		if ( is_bool( $data ) ) {
			return $data;
		}

		// For null or other types, return as-is
		return $data;
	}
} 