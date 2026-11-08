<?php
/**
 * Email Header Template
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

$mail_text_direction = is_rtl() ? 'rtl' : 'ltr';

// Ensure all required variables have defaults
$icons_url = isset( $icons_url ) ? $icons_url : '';
$assets_url = isset( $assets_url ) ? $assets_url : '';
$start_date = isset( $start_date ) ? $start_date : '';
$end_date = isset( $end_date ) ? $end_date : '';
$site_url = isset( $site_url ) ? $site_url : get_site_url();
// Ensure header_image is always an array (may be passed as URL string by filters).
if ( ! isset( $header_image ) ) {
	$header_image = array();
} elseif ( ! is_array( $header_image ) ) {
	$header_image = ( is_string( $header_image ) && $header_image !== '' ) ? array( 'url' => $header_image ) : array();
}
$logo_link = isset( $logo_link ) ? $logo_link : 'https://www.monsterinsights.com/';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html dir="<?php echo esc_attr( $mail_text_direction ); ?>" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="x-apple-disable-message-reformatting">
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<meta name="color-scheme" content="light only">
	<meta name="supported-color-schemes" content="light only">
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	<!--[if mso]>
	<noscript>
		<xml>
			<o:OfficeDocumentSettings>
				<o:AllowPNG/>
				<o:PixelsPerInch>96</o:PixelsPerInch>
			</o:OfficeDocumentSettings>
		</xml>
	</noscript>
	<![endif]-->
	<style type="text/css">
		:root { color-scheme: light only; }
		/* Reset styles */
		body, table, td, p, a, li, blockquote { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; box-sizing: border-box; }
		table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
		img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; max-width: 100%; }
		body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
		a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }

		/* Mobile-first: base = small screens (full-width buttons, stacked layout, compact padding) */
		.mset-btn-full { display: block !important; width: 100% !important; max-width: 100% !important; text-align: center !important; box-sizing: border-box !important; }
		.mset-cta-report { display: block !important; width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; text-align: center !important; }
		.mset-section-padding { padding-left: 16px !important; padding-right: 16px !important; }
		.mset-section-padding-lg { padding: 10px 20px !important; text-align: left !important; }
		.mset-header-inner { padding-top: 20px !important; padding-bottom: 20px !important; }
		.mset-stat-col { display: block !important; width: 100% !important; }
		.mset-stat-col-2-3 { display: table-cell !important; width: 50% !important; }
		.mset-form-col { display: table-cell !important; width: 104px !important; min-width: 104px !important; }
		.mset-form-col-half { display: table-cell !important; width: 50% !important; }
		.mset-content-max { width: 100% !important; max-width: 700px !important; margin: 0 auto !important; }
		.mset-section-padding-xl { padding-left: 16px !important; padding-right: 16px !important; }

		/* Tablet and up: buttons auto width, more padding */
		@media only screen and (min-width: 481px) {
			.mset-btn-full { display: inline-block !important; width: auto !important; max-width: none !important; }
			.mset-section-padding { padding-left: 20px !important; padding-right: 20px !important; }
			.mset-section-padding-lg { padding: 10px 24px !important; }
			.mset-header-inner { padding-left: 24px !important; padding-right: 24px !important; }
			.mset-section-padding-xl { padding-left: 30px !important; padding-right: 30px !important; }
		}

		/* Desktop: 3-column stats, full section padding */
		@media only screen and (min-width: 600px) {
			.mset-section-padding { padding-left: 30px !important; padding-right: 30px !important; }
			.mset-section-padding-lg { padding: 10px 30px !important; }
			.mset-header-inner { padding-left: 30px !important; padding-right: 30px !important; }
			.mset-stat-col { display: table-cell !important; width: 33.333% !important; }
			.mset-stat-col-2-3 { width: 33.333% !important; }
			.mset-form-col { width: 33.333% !important; min-width: 0 !important; }
			.mset-form-col-half { width: 50% !important; }
			.mset-section-padding-xl { padding-left: 50px !important; padding-right: 50px !important; }
		}

		/* Analytics Stats: 2 cols mobile (duplicate table), 3 cols desktop */
		.mset-stats-mobile { display: table !important; width: 100% !important; }
		.mset-stats-desktop { display: none !important; }
		@media only screen and (min-width: 600px) {
			.mset-stats-mobile { display: none !important; }
			.mset-stats-desktop { display: table !important; width: 100% !important; }
		}

		/* Categories + Post Types: stacked on mobile, 2 cols side-by-side on desktop */
		.mset-categories-posttypes-col { display: block !important; width: 100% !important; }
		@media only screen and (min-width: 600px) {
			.mset-categories-posttypes-col { display: table-cell !important; width: 50% !important; vertical-align: top !important; }
			.mset-categories-posttypes-col-first { padding-right: 7px !important; }
			.mset-categories-posttypes-col-second { padding-left: 7px !important; }
		}

		/* Store Performance: stacked on mobile, 2 cols on desktop (3 items + 2 items) */
		.mset-store-col { display: block !important; width: 100% !important; }
		@media only screen and (min-width: 600px) {
			.mset-store-col { display: table-cell !important; width: 50% !important; vertical-align: top !important; }
			.mset-store-col-first { padding-right: 7px !important; }
			.mset-store-col-second { padding-left: 7px !important; }
		}

		/* Mobile overrides (max-width) for hide/stack */
		@media only screen and (max-width: 680px) {
			.mobile-full-width { width: 100% !important; max-width: 100% !important; }
			.mobile-padding { padding-left: 16px !important; padding-right: 16px !important; }
			.mobile-hide { display: none !important; }
			.mobile-stack { display: block !important; width: 100% !important; }
		}
	</style>
</head>
<body bgcolor="#F5FAFF" style="margin: 0; padding: 0; width: 100%; background-color: #F5FAFF; background-image: linear-gradient(#F5FAFF, #F5FAFF); font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">

<!-- Wrapper Table -->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#F5FAFF" style="width: 100%; background-color: #F5FAFF; background-image: linear-gradient(#F5FAFF, #F5FAFF);">
	<tr>
		<td align="center" style="padding: 0; width: 100%;">

			<!-- Logo Section -->
			<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="700" bgcolor="#F5FAFF" style="width: 700px;"><tr><td><![endif]-->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#F5FAFF" style="width: 100%; background-color: #F5FAFF; background-image: linear-gradient(#F5FAFF, #F5FAFF);">
				<tr>
					<td align="center" bgcolor="#F5FAFF" style="padding: 30px 20px 20px 20px; background-color: #F5FAFF; background-image: linear-gradient(#F5FAFF, #F5FAFF);">
						<a href="<?php echo esc_url( is_string( $logo_link ) ? $logo_link : 'https://www.monsterinsights.com/' ); ?>" target="_blank" style="text-decoration: none;">
							<?php if ( $icons_url ) : ?>
							<img src="<?php echo esc_url( $icons_url . 'footer-logo.png' ); ?>" alt="<?php echo esc_attr__( 'MonsterInsights', 'google-analytics-premium' ); ?>" width="200" style="display: block; max-width: 200px; height: auto; border: 0;" />
							<?php else : ?>
							<span style="font-family: Arial, Helvetica, sans-serif; font-size: 24px; font-weight: 700; color: #338EEF;">MonsterInsights</span>
							<?php endif; ?>
						</a>
					</td>
				</tr>
			</table>
			<!--[if mso]></td></tr></table><![endif]-->

			<!-- Header Section with Background -->
			<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ffffff" style="background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); width: 100%; margin: 0 auto; height: 152px;">
				<tr>
					<td align="center" style="padding: 0;<?php if ( $icons_url ) : ?> background-image: url('<?php echo esc_url( $icons_url . 'weekly-stats.png' ); ?>'); background-repeat: no-repeat; background-size: cover; background-position: center;<?php endif; ?>" <?php if ( $icons_url ) : ?>background="<?php echo esc_url( $icons_url . 'weekly-stats.png' ); ?>"<?php endif; ?>>
						<!--[if gte mso 9]>
						<v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false" style="width:700px;height:200px;">
						<?php if ( $icons_url ) : ?>
						<v:fill type="frame" src="<?php echo esc_url( $icons_url . 'weekly-stats.png' ); ?>" color="#ffffff" />
						<?php endif; ?>
						<v:textbox inset="0,0,0,0">
						<![endif]-->
						<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; margin: 0 auto;">
							<tr>
								<td class="mset-section-padding mset-section-padding-lg mset-header-inner" style="padding: 20px 20px; text-align: left;">
									<!-- Title -->
									<h1 style="margin: 0 0 20px 0; font-family: Arial, Helvetica, sans-serif; font-size: 24px; font-weight: 600; line-height: 32px; color: #111827; text-align: left;">
										<?php esc_html_e('Weekly Website Stats', 'google-analytics-premium'); ?>
									</h1>
									
									<?php if ( ! empty( $start_date ) && ! empty( $end_date ) && is_string( $start_date ) && is_string( $end_date ) ) : ?>
									<!-- Date -->
									<table role="presentation" cellpadding="0" cellspacing="0" border="0">
										<tr>
											<td style="vertical-align: middle; padding-right: 8px;">
												<?php if ( $icons_url ) : ?>
												<img src="<?php echo esc_url( $icons_url . 'calendar.png' ); ?>" alt="Calendar" width="16" height="16" style="display: block; border: 0;" />
												<?php endif; ?>
											</td>
											<td style="vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #111827;">
												<?php echo esc_html( $start_date ); ?> - <?php echo esc_html( $end_date ); ?>
											</td>
										</tr>
									</table>
									<?php endif; ?>
									
									<!-- Site URL -->
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top: 15px;">
										<tr>
											<td style="vertical-align: middle; padding-right: 8px;">
												<?php if ( $icons_url ) : ?>
												<img src="<?php echo esc_url( $icons_url . 'globe-bw.png' ); ?>" alt="Globe" width="16" height="16" style="display: block; border: 0;" />
												<?php endif; ?>
											</td>
											<td style="vertical-align: middle;">
												<?php 
												$site_url_display = ! empty( $site_url ) ? $site_url : get_site_url();
												if ( ! preg_match( '/^https?:\/\//', $site_url_display ) ) {
													$site_url_display = 'https://' . $site_url_display;
												}
												?>
												<a href="<?php echo esc_url( $site_url_display ); ?>" target="_blank" style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #338EEF; text-decoration: none;">
													<?php echo esc_html( $site_url_display ); ?>
												</a>
											</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
						<!--[if gte mso 9]>
						</v:textbox>
						</v:rect>
						<![endif]-->
					</td>
				</tr>
			</table>

			<!-- Main Content Container (wrapper padding 50px 0, mobile-first section padding) -->
			<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="700"><tr><td><![endif]-->
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="mset-content-max" bgcolor="#F5FAFF" style="width: 100%; margin: 0 auto; background-color: #F5FAFF; background-image: linear-gradient(#F5FAFF, #F5FAFF);">
				<tr>
					<td class="mset-section-padding" width="100%" style="padding: 50px 0 20px 0; border-radius: 4px; width: 100%;">
