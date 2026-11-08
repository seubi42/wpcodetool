<?php

namespace Smbb\WpCodeTool\Woo;

defined('ABSPATH') || exit;

/**
 * Moyen de paiement par carte FICTIF (réglage CodeTool › WooCommerce).
 *
 * Outil de développement : une passerelle WooCommerce « Carte bancaire (TEST) » avec un formulaire
 * de carte embarqué façon Stripe (numéros aléatoires valides), une simulation de 3D Secure dans une
 * lightbox, puis la commande passe « en cours » comme après un vrai paiement. Rien n'est encaissé.
 *
 * Garde-fous : coupure automatique après une durée choisie, bandeau rouge sur toutes les pages
 * d'admin avec un bouton « Désactiver maintenant », pastille rouge dans la barre d'admin, libellé
 * « TEST » imposé au moyen de paiement, note sur chaque commande, option « admins seulement ».
 *
 * Marche avec le tunnel classique (shortcode), la page « Payer la commande » et le bloc Commande.
 */
final class FakeCard
{
    const OPTION = 'smbb_codetool_woocommerce';
    const GATEWAY_ID = 'smbb_fake_card';
    const GATEWAY_OPTION = 'woocommerce_smbb_fake_card_settings';
    const OFF_ACTION = 'smbb_fake_card_off';

    /** Durées de mise en service proposées : clé => secondes (0 = sans limite). */
    const DURATIONS = array(
        '1h' => 3600,
        '1d' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
        'never' => 0,
    );

    public static function hooks()
    {
        add_action('before_woocommerce_init', array(__CLASS__, 'declareCompatibility'));
        add_action('admin_post_' . self::OFF_ACTION, array(__CLASS__, 'handleOff'));

        if (!self::enabled()) {
            return;
        }

        add_filter('woocommerce_payment_gateways', array(__CLASS__, 'registerGateway'));
        add_action('woocommerce_blocks_payment_method_type_registration', array(__CLASS__, 'registerBlocks'));
        add_action('init', array(__CLASS__, 'registerAssets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueueClassic'));
        add_action('admin_notices', array(__CLASS__, 'adminNotice'), 1);
        add_action('admin_bar_menu', array(__CLASS__, 'adminBarNode'), 5);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'adminBarStyle'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'adminBarStyle'));
    }

    /**
     * Réglages enregistrés, complétés par les valeurs par défaut.
     *
     * @return array{fake_card:int,fake_card_for:string,fake_card_until:int,admins_only:int,scenario:string,auto3ds:int}
     */
    public static function settings()
    {
        $saved = get_option(self::OPTION, array());

        return array_merge(array(
            'fake_card' => 0,
            'fake_card_for' => '7d',
            'fake_card_until' => 0,
            'admins_only' => 0,
            'scenario' => '3ds',
            'auto3ds' => 0,
        ), is_array($saved) ? $saved : array());
    }

    /**
     * Coché et pas encore arrivé au bout de sa durée.
     */
    public static function enabled()
    {
        $settings = self::settings();

        if (empty($settings['fake_card'])) {
            return false;
        }

        return (int) $settings['fake_card_until'] === 0 || (int) $settings['fake_card_until'] > time();
    }

    /**
     * Coché mais la durée est passée : la page de réglages le signale.
     */
    public static function expired()
    {
        $settings = self::settings();

        return !empty($settings['fake_card']) && (int) $settings['fake_card_until'] > 0 && (int) $settings['fake_card_until'] <= time();
    }

    /**
     * Enregistre les réglages et met le moyen de paiement WooCommerce dans le même état.
     */
    public static function save(array $values)
    {
        $current = self::settings();
        $durations = self::DURATIONS;
        $for = isset($values['fake_card_for'], $durations[$values['fake_card_for']]) ? $values['fake_card_for'] : $current['fake_card_for'];
        $on = !empty($values['fake_card']);
        $scenarios = self::scenarios();

        $settings = array(
            'fake_card' => $on ? 1 : 0,
            'fake_card_for' => $for,
            // La durée repart de l'enregistrement.
            'fake_card_until' => $on && $durations[$for] > 0 ? time() + $durations[$for] : 0,
            'admins_only' => !empty($values['admins_only']) ? 1 : 0,
            'scenario' => isset($values['scenario'], $scenarios[$values['scenario']]) ? $values['scenario'] : '3ds',
            'auto3ds' => !empty($values['auto3ds']) ? 1 : 0,
        );

        update_option(self::OPTION, $settings, false);
        self::syncGateway($on);

        return $settings;
    }

    /**
     * Coche / décoche aussi « Activer » dans WooCommerce › Réglages › Paiements.
     */
    private static function syncGateway($on)
    {
        $gateway = get_option(self::GATEWAY_OPTION, array());
        $gateway = is_array($gateway) ? $gateway : array();
        $gateway['enabled'] = $on ? 'yes' : 'no';
        update_option(self::GATEWAY_OPTION, $gateway);
    }

    /**
     * Scénarios de test : clé => libellé.
     */
    public static function scenarios()
    {
        return array(
            '3ds' => __('3D Secure demandé puis accepté', 'smbb-wpcodetool'),
            'frictionless' => __('Accepté sans 3D Secure', 'smbb-wpcodetool'),
            '3ds_fail' => __('3D Secure refusé par la banque', 'smbb-wpcodetool'),
            'declined' => __('Carte refusée', 'smbb-wpcodetool'),
            'funds' => __('Fonds insuffisants', 'smbb-wpcodetool'),
        );
    }

    /**
     * Scénarios qui passent par la lightbox 3D Secure.
     */
    public static function needs3ds($scenario)
    {
        return in_array($scenario, array('3ds', '3ds_fail'), true);
    }

    /**
     * Visible pour le visiteur courant (option « administrateurs seulement »).
     */
    public static function visibleForCurrentUser()
    {
        $settings = self::settings();

        return empty($settings['admins_only']) || current_user_can('manage_woocommerce');
    }

    public static function durations()
    {
        return array(
            '1h' => __('1 heure', 'smbb-wpcodetool'),
            '1d' => __('1 jour', 'smbb-wpcodetool'),
            '7d' => __('7 jours', 'smbb-wpcodetool'),
            '30d' => __('30 jours', 'smbb-wpcodetool'),
            'never' => __('Sans limite (déconseillé)', 'smbb-wpcodetool'),
        );
    }

    /**
     * « jusqu'au 14/11 à 09:30 » ou « sans limite ».
     */
    public static function untilLabel()
    {
        $settings = self::settings();
        $until = (int) $settings['fake_card_until'];

        if ($until === 0) {
            return __('sans limite de durée', 'smbb-wpcodetool');
        }

        return sprintf(
            /* translators: %s: date et heure */
            __('coupure automatique le %s', 'smbb-wpcodetool'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $until)
        );
    }

    public static function declareCompatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', SMBB_WPCODETOOL_FILE, true);
        }
    }

    public static function registerGateway($gateways)
    {
        if (class_exists('WC_Payment_Gateway')) {
            $gateways[] = FakeCardGateway::class;
        }

        return $gateways;
    }

    public static function registerBlocks($registry)
    {
        if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            $registry->register(new FakeCardBlocks());
        }
    }

    /**
     * Le script commun (formulaire, numéros aléatoires, lightbox 3D Secure) et celui du bloc Commande.
     */
    public static function registerAssets()
    {
        if (wp_script_is('smbb-fake-card', 'registered')) {
            return;
        }

        $version = static function ($file) {
            $path = SMBB_WPCODETOOL_PATH . 'assets/' . $file;

            return is_readable($path) ? SMBB_WPCODETOOL_VERSION . '.' . filemtime($path) : SMBB_WPCODETOOL_VERSION;
        };

        wp_register_style('smbb-fake-card', SMBB_WPCODETOOL_URL . 'assets/fake-card.css', array(), $version('fake-card.css'));
        wp_register_script('smbb-fake-card', SMBB_WPCODETOOL_URL . 'assets/fake-card.js', array('jquery'), $version('fake-card.js'), true);
        wp_localize_script('smbb-fake-card', 'smbbFakeCard', self::scriptData());
        wp_register_script(
            'smbb-fake-card-blocks',
            SMBB_WPCODETOOL_URL . 'assets/fake-card-blocks.js',
            array('smbb-fake-card', 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            $version('fake-card-blocks.js'),
            true
        );
    }

    /**
     * Données passées au JS (window.smbbFakeCard).
     */
    public static function scriptData()
    {
        $settings = self::settings();

        return array(
            'scenario' => $settings['scenario'],
            'auto3ds' => (int) $settings['auto3ds'],
            'merchant' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'scenarios' => self::scenarios(),
            'i18n' => array(
                'number' => __('Numéro de carte', 'smbb-wpcodetool'),
                'expiry' => __('Expiration', 'smbb-wpcodetool'),
                'cvc' => __('CVC', 'smbb-wpcodetool'),
                'scenario' => __('Scénario', 'smbb-wpcodetool'),
                'shuffle' => __('Autre carte', 'smbb-wpcodetool'),
                'ribbon' => __('Mode test : aucune carte n\'est débitée', 'smbb-wpcodetool'),
                'badNumber' => __('Le numéro de carte n\'est pas valide.', 'smbb-wpcodetool'),
                'badExpiry' => __('La date d\'expiration n\'est pas valide.', 'smbb-wpcodetool'),
                'badCvc' => __('Le code de sécurité n\'est pas valide.', 'smbb-wpcodetool'),
                'cancelled' => __('Authentification 3D Secure annulée. Vous pouvez réessayer.', 'smbb-wpcodetool'),
                'connecting' => __('Connexion sécurisée à votre banque…', 'smbb-wpcodetool'),
                'bank' => __('Banque Démo', 'smbb-wpcodetool'),
                'confirmTitle' => __('Confirmez votre paiement', 'smbb-wpcodetool'),
                /* translators: 1: montant, 2: boutique, 3: 4 derniers chiffres */
                'confirmText' => __('Paiement de %1$s chez %2$s avec la carte •••• %3$s.', 'smbb-wpcodetool'),
                'smsText' => __('Saisissez le code reçu par SMS au •• •• •• •• 42.', 'smbb-wpcodetool'),
                'code' => __('Code de vérification', 'smbb-wpcodetool'),
                'confirm' => __('Valider', 'smbb-wpcodetool'),
                'checking' => __('Vérification…', 'smbb-wpcodetool'),
                'cancel' => __('Annuler', 'smbb-wpcodetool'),
                'simulateFail' => __('Simuler un refus', 'smbb-wpcodetool'),
                'success' => __('Paiement authentifié', 'smbb-wpcodetool'),
                'failure' => __('Authentification refusée par votre banque', 'smbb-wpcodetool'),
                'testNote' => __('Simulation CodeTool : aucune banque n\'est contactée.', 'smbb-wpcodetool'),
                'resend' => __('Renvoyer le code', 'smbb-wpcodetool'),
            ),
        );
    }

    /**
     * Tunnel classique et page « Payer la commande » : CSS + JS seulement là.
     */
    public static function enqueueClassic()
    {
        if (!function_exists('is_checkout') || !is_checkout() || !self::visibleForCurrentUser()) {
            return;
        }

        self::registerAssets();
        wp_enqueue_style('smbb-fake-card');
        wp_enqueue_script('smbb-fake-card');
    }

    /**
     * Bandeau rouge sur toutes les pages d'admin, impossible à fermer tant que c'est actif.
     */
    public static function adminNotice()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $production = function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production';
        ?>
        <div class="notice notice-error smbb-fake-card-notice" data-smbb-bell data-smbb-bell-flag="card" data-smbb-bell-flag-title="<?php esc_attr_e('Paiement par carte FICTIF actif', 'smbb-wpcodetool'); ?>" style="border-left-width:6px">
            <p>
                <strong><?php esc_html_e('Paiement par carte FICTIF actif.', 'smbb-wpcodetool'); ?></strong>
                <?php esc_html_e('Les commandes payées avec « Carte bancaire (TEST) » passent en cours sans aucun encaissement.', 'smbb-wpcodetool'); ?>
                <?php echo esc_html(ucfirst(self::untilLabel()) . '.'); ?>
                <?php if ($production) : ?>
                    <br><strong><?php esc_html_e('Ce site se déclare en production (WP_ENVIRONMENT_TYPE) : à ne laisser que le temps d\'un test.', 'smbb-wpcodetool'); ?></strong>
                <?php endif; ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(self::offUrl()); ?>"><?php esc_html_e('Désactiver maintenant', 'smbb-wpcodetool'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=smbb-wpcodetool-woocommerce')); ?>"><?php esc_html_e('Réglages', 'smbb-wpcodetool'); ?></a>
            </p>
        </div>
        <?php
    }

    public static function adminBarNode($bar)
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // En admin avec la cloche des messages : la petite CB rouge clignote dans la cloche,
        // qui déplie le bandeau d'explication. Pas de bloc à part dans la barre.
        if (is_admin() && \Smbb\WpCodeTool\Admin\WpSkin::bellOn()) {
            return;
        }

        // Ailleurs (site, ou cloche coupée) : la même CB rouge clignotante, seule, avec son menu.
        $bar->add_node(array(
            'id' => 'smbb-fake-card',
            'parent' => 'top-secondary',
            'title' => '<span class="smbb-cb-flag" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Paiement par carte FICTIF actif', 'smbb-wpcodetool') . '</span>',
            'href' => admin_url('admin.php?page=smbb-wpcodetool-woocommerce'),
            'meta' => array('title' => __('Carte bancaire fictive active : rien n\'est encaissé.', 'smbb-wpcodetool')),
        ));
        $bar->add_node(array(
            'parent' => 'smbb-fake-card',
            'id' => 'smbb-fake-card-info',
            'title' => esc_html__('Paiement par carte FICTIF actif : rien n\'est encaissé', 'smbb-wpcodetool'),
            'href' => admin_url('admin.php?page=smbb-wpcodetool-woocommerce'),
        ));
        $bar->add_node(array(
            'parent' => 'smbb-fake-card',
            'id' => 'smbb-fake-card-off',
            'title' => esc_html__('Désactiver maintenant', 'smbb-wpcodetool'),
            'href' => self::offUrl(),
        ));
    }

    public static function adminBarStyle()
    {
        if (!is_admin_bar_showing() || !current_user_can('manage_woocommerce')) {
            return;
        }

        // Petite carte bancaire rouge qui clignote (barre d'admin seule, ou dans la cloche des messages).
        wp_add_inline_style('admin-bar', '#wpadminbar .smbb-cb-flag{display:inline-block;position:relative;width:20px;height:14px;margin:0 2px;border-radius:3px;'
            . 'background:linear-gradient(#d63638 0 3px,#3c0d0e 3px 6px,#d63638 6px);box-shadow:0 0 0 0 rgba(214,54,56,.6);vertical-align:middle;'
            . 'animation:smbb-cb-blink 1.2s ease-in-out infinite}'
            . '#wpadminbar .smbb-cb-flag:after{content:"";position:absolute;left:3px;bottom:2px;width:6px;height:2px;border-radius:1px;background:#ffd5d5}'
            . '@keyframes smbb-cb-blink{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(214,54,56,.6)}50%{opacity:.35;box-shadow:0 0 0 4px rgba(214,54,56,0)}}'
            . '@media (prefers-reduced-motion:reduce){#wpadminbar .smbb-cb-flag{animation:none}}'
            . '#wpadminbar #wp-admin-bar-smbb-notices .smbb-cb-flag{margin:0 6px 0 0}');
    }

    public static function offUrl()
    {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::OFF_ACTION), self::OFF_ACTION);
    }

    /**
     * « Désactiver maintenant » (bandeau, barre d'admin).
     */
    public static function handleOff()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier les moyens de paiement.', 'smbb-wpcodetool'));
        }

        check_admin_referer(self::OFF_ACTION);

        $settings = self::settings();
        $settings['fake_card'] = 0;
        $settings['fake_card_until'] = 0;
        update_option(self::OPTION, $settings, false);
        self::syncGateway(false);

        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : admin_url('admin.php?page=smbb-wpcodetool-woocommerce'));
        exit;
    }

    /**
     * Contrôle de Luhn.
     */
    public static function luhn($digits)
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
            $double = !$double;
        }

        return $digits !== '' && $sum % 10 === 0;
    }

    public static function brand($digits)
    {
        if (preg_match('/^3[47]/', $digits)) {
            return 'amex';
        }

        if (preg_match('/^(5[1-5]|2[2-7])/', $digits)) {
            return 'mastercard';
        }

        if (strpos($digits, '4') === 0) {
            return 'visa';
        }

        return 'card';
    }

    public static function brandLabel($brand)
    {
        $labels = array('visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express');

        return isset($labels[$brand]) ? $labels[$brand] : __('Carte', 'smbb-wpcodetool');
    }
}
