<?php
/**
 * Email Summaries body template (plain text).
 *
 * Same sections as body-summaries.php for text/plain emails.
 *
 * @since 7.10.5
 * @since 8.19.0 Aligned with HTML template sections and variables.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Defaults (same as HTML body)
$site_url             = isset( $site_url ) ? $site_url : get_site_url();
$start_date           = isset( $startDate ) ? $startDate : '';
$end_date             = isset( $endDate ) ? $endDate : '';
$analytics_stats      = isset( $analytics_stats ) ? $analytics_stats : array();
$store_performance    = isset( $store_performance ) ? $store_performance : array();
$form_performance     = isset( $form_performance ) ? $form_performance : array();
$organic_performance  = isset( $organic_performance ) ? $organic_performance : array();
$top_keywords        = isset( $top_keywords ) ? $top_keywords : array();
$most_popular_categories = isset( $most_popular_categories ) ? $most_popular_categories : array();
$most_popular_post_types = isset( $most_popular_post_types ) ? $most_popular_post_types : array();
$source_medium_traffic   = isset( $source_medium_traffic ) ? $source_medium_traffic : array();
$device_breakdown     = isset( $device_breakdown ) ? $device_breakdown : array();
$top_pages            = isset( $top_pages ) ? $top_pages : array();
$custom_dimensions    = isset( $custom_dimensions ) ? $custom_dimensions : array();
$blog_posts           = isset( $blog_posts ) ? $blog_posts : array();
$blog_posts_url      = isset( $blog_posts_url ) ? $blog_posts_url : 'https://www.monsterinsights.com/blog/';
$update_available    = isset( $update_available ) ? $update_available : false;
$update_version      = isset( $update_version ) ? $update_version : '';
$settings_tab_url    = isset( $settings_tab_url ) ? $settings_tab_url : esc_url( admin_url( 'admin.php?page=monsterinsights_settings#/advanced' ) );
$info_block          = isset( $info_block ) ? $info_block : array();

// Format dates for display
if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
	$start_date = is_numeric( strtotime( $start_date ) ) ? date( 'F j, Y', strtotime( $start_date ) ) : $start_date; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	$end_date   = is_numeric( strtotime( $end_date ) ) ? date( 'F j, Y', strtotime( $end_date ) ) : $end_date; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
}

echo esc_html__( 'Weekly Website Stats', 'google-analytics-premium' ) . "\n\n";
echo esc_html__( "Let's take a look at how your website traffic performed in the past week.", 'google-analytics-premium' ) . "\n\n";
if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
	echo esc_html( $start_date . ' - ' . $end_date ) . "\n";
}
echo esc_url( $site_url ) . "\n\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// Update Notice
if ( $update_available === true && ! empty( $update_version ) ) {
	echo esc_html__( 'MonsterInsights', 'google-analytics-premium' ) . ' - ';
	/* translators: %s: Version number */
	echo esc_html( sprintf( __( 'New Version %s', 'google-analytics-premium' ), $update_version ) ) . "\n";
	echo esc_html__( 'UPDATE AVAILABLE', 'google-analytics-premium' ) . ' - ' . esc_url( admin_url( 'plugins.php' ) ) . "\n\n";
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Analytics Stats
echo esc_html__( 'Analytics Stats', 'google-analytics-premium' ) . "\n\n";
$stats_labels = array(
	'sessions'            => __( 'Sessions', 'google-analytics-premium' ),
	'pageviews'           => __( 'Page Views', 'google-analytics-premium' ),
	'avg_session_length'  => __( 'Avg Session Length', 'google-analytics-premium' ),
	'bounce_rate'         => __( 'Bounce Rate', 'google-analytics-premium' ),
	'no_of_blog_posts'    => __( 'No. of Blog Posts', 'google-analytics-premium' ),
	'no_of_pages_viewed'  => __( 'No. of Pages', 'google-analytics-premium' ),
);
foreach ( $stats_labels as $key => $label ) {
	$stat = ! empty( $analytics_stats[ $key ] ) ? $analytics_stats[ $key ] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$trend = ! empty( $stat['increased'] ) ? __( 'Increase', 'google-analytics-premium' ) : __( 'Decrease', 'google-analytics-premium' );
	echo esc_html( $label ) . '   -   ' . esc_html( $stat['value'] ) . '   (' . absint( $stat['percentage'] ) . '% ' . esc_html( $trend ) . ' ' . esc_html__( 'vs previous week', 'google-analytics-premium' ) . ")\n";
}
echo esc_html__( 'See My Analytics', 'google-analytics-premium' ) . ' - ' . esc_url( admin_url( 'admin.php?page=monsterinsights_reports' ) ) . "\n\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// Store Performance
if ( class_exists( 'MonsterInsights_eCommerce' ) && ! empty( $store_performance ) ) {
	echo esc_html__( "Your Store's Performance", 'google-analytics-premium' ) . "\n\n";
	$revenue = ! empty( $store_performance['last_week_revenue'] ) ? $store_performance['last_week_revenue'] : array( 'value' => '$0', 'percentage' => 0, 'increased' => false );
	$orders  = ! empty( $store_performance['last_week_orders'] ) ? $store_performance['last_week_orders'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$aov     = ! empty( $store_performance['average_order_value'] ) ? $store_performance['average_order_value'] : array( 'value' => '$0', 'percentage' => 0, 'increased' => false );
	$conv    = ! empty( $store_performance['conversion_rate'] ) ? $store_performance['conversion_rate'] : array( 'value' => '0%', 'percentage' => 0, 'increased' => false );
	echo esc_html__( "Last Week's Revenue", 'google-analytics-premium' ) . '   -   ' . esc_html( $revenue['value'] ) . '   (' . absint( $revenue['percentage'] ) . "% " . esc_html__( 'vs previous week', 'google-analytics-premium' ) . ")\n";
	echo esc_html__( "Last Week's Orders", 'google-analytics-premium' ) . '   -   ' . esc_html( $orders['value'] ) . '   (' . absint( $orders['percentage'] ) . "% " . esc_html__( 'vs previous week', 'google-analytics-premium' ) . ")\n";
	$top_product = ! empty( $store_performance['top_selling_product'] ) ? $store_performance['top_selling_product'] : array( 'name' => '', 'total_sales' => '' );
	if ( ! empty( $top_product['name'] ) ) {
		echo esc_html__( 'Top Selling Product', 'google-analytics-premium' ) . '   -   ' . esc_html( $top_product['name'] ) . ( ! empty( $top_product['total_sales'] ) ? '   (' . esc_html( $top_product['total_sales'] ) . ' ' . esc_html__( 'sold', 'google-analytics-premium' ) . ')' : '' ) . "\n";
	}
	echo esc_html__( 'Average Order Value', 'google-analytics-premium' ) . '   -   ' . esc_html( $aov['value'] ) . '   (' . absint( $aov['percentage'] ) . "% " . esc_html__( 'vs previous week', 'google-analytics-premium' ) . ")\n";
	echo esc_html__( 'Conversion Rate', 'google-analytics-premium' ) . '   -   ' . esc_html( $conv['value'] ) . '   (' . absint( $conv['percentage'] ) . "% " . esc_html__( 'vs previous week', 'google-analytics-premium' ) . ")\n";
	echo esc_html__( 'View Full eCommerce Stats', 'google-analytics-premium' ) . ' - ' . esc_url( admin_url( 'admin.php?page=monsterinsights_reports#/ecommerce' ) ) . "\n\n";
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Form Performance
if ( class_exists( 'MonsterInsights_Forms' ) && ! empty( $form_performance ) ) {
	echo esc_html__( 'Form Performance', 'google-analytics-premium' ) . "\n\n";
	$imp  = ! empty( $form_performance['total_impressions'] ) ? $form_performance['total_impressions'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$conv = ! empty( $form_performance['total_conversions'] ) ? $form_performance['total_conversions'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$cvr  = ! empty( $form_performance['total_conversion_rate'] ) ? $form_performance['total_conversion_rate'] : array( 'value' => '0%', 'percentage' => 0, 'increased' => false );
	echo esc_html__( 'Impressions', 'google-analytics-premium' ) . '   -   ' . esc_html( $imp['value'] ) . '   (' . absint( $imp['percentage'] ) . "%)\n";
	echo esc_html__( 'Conversions', 'google-analytics-premium' ) . '   -   ' . esc_html( $conv['value'] ) . '   (' . absint( $conv['percentage'] ) . "%)\n";
	echo esc_html__( 'CVR', 'google-analytics-premium' ) . '   -   ' . esc_html( $cvr['value'] ) . '   (' . absint( $cvr['percentage'] ) . "%)\n";
	echo esc_html__( 'View Forms Report', 'google-analytics-premium' ) . ' - ' . esc_url( admin_url( 'admin.php?page=monsterinsights_reports#forms' ) ) . "\n\n";
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Organic Performance
if ( ! empty( $organic_performance ) ) {
	echo esc_html__( 'Organic Performance', 'google-analytics-premium' ) . "\n\n";
	$impressions = ! empty( $organic_performance['total_impressions'] ) ? $organic_performance['total_impressions'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$clicks      = ! empty( $organic_performance['total_clicks'] ) ? $organic_performance['total_clicks'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	echo esc_html__( 'Impressions', 'google-analytics-premium' ) . '   -   ' . esc_html( $impressions['value'] ) . '   (' . absint( $impressions['percentage'] ) . "%)\n";
	echo esc_html__( 'Clicks', 'google-analytics-premium' ) . '   -   ' . esc_html( $clicks['value'] ) . '   (' . absint( $clicks['percentage'] ) . "%)\n";
	$top_keyword = ! empty( $organic_performance['top_keyword'] ) ? $organic_performance['top_keyword'] : array();
	if ( ! empty( $top_keyword['keyword'] ) ) {
		echo esc_html__( 'Top Keyword', 'google-analytics-premium' ) . '   -   ' . esc_html( $top_keyword['keyword'] ) . ( ! empty( $top_keyword['impressions'] ) ? '   (' . esc_html( $top_keyword['impressions'] ) . ')' : '' ) . "\n";
	}
	if ( ! empty( $top_keywords ) && is_array( $top_keywords ) ) {
		echo "\n" . esc_html__( 'Top Keywords', 'google-analytics-premium' ) . ' (' . esc_html__( 'Impressions Last Week', 'google-analytics-premium' ) . ")\n\n";
		foreach ( $top_keywords as $idx => $kw ) {
			$name = isset( $kw['keyword'] ) ? $kw['keyword'] : '';
			$imp  = isset( $kw['impressions'] ) ? $kw['impressions'] : '';
			echo esc_html( $idx + 1 ) . '. ' . esc_html( $name ) . '   -   ' . esc_html( $imp ) . "\n";
		}
		echo "\n";
	}
	echo esc_html__( 'View Full GSC report', 'google-analytics-premium' ) . ' - ' . esc_url( admin_url( 'admin.php?page=monsterinsights_reports#search-console' ) ) . "\n\n";
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Custom Dimensions
if ( ! empty( $custom_dimensions ) && is_array( $custom_dimensions ) ) {
	echo esc_html__( 'Custom Dimensions', 'google-analytics-premium' ) . "\n\n";
	$log_in  = ! empty( $custom_dimensions['log_in_count'] ) ? (int) $custom_dimensions['log_in_count'] : 0;
	$logout  = ! empty( $custom_dimensions['logout_count'] ) ? (int) $custom_dimensions['logout_count'] : 0;
	$pct     = ! empty( $custom_dimensions['percentage'] ) ? (float) $custom_dimensions['percentage'] : 0;
	$inc     = ! empty( $custom_dimensions['increased'] );
	echo esc_html__( 'Log In / Out', 'google-analytics-premium' ) . '   -   ' . esc_html( $log_in . ' / ' . $logout ) . '   (' . esc_html( number_format( $pct, 1 ) ) . '% ' . esc_html__( 'vs previous week', 'google-analytics-premium' ) . ")\n\n";
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Most Popular Categories
if ( ! empty( $most_popular_categories ) && is_array( $most_popular_categories ) ) {
	echo esc_html__( 'Most Popular Categories', 'google-analytics-premium' ) . "\n\n";
	foreach ( $most_popular_categories as $idx => $category_item ) {
		echo esc_html( $idx + 1 ) . '. ' . esc_html( isset( $category_item['name'] ) ? $category_item['name'] : '' ) . '   -   ' . esc_html( isset( $category_item['percentage'] ) ? $category_item['percentage'] : '' ) . "\n";
	}
	echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Most Popular Post Types
if ( ! empty( $most_popular_post_types ) && is_array( $most_popular_post_types ) ) {
	echo esc_html__( 'Most Popular Post Types', 'google-analytics-premium' ) . "\n\n";
	foreach ( $most_popular_post_types as $idx => $pt ) {
		echo esc_html( $idx + 1 ) . '. ' . esc_html( isset( $pt['name'] ) ? $pt['name'] : '' ) . '   -   ' . esc_html( isset( $pt['impressions'] ) ? $pt['impressions'] : '' ) . ' ' . esc_html__( 'Impressions', 'google-analytics-premium' ) . "\n";
	}
	echo "\n" . esc_html__( 'View Dimensions Report', 'google-analytics-premium' ) . ' - ' . esc_url( admin_url( 'admin.php?page=monsterinsights_reports#dimensions' ) ) . "\n\n";
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// How is Traffic Finding My Website
if ( ! empty( $source_medium_traffic ) && is_array( $source_medium_traffic ) ) {
	echo esc_html__( 'How is Traffic Finding My Website', 'google-analytics-premium' ) . "\n\n";
	echo esc_html__( 'Traffic', 'google-analytics-premium' ) . ' - ' . esc_html__( 'Default Channels • Traffic (W/W Comparison)', 'google-analytics-premium' ) . "\n\n";
	foreach ( $source_medium_traffic as $idx => $item ) {
		$source  = isset( $item['source'] ) ? $item['source'] : '';
		$medium  = isset( $item['medium'] ) ? $item['medium'] : '';
		$sessions = isset( $item['sessions'] ) ? number_format( (int) $item['sessions'] ) : '0';
		$label   = $source . ( ! empty( $medium ) ? ' / ' . $medium : '' );
		echo esc_html( $idx + 1 ) . '. ' . esc_html( $label ) . '   -   ' . esc_html( $sessions ) . ' ' . esc_html__( 'Sessions', 'google-analytics-premium' ) . "\n";
	}
	echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Device Breakdown
if ( ! empty( $device_breakdown ) && is_array( $device_breakdown ) ) {
	echo esc_html__( 'Device Breakdown', 'google-analytics-premium' ) . "\n";
	echo esc_html__( 'Compared to last week', 'google-analytics-premium' ) . "\n\n";
	foreach ( $device_breakdown as $device ) {
		if ( isset( $device['percentage_change'] ) && (float) $device['percentage_change'] === 0.0 ) {
			continue;
		}
		$name = isset( $device['device'] ) ? ucfirst( $device['device'] ) : '';
		$pct  = isset( $device['percentage'] ) ? $device['percentage'] : '';
		$chg  = isset( $device['percentage_change'] ) ? number_format( (float) $device['percentage_change'], 1 ) . '%' : '';
		echo esc_html( $name ) . '   -   ' . esc_html( $pct ) . ( $chg ? '   (' . esc_html( $chg ) . ' ' . esc_html__( 'vs previous week', 'google-analytics-premium' ) . ')' : '' ) . "\n";
	}
	echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Your Top 5 Pages
if ( ! empty( $top_pages ) && is_array( $top_pages ) ) {
	echo esc_html__( 'Your Top 5 Pages', 'google-analytics-premium' ) . "\n\n";
	foreach ( $top_pages as $idx => $page_item ) {
		$page_title = ! empty( $page_item['page_title'] ) ? $page_item['page_title'] : ( isset( $page_item['page_path'] ) ? $page_item['page_path'] : '' );
		$views = isset( $page_item['page_views'] ) ? $page_item['page_views'] : '';
		echo esc_html( $idx + 1 ) . '. ' . esc_html( $page_title ) . '   -   ' . esc_html( $views ) . ' ' . esc_html__( 'Views', 'google-analytics-premium' ) . "\n";
	}
	echo "\n" . esc_html__( 'View Top Pages Report', 'google-analytics-premium' ) . ' - ' . esc_url( admin_url( 'admin.php?page=monsterinsights_reports#publishers-pages' ) ) . "\n\n";
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// What's New at MonsterInsights (Blog posts)
if ( ! empty( $blog_posts ) && is_array( $blog_posts ) ) {
	echo esc_html__( "What's New at MonsterInsights", 'google-analytics-premium' ) . "\n\n";
	foreach ( $blog_posts as $blog_post ) {
		$post_title = isset( $blog_post['title'] ) ? $blog_post['title'] : '';
		$post_link  = isset( $blog_post['link'] ) ? $blog_post['link'] : '';
		echo esc_html( $post_title ) . ( $post_link ? "\n" . esc_url( $post_link ) : '' ) . "\n\n";
	}
	echo esc_html__( 'Read More Posts', 'google-analytics-premium' ) . ' - ' . esc_url( $blog_posts_url ) . "\n\n";
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Pro Tip (info block)
if ( ! empty( $info_block['title'] ) ) {
	echo esc_html__( 'Pro Tip from our experts', 'google-analytics-premium' ) . "\n\n";
	echo wp_kses_post( $info_block['title'] ) . "\n\n";
	if ( ! empty( $info_block['html'] ) ) {
		echo esc_html( wp_strip_all_tags( $info_block['html'] ) ) . "\n\n";
	}
	if ( ! empty( $info_block['link_text'] ) && ! empty( $info_block['link_url'] ) ) {
		echo esc_html( $info_block['link_text'] ) . ' - ' . esc_url( $info_block['link_url'] ) . "\n\n";
	}
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

// Footer
/* translators: %1$s: Opening link tag, %2$s: Closing link tag */
echo esc_html( sprintf( __( 'This email was auto-generated and sent from MonsterInsights. Learn how to %1$sdisable%2$s it.', 'google-analytics-premium' ), '', '' ) ) . ' ' . esc_url( $settings_tab_url ) . "\n";
