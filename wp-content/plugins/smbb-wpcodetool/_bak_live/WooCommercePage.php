<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Woo\FakeCard;

defined('ABSPATH') || exit;

/**
 * Page « WooCommerce » des réglages CodeTool : outils WooCommerce pour le développement.
 * Premier réglage : le moyen de paiement par carte fictif (voir Woo\FakeCard).
 */
final class WooCommercePage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-woocommerce';
    const NONCE = 'smbb_codetool_woocommerce';

    public static function handle()
    {
        if (!isset($_POST['smbb_codetool_woocommerce_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_codetool_woocommerce_nonce'])), self::NONCE)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier les réglages WooCommerce de CodeTool.', 'smbb-wpcodetool'));
        }

        $read = static function ($key) {
            return isset($_POST[$key]) ? sanitize_key(wp_unslash($_POST[$key])) : '';
        };

        FakeCard::save(array(
            'fake_card' => $read('fake_card') === '1',
            'fake_card_for' => $read('fake_card_for'),
            'admins_only' => $read('admins_only') === '1',
            'scenario' => $read('scenario'),
            'auto3ds' => $read('auto3ds') === '1',
        ));

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&saved=1'));
        exit;
    }

    public function render()
    {
        $manager = $this->manager();
        $settings = FakeCard::settings();
        $woo = class_exists('WooCommerce');
        $on = FakeCard::enabled();
        $checked = !empty($settings['fake_card']) && !FakeCard::expired();
        $production = function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production';
        $notices = array();

        if (isset($_GET['saved'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $notices[] = '<div class="smbb-codetool-notice is-success"><p class="smbb-codetool-notice-message">' . esc_html__('Réglages WooCommerce enregistrés.', 'smbb-wpcodetool') . '</p></div>';
        }

        if (!$woo) {
            $notices[] = '<div class="smbb-codetool-notice is-info"><p class="smbb-codetool-notice-message">' . esc_html__('WooCommerce n\'est pas actif : ces réglages seront pris en compte dès qu\'il le sera.', 'smbb-wpcodetool') . '</p></div>';
        }

        if (FakeCard::expired()) {
            $notices[] = '<div class="smbb-codetool-notice is-info"><p class="smbb-codetool-notice-message">' . esc_html__('La carte fictive s\'est coupée toute seule au bout de la durée choisie. Enregistrez pour la relancer.', 'smbb-wpcodetool') . '</p></div>';
        }
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
                __('WooCommerce', 'smbb-wpcodetool'),
                __('Outils WooCommerce pour développer et tester une boutique sans vrai paiement.', 'smbb-wpcodetool'),
                'dashicons-cart',
                $notices
            );
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">
                <?php wp_nonce_field(self::NONCE, 'smbb_codetool_woocommerce_nonce'); ?>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-money-alt"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Paiement', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Pour vos tests uniquement : rien n\'est jamais encaissé.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php AppearancePage::toggle('fake_card', $checked, __('Moyen de paiement fictif', 'smbb-wpcodetool'), __('Ajoute « Carte bancaire (TEST) » au paiement : formulaire de carte façon Stripe rempli de numéros aléatoires, 3D Secure simulé dans une fenêtre, puis la commande passe « en cours » comme avec une vraie carte (stock, e-mails, hooks).', 'smbb-wpcodetool')); ?>

                        <div data-smbb-fake-card-options<?php echo $checked ? '' : ' hidden'; ?>>
                            <?php if ($production) : ?>
                                <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('Ce site se déclare en production (WP_ENVIRONMENT_TYPE vaut « production », valeur par défaut de WordPress). Si c\'est un vrai site, n\'activez la carte fictive que le temps d\'un test, avec « Réservé aux administrateurs ».', 'smbb-wpcodetool'); ?></p>
                            <?php endif; ?>

                            <?php if ($on) : ?>
                                <p class="smbb-codetool-encryption-state is-good"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php echo esc_html(sprintf(__('Active, %s.', 'smbb-wpcodetool'), FakeCard::untilLabel())); ?></p>
                            <?php endif; ?>

                            <p>
                                <label class="smbb-codetool-field-label" for="smbb-fake-card-for"><?php esc_html_e('Couper automatiquement après', 'smbb-wpcodetool'); ?></label><br>
                                <select id="smbb-fake-card-for" name="fake_card_for">
                                    <?php foreach (FakeCard::durations() as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['fake_card_for'], $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="description"><?php esc_html_e('Compté à partir de l\'enregistrement : on ne l\'oublie pas allumée.', 'smbb-wpcodetool'); ?></span>
                            </p>

                            <?php AppearancePage::toggle('admins_only', $settings['admins_only'], __('Réservé aux administrateurs', 'smbb-wpcodetool'), __('Seuls les comptes qui gèrent WooCommerce voient la carte fictive au paiement. Décoché : tout visiteur la voit (pratique pour tester en client déconnecté).', 'smbb-wpcodetool')); ?>

                            <p>
                                <label class="smbb-codetool-field-label" for="smbb-fake-card-scenario"><?php esc_html_e('Scénario proposé par défaut', 'smbb-wpcodetool'); ?></label><br>
                                <select id="smbb-fake-card-scenario" name="scenario">
                                    <?php foreach (FakeCard::scenarios() as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['scenario'], $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="description"><?php esc_html_e('Modifiable à chaque paiement dans le formulaire de carte.', 'smbb-wpcodetool'); ?></span>
                            </p>

                            <?php AppearancePage::toggle('auto3ds', $settings['auto3ds'], __('Valider le 3D Secure tout seul', 'smbb-wpcodetool'), __('La fenêtre 3D Secure s\'anime puis se valide sans clic : pratique pour enchaîner les commandes.', 'smbb-wpcodetool')); ?>

                            <?php if ($woo) : ?>
                                <p><a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . FakeCard::GATEWAY_ID)); ?>"><?php esc_html_e('Titre et description dans WooCommerce › Réglages › Paiements', 'smbb-wpcodetool'); ?></a></p>
                            <?php endif; ?>
                        </div>
                        <script>
                            (function () {
                                var box = document.getElementById('smbb-appearance-fake_card');
                                var options = document.querySelector('[data-smbb-fake-card-options]');
                                if (!box || !options) { return; }
                                box.addEventListener('change', function () { options.hidden = !box.checked; });
                            })();
                        </script>
                    </div>
                </section>

                <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button></p>
            </form>
        </div>
        <?php
    }
}
