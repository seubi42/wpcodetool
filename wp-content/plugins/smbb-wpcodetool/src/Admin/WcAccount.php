<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Page « Mon compte » de WooCommerce aux couleurs du thème CodeTool (réglage CodeTool › Apparence).
 *
 * WooCommerce construit cette page avec les mêmes blocs quel que soit le thème
 * (.woocommerce-MyAccount-navigation / -content, une classe par onglet) : on l'habille en CSS
 * (assets/wc-account.css, tout sous body.smbb-wc-account) et on remplace le texte du tableau de
 * bord par des tuiles. Les autres onglets gardent leur contenu, seule la présentation change.
 *
 * Icônes des onglets : filtre `smbb_codetool_account_icons` (clé d'onglet => code Dashicons).
 * Tuiles en plus sur le tableau de bord : action `smbb_codetool_account_dashboard`.
 */
final class WcAccount
{
    /** Icônes par défaut (WooCommerce + onglets Tomo), en codes Dashicons. */
    const ICONS = array(
        'dashboard' => 'f226',
        'orders' => 'f174',
        'downloads' => 'f316',
        'edit-address' => 'f230',
        'payment-methods' => 'f18e',
        'edit-account' => 'f110',
        'customer-logout' => 'f14a',
        'mes-ateliers' => 'f508',
        'participants-favoris' => 'f487',
        'factures' => 'f497',
        'passflow-portal-host' => 'f307',
    );

    const FALLBACK_ICON = 'f345';

    public static function hooks()
    {
        add_filter('body_class', array(__CLASS__, 'bodyClass'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        // Avant le texte de WooCommerce : nos tuiles d'abord, le texte d'origine est masqué en CSS.
        add_action('woocommerce_account_dashboard', array(__CLASS__, 'dashboard'), 5);
        // Page « Commande reçue » : même habillage, avec une carte « Merci » animée en tête.
        add_action('woocommerce_before_thankyou', array(__CLASS__, 'thanks'), 5);
    }

    public static function enabled()
    {
        $settings = Skin::settings();

        return !empty($settings['modern']) && !empty($settings['wc_account']);
    }

    /**
     * Réglage à part (CodeTool › Apparence › Côté site) : « Commande reçue » sans forcément « Mon compte ».
     */
    public static function thanksEnabled()
    {
        $settings = Skin::settings();

        return !empty($settings['modern']) && !empty($settings['wc_thanks']);
    }

    private static function onPage()
    {
        return self::enabled() && function_exists('is_account_page') && is_account_page();
    }

    /**
     * Page « Commande reçue » (remerciement après paiement), habillée comme « Mon compte ».
     */
    private static function onThanks()
    {
        return self::thanksEnabled() && function_exists('is_order_received_page') && is_order_received_page();
    }

    private static function onDashboard()
    {
        if (!is_user_logged_in() || !function_exists('WC') || !WC()->query) {
            return false;
        }

        return WC()->query->get_current_endpoint() === '';
    }

    public static function bodyClass($classes)
    {
        if (self::onThanks()) {
            $classes[] = 'smbb-wc-account';
            $classes[] = 'smbb-wc-thanks';
        } elseif (self::onPage()) {
            $classes[] = 'smbb-wc-account';

            if (self::onDashboard()) {
                $classes[] = 'smbb-wc-dash';
            }
        }

        return $classes;
    }

    public static function enqueue()
    {
        $thanks = self::onThanks();

        if (!$thanks && !self::onPage()) {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style('smbb-wpcodetool-wc-account', SMBB_WPCODETOOL_URL . 'assets/wc-account.css', array('dashicons'), self::version());

        // Couleurs du thème du site, posées sur la page (le front n'a pas body.smbb-skin).
        $vars = str_replace('body.smbb-skin,body.smbb-login', 'body.smbb-wc-account', Skin::css());
        wp_add_inline_style('smbb-wpcodetool-wc-account', $vars . self::iconsCss());

        if ($thanks) {
            return;
        }

        // Mes ateliers (Tomo) : liste épurée + fiche détaillée.
        wp_enqueue_script('smbb-wpcodetool-wc-account', SMBB_WPCODETOOL_URL . 'assets/wc-account.js', array(), self::version('assets/wc-account.js'), true);
        wp_localize_script('smbb-wpcodetool-wc-account', 'smbbWcAccount', array(
            'close' => __('Fermer', 'smbb-wpcodetool'),
            'participant' => __('Participant', 'smbb-wpcodetool'),
            'status' => __('Statut', 'smbb-wpcodetool'),
            'order' => __('Commande', 'smbb-wpcodetool'),
            'ticket' => __('Mon billet', 'smbb-wpcodetool'),
            'documents' => __('Documents', 'smbb-wpcodetool'),
            'pdf' => __('Télécharger le billet (PDF)', 'smbb-wpcodetool'),
            'ics' => __('Ajouter à mon agenda', 'smbb-wpcodetool'),
        ));
    }

    /**
     * Une icône par onglet, y compris ceux ajoutés par les extensions (Tomo…).
     */
    private static function iconsCss()
    {
        $icons = (array) apply_filters('smbb_codetool_account_icons', self::ICONS);
        $keys = function_exists('wc_get_account_menu_items') ? array_keys(wc_get_account_menu_items()) : array_keys($icons);
        $css = '';

        foreach ($keys as $key) {
            $code = isset($icons[$key]) ? $icons[$key] : self::FALLBACK_ICON;
            $code = preg_replace('/[^0-9a-f]/i', '', (string) $code);
            $class = sanitize_html_class($key);

            if ($code === '' || $class === '') {
                continue;
            }

            $css .= 'body.smbb-wc-account .woocommerce-MyAccount-navigation-link--' . $class . ' a::before,'
                . 'body.smbb-wc-account .smbb-acc-tile.is-' . $class . ' .smbb-acc-ic::before{content:"\\' . $code . '"}';
        }

        return $css;
    }

    /**
     * Tableau de bord : bonjour, dernière commande, raccourcis vers chaque onglet.
     */
    public static function dashboard()
    {
        if (!self::enabled()) {
            return;
        }

        $user = wp_get_current_user();

        if (!$user || !$user->exists()) {
            return;
        }

        $name = $user->first_name !== '' ? $user->first_name : $user->display_name;
        $items = wc_get_account_menu_items();
        $orders = function_exists('wc_get_orders') ? wc_get_orders(array('customer_id' => $user->ID, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC')) : array();
        $order = $orders ? $orders[0] : null;
        ?>
        <div class="smbb-acc-dash">
            <section class="smbb-acc-card smbb-acc-hello">
                <?php echo get_avatar($user->ID, 64, '', '', array('class' => 'smbb-acc-avatar')); ?>
                <div class="smbb-acc-hello-text">
                    <h2><?php echo esc_html(sprintf(__('Bonjour %s', 'smbb-wpcodetool'), $name)); ?></h2>
                    <p><?php echo esc_html($user->user_email); ?></p>
                </div>
                <?php if (isset($items['edit-account'])) : ?>
                    <a class="smbb-acc-btn is-ghost" href="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>"><?php esc_html_e('Modifier mes informations', 'smbb-wpcodetool'); ?></a>
                <?php endif; ?>
            </section>

            <section class="smbb-acc-card smbb-acc-last">
                <h3><?php esc_html_e('Dernière commande', 'smbb-wpcodetool'); ?></h3>
                <?php if ($order) : ?>
                    <div class="smbb-acc-order">
                        <div>
                            <strong><?php echo esc_html(sprintf(__('Commande n°%s', 'smbb-wpcodetool'), $order->get_order_number())); ?></strong>
                            <span class="smbb-acc-muted"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></span>
                        </div>
                        <span class="smbb-acc-status is-<?php echo esc_attr(sanitize_html_class($order->get_status())); ?>"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span>
                        <span class="smbb-acc-total"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
                        <a class="smbb-acc-btn" href="<?php echo esc_url($order->get_view_order_url()); ?>"><?php esc_html_e('Voir', 'smbb-wpcodetool'); ?></a>
                    </div>
                <?php else : ?>
                    <p class="smbb-acc-muted"><?php esc_html_e('Aucune commande pour l\'instant.', 'smbb-wpcodetool'); ?></p>
                    <?php if (wc_get_page_id('shop') > 0) : ?>
                        <a class="smbb-acc-btn" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Découvrir la boutique', 'smbb-wpcodetool'); ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php do_action('smbb_codetool_account_dashboard', $user); ?>

            <nav class="smbb-acc-tiles" aria-label="<?php esc_attr_e('Raccourcis', 'smbb-wpcodetool'); ?>">
                <?php foreach ($items as $key => $label) : ?>
                    <?php
                    if ($key === 'dashboard' || $key === 'customer-logout') {
                        continue;
                    }
                    ?>
                    <a class="smbb-acc-tile is-<?php echo esc_attr(sanitize_html_class($key)); ?>" href="<?php echo esc_url(wc_get_account_endpoint_url($key)); ?>">
                        <span class="smbb-acc-ic" aria-hidden="true"></span>
                        <span><?php echo esc_html(wp_strip_all_tags($label)); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    /**
     * Carte « Merci pour votre commande » en tête de la page Commande reçue : pouce animé,
     * prénom, e-mail de confirmation, statut et raccourcis. Le texte d'origine de WooCommerce
     * est masqué en CSS ; le récapitulatif (n°, date, total…) reste dessous, en carte.
     *
     * Boutons : filtre `smbb_codetool_thanks_actions` (liste de [label, url, ghost]).
     *
     * @param int $order_id
     */
    public static function thanks($order_id)
    {
        if (!self::onThanks()) {
            return;
        }

        $order = $order_id ? wc_get_order($order_id) : null;

        if (!$order || $order->has_status('failed')) {
            return;
        }

        $name = trim((string) $order->get_billing_first_name());
        $email = (string) $order->get_billing_email();
        $status = $order->get_status();
        $paid = $order->is_paid();

        if ($paid) {
            $lead = $name !== ''
                ? sprintf(__('Merci %s, c\'est tout bon : votre paiement est bien reçu.', 'smbb-wpcodetool'), $name)
                : __('C\'est tout bon : votre paiement est bien reçu.', 'smbb-wpcodetool');
        } else {
            $lead = $name !== ''
                ? sprintf(__('Merci %s, votre commande est bien enregistrée.', 'smbb-wpcodetool'), $name)
                : __('Votre commande est bien enregistrée.', 'smbb-wpcodetool');
        }

        $actions = array();

        if (is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id()) {
            $items = wc_get_account_menu_items();

            foreach (array('mes-ateliers', 'orders') as $key) {
                if (isset($items[$key])) {
                    $actions[] = array(wp_strip_all_tags($items[$key]), wc_get_account_endpoint_url($key), !empty($actions));
                }
            }
        }

        if (wc_get_page_id('shop') > 0) {
            $actions[] = array(__('Continuer mes achats', 'smbb-wpcodetool'), wc_get_page_permalink('shop'), true);
        }

        $actions = (array) apply_filters('smbb_codetool_thanks_actions', $actions, $order);
        ?>
        <section class="smbb-ty-hero" role="status">
            <div class="smbb-ty-badge" aria-hidden="true">
                <span class="smbb-ty-ring"></span>
                <?php for ($i = 1; $i <= 8; $i++) : ?>
                    <span class="smbb-ty-spark" style="--i:<?php echo (int) $i; ?>"></span>
                <?php endfor; ?>
                <span class="smbb-ty-disc">
                    <svg class="smbb-ty-thumb" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>
                </span>
            </div>
            <div class="smbb-ty-text">
                <p class="smbb-ty-kicker">
                    <span><?php echo esc_html(sprintf(__('Commande n°%s', 'smbb-wpcodetool'), $order->get_order_number())); ?></span>
                    <span class="smbb-acc-status is-<?php echo esc_attr(sanitize_html_class($status)); ?>"><?php echo esc_html(wc_get_order_status_name($status)); ?></span>
                </p>
                <h2><?php esc_html_e('Merci pour votre commande !', 'smbb-wpcodetool'); ?></h2>
                <p class="smbb-ty-lead"><?php echo esc_html($lead); ?></p>
                <?php if ($email !== '') : ?>
                    <p class="smbb-ty-mail">
                        <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                        <span><?php echo wp_kses(sprintf(__('Un e-mail de confirmation part à %s.', 'smbb-wpcodetool'), '<strong>' . esc_html($email) . '</strong>'), array('strong' => array())); ?></span>
                    </p>
                <?php endif; ?>
                <?php if ($actions) : ?>
                    <div class="smbb-ty-actions">
                        <?php foreach ($actions as $action) : ?>
                            <?php
                            if (!is_array($action) || empty($action[0]) || empty($action[1])) {
                                continue;
                            }
                            ?>
                            <a class="smbb-acc-btn<?php echo !empty($action[2]) ? ' is-ghost' : ''; ?>" href="<?php echo esc_url($action[1]); ?>"><?php echo esc_html($action[0]); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private static function version($file = 'assets/wc-account.css')
    {
        $path = SMBB_WPCODETOOL_PATH . $file;

        return SMBB_WPCODETOOL_VERSION . (file_exists($path) ? '.' . filemtime($path) : '');
    }
}
