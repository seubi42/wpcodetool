<?php
/**
 * Email Footer Template
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

// Ensure all required variables have defaults
$icons_url = isset( $icons_url ) ? $icons_url : '';
$settings_tab_url = isset( $settings_tab_url ) ? $settings_tab_url : esc_url( admin_url( 'admin.php?page=monsterinsights_settings#/advanced' ) );
?>

					</td>
				</tr>
			</table>
			<!--[if mso]></td></tr></table><![endif]-->

		</td>
	</tr>
</table>


<!-- Footer Section -->
<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#EFF2FF" style="background-color: #EFF2FF; background-image: linear-gradient(#EFF2FF, #EFF2FF); width: 100%; margin: 0 auto;">
	<tr>
		<td align="center" style="padding: 30px 0;">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%;">
				<tr>
					<td align="center" class="mset-section-padding-xl" style="padding: 0 16px;">
						
						<!-- Logo (margin-bottom 20px per test .mset-footer-logo) -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
							<tr>
								<td align="center">
									<?php if ( $icons_url ) : ?>
									<img src="<?php echo esc_url( $icons_url . 'footer-logo.png' ); ?>" alt="MonsterInsights" width="200" style="display: block; max-width: 200px; height: auto; border: 0;" />
									<?php else : ?>
									<span style="font-family: Arial, Helvetica, sans-serif; font-size: 24px; font-weight: 700; color: #338EEF;">MonsterInsights</span>
									<?php endif; ?>
								</td>
							</tr>
						</table>
						
						<!-- Divider -->
						<table class="mset-content-max" role="presentation" cellpadding="0" cellspacing="0" border="0" width="400" style="max-width: 400px; margin: 20px auto;">
							<tr>
								<td style="height: 1px; background-color: #D4D8DE; background-image: linear-gradient(#D4D8DE, #D4D8DE); font-size: 1px; line-height: 1px;">&nbsp;</td>
							</tr>
						</table>
						
						<!-- Social Icons -->
						<?php if ( $icons_url ) : ?>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">
							<tr>
								<td style="padding: 0 20px;">
									<a href="https://www.facebook.com/monsterinsights" target="_blank" style="text-decoration: none;">
										<img src="<?php echo esc_url( $icons_url . 'facebook.png' ); ?>" alt="Facebook" width="40" height="40" style="display: block; width: 100%; height: auto; border: 0;" />
									</a>
								</td>
								<td style="padding: 0 20px;">
									<a href="https://x.com/monsterinsights" target="_blank" style="text-decoration: none;">
										<img src="<?php echo esc_url( $icons_url . 'twitter.png' ); ?>" alt="Twitter" width="40" height="40" style="display: block; width: 100%; height: auto; border: 0;" />
									</a>
								</td>
								<td style="padding: 0 20px;">
									<a href="https://www.youtube.com/monsterinsights" target="_blank" style="text-decoration: none;">
										<img src="<?php echo esc_url( $icons_url . 'youtube.png' ); ?>" alt="YouTube" width="40" height="40" style="display: block; width: 100%; height: auto; border: 0;" />
									</a>
								</td>
								<td style="padding: 0 20px;">
									<a href="https://www.linkedin.com/company/monsterinsights" target="_blank" style="text-decoration: none;">
										<img src="<?php echo esc_url( $icons_url . 'linkedin.png' ); ?>" alt="LinkedIn" width="40" height="40" style="display: block; width: 100%; height: auto; border: 0;" />
									</a>
								</td>
							</tr>
						</table>
						<?php endif; ?>
						
						<!-- Footer Text -->
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top: 20px;">
							<tr>
								<td align="center" style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 400; line-height: 20px; color: #8C95A8; text-align: center;">
									<?php
									if ( ! empty( $settings_tab_url ) ) :
										$footer = sprintf(
											/* translators: %1$s: Opening link tag, %2$s: Closing link tag */
											esc_html__('This email was auto-generated and sent from MonsterInsights. Learn how to %1$sdisable%2$s it.', 'google-analytics-premium' ),
											'<a href="' . esc_url( $settings_tab_url ) . '" target="_blank" style="color: #8C95A8; text-decoration: underline;">',
											'</a>'
										);
										echo apply_filters( 'mi_email_summaries_footer_text', $footer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									endif;
									?>
								</td>
							</tr>
						</table>
						
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>

</body>
</html>
