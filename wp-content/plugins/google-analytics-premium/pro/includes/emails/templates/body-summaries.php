<?php
/**
 * Email Body Template
 *
 * Email-client compatible template using tables and inline styles.
 * Compatible with Gmail, Outlook, Yahoo Mail, Apple Mail, etc.
 *
 * @since 8.19.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure icons_url is available
$icons_url = isset( $icons_url ) ? $icons_url : '';

// Ensure all required variables have defaults
$analytics_stats = isset( $analytics_stats ) ? $analytics_stats : array();
$store_performance = isset( $store_performance ) ? $store_performance : array();
$form_performance = isset( $form_performance ) ? $form_performance : array();
$organic_performance = isset( $organic_performance ) ? $organic_performance : array();
$top_keywords = isset( $top_keywords ) ? $top_keywords : array();
$most_popular_categories = isset( $most_popular_categories ) ? $most_popular_categories : array();
$most_popular_post_types = isset( $most_popular_post_types ) ? $most_popular_post_types : array();
$source_medium_traffic = isset( $source_medium_traffic ) ? $source_medium_traffic : array();
$device_breakdown = isset( $device_breakdown ) ? $device_breakdown : array();
$top_pages = isset( $top_pages ) ? $top_pages : array();
$custom_dimensions = isset( $custom_dimensions ) ? $custom_dimensions : array();
$blog_posts = isset( $blog_posts ) ? $blog_posts : array();
$blog_posts_url = isset( $blog_posts_url ) ? $blog_posts_url : 'https://www.monsterinsights.com/blog/';
$update_available = isset( $update_available ) ? $update_available : false;
$update_version = isset( $update_version ) ? $update_version : '';

// Helper function to get trend styles
$get_trend_bg = function( $increased ) {
	return $increased ? '#E7F6EF' : '#FFEAED';
};
$get_trend_color = function( $increased ) {
	return $increased ? '#5CC0A5' : '#EB5757';
};
$get_trend_bg_style = function( $increased ) use ( $get_trend_bg ) {
	$bg = $get_trend_bg( $increased );
	return "background-color: {$bg}; background-image: linear-gradient({$bg}, {$bg});";
};

// Safe string length/substr (mbstring is not required by WordPress).
$mset_strlen = function_exists( 'mb_strlen' ) ? function( $str ) { return mb_strlen( $str ); } : function( $str ) { return strlen( $str ); };
$mset_substr = function_exists( 'mb_substr' ) ? function( $str, $start, $length ) { return mb_substr( $str, $start, $length ); } : function( $str, $start, $length ) { return substr( $str, $start, $length ); };
?>

<!-- Intro Message (border-radius 4px, padding 10px 0 per test) -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin: 0 auto;">
	<tr>
		<td style="padding: 10px 0; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 24px; color: #393F4C; border-radius: 4px;">
			<?php esc_html_e("Let's take a look at how your website traffic performed in the past week.", 'google-analytics-premium'); ?>
		</td>
	</tr>
</table>

<?php
// Update Notice
if ( $update_available === true ) :
	$mi_update_version = ! empty( $update_version ) ? $update_version : ( defined( 'MONSTERINSIGHTS_VERSION' ) ? MONSTERINSIGHTS_VERSION : '' );
?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin-bottom: 30px;">
	<tr>
		<td class="mset-section-padding" style="padding: 0;">
			<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#1EC185" style="background-color: #1EC185; background-image: linear-gradient(#1EC185, #1EC185); border-radius: 8px; width: 100%; margin: 0 auto;">
				<tr>
					<td style="padding: 10px 24px;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
							<tr>
								<td style="vertical-align: middle;">
									<p style="margin: 0 0 4px 0; font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: 600; color: #ffffff;">
										<?php esc_html_e( 'MonsterInsights', 'google-analytics-premium' ); ?>
									</p>
									<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #ffffff;">
										<?php /* translators: %s: Version number */ echo esc_html( sprintf( __( 'New Version %s', 'google-analytics-premium' ), $mi_update_version ) ); ?>
									</p>
								</td>
								<td align="right" style="vertical-align: middle;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin-top: 8px;">
										<tr><td align="right" style="padding: 0;"><a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" target="_blank" class="mset-btn-full" style="display: inline-block; padding: 10px 20px; font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: 600; color: #ffffff; text-decoration: none; border: 1px solid #ffffff; border-radius: 20px; text-transform: uppercase;"><?php esc_html_e( 'UPDATE AVAILABLE', 'google-analytics-premium' ); ?></a></td></tr>
									</table>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<?php endif; ?>

<?php
// Analytics Stats Section
$sessions = ! empty( $analytics_stats['sessions'] ) ? $analytics_stats['sessions'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
$pageviews = ! empty( $analytics_stats['pageviews'] ) ? $analytics_stats['pageviews'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
$avg_session_length = ! empty( $analytics_stats['avg_session_length'] ) ? $analytics_stats['avg_session_length'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
$bounce_rate = ! empty( $analytics_stats['bounce_rate'] ) ? $analytics_stats['bounce_rate'] : array( 'value' => '0%', 'percentage' => 0, 'increased' => false );
$no_of_blog_posts = ! empty( $analytics_stats['no_of_blog_posts'] ) ? $analytics_stats['no_of_blog_posts'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
$no_of_pages_viewed = ! empty( $analytics_stats['no_of_pages_viewed'] ) ? $analytics_stats['no_of_pages_viewed'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );

$stats_items = array(
	array( 'label' => __('Sessions', 'google-analytics-premium'), 'icon' => 'sessions.png', 'data' => $sessions ),
	array( 'label' => __('Page Views', 'google-analytics-premium'), 'icon' => 'page-views.png', 'data' => $pageviews ),
	array( 'label' => __('Avg Session Lgth', 'google-analytics-premium'), 'icon' => 'avg-session-length.png', 'data' => $avg_session_length ),
	array( 'label' => __('Bounce Rate', 'google-analytics-premium'), 'icon' => 'bounce-rate.png', 'data' => $bounce_rate, 'invert' => true ),
	array( 'label' => __('No. of Blog Posts', 'google-analytics-premium'), 'icon' => 'no-of-blog-posts.png', 'data' => $no_of_blog_posts ),
	array( 'label' => __('No. of Pages', 'google-analytics-premium'), 'icon' => 'no-of-pages.png', 'data' => $no_of_pages_viewed ),
);
?>

<!-- Analytics Stats Section (mobile-first: full-width buttons, stacked stats on small screens) -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 14px; width: 100%; margin-left: auto; margin-right: auto;">
	<tr>
		<td class="mset-section-padding" style="padding: 0 !important;">
			<!-- Section Header -->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 4px 4px 0 0;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<h2 style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #393E4B;">
							<?php esc_html_e('Analytics Stats', 'google-analytics-premium'); ?>
						</h2>
					</td>
				</tr>
			</table>
			
			<!-- Stats Grid (2 cols mobile, 3 cols desktop) -->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 0 0 4px 4px;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<?php
						$render_stat_card = function( $stat ) use ( $icons_url, $get_trend_bg, $get_trend_color, $get_trend_bg_style ) {
							$increased = isset( $stat['invert'] ) && $stat['invert'] ? ! $stat['data']['increased'] : $stat['data']['increased'];
							$trend_icon = $stat['data']['increased'] ? 'up.png' : 'down.png';
							?>
							<td style="padding: 5px; vertical-align: top; width: 50%;">
								<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border: 1px solid #E3F0FD; border-radius: 8px;">
									<tr>
										<td style="padding: 12px 18px;">
											<p style="margin: 0 0 10px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #393F4C; white-space: nowrap;"><?php echo esc_html( $stat['label'] ); ?></p>
											<?php if ( $icons_url ) : ?>
											<img src="<?php echo esc_url( $icons_url . $stat['icon'] ); ?>" alt="<?php echo esc_attr( $stat['label'] ); ?>" width="40" height="40" style="display: block; margin-bottom: 10px; border: 0;" />
											<?php endif; ?>
											<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
												<tr>
													<td style="vertical-align: middle;">
														<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 24px; font-weight: 600; color: #393F4C; white-space: nowrap;"><?php echo esc_html( $stat['data']['value'] ); ?></p>
													</td>
													<td align="right" style="vertical-align: middle;">
														<table role="presentation" cellpadding="0" cellspacing="0" border="0">
															<tr>
																<td bgcolor="<?php echo esc_attr( $get_trend_bg( $increased ) ); ?>" style="padding: 4px 8px; white-space: nowrap; <?php echo esc_attr( $get_trend_bg_style( $increased ) ); ?> border-radius: 100px;">
																	<span style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( $increased ) ); ?>;"><?php echo absint( $stat['data']['percentage'] ); ?>%</span>
																	<?php if ( $icons_url ) : ?>
																	<img src="<?php echo esc_url( $icons_url . $trend_icon ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
																	<?php endif; ?>
																</td>
															</tr>
														</table>
													</td>
												</tr>
											</table>
											<p style="margin: 8px 0 0 0; font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #84868A;"><?php esc_html_e('vs previous week', 'google-analytics-premium'); ?></p>
										</td>
									</tr>
								</table>
							</td>
							<?php
						};
						?>
						<!-- Mobile: 2 columns (3 rows of 2) -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="mset-stats-mobile" style="width: 100%; table-layout: fixed;">
							<?php for ( $r = 0; $r < 3; $r++ ) : ?>
							<tr>
								<?php for ( $c = 0; $c < 2; $c++ ) : $i = $r * 2 + $c; if ( $i >= count( $stats_items ) ) break; $render_stat_card( $stats_items[ $i ] ); endfor; ?>
							</tr>
							<?php endfor; ?>
						</table>
						<!-- Desktop: 3 columns (2 rows of 3) -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="mset-stats-desktop" style="display: none; width: 100%; table-layout: fixed;">
							<?php for ( $r = 0; $r < 2; $r++ ) : ?>
							<tr>
								<?php for ( $c = 0; $c < 3; $c++ ) : $i = $r * 3 + $c; if ( $i >= count( $stats_items ) ) break; $increased = isset( $stats_items[ $i ]['invert'] ) && $stats_items[ $i ]['invert'] ? ! $stats_items[ $i ]['data']['increased'] : $stats_items[ $i ]['data']['increased']; $trend_icon = $stats_items[ $i ]['data']['increased'] ? 'up.png' : 'down.png'; $stat = $stats_items[ $i ]; ?>
								<td class="mset-stat-col-2-3" style="padding: 5px; vertical-align: top;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border: 1px solid #E3F0FD; border-radius: 8px;">
										<tr>
											<td style="padding: 12px 18px;">
												<p style="margin: 0 0 10px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #393F4C; white-space: nowrap;"><?php echo esc_html( $stat['label'] ); ?></p>
												<?php if ( $icons_url ) : ?>
												<img src="<?php echo esc_url( $icons_url . $stat['icon'] ); ?>" alt="<?php echo esc_attr( $stat['label'] ); ?>" width="40" height="40" style="display: block; margin-bottom: 10px; border: 0;" />
												<?php endif; ?>
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
													<tr>
														<td style="vertical-align: middle;">
															<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 24px; font-weight: 600; color: #393F4C; white-space: nowrap;"><?php echo esc_html( $stat['data']['value'] ); ?></p>
														</td>
														<td align="right" style="vertical-align: middle;">
															<table role="presentation" cellpadding="0" cellspacing="0" border="0">
																<tr>
																	<td bgcolor="<?php echo esc_attr( $get_trend_bg( $increased ) ); ?>" style="padding: 4px 8px; white-space: nowrap; <?php echo esc_attr( $get_trend_bg_style( $increased ) ); ?> border-radius: 100px;">
																		<span style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( $increased ) ); ?>;"><?php echo absint( $stat['data']['percentage'] ); ?>%</span>
																		<?php if ( $icons_url ) : ?>
																		<img src="<?php echo esc_url( $icons_url . $trend_icon ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
																		<?php endif; ?>
																	</td>
																</tr>
															</table>
														</td>
													</tr>
												</table>
												<p style="margin: 8px 0 0 0; font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #84868A;"><?php esc_html_e('vs previous week', 'google-analytics-premium'); ?></p>
											</td>
										</tr>
									</table>
								</td>
								<?php endfor; ?>
							</tr>
							<?php endfor; ?>
						</table>
						
						<!-- CTA Button  -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin-top: 20px;">
							<tr>
								<td align="center" width="100%" style="width: 100%; padding: 0;">
									<a href="<?php echo esc_url( admin_url('admin.php?page=monsterinsights_reports') ); ?>" target="_blank" class="mset-btn-full mset-cta-report" style="display: block; width: 100% !important; box-sizing: border-box; text-align: center; padding: 14px 24px; background-color: #338EEF; background-image: linear-gradient(#338EEF, #338EEF); border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #ffffff; text-decoration: none;">
										<?php esc_html_e('See My Analytics', 'google-analytics-premium'); ?>
										<?php if ( $icons_url ) : ?>
										<img src="<?php echo esc_url( $icons_url . 'arrow-right.png' ); ?>" alt="" width="16" height="16" style="display: inline; vertical-align: middle; margin-left: 8px; border: 0;" />
										<?php endif; ?>
									</a>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>

<?php
// Store Performance Section (full-width, outside main container)
if ( class_exists( 'MonsterInsights_eCommerce' ) && ! empty( $store_performance ) ) :
	$revenue = ! empty( $store_performance['last_week_revenue'] ) ? $store_performance['last_week_revenue'] : array( 'value' => '$0', 'percentage' => 0, 'increased' => false );
	$orders = ! empty( $store_performance['last_week_orders'] ) ? $store_performance['last_week_orders'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$top_product = ! empty( $store_performance['top_selling_product'] ) ? $store_performance['top_selling_product'] : array( 'name' => '', 'total_sales' => '0' );
	$aov = ! empty( $store_performance['average_order_value'] ) ? $store_performance['average_order_value'] : array( 'value' => '$0', 'percentage' => 0, 'increased' => false );
	$conversion_rate = ! empty( $store_performance['conversion_rate'] ) ? $store_performance['conversion_rate'] : array( 'value' => '0%', 'percentage' => 0, 'increased' => false );
	
	$store_items = array(
		array( 'label' => __("Last Week's Revenue", 'google-analytics-premium'), 'icon' => 'last_week_revenue.png', 'data' => $revenue ),
		array( 'label' => __("Last Week's Orders", 'google-analytics-premium'), 'icon' => 'last_week_sales.png', 'data' => $orders ),
		array( 'label' => __('Average Order Value', 'google-analytics-premium'), 'icon' => 'avg-order-value.png', 'data' => $aov ),
		array( 'label' => __('Conversion Rate', 'google-analytics-premium'), 'icon' => 'conversion-rate.png', 'data' => $conversion_rate ),
	);
	?>
</td></tr></table>
<!--[if mso]></td></tr></table><![endif]-->
<!-- Store Performance Section (background #F3F6FF, max-width 700px) -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#f5faff" style="background-color: #f5faff; background-image: linear-gradient(#f5faff, #f5faff); width: 100%; margin: 0 auto;">
	<tr>
		<td width="100%" style="padding: 30px 0; width: 100%;">
			<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" align="center" style="width: 100%; margin: 0 auto;">
				<tr>
					<td class="mset-section-padding" width="100%" style="padding: 0; width: 100%;">
						<!-- Section Header -->
						<h2 style="margin: 0 0 20px 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #393E4B;">
							<?php esc_html_e("Your Store's Performance", 'google-analytics-premium'); ?>
						</h2>
						<!-- 2 cols on desktop: 3 items left, 2 items right -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
							<tr>
								<td class="mset-store-col mset-store-col-first" style="display: block; width: 100%; padding: 0; vertical-align: top;">
									<?php for ( $i = 0; $i < 3 && $i < count( $store_items ); $i++ ) : $item = $store_items[ $i ]; ?>
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border: 1px solid #E5E7E9; border-radius: 8px; margin-bottom: 15px;">
										<tr>
											<td style="padding: 15px;">
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
													<tr>
														<td width="60" style="vertical-align: middle;">
															<?php if ( $icons_url ) : ?>
															<img src="<?php echo esc_url( $icons_url . $item['icon'] ); ?>" alt="" width="44" height="44" style="display: block; border: 0;" />
															<?php endif; ?>
														</td>
														<td style="vertical-align: middle;">
															<p style="margin: 0 0 5px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #393F4C;">
																<?php echo esc_html( $item['label'] ); ?>
															</p>
															<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 600; color: #23262E;">
																<?php echo esc_html( $item['data']['value'] ); ?>
															</p>
														</td>
														<td align="right" style="vertical-align: middle;">
															<table role="presentation" cellpadding="0" cellspacing="0" border="0">
																<tr>
																	<td bgcolor="<?php echo esc_attr( $get_trend_bg( $item['data']['increased'] ) ); ?>" style="padding: 4px 8px; <?php echo esc_attr( $get_trend_bg_style( $item['data']['increased'] ) ); ?> border-radius: 100px;">
																		<span style="display: inline; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( $item['data']['increased'] ) ); ?>;">
																			<?php echo absint( $item['data']['percentage'] ); ?>%
																		</span>
																		<?php if ( $icons_url ) : ?>
																		<img src="<?php echo esc_url( $icons_url . ( $item['data']['increased'] ? 'up.png' : 'down.png' ) ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
																		<?php endif; ?>
																	</td>
																</tr>
																<tr>
																	<td style="padding-top: 4px;">
																		<span style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #84868A;">
																			<?php esc_html_e('vs previous week', 'google-analytics-premium'); ?>
																		</span>
																	</td>
																</tr>
															</table>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
									<?php endfor; ?>
								</td>
								<td class="mset-store-col mset-store-col-second" style="display: block; width: 100%; padding: 0; vertical-align: top;">
									<?php for ( $i = 3; $i < count( $store_items ); $i++ ) : $item = $store_items[ $i ]; ?>
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border: 1px solid #E5E7E9; border-radius: 8px; margin-bottom: 15px;">
										<tr>
											<td style="padding: 15px;">
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
													<tr>
														<td width="60" style="vertical-align: middle;">
															<?php if ( $icons_url ) : ?>
															<img src="<?php echo esc_url( $icons_url . $item['icon'] ); ?>" alt="" width="44" height="44" style="display: block; border: 0;" />
															<?php endif; ?>
														</td>
														<td style="vertical-align: middle;">
															<p style="margin: 0 0 5px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #393F4C;">
																<?php echo esc_html( $item['label'] ); ?>
															</p>
															<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 600; color: #23262E;">
																<?php echo esc_html( $item['data']['value'] ); ?>
															</p>
														</td>
														<td align="right" style="vertical-align: middle;">
															<table role="presentation" cellpadding="0" cellspacing="0" border="0">
																<tr>
																	<td bgcolor="<?php echo esc_attr( $get_trend_bg( $item['data']['increased'] ) ); ?>" style="padding: 4px 8px; <?php echo esc_attr( $get_trend_bg_style( $item['data']['increased'] ) ); ?> border-radius: 100px;">
																		<span style="display: inline; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( $item['data']['increased'] ) ); ?>;">
																			<?php echo absint( $item['data']['percentage'] ); ?>%
																		</span>
																		<?php if ( $icons_url ) : ?>
																		<img src="<?php echo esc_url( $icons_url . ( $item['data']['increased'] ? 'up.png' : 'down.png' ) ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
																		<?php endif; ?>
																	</td>
																</tr>
																<tr>
																	<td style="padding-top: 4px;">
																		<span style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #84868A;">
																			<?php esc_html_e('vs previous week', 'google-analytics-premium'); ?>
																		</span>
																	</td>
																</tr>
															</table>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
									<?php endfor; ?>
									<?php if ( ! empty( $top_product ) && ! empty( $top_product['name'] ) ) : ?>
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border: 1px solid #E5E7E9; border-radius: 8px; margin-bottom: 15px;">
										<tr>
											<td style="padding: 15px;">
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
													<tr>
														<td width="60" style="vertical-align: middle;">
															<?php if ( $icons_url ) : ?>
															<img src="<?php echo esc_url( $icons_url . 'top-selling-product.png' ); ?>" alt="" width="44" height="44" style="display: block; border: 0;" />
															<?php endif; ?>
														</td>
														<td style="vertical-align: middle;">
															<p style="margin: 0 0 5px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #393F4C;">
																<?php esc_html_e('Top Selling Product', 'google-analytics-premium'); ?>
															</p>
															<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 600; color: #23262E;">
																<?php echo esc_html( $mset_strlen( $top_product['name'] ) > 20 ? $mset_substr( $top_product['name'], 0, 20 ) . '...' : $top_product['name'] ); ?>
															</p>
														</td>
														<?php if ( ! empty( $top_product['total_sales'] ) ) : ?>
														<td align="right" style="vertical-align: middle;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #84868A;">
																<?php echo esc_html( $top_product['total_sales'] ); ?> <?php esc_html_e('sold', 'google-analytics-premium'); ?>
															</span>
														</td>
														<?php endif; ?>
													</tr>
												</table>
											</td>
										</tr>
									</table>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td colspan="2" class="mset-section-padding" style="padding-top: 14px !important;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
										<tr>
											<td align="center" width="100%" style="width: 100%; padding: 0;">
												<a href="<?php echo esc_url( admin_url('admin.php?page=monsterinsights_reports#/ecommerce') ); ?>" target="_blank" class="mset-btn-full mset-cta-report" style="display: block; width: 100% !important; box-sizing: border-box; text-align: center; padding: 14px 24px; background-color: #338EEF; background-image: linear-gradient(#338EEF, #338EEF); border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #ffffff; text-decoration: none;">
													<?php esc_html_e('View Full eCommerce Stats', 'google-analytics-premium'); ?>
													<?php if ( $icons_url ) : ?>
													<img src="<?php echo esc_url( $icons_url . 'arrow-right.png' ); ?>" alt="" width="16" height="16" style="display: inline; vertical-align: middle; margin-left: 8px; border: 0;" />
													<?php endif; ?>
												</a>
											</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="700"><tr><td><![endif]-->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="mset-content-max" bgcolor="#F5FAFF" style="width: 100%; margin: 0 auto; background-color: #F5FAFF; background-image: linear-gradient(#F5FAFF, #F5FAFF);">
	<tr>
		<td class="mset-section-padding" width="100%" style="padding: 50px 0 20px 0; border-radius: 4px; width: 100%;">
<?php endif; ?>

<?php
// Form Performance Section
if ( class_exists( 'MonsterInsights_Forms' ) && ! empty( $form_performance ) ) :
	$impressions = ! empty( $form_performance['total_impressions'] ) ? $form_performance['total_impressions'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$conversions = ! empty( $form_performance['total_conversions'] ) ? $form_performance['total_conversions'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$cmr = ! empty( $form_performance['total_conversion_rate'] ) ? $form_performance['total_conversion_rate'] : array( 'value' => '0%', 'percentage' => 0, 'increased' => false );
	
	$form_items = array(
		array( 'label' => __('Impressions', 'google-analytics-premium'), 'data' => $impressions ),
		array( 'label' => __('Conversions', 'google-analytics-premium'), 'data' => $conversions ),
		array( 'label' => __('CVR', 'google-analytics-premium'), 'data' => $cmr ),
	);
?>

<!-- Form Performance Section (mobile-first) -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 14px; width: 100%; margin-left: auto; margin-right: auto;">
	<tr>
		<td class="mset-section-padding" style="padding: 0;">
			<!-- Section Header -->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 4px 4px 0 0;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<h2 style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #393E4B;">
							<?php esc_html_e('Form Performance', 'google-analytics-premium'); ?>
						</h2>
					</td>
				</tr>
			</table>
			
			<!-- Performance Grid (3 cols desktop, stacked mobile) -->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 0 0 4px 4px;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
							<tr>
								<?php foreach ( $form_items as $item ) : ?>
								<td class="mset-form-col" style="padding: 5px; vertical-align: top;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#FBFDFF" style="width: 100%; background-color: #FBFDFF; background-image: linear-gradient(#FBFDFF, #FBFDFF); border: 1px solid #E3F0FD; border-radius: 8px;">
										<tr>
											<td style="padding: 15px;">
												<p style="margin: 0 0 8px 0; font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #393F4C;">
													<?php echo esc_html( $item['label'] ); ?>
												</p>
												<p style="margin: 0 0 10px 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #111827;">
													<?php echo esc_html( $item['data']['value'] ); ?>
												</p>
												<table role="presentation" cellpadding="0" cellspacing="0" border="0">
													<tr>
														<td bgcolor="<?php echo esc_attr( $get_trend_bg( $item['data']['increased'] ) ); ?>" style="padding: 4px 8px; <?php echo esc_attr( $get_trend_bg_style( $item['data']['increased'] ) ); ?> border-radius: 100px;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( $item['data']['increased'] ) ); ?>;">
																<?php echo absint( $item['data']['percentage'] ); ?>%
															</span>
															<?php if ( $icons_url ) : ?>
															<img src="<?php echo esc_url( $icons_url . ( $item['data']['increased'] ? 'up.png' : 'down.png' ) ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
															<?php endif; ?>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
								</td>
								<?php endforeach; ?>
							</tr>
						</table>
						
						<!-- CTA Button (full width on mobile) -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin-top: 20px;">
							<tr>
								<td align="center" width="100%" style="width: 100%; padding: 0;">
									<a href="<?php echo esc_url( admin_url('admin.php?page=monsterinsights_reports#forms') ); ?>" target="_blank" class="mset-btn-full mset-cta-report" style="display: block; width: 100% !important; box-sizing: border-box; text-align: center; padding: 14px 24px; background-color: #338EEF; background-image: linear-gradient(#338EEF, #338EEF); border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #ffffff; text-decoration: none;">
										<?php esc_html_e('View Forms Report', 'google-analytics-premium'); ?>
										<?php if ( $icons_url ) : ?>
										<img src="<?php echo esc_url( $icons_url . 'arrow-right.png' ); ?>" alt="" width="16" height="16" style="display: inline; vertical-align: middle; margin-left: 8px; border: 0;" />
										<?php endif; ?>
									</a>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<?php endif; ?>

<?php
// Organic Performance Section
if ( ! empty( $organic_performance ) ) :
	$organic_impressions = ! empty( $organic_performance['total_impressions'] ) ? $organic_performance['total_impressions'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$organic_clicks = ! empty( $organic_performance['total_clicks'] ) ? $organic_performance['total_clicks'] : array( 'value' => '0', 'percentage' => 0, 'increased' => false );
	$top_keyword = ! empty( $organic_performance['top_keyword'] ) ? $organic_performance['top_keyword'] : array();
	$organic_has_keyword = ! empty( $top_keyword ) && is_array( $top_keyword ) && ! empty( $top_keyword['keyword'] );
	$organic_col_class = $organic_has_keyword ? 'mset-form-col' : 'mset-form-col-half';
?>

<!-- Organic Performance Section -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 14px; width: 100%; margin-left: auto; margin-right: auto;">
	<tr>
		<td class="mset-section-padding" style="padding: 0;">
			<!-- Section Header -->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 4px 4px 0 0;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<h2 style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #393E4B;">
							<?php esc_html_e('Organic Performance', 'google-analytics-premium'); ?>
						</h2>
					</td>
				</tr>
			</table>
			
			<!-- Performance Grid (3 cols) -->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 0 0 4px 4px;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
							<tr>
								<!-- Impressions -->
								<td class="<?php echo esc_attr( $organic_col_class ); ?>" style="padding: 5px; vertical-align: top;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#FBFDFF" style="width: 100%; background-color: #FBFDFF; background-image: linear-gradient(#FBFDFF, #FBFDFF); border: 1px solid #E3F0FD; border-radius: 8px;">
										<tr>
											<td style="padding: 15px;">
												<p style="margin: 0 0 8px 0; font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #393F4C;">
													<?php esc_html_e('Impressions', 'google-analytics-premium'); ?>
												</p>
												<p style="margin: 0 0 10px 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #111827;">
													<?php echo esc_html( $organic_impressions['value'] ); ?>
												</p>
												<table role="presentation" cellpadding="0" cellspacing="0" border="0">
													<tr>
														<td bgcolor="<?php echo esc_attr( $get_trend_bg( $organic_impressions['increased'] ) ); ?>" style="padding: 4px 8px; <?php echo esc_attr( $get_trend_bg_style( $organic_impressions['increased'] ) ); ?> border-radius: 100px;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( $organic_impressions['increased'] ) ); ?>;">
																<?php echo absint( $organic_impressions['percentage'] ); ?>%
															</span>
															<?php if ( $icons_url ) : ?>
															<img src="<?php echo esc_url( $icons_url . ( $organic_impressions['increased'] ? 'up.png' : 'down.png' ) ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
															<?php endif; ?>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
								</td>
								<!-- Clicks -->
								<td class="<?php echo esc_attr( $organic_col_class ); ?>" style="padding: 5px; vertical-align: top;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#FBFDFF" style="width: 100%; background-color: #FBFDFF; background-image: linear-gradient(#FBFDFF, #FBFDFF); border: 1px solid #E3F0FD; border-radius: 8px;">
										<tr>
											<td style="padding: 15px;">
												<p style="margin: 0 0 8px 0; font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #393F4C;">
													<?php esc_html_e('Clicks', 'google-analytics-premium'); ?>
												</p>
												<p style="margin: 0 0 10px 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #111827;">
													<?php echo esc_html( $organic_clicks['value'] ); ?>
												</p>
												<table role="presentation" cellpadding="0" cellspacing="0" border="0">
													<tr>
														<td bgcolor="<?php echo esc_attr( $get_trend_bg( $organic_clicks['increased'] ) ); ?>" style="padding: 4px 8px; <?php echo esc_attr( $get_trend_bg_style( $organic_clicks['increased'] ) ); ?> border-radius: 100px;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( $organic_clicks['increased'] ) ); ?>;">
																<?php echo absint( $organic_clicks['percentage'] ); ?>%
															</span>
															<?php if ( $icons_url ) : ?>
															<img src="<?php echo esc_url( $icons_url . ( $organic_clicks['increased'] ? 'up.png' : 'down.png' ) ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
															<?php endif; ?>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
								</td>
								<?php if ( $organic_has_keyword ) : ?>
								<!-- Top Keyword -->
								<td class="<?php echo esc_attr( $organic_col_class ); ?>" style="padding: 5px; vertical-align: top;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#FBFDFF" style="width: 100%; background-color: #FBFDFF; background-image: linear-gradient(#FBFDFF, #FBFDFF); border: 1px solid #E3F0FD; border-radius: 8px;">
										<tr>
											<td style="padding: 15px;">
												<p style="margin: 0 0 8px 0; font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #393F4C;">
													<?php esc_html_e('Top Keyword', 'google-analytics-premium'); ?>
												</p>
												<p style="margin: 0 0 10px 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #111827;">
													<?php echo esc_html( isset( $top_keyword['impressions'] ) ? $top_keyword['impressions'] : '0' ); ?>
												</p>
												<span style="display: inline-block; padding: 4px 8px; background-color: #efeeff; background-image: linear-gradient(#efeeff, #efeeff); font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #6c49b6; border-radius: 100px;">
													<?php echo esc_html( $mset_strlen( $top_keyword['keyword'] ) > 15 ? $mset_substr( $top_keyword['keyword'], 0, 15 ) . '...' : $top_keyword['keyword'] ); ?>
												</span>
											</td>
										</tr>
									</table>
								</td>
								<?php endif; ?>
							</tr>
						</table>
						
						<?php if ( ! empty( $top_keywords ) && is_array( $top_keywords ) ) : ?>
						<!-- Top Keywords List -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin-top: 20px;">
							<tr>
								<td>
									<h3 style="margin: 0 0 5px 0; font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: 600; color: #23262E;">
										<?php esc_html_e('Top Keywords', 'google-analytics-premium'); ?>
									</h3>
									<p style="margin: 0 0 15px 0; font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #393F4C;">
										<?php esc_html_e('Impressions Last Week', 'google-analytics-premium'); ?>
									</p>
								</td>
							</tr>
						</table>
						
						<!-- Keywords list border-radius 8px per test .mset-keywords-list -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#FAFBFF" style="width: 100%; background-color: #FAFBFF; background-image: linear-gradient(#FAFBFF, #FAFBFF); border: 1px solid #EBEDF0; border-radius: 8px;">
							<tr>
								<td style="padding: 15px;">
									<?php foreach ( $top_keywords as $index => $keyword ) : ?>
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; padding: 10px 0; <?php echo $index < count( $top_keywords ) - 1 ? 'border-bottom: 1px solid #EBEDF0;' : ''; ?>">
										<tr>
											<td width="30" style="vertical-align: top; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #23262E;">
												<?php echo esc_html( ( $index + 1 ) . '.' ); ?>
											</td>
											<td style="vertical-align: top;">
												<p style="margin: 0 0 2px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #23262E;">
													<?php echo esc_html( $mset_strlen( $keyword['keyword'] ) > 25 ? $mset_substr( $keyword['keyword'], 0, 25 ) . '...' : $keyword['keyword'] ); ?>
												</p>
												<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 700; color: #111827;">
													<?php echo esc_html( $keyword['impressions'] ); ?>
													<span style="font-weight: 400; font-size: 12px; color: #393F4C; margin-left: 4px;"><?php esc_html_e('Impressions', 'google-analytics-premium'); ?></span>
												</p>
											</td>
											<?php if ( isset( $keyword['percentage'] ) ) : ?>
											<td align="right" style="vertical-align: top;">
												<table role="presentation" cellpadding="0" cellspacing="0" border="0">
													<tr>
														<td bgcolor="<?php echo esc_attr( $get_trend_bg( ! empty( $keyword['increased'] ) ) ); ?>" style="padding: 4px 8px; <?php echo esc_attr( $get_trend_bg_style( ! empty( $keyword['increased'] ) ) ); ?> border-radius: 100px;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( ! empty( $keyword['increased'] ) ) ); ?>;">
																<?php echo number_format( (float) $keyword['percentage'], 1 ); ?>%
															</span>
															<?php if ( $icons_url ) : ?>
															<img src="<?php echo esc_url( $icons_url . ( ! empty( $keyword['increased'] ) ? 'up.png' : 'down.png' ) ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
															<?php endif; ?>
														</td>
													</tr>
													<tr>
														<td style="padding-top: 4px;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #84868A;">
																<?php esc_html_e('from last week', 'google-analytics-premium'); ?>
															</span>
														</td>
													</tr>
												</table>
											</td>
											<?php endif; ?>
										</tr>
									</table>
									<?php endforeach; ?>
								</td>
							</tr>
						</table>
						<?php endif; ?>
						
						<!-- CTA Button (full width on mobile) -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin-top: 20px;">
							<tr>
								<td align="center" width="100%" style="width: 100%; padding: 0;">
									<a href="<?php echo esc_url( admin_url('admin.php?page=monsterinsights_reports#search-console') ); ?>" target="_blank" class="mset-btn-full mset-cta-report" style="display: block; width: 100% !important; box-sizing: border-box; text-align: center; padding: 14px 24px; background-color: #338EEF; background-image: linear-gradient(#338EEF, #338EEF); border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #ffffff; text-decoration: none;">
										<?php esc_html_e('View Full GSC report', 'google-analytics-premium'); ?>
										<?php if ( $icons_url ) : ?>
										<img src="<?php echo esc_url( $icons_url . 'arrow-right.png' ); ?>" alt="" width="16" height="16" style="display: inline; vertical-align: middle; margin-left: 8px; border: 0;" />
										<?php endif; ?>
									</a>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<?php endif; ?>

<?php
// Custom Dimensions Section
if ( ! empty( $custom_dimensions ) && is_array( $custom_dimensions ) ) :
	$log_in_count  = ! empty( $custom_dimensions['log_in_count'] ) ? (int) $custom_dimensions['log_in_count'] : 0;
	$logout_count  = ! empty( $custom_dimensions['logout_count'] ) ? (int) $custom_dimensions['logout_count'] : 0;
	$cd_percentage = ! empty( $custom_dimensions['percentage'] ) ? (float) $custom_dimensions['percentage'] : 0;
	$cd_increased  = ! empty( $custom_dimensions['increased'] );
?>
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 14px; width: 100%; margin-left: auto; margin-right: auto;">
	<tr>
		<td class="mset-section-padding" style="padding: 0;">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 4px 4px 0 0;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<h2 style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #393E4B;">
							<?php esc_html_e( 'Custom Dimensions', 'google-analytics-premium' ); ?>
						</h2>
					</td>
				</tr>
			</table>
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 0 0 4px 4px;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#FBFDFF" style="width: 100%; background-color: #FBFDFF; background-image: linear-gradient(#FBFDFF, #FBFDFF); border: 1px solid #E3F0FD; border-radius: 8px;">
							<tr>
								<td style="padding: 15px;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
										<tr>
											<td style="vertical-align: middle;">
												<p style="margin: 0 0 8px 0; font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #393F4C;">
													<?php esc_html_e( 'Log In / Out', 'google-analytics-premium' ); ?>
												</p>
												<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #111827;">
													<?php echo esc_html( $log_in_count . ' / ' . $logout_count ); ?>
												</p>
											</td>
											<td align="right" style="vertical-align: middle;">
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="right">
													<tr>
														<td bgcolor="<?php echo esc_attr( $get_trend_bg( $cd_increased ) ); ?>" style="padding: 4px 8px; <?php echo esc_attr( $get_trend_bg_style( $cd_increased ) ); ?> border-radius: 100px;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( $cd_increased ) ); ?>;">
																<?php echo esc_html( number_format( $cd_percentage, 1 ) ); ?>%
															</span>
															<?php if ( $icons_url ) : ?>
															<img src="<?php echo esc_url( $icons_url . ( $cd_increased ? 'up.png' : 'down.png' ) ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
															<?php endif; ?>
														</td>
													</tr>
													<tr>
														<td style="padding-top: 4px; text-align: right;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #84868A;">
																<?php esc_html_e( 'vs previous week', 'google-analytics-premium' ); ?>
															</span>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<?php endif; ?>

<?php
// Most Popular Categories + Most Popular Post Types (2 cols side-by-side on desktop only)
$has_categories = ! empty( $most_popular_categories ) && is_array( $most_popular_categories );
$has_post_types = ! empty( $most_popular_post_types ) && is_array( $most_popular_post_types );
$both_cols = $has_categories && $has_post_types;
if ( $has_categories || $has_post_types ) :
?>
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 14px; width: 100%; margin-left: auto; margin-right: auto;">
	<tr>
		<?php if ( $has_categories ) : ?>
		<td class="mset-categories-posttypes-col<?php echo $both_cols ? ' mset-categories-posttypes-col-first' : ''; ?>" style="display: block; width: 100%; padding: 0; vertical-align: top;">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin-bottom: 14px;">
				<tr>
					<td class="mset-section-padding" style="padding: 0 !important;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 4px 4px 0 0;">
							<tr>
								<td class="mset-section-padding-lg" style="padding: 10px 20px;">
									<h2 style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #393E4B;">
										<?php esc_html_e( 'Most Popular Categories', 'google-analytics-premium' ); ?>
									</h2>
								</td>
							</tr>
						</table>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 0 0 4px 4px;">
							<tr>
								<td class="mset-section-padding-lg" style="padding: 10px 20px;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#FBFDFF" style="width: 100%; background-color: #FBFDFF; background-image: linear-gradient(#FBFDFF, #FBFDFF); border: 1px solid #E3F0FD; border-radius: 8px;">
										<tr>
											<td style="padding: 15px 20px;">
												<?php foreach ( $most_popular_categories as $index => $category ) : ?>
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; padding: 12px 0;">
													<tr>
														<td width="30" style="vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: 400; color: #23262E; text-align: center; line-height: 24px;"><?php echo esc_html( ( $index + 1 ) . '.' ); ?></td>
														<td style="vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #23262E;"><?php echo esc_html( $category['name'] ); ?></td>
														<td align="right" style="vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600; color: #393F4C;"><?php echo esc_html( $category['percentage'] ); ?></td>
													</tr>
												</table>
												<?php endforeach; ?>
											</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
		<?php endif; ?>
		<?php if ( $has_post_types ) : ?>
		<td class="mset-categories-posttypes-col<?php echo $both_cols ? ' mset-categories-posttypes-col-second' : ''; ?>" style="display: block; width: 100%; padding: 0; vertical-align: top;">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin-bottom: 14px;">
				<tr>
					<td class="mset-section-padding" style="padding: 0 !important;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 4px 4px 0 0;">
							<tr>
								<td class="mset-section-padding-lg" style="padding: 10px 20px;">
									<h2 style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #393E4B;">
										<?php esc_html_e( 'Most Popular Post Types', 'google-analytics-premium' ); ?>
									</h2>
								</td>
							</tr>
						</table>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 0 0 4px 4px;">
							<tr>
								<td class="mset-section-padding-lg" style="padding: 10px 20px;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#FBFDFF" style="width: 100%; background-color: #FBFDFF; background-image: linear-gradient(#FBFDFF, #FBFDFF); border: 1px solid #E3F0FD; border-radius: 8px;">
										<tr>
											<td style="padding: 15px 20px;">
												<!-- Header row -->
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; padding-bottom: 12px; margin-bottom: 4px;">
													<tr>
														<td style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; color: #6B7280;"><?php esc_html_e( 'Post Type', 'google-analytics-premium' ); ?></td>
														<td align="right" style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; color: #6B7280;"><?php esc_html_e( 'Impressions', 'google-analytics-premium' ); ?></td>
													</tr>
												</table>
												<?php foreach ( $most_popular_post_types as $index => $type_item ) : ?>
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; padding: 12px 0;">
													<tr>
														<td width="30" style="vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: 400; color: #23262E; text-align: center; line-height: 24px;"><?php echo esc_html( ( $index + 1 ) . '.' ); ?></td>
														<td style="vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #23262E;"><?php echo esc_html( $type_item['name'] ); ?></td>
														<td align="right" style="vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600; color: #393F4C;"><?php echo esc_html( $type_item['impressions'] ); ?></td>
													</tr>
												</table>
												<?php endforeach; ?>
											</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
		<?php endif; ?>
	</tr>
	<?php if ( $has_post_types ) : ?>
	<tr>
		<td colspan="<?php echo $both_cols ? 2 : 1; ?>" class="mset-section-padding" style="padding-top: 14px !important;">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin-top: 0;">
				<tr>
					<td align="center" width="100%" style="width: 100%; padding: 0;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=monsterinsights_reports#dimensions' ) ); ?>" target="_blank" class="mset-btn-full mset-cta-report" style="display: block; width: 100% !important; box-sizing: border-box; text-align: center; padding: 14px 24px; background-color: #338EEF; background-image: linear-gradient(#338EEF, #338EEF); border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #ffffff; text-decoration: none;">
							<?php esc_html_e( 'View Dimensions Report', 'google-analytics-premium' ); ?>
							<?php if ( $icons_url ) : ?>
							<img src="<?php echo esc_url( $icons_url . 'arrow-right-blue.png' ); ?>" alt="" width="16" height="16" style="display: inline; vertical-align: middle; margin-left: 8px; border: 0;" />
							<?php endif; ?>
						</a>
					</td>
				</tr>
			</table>
		</td>
	</tr>
	<?php endif; ?>
</table>
<?php endif; ?>

<?php
// How is Traffic Finding My Website Section (full-width, outside main container)
if ( ! empty( $source_medium_traffic ) && is_array( $source_medium_traffic ) ) :
	$get_traffic_source_label = function( $source, $medium ) {
		$source_lower = strtolower( trim( $source ) );
		$medium_lower = strtolower( trim( $medium ) );
		if ( '(direct)' === $source_lower || '(none)' === $source_lower ) {
			return __( 'Direct', 'google-analytics-premium' );
		}
		if ( 'organic' === $medium_lower || in_array( $source_lower, array( 'google', 'bing', 'yahoo', 'duckduckgo' ), true ) ) {
			return __( 'Organic Search', 'google-analytics-premium' );
		}
		if ( 'referral' === $medium_lower ) {
			return __( 'Referral', 'google-analytics-premium' );
		}
		$social_sources = array( 'facebook', 'twitter', 'instagram', 'linkedin', 'pinterest', 'youtube', 'tiktok', 't.co', 'reddit' );
		foreach ( $social_sources as $social ) {
			if ( false !== strpos( $source_lower, $social ) ) {
				return __( 'Social Media', 'google-analytics-premium' );
			}
		}
		return ucfirst( $source );
	};
	$traffic_bg_style = $icons_url ? ' background-image: url(' . esc_url( $icons_url . 'traffic-background.jpg' ) . '); background-repeat: no-repeat; background-size: 100% 100%; background-position: center center;' : '';
?>
</td></tr></table>
<!--[if mso]></td></tr></table><![endif]-->
<!-- How is Traffic Finding My Website Section (background image, max-width 700px) -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" align="center" style="margin-bottom: 0; width: 100%; margin-left: auto; margin-right: auto;">
	<tr>
		<td width="100%" style="width: 100%; padding: 30px 0;<?php echo esc_attr( $traffic_bg_style ); ?>">
			<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" align="center" style="width: 100%; margin: 0 auto;">
				<tr>
					<td class="mset-section-padding-xl" width="100%" style="padding: 10px 16px; width: 100%;">
						<!-- White card container -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border-radius: 8px;">
							<tr>
								<td style="padding: 10px 20px;">
						<?php if ( $icons_url ) : ?>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 8px;">
							<tr>
								<td width="40" height="40" align="center" valign="middle" bgcolor="#E3F0FD" style="background-color: #E3F0FD; background-image: linear-gradient(#E3F0FD, #E3F0FD); border-radius: 50%; width: 40px; height: 40px;">
									<img src="<?php echo esc_url( $icons_url . 'globe.png' ); ?>" alt="" width="24" height="24" style="display: block; margin: 0 auto; border: 0;" />
								</td>
							</tr>
						</table>
						<?php endif; ?>
						<h2 style="margin: 0; padding-bottom: 1em; font-family: Arial, Helvetica, sans-serif; font-size: 24px; font-weight: 700; color: #393E4B;">
							<?php esc_html_e( 'How is Traffic Finding My Website', 'google-analytics-premium' ); ?>
						</h2>
						<p style="margin: 5px 0 0 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600; color: #393E4B;">
							<?php esc_html_e( 'Traffic', 'google-analytics-premium' ); ?>
						</p>
						<p style="margin: 5px 0 0 0; font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #84868A;">
							<?php esc_html_e( 'Default Channels • Traffic (W/W Comparison)', 'google-analytics-premium' ); ?>
						</p>
								</td>
							</tr>
							<tr>
								<td style="padding: 10px 20px;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
										<tr>
											<td style="padding: 12px 15px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; color: #23262E;">
												<?php esc_html_e( 'Source', 'google-analytics-premium' ); ?>
											</td>
											<td align="right" style="padding: 12px 15px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; color: #23262E;">
												<?php esc_html_e( 'Sessions', 'google-analytics-premium' ); ?>
											</td>
										</tr>
										<?php
										$displayed = 0;
										$max_sources = 8;
										foreach ( $source_medium_traffic as $item ) :
											if ( $displayed >= $max_sources ) {
												break;
											}
											$label = $get_traffic_source_label( $item['source'], isset( $item['medium'] ) ? $item['medium'] : '' );
											$sessions = isset( $item['sessions'] ) ? (int) $item['sessions'] : 0;
											$sessions_formatted = number_format( $sessions );
											$displayed++;
										?>
										<tr>
											<td style="padding: 12px 15px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #23262E;">
												<?php echo esc_html( $displayed . '. ' . $label ); ?>
											</td>
											<td align="right" style="padding: 12px 15px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 700; color: #111827;">
												<?php echo esc_html( $sessions_formatted ); ?>
											</td>
										</tr>
										<?php endforeach; ?>
									</table>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="700"><tr><td><![endif]-->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="mset-content-max" bgcolor="#F5FAFF" style="width: 100%; margin: 0 auto; background-color: #F5FAFF; background-image: linear-gradient(#F5FAFF, #F5FAFF);">
	<tr>
		<td class="mset-section-padding" width="100%" style="padding: 50px 0 20px 0; border-radius: 4px; width: 100%;">
<?php endif; ?>

<?php
// Device Breakdown Section
if ( ! empty( $device_breakdown ) && is_array( $device_breakdown ) ) :
?>

<!-- Device Breakdown Section (mobile-first) -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 14px; width: 100%; margin-left: auto; margin-right: auto;">
	<tr>
		<td class="mset-section-padding" style="padding: 0;">
			<!-- Section Header -->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 4px 4px 0 0;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<h2 style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #393E4B;">
							<?php esc_html_e('Device Breakdown', 'google-analytics-premium'); ?>
						</h2>
						<p style="margin: 5px 0 0 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #84868A;">
							<?php esc_html_e('Compared to last week', 'google-analytics-premium'); ?>
						</p>
					</td>
				</tr>
			</table>
			
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 0 0 4px 4px;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<?php foreach ( $device_breakdown as $device ) :
							if ( isset( $device['percentage_change'] ) && (float) $device['percentage_change'] === 0.0 ) {
								continue;
							}
							$device_icon = 'desktop.png';
							if ( stripos( $device['device'], 'mobile' ) !== false ) {
								$device_icon = 'mobile.png';
							} elseif ( stripos( $device['device'], 'tablet' ) !== false ) {
								$device_icon = 'tablet.png';
							}
							$device_increased = ! empty( $device['increased'] );
						?>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border: 1px solid #E3F0FD; border-radius: 8px; margin-bottom: 14px;">
							<tr>
								<td style="padding: 15px;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
										<tr>
											<td width="55" style="vertical-align: middle;">
												<?php if ( $icons_url ) : ?>
												<img src="<?php echo esc_url( $icons_url . $device_icon ); ?>" alt="" width="40" height="40" style="display: block; border: 0;" />
												<?php endif; ?>
											</td>
											<td style="vertical-align: middle;">
												<p style="margin: 0 0 4px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #393F4C;">
													<?php echo esc_html( ucfirst( $device['device'] ) ); ?>
												</p>
												<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 24px; font-weight: 700; color: #393F4C;">
													<?php echo esc_html( $device['percentage'] ); ?>
												</p>
											</td>
											<td align="right" style="vertical-align: middle;">
												<table role="presentation" cellpadding="0" cellspacing="0" border="0">
													<tr>
														<td bgcolor="<?php echo esc_attr( $get_trend_bg( $device_increased ) ); ?>" style="padding: 4px 8px; <?php echo esc_attr( $get_trend_bg_style( $device_increased ) ); ?> border-radius: 100px;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: <?php echo esc_attr( $get_trend_color( $device_increased ) ); ?>;">
																<?php echo number_format( (float) $device['percentage_change'], 1 ); ?>%
															</span>
															<?php if ( $icons_url ) : ?>
															<img src="<?php echo esc_url( $icons_url . ( $device_increased ? 'up.png' : 'down.png' ) ); ?>" alt="" width="12" height="12" style="display: inline; vertical-align: middle; margin-left: 4px; border: 0;" />
															<?php endif; ?>
														</td>
													</tr>
													<tr>
														<td style="padding-top: 4px;">
															<span style="font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #84868A;">
																<?php esc_html_e('vs previous week', 'google-analytics-premium'); ?>
															</span>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<?php endif; ?>

<?php
// Top 5 Pages Section (full-width, outside main container)
$page_colors = array( '#1319D5', '#3A41FF', '#6F3AFF', '#933AFF', '#B33AFF' );
if ( ! empty( $top_pages ) && is_array( $top_pages ) ) :
?>
</td></tr></table>
<!--[if mso]></td></tr></table><![endif]-->
<!-- Top 5 Pages Section (background #338EEF, max-width 700px) -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#338EEF" style="background-color: #338EEF; background-image: linear-gradient(#338EEF, #338EEF); width: 100%; margin: 0 auto;">
	<tr>
		<td width="100%" style="padding: 30px 0; width: 100%;">
			<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" align="center" style="width: 100%; margin: 0 auto;">
				<tr>
					<td class="mset-section-padding-xl" width="100%" style="padding: 0 16px; width: 100%;">
						<!-- Section Header -->
						<h2 style="margin: 0 0 15px 0; font-family: Arial, Helvetica, sans-serif; font-size: 24px; font-weight: 700; color: #ffffff;">
							<?php esc_html_e('Your Top 5 Pages', 'google-analytics-premium'); ?>
						</h2>
						
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
							<tr>
								<td width="100%" style="vertical-align: top; width: 100%;">
									<!-- Pages List (border-radius 8px per test .mset-top-pages-list-variant, page numbers border-radius 50%) -->
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border: 1px solid #E3F0FD; border-radius: 8px;">
										<tr>
											<td style="padding: 15px 20px;">
												<!-- Header Row -->
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; padding-bottom: 12px; margin-bottom: 4px; border-bottom: 1px solid #E3F0FD;">
													<tr>
														<td style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; color: #6B7280;">
															<?php esc_html_e('Page', 'google-analytics-premium'); ?>
														</td>
														<td align="right" style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; color: #6B7280;">
															<?php esc_html_e('Views', 'google-analytics-premium'); ?>
														</td>
													</tr>
												</table>
												
												<?php foreach ( $top_pages as $index => $page_item ) :
													$page_title = ! empty( $page_item['page_title'] ) ? $page_item['page_title'] : $page_item['page_path'];
													$page_title_display = $mset_strlen( $page_title ) > 30 ? $mset_substr( $page_title, 0, 30 ) . '...' : $page_title;
													$page_color = isset( $page_colors[ $index ] ) ? $page_colors[ $index ] : '#B33AFF';
												?>
												<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; padding: 12px 0; <?php echo $index < count( $top_pages ) - 1 ? 'border-bottom: 1px solid #E3F0FD;' : ''; ?>">
													<tr>
														<td width="34" style="vertical-align: middle;">
															<span style="display: inline-block; width: 24px; height: 24px; text-align: center; line-height: 24px; font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: 600; color: #ffffff; background-color: <?php echo esc_attr( $page_color ); ?>; background-image: linear-gradient(<?php echo esc_attr( $page_color ); ?>, <?php echo esc_attr( $page_color ); ?>); border-radius: 50%;">
																<?php echo esc_html( $index + 1 ); ?>
															</span>
														</td>
														<td style="vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #23262E;">
															<?php echo esc_html( $page_title_display ); ?>
														</td>
														<td align="right" style="vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600; color: #393F4C;">
															<?php echo esc_html( $page_item['page_views'] ); ?>
														</td>
													</tr>
												</table>
												<?php endforeach; ?>
											</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
						
						<!-- CTA Button (full width on mobile) -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; margin-top: 20px; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border-radius: 8px;">
							<tr>
								<td align="center" width="100%" bgcolor="#ffffff" style="width: 100%; padding: 0; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border-radius: 8px;">
									<a href="<?php echo esc_url( admin_url('admin.php?page=monsterinsights_reports#publishers-pages') ); ?>" target="_blank" class="mset-btn-full mset-cta-report" style="display: block; width: 100% !important; box-sizing: border-box; text-align: center; padding: 14px 24px; background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #338EEF; text-decoration: none;">
										<?php esc_html_e('View Top Pages Report', 'google-analytics-premium'); ?>
										<?php if ( $icons_url ) : ?>
										<img src="<?php echo esc_url( $icons_url . 'arrow-right-blue.png' ); ?>" alt="" width="16" height="16" style="display: inline; vertical-align: middle; margin-left: 8px; border: 0;" />
										<?php endif; ?>
									</a>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="700"><tr><td><![endif]-->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="mset-content-max" bgcolor="#F5FAFF" style="width: 100%; margin: 0 auto; background-color: #F5FAFF; background-image: linear-gradient(#F5FAFF, #F5FAFF);">
	<tr>
		<td class="mset-section-padding" width="100%" style="padding: 50px 0 20px 0; border-radius: 4px; width: 100%;">
<?php endif; ?>

<?php
// Blog Posts Section
if ( ! empty( $blog_posts ) && is_array( $blog_posts ) ) :
	// Helper function to add UTM parameters
	$add_utm_params = function( $url ) {
		if ( empty( $url ) ) {
			return $url;
		}
		$utm_params = array(
			'utm_source'   => 'proplugin',
			'utm_medium'   => 'weekly-plugin-email',
			'utm_campaign' => 'weekly-plugin-email',
		);
		$parsed_url = wp_parse_url( $url );
		$query_params = array();
		if ( ! empty( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query_params );
		}
		$query_params = array_merge( $query_params, $utm_params );
		$scheme   = ! empty( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
		$host     = ! empty( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$path     = ! empty( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$port     = ! empty( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$fragment = ! empty( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';
		$new_url = $scheme . $host . $port . $path;
		if ( ! empty( $query_params ) ) {
			$new_url .= '?' . http_build_query( $query_params );
		}
		if ( ! empty( $fragment ) ) {
			$new_url .= $fragment;
		}
		return $new_url;
	};
	
	$blog_posts_url_with_utm = $add_utm_params( $blog_posts_url );
?>

<!-- Blog Posts Section (mobile-first) -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 20px 0; width: 100%; margin-left: auto; margin-right: auto;">
	<tr>
		<td class="mset-section-padding" style="padding: 0;">
			<!-- Section Header -->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 4px 4px 0 0;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<h2 style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 20px; font-weight: 700; color: #393E4B;">
							<?php esc_html_e("What's New at MonsterInsights", 'google-analytics-premium'); ?>
						</h2>
					</td>
				</tr>
			</table>
			
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; background-image: linear-gradient(#f5faff, #f5faff); border-radius: 0 0 4px 4px;">
				<tr>
					<td class="mset-section-padding-lg" style="padding: 10px 20px;">
						<?php foreach ( $blog_posts as $blog_post ) :
							$post_link_with_utm = ! empty( $blog_post['link'] ) ? $add_utm_params( $blog_post['link'] ) : '';
						?>
						<!-- Each post: image row, then h4 row, then paragraph row (stacked vertically) -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; padding-bottom: 20px; margin-bottom: 20px;">
							<tr>
								<td width="100%" style="width: 100%; vertical-align: top; padding-bottom: 10px;">
									<?php if ( ! empty( $blog_post['featured_image'] ) ) : ?>
									<img src="<?php echo esc_url( $blog_post['featured_image'] ); ?>" alt="<?php echo esc_attr( $blog_post['title'] ); ?>" width="100%" style="display: block; width: 100%; max-width: 100%; height: auto; border: 0;" />
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td style="vertical-align: top; padding-bottom: 8px;">
									<h4 style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: 600; line-height: 24px; color: #111827;">
										<a href="<?php echo esc_url( $post_link_with_utm ); ?>" target="_blank" style="color: #111827; text-decoration: none;">
											<?php echo esc_html( $blog_post['title'] ); ?>
										</a>
									</h4>
								</td>
							</tr>
							<?php if ( ! empty( $blog_post['excerpt'] ) ) : ?>
							<tr>
								<td style="vertical-align: top;">
									<p style="margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 20px; color: #393F4C;">
										<?php echo esc_html( $blog_post['excerpt'] ); ?>
									</p>
								</td>
							</tr>
							<?php endif; ?>
						</table>
						<?php endforeach; ?>
						
						<!-- CTA Button (full width on mobile) -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
							<tr>
								<td align="center" width="100%" style="width: 100%; padding: 0;">
									<a href="<?php echo esc_url( $blog_posts_url_with_utm ); ?>" target="_blank" class="mset-btn-full mset-cta-report" style="display: block; width: 100% !important; box-sizing: border-box; text-align: center; padding: 14px 24px; background-color: #338EEF; background-image: linear-gradient(#338EEF, #338EEF); border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 500; color: #ffffff; text-decoration: none;">
										<?php esc_html_e('Read More Posts', 'google-analytics-premium'); ?>
										<?php if ( $icons_url ) : ?>
										<img src="<?php echo esc_url( $icons_url . 'arrow-right.png' ); ?>" alt="" width="16" height="16" style="display: inline; vertical-align: middle; margin-left: 8px; border: 0;" />
										<?php endif; ?>
									</a>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<?php endif; ?>
