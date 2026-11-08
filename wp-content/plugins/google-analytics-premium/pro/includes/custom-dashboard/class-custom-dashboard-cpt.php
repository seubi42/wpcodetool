<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class MonsterInsights_Custom_Dashboard_CPT {

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Custom Views', 'post type general name', 'google-analytics-premium' ),
			'singular_name'      => _x( 'Custom View', 'post type singular name', 'google-analytics-premium' ),
			'add_new'            => _x( 'Add New', 'custom view', 'google-analytics-premium' ),
			'add_new_item'       => __( 'Add New Custom View', 'google-analytics-premium' ),
			'edit_item'          => __( 'Edit Custom View', 'google-analytics-premium' ),
			'new_item'           => __( 'New Custom View', 'google-analytics-premium' ),
			'all_items'          => __( 'All Custom Views', 'google-analytics-premium' ),
			'view_item'          => __( 'View Custom View', 'google-analytics-premium' ),
			'search_items'       => __( 'Search Custom Views', 'google-analytics-premium' ),
			'not_found'          => __( 'No custom views found', 'google-analytics-premium' ),
			'not_found_in_trash' => __( 'No custom views found in the Trash', 'google-analytics-premium' ),
			'parent_item_colon'  => '',
			'menu_name'          => __( 'Custom Views', 'google-analytics-premium' ),
		);

		$args = array(
			'labels'        => $labels,
			'description'   => __( 'Holds MonsterInsights Custom Views', 'google-analytics-premium' ),
			'public'        => false,
			'show_ui'       => false, // We will handle the UI entirely in our Vue app.
			'show_in_menu'  => false,
			'query_var'     => false,
			'rewrite'       => false,
			'capability_type' => 'post',
			'has_archive'   => false,
			'hierarchical'  => false,
			'supports'      => array( 'title', 'editor', 'author' ),
			'show_in_rest'  => false,
		);

		register_post_type( 'mi_custom_view', $args );
	}
} 