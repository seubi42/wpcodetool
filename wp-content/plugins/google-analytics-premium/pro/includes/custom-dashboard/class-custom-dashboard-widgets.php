<?php

defined( 'ABSPATH' ) || exit;

class MonsterInsights_Custom_Dashboard_Widgets {

	/**
	 * Dynamically get all available widgets by scanning the widgets directory.
	 *
	 * @return array Array of widget metadata as a flat list.
	 */
	public static function get_widgets() {
		$widgets_dir = plugin_dir_path( __FILE__ ) . 'widgets/';
		$widgets = array();

		if ( ! is_dir( $widgets_dir ) ) {
			return $widgets;
		}

		$files = glob( $widgets_dir . 'class-*.php' );

		foreach ( $files as $file ) {
			$class_name = self::get_class_name_from_file( $file );

			if ( ! $class_name ) {
				continue;
			}

			// phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- File path from glob() is validated.
			require_once $file;

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$widget_instance = new $class_name();

			if ( ! $widget_instance instanceof MonsterInsights_Widget_Interface ) {
				continue;
			}

			$widget_metadata = $widget_instance->get_metadata();
			$widgets[ $widget_instance->get_type() ] = $widget_metadata;
		}

		return apply_filters( 'monsterinsights_custom_dashboard_widgets', $widgets );
	}

	/**
	 * Extract class name from widget file path following naming convention.
	 *
	 * @param string $file_path Path to widget file.
	 * @return string|false Class name or false if not valid.
	 */
	private static function get_class_name_from_file( $file_path ) {
		$filename = basename( $file_path, '.php' );
		
		if ( strpos( $filename, 'class-' ) !== 0 ) {
			return false;
		}

		$class_name = str_replace( 'class-', '', $filename );
		$class_name = str_replace( '-', '_', $class_name );
		$class_name = 'MonsterInsights_' . ucwords( $class_name, '_' );

		return $class_name;
	}

	/**
	 * Get a specific widget instance by type.
	 *
	 * @param string $widget_type The widget type.
	 * @return MonsterInsights_Widget_Interface|false Widget instance or false if not found.
	 */
	public static function get_widget( $widget_type ) {
		$widgets_dir = plugin_dir_path( __FILE__ ) . 'widgets/';
		$files = glob( $widgets_dir . 'class-*.php' );

		foreach ( $files as $file ) {
			$class_name = self::get_class_name_from_file( $file );

			if ( ! $class_name ) {
				continue;
			}

			// phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- File path from glob() is validated.
			require_once $file;

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$widget_instance = new $class_name();

			if ( ! $widget_instance instanceof MonsterInsights_Widget_Interface ) {
				continue;
			}

			if ( $widget_instance->get_type() === $widget_type ) {
				return $widget_instance;
			}
		}

		return false;
	}
} 