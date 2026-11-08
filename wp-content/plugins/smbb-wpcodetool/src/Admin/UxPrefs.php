<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * « Mes préférences d'affichage » : widget de l'accueil de l'admin où chacun règle, pour lui seul,
 * le mode (clair / sombre / auto), la palette, la densité, le style du menu et les animations.
 * Les choix vont dans les mêmes métas utilisateur que le profil (Skin::USER_*), plus une nouvelle :
 * smbb_codetool_motion = « reduce » (animations et transitions coupées pour cet utilisateur).
 *
 * Pour les administrateurs, le widget (et CodeTool › Apparence) porte aussi le « bouton magique » :
 * il coche d'un coup toutes les optimisations UX de CodeTool pour tout le site. Le réglage d'avant
 * est gardé (option smbb_codetool_appearance_backup) et se rétablit d'un clic.
 *
 * Filtres : `smbb_codetool_prefs_widget` (false = pas de widget), `smbb_codetool_magic_switches`
 * (liste des interrupteurs cochés par le bouton magique).
 */
final class UxPrefs
{
    const USER_MOTION = 'smbb_codetool_motion';
    const BACKUP = 'smbb_codetool_appearance_backup';
    const WIDGET = 'smbb_codetool_prefs';
    const NONCE = 'smbb_codetool_prefs';
    const MAGIC_NONCE = 'smbb_codetool_magic';

    /**
     * Optimisations UX cochées par le bouton magique (interrupteurs de Skin::SWITCHES).
     * Restent à part, parce que ce n'est pas de l'UX ou que ça se décide au cas par cas :
     * marque blanche, favicon, barre du haut allégée, accueil sans widgets WordPress,
     * commentaires coupés, « Mon compte » côté clients, polices Porsche / Geist.
     */
    const MAGIC = array(
        'modern',
        'icons',
        'wp_chrome',
        'wp_screens',
        'wp_notices',
        'wp_profile',
        'wp_media',
        'list_ux',
        'list_columns',
        'palette',
        'mode_toggle',
        'login',
        'fx_glass',
        'fx_transitions',
        'fx_toasts',
        'fx_skeleton',
        'fx_prefetch',
    );

    public static function hooks()
    {
        add_action('wp_dashboard_setup', array(__CLASS__, 'register'));
        add_action('wp_ajax_smbb_codetool_prefs', array(__CLASS__, 'save'));
        add_action('admin_post_smbb_codetool_magic', array(__CLASS__, 'magic'));
        add_action('admin_post_smbb_codetool_magic_undo', array(__CLASS__, 'undo'));
        add_filter('admin_body_class', array(__CLASS__, 'bodyClass'), 30);
        add_action('admin_head', array(__CLASS__, 'motionCss'), 1);
        add_action('admin_bar_menu', array(__CLASS__, 'barLink'), 9); // juste après la bascule du mode (8)
    }

    /* ---------------------------------------------------------------- Animations */

    /** L'utilisateur courant a demandé moins d'animations ? */
    public static function reducedMotion()
    {
        return get_user_meta(get_current_user_id(), self::USER_MOTION, true) === 'reduce';
    }

    public static function bodyClass($classes)
    {
        return self::reducedMotion() ? trim($classes . ' smbb-reduce-motion') : $classes;
    }

    public static function motionCss()
    {
        // Toujours posé (scopé sur la classe) : le widget coupe les animations sans recharger.
        echo '<style id="smbb-reduce-motion">body.smbb-reduce-motion *,body.smbb-reduce-motion *::before,body.smbb-reduce-motion *::after'
            . '{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important;transition-delay:0s!important;scroll-behavior:auto!important}</style>' . "\n";
    }

    /** Lien « Mes préférences… » sous la bascule clair / sombre de la barre du haut. */
    public static function barLink($bar)
    {
        if (!is_admin() || !$bar->get_node('smbb-mode') || !self::widgetEnabled()) {
            return;
        }

        $bar->add_node(array(
            'id' => 'smbb-mode-prefs',
            'parent' => 'smbb-mode',
            'title' => esc_html__('Mes préférences…', 'smbb-wpcodetool'),
            'href' => admin_url('index.php#' . self::WIDGET),
            'meta' => array('class' => 'smbb-mode-prefs'),
        ));
    }

    /* ---------------------------------------------------------------- Widget */

    private static function widgetEnabled()
    {
        return (bool) apply_filters('smbb_codetool_prefs_widget', true);
    }

    public static function register()
    {
        if (!self::widgetEnabled()) {
            return;
        }

        wp_add_dashboard_widget(self::WIDGET, __('Mes préférences d\'affichage', 'smbb-wpcodetool'), array(__CLASS__, 'render'), null, null, 'normal', 'high');
        add_action('admin_print_footer_scripts', array(__CLASS__, 'script'));
    }

    public static function render()
    {
        $user_id = get_current_user_id();
        $settings = Skin::settings();
        $themes = Skin::themes();
        unset($themes[Skin::CUSTOM]);

        $theme = (string) get_user_meta($user_id, Skin::USER_META, true);
        $theme = $theme === Skin::CLASSIC || isset($themes[$theme]) ? $theme : '';
        $shown = $theme !== '' ? $theme : Skin::siteTheme();
        $mode = Skin::mode();
        $density = Skin::density();
        $menu = Skin::menuStyle();
        $motion = self::reducedMotion();
        $classic = Skin::current() === Skin::CLASSIC;
        $personal = self::personal($user_id);

        echo '<style>' . self::css() . '</style>'; // phpcs:ignore -- CSS fixe + couleurs du thème.
        ?>
        <div class="smbb-uxp" data-smbb-uxp data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE)); ?>">
            <?php self::notice(); ?>
            <div class="smbb-uxp-row">
                <span class="smbb-uxp-label"><?php esc_html_e('Mon mode', 'smbb-wpcodetool'); ?></span>
                <div class="smbb-uxp-seg" role="group" aria-label="<?php esc_attr_e('Mode d\'affichage', 'smbb-wpcodetool'); ?>">
                    <?php foreach (Skin::modes() as $key => $label) : ?>
                        <button type="button" class="smbb-uxp-opt" data-pref="mode" data-value="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $mode === $key ? 'true' : 'false'; ?>"<?php disabled($classic); ?> title="<?php echo $classic ? esc_attr__('Choisissez une palette pour le mode sombre.', 'smbb-wpcodetool') : ''; ?>">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo WpExtras::MODE_ICONS[$key]; // phpcs:ignore -- SVG fixe. ?></svg>
                            <?php echo esc_html($label); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (\Smbb\WpCodeTool\Live\LiveFeed::relevant()) : ?>
                <?php $live = \Smbb\WpCodeTool\Live\LiveFeed::mode(); ?>
                <div class="smbb-uxp-row">
                    <span class="smbb-uxp-label">
                        <?php esc_html_e('Ventes en direct', 'smbb-wpcodetool'); ?>
                        <?php if ($live !== 'off') : ?>
                            <button type="button" class="button-link smbb-uxp-try" data-smbb-live-demo><?php esc_html_e('Voir un exemple', 'smbb-wpcodetool'); ?></button>
                        <?php endif; ?>
                    </span>
                    <div class="smbb-uxp-seg" role="group" aria-label="<?php esc_attr_e('Ventes en direct', 'smbb-wpcodetool'); ?>">
                        <?php foreach (\Smbb\WpCodeTool\Live\LiveFeed::modes() as $key => $label) : ?>
                            <button type="button" class="smbb-uxp-opt" data-pref="live" data-value="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $live === $key ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="smbb-uxp-row">
                <span class="smbb-uxp-label"><?php esc_html_e('Préférence', 'smbb-wpcodetool'); ?></span>
                <div class="smbb-uxp-seg" role="group" aria-label="<?php esc_attr_e('Préférence', 'smbb-wpcodetool'); ?>">
                    <button type="button" class="smbb-uxp-opt" data-pref="style" data-value="site" aria-pressed="<?php echo $personal ? 'false' : 'true'; ?>" title="<?php esc_attr_e('Suivre l\'apparence choisie pour tout le site.', 'smbb-wpcodetool'); ?>"><?php esc_html_e('Site', 'smbb-wpcodetool'); ?></button>
                    <button type="button" class="smbb-uxp-opt" data-pref="style" data-value="perso" aria-pressed="<?php echo $personal ? 'true' : 'false'; ?>"><?php esc_html_e('Perso', 'smbb-wpcodetool'); ?></button>
                </div>
            </div>

            <div class="smbb-uxp-perso" data-smbb-uxp-perso<?php echo $personal ? '' : ' hidden'; ?>>
                <div class="smbb-uxp-swatches" role="group" aria-label="<?php esc_attr_e('Palette de couleurs', 'smbb-wpcodetool'); ?>">
                    <?php foreach ($themes as $key => $def) : ?>
                        <button type="button" class="smbb-uxp-swatch" data-pref="theme" data-value="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $shown === $key ? 'true' : 'false'; ?>" title="<?php echo esc_attr($def['label']); ?>" style="<?php echo esc_attr(self::swatch($def)); ?>">
                            <span class="screen-reader-text"><?php echo esc_html($def['label']); ?></span>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" class="smbb-uxp-site" data-pref="theme" data-value="<?php echo esc_attr(Skin::CLASSIC); ?>" aria-pressed="<?php echo $shown === Skin::CLASSIC ? 'true' : 'false'; ?>" title="<?php esc_attr_e('Le style WordPress d\'avant.', 'smbb-wpcodetool'); ?>"><?php esc_html_e('Classique', 'smbb-wpcodetool'); ?></button>
                </div>

                <div class="smbb-uxp-row">
                    <span class="smbb-uxp-label"><?php esc_html_e('Densité', 'smbb-wpcodetool'); ?></span>
                    <div class="smbb-uxp-seg" role="group" aria-label="<?php esc_attr_e('Densité', 'smbb-wpcodetool'); ?>">
                        <?php foreach (Skin::densities() as $key => $label) : ?>
                            <button type="button" class="smbb-uxp-opt" data-pref="density" data-value="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $density === $key ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!empty($settings['wp_chrome'])) : ?>
                    <div class="smbb-uxp-row">
                        <label class="smbb-uxp-label" for="smbb-uxp-menu"><?php esc_html_e('Menu de gauche', 'smbb-wpcodetool'); ?></label>
                        <select id="smbb-uxp-menu" data-pref="menu">
                            <?php foreach (Skin::menuStyles() as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($menu, $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="smbb-uxp-row">
                    <label class="smbb-uxp-label" for="smbb-uxp-motion"><?php esc_html_e('Moins d\'animations', 'smbb-wpcodetool'); ?></label>
                    <span class="smbb-uxp-switch">
                        <input type="checkbox" id="smbb-uxp-motion" data-pref="motion" value="reduce" <?php checked($motion); ?>>
                        <span aria-hidden="true"></span>
                    </span>
                </div>
            </div>

            <p class="smbb-uxp-foot">
                <span class="smbb-uxp-status" role="status" aria-live="polite"></span>
                <?php if (current_user_can('manage_options')) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . Pages\AppearancePage::SLUG)); ?>"><?php esc_html_e('Réglages site', 'smbb-wpcodetool'); ?></a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /** L'utilisateur a-t-il son propre style (palette, densité, menu ou animations) ? */
    private static function personal($user_id)
    {
        foreach (array(Skin::USER_META, Skin::USER_MENU, Skin::USER_DENSITY, self::USER_MOTION) as $meta) {
            if ((string) get_user_meta($user_id, $meta, true) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Pastille de couleur (dégradé quand la palette en a un). */
    private static function swatch(array $def)
    {
        $second = !empty($def['accent_2']) ? $def['accent_2'] : $def['accent'];

        return '--sw:linear-gradient(135deg,' . $def['accent'] . ',' . $second . ');';
    }

    /** Enregistre un réglage personnel (AJAX). */
    public static function save()
    {
        check_ajax_referer(self::NONCE);

        $user_id = get_current_user_id();
        $key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
        $value = isset($_POST['value']) ? sanitize_key(wp_unslash($_POST['value'])) : '';
        $reload = true;

        switch ($key) {
            case 'mode':
                if (!isset(Skin::modes()[$value])) {
                    wp_send_json_error(null, 400);
                }
                update_user_meta($user_id, Skin::USER_MODE, $value);
                $reload = false; // appliqué tout de suite par le script
                break;

            case 'theme':
                $themes = Skin::themes();

                if ($value === '') {
                    delete_user_meta($user_id, Skin::USER_META);
                } elseif ($value === Skin::CLASSIC || ($value !== Skin::CUSTOM && isset($themes[$value]))) {
                    update_user_meta($user_id, Skin::USER_META, $value);
                } else {
                    wp_send_json_error(null, 400);
                }
                break;

            case 'density':
                if (!isset(Skin::densities()[$value])) {
                    wp_send_json_error(null, 400);
                }
                update_user_meta($user_id, Skin::USER_DENSITY, $value);
                break;

            case 'menu':
                if (!isset(Skin::menuStyles()[$value])) {
                    wp_send_json_error(null, 400);
                }
                update_user_meta($user_id, Skin::USER_MENU, $value);
                break;

            case 'motion':
                if ($value === 'reduce') {
                    update_user_meta($user_id, self::USER_MOTION, 'reduce');
                } else {
                    delete_user_meta($user_id, self::USER_MOTION);
                }
                $reload = false;
                break;

            case 'live':
                // Ventes en direct (Live\LiveFeed) : avec son / sans son / coupé. En sortant de « coupé »,
                // le script n'est pas encore chargé sur la page : on recharge.
                $was = \Smbb\WpCodeTool\Live\LiveFeed::mode($user_id);
                if (!\Smbb\WpCodeTool\Live\LiveFeed::setMode($value, $user_id)) {
                    wp_send_json_error(null, 400);
                }
                $reload = $was === 'off' && $value !== 'off';
                break;

            case 'style':
                if ($value === 'perso') {
                    // On part de ce que l'on voit déjà : les choix du site deviennent les siens.
                    $settings = Skin::settings();
                    $themes = Skin::themes();

                    if ((string) get_user_meta($user_id, Skin::USER_META, true) === '') {
                        $site = Skin::siteTheme();
                        if ($site === Skin::CLASSIC || ($site !== Skin::CUSTOM && isset($themes[$site]))) {
                            update_user_meta($user_id, Skin::USER_META, $site);
                        }
                    }
                    if ((string) get_user_meta($user_id, Skin::USER_DENSITY, true) === '') {
                        update_user_meta($user_id, Skin::USER_DENSITY, $settings['density']);
                    }
                    $reload = false; // rien ne change à l'écran, le panneau s'ouvre
                } elseif ($value === 'site') {
                    foreach (array(Skin::USER_META, Skin::USER_MENU, Skin::USER_DENSITY, self::USER_MOTION) as $meta) {
                        delete_user_meta($user_id, $meta);
                    }
                } else {
                    wp_send_json_error(null, 400);
                }
                break;

            case 'reset':
                foreach (array(Skin::USER_META, Skin::USER_MODE, Skin::USER_MENU, Skin::USER_DENSITY, self::USER_MOTION) as $meta) {
                    delete_user_meta($user_id, $meta);
                }
                break;

            default:
                wp_send_json_error(null, 400);
        }

        wp_send_json_success(array('reload' => $reload));
    }

    /* ---------------------------------------------------------------- Bouton magique */

    /**
     * Interrupteurs cochés par le bouton magique, avec leur libellé.
     *
     * @return array<string,string>
     */
    public static function magicSwitches()
    {
        $labels = array(
            'modern' => __('Style moderne', 'smbb-wpcodetool'),
            'icons' => __('Icônes dans la barre en haut', 'smbb-wpcodetool'),
            'wp_chrome' => __('Barre du haut et menu de gauche', 'smbb-wpcodetool'),
            'wp_screens' => __('Écrans de WordPress', 'smbb-wpcodetool'),
            'wp_notices' => __('Messages des extensions dans une cloche', 'smbb-wpcodetool'),
            'wp_profile' => __('Page profil rangée', 'smbb-wpcodetool'),
            'wp_media' => __('Médiathèque et éditeur', 'smbb-wpcodetool'),
            'list_ux' => __('Listes plus confortables', 'smbb-wpcodetool'),
            'list_columns' => __('Vignette et ID dans les listes', 'smbb-wpcodetool'),
            'palette' => __('Recherche Ctrl K partout', 'smbb-wpcodetool'),
            'mode_toggle' => __('Bascule clair / sombre', 'smbb-wpcodetool'),
            'login' => __('Page de connexion', 'smbb-wpcodetool'),
            'fx_glass' => __('Verre dépoli', 'smbb-wpcodetool'),
            'fx_transitions' => __('Transitions entre les pages', 'smbb-wpcodetool'),
            'fx_toasts' => __('Confirmations en bulles', 'smbb-wpcodetool'),
            'fx_skeleton' => __('Squelette de chargement', 'smbb-wpcodetool'),
            'fx_prefetch' => __('Pages instantanées', 'smbb-wpcodetool'),
        );
        $keys = (array) apply_filters('smbb_codetool_magic_switches', self::MAGIC);
        $out = array();

        foreach ($keys as $key) {
            if (array_key_exists($key, Skin::SWITCHES)) {
                $out[$key] = isset($labels[$key]) ? $labels[$key] : $key;
            }
        }

        return $out;
    }

    /** Optimisations encore coupées (la barre d'app en haut compte pour une). */
    public static function magicMissing()
    {
        $settings = Skin::settings();
        $missing = array();

        foreach (self::magicSwitches() as $key => $label) {
            if (empty($settings[$key])) {
                $missing[$key] = $label;
            }
        }

        if ($settings['nav'] !== 'top') {
            $missing['nav'] = __('Barre d\'app en haut', 'smbb-wpcodetool');
        }

        return $missing;
    }

    /**
     * Encadré du bouton magique (widget de l'accueil et haut de CodeTool › Apparence).
     * Hors de tout formulaire : il porte le sien.
     */
    public static function magicBox($context = 'widget')
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $all = self::magicSwitches();
        $missing = self::magicMissing();
        $total = count($all) + 1;
        $done = $total - count($missing);
        $backup = get_option(self::BACKUP);
        $back = $context === 'page' ? 'page' : 'widget';
        $confirm = sprintf(
            /* translators: %s: liste des réglages */
            __("Activer pour tout le site :\n\n• %s\n\nLe réglage actuel est gardé : un clic sur « Annuler » le rétablit.", 'smbb-wpcodetool'),
            implode("\n• ", $missing)
        );

        if ($context === 'page') {
            // Page Apparence : le widget n'y est pas, on pose ses styles et le message ici.
            echo '<style>' . self::css() . '</style><div class="smbb-uxp">'; // phpcs:ignore -- CSS fixe + couleurs du thème.
            self::notice();
        }
        ?>
        <div class="smbb-uxp-magic<?php echo $context === 'page' ? ' is-page' : ''; ?>">
            <div class="smbb-uxp-magic-text">
                <strong><?php esc_html_e('Pour tout le site', 'smbb-wpcodetool'); ?></strong>
                <span>
                    <?php
                    echo esc_html($missing
                        ? sprintf(__('%1$d optimisations UX sur %2$d actives. Le bouton magique coche le reste d\'un coup.', 'smbb-wpcodetool'), $done, $total)
                        : __('Toutes les optimisations UX de CodeTool sont actives.', 'smbb-wpcodetool'));
                    ?>
                </span>
                <span class="smbb-uxp-meter" aria-hidden="true"><i style="width:<?php echo esc_attr((string) round($done / $total * 100)); ?>%"></i></span>
            </div>
            <div class="smbb-uxp-magic-actions">
                <?php if ($missing) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode($confirm)); ?>);">
                        <input type="hidden" name="action" value="smbb_codetool_magic">
                        <input type="hidden" name="back" value="<?php echo esc_attr($back); ?>">
                        <?php wp_nonce_field(self::MAGIC_NONCE); ?>
                        <button type="submit" class="button button-primary smbb-uxp-magic-btn">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 4 1 2.5L18.5 7.5 16 8.5 15 11l-1-2.5L11.5 7.5 14 6.5zM6 13l1.5 3.5L11 18l-3.5 1.5L6 23l-1.5-3.5L1 18l3.5-1.5zM19 14l.7 1.8 1.8.7-1.8.7L19 19l-.7-1.8-1.8-.7 1.8-.7z"/></svg>
                            <?php esc_html_e('Tout activer', 'smbb-wpcodetool'); ?>
                        </button>
                    </form>
                <?php endif; ?>
                <?php if (is_array($backup) && isset($backup['value'])) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="smbb_codetool_magic_undo">
                        <input type="hidden" name="back" value="<?php echo esc_attr($back); ?>">
                        <?php wp_nonce_field(self::MAGIC_NONCE); ?>
                        <button type="submit" class="button-link"><?php esc_html_e('Annuler (réglages d\'avant)', 'smbb-wpcodetool'); ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($context !== 'page') : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . Pages\AppearancePage::SLUG)); ?>"><?php esc_html_e('Apparence', 'smbb-wpcodetool'); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        if ($context === 'page') {
            echo '</div>';
        }
    }

    public static function magic()
    {
        check_admin_referer(self::MAGIC_NONCE);

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier l\'apparence de CodeTool.', 'smbb-wpcodetool'));
        }

        $before = get_option(Skin::OPTION, array());
        update_option(self::BACKUP, array(
            'time' => time(),
            'user' => get_current_user_id(),
            'value' => is_array($before) ? $before : array(),
        ), false);

        $settings = Skin::settings();
        $extra = array('nav' => 'top');

        foreach (array_keys(self::magicSwitches()) as $key) {
            $extra[$key] = 1;
        }

        Skin::saveSettings($settings['theme'], $settings['accent'], $extra);

        self::redirect('on');
    }

    public static function undo()
    {
        check_admin_referer(self::MAGIC_NONCE);

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier l\'apparence de CodeTool.', 'smbb-wpcodetool'));
        }

        $backup = get_option(self::BACKUP);

        if (is_array($backup) && isset($backup['value']) && is_array($backup['value'])) {
            update_option(Skin::OPTION, $backup['value'], true);
        }

        delete_option(self::BACKUP);

        self::redirect('undone');
    }

    private static function redirect($state)
    {
        $back = isset($_POST['back']) && $_POST['back'] === 'page' ? 'page' : 'widget'; // phpcs:ignore WordPress.Security.NonceVerification -- vérifié par l'appelant.
        $url = $back === 'page'
            ? admin_url('admin.php?page=' . Pages\AppearancePage::SLUG)
            : admin_url('index.php');

        wp_safe_redirect(add_query_arg('smbb_magic', $state, $url) . ($back === 'page' ? '' : '#' . self::WIDGET));
        exit;
    }

    /** Message après le bouton magique (ou son annulation). */
    public static function notice()
    {
        $state = isset($_GET['smbb_magic']) ? sanitize_key(wp_unslash($_GET['smbb_magic'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ($state === 'on') {
            echo '<p class="smbb-uxp-done">' . esc_html__('Abracadabra : toutes les optimisations UX sont activées.', 'smbb-wpcodetool') . '</p>';
        } elseif ($state === 'undone') {
            echo '<p class="smbb-uxp-done">' . esc_html__('Réglages d\'avant rétablis.', 'smbb-wpcodetool') . '</p>';
        }
    }

    /* ---------------------------------------------------------------- Styles et script */

    /** Styles du widget, posés sur .smbb-uxp avec les couleurs du thème (même quand la peau WP est coupée). */
    public static function css()
    {
        $vars = str_replace(
            array('html.smbb-dark body.smbb-skin,html.smbb-dark body.smbb-login', 'body.smbb-skin,body.smbb-login'),
            array('html.smbb-dark .smbb-uxp', '.smbb-uxp'),
            Skin::css()
        );

        return $vars . <<<'CSS'
.smbb-uxp{--a:var(--smbb-t-accent,#2271b1);--ad:var(--smbb-t-accent-deep,#135e96);--as:var(--smbb-t-accent-soft,#f0f6fc);--ln:var(--smbb-t-line,#dcdcde);--mu:var(--smbb-t-muted,#646970);--fl:var(--smbb-t-fill,#f6f7f7);--tx:var(--smbb-t-text,#1d2327);color:var(--tx)}
.smbb-uxp-intro{margin:0 0 12px;color:var(--mu)}
.smbb-uxp-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:6px 0}
.smbb-uxp-perso{margin:4px 0 0;padding:10px 0 0;border-top:1px solid var(--ln)}
.smbb-uxp-perso[hidden]{display:none}
.smbb-uxp-perso .smbb-uxp-swatches{padding-bottom:4px}
.smbb-uxp-row.is-stacked{flex-direction:column;align-items:stretch}
.smbb-uxp-label{font-weight:600;display:flex;flex-direction:column;gap:2px}
.smbb-uxp-hint{font-weight:400;color:var(--mu);font-size:12px;margin:0}
p.smbb-uxp-hint{margin:-4px 0 8px}
.smbb-uxp-seg{display:inline-flex;padding:2px;border:1px solid var(--ln);border-radius:8px;background:var(--fl)}
.smbb-uxp-opt{display:inline-flex;align-items:center;gap:5px;border:0;background:none;color:var(--mu);padding:4px 10px;border-radius:6px;font:inherit;font-size:12px;cursor:pointer}
.smbb-uxp-opt[aria-pressed="true"]{background:var(--smbb-t-card,#fff);color:var(--tx);box-shadow:0 1px 2px rgba(0,0,0,.08);font-weight:600}
.smbb-uxp-opt:disabled{opacity:.45;cursor:not-allowed}
.smbb-uxp-swatches{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.smbb-uxp-swatch{width:24px;height:24px;border-radius:50%;border:0;padding:0;background:var(--sw);cursor:pointer;box-shadow:inset 0 0 0 1px rgba(0,0,0,.12)}
.smbb-uxp-swatch[aria-pressed="true"],.smbb-uxp-site[aria-pressed="true"]{outline:2px solid var(--a);outline-offset:2px}
.smbb-uxp-site{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 10px;border:1px solid var(--ln);border-radius:6px;background:var(--smbb-t-card,#fff);color:var(--tx);font:inherit;font-size:12px;cursor:pointer}
.smbb-uxp-site i{width:12px;height:12px;border-radius:50%;background:var(--sw)}
.smbb-uxp select{min-width:170px}
.smbb-uxp-switch{position:relative;display:inline-block;width:36px;height:20px;flex:none}
.smbb-uxp-switch input{position:absolute;inset:0;opacity:0;margin:0;width:100%;height:100%;cursor:pointer;z-index:1}
.smbb-uxp-switch span{position:absolute;inset:0;border-radius:20px;background:var(--ln);transition:background .15s}
.smbb-uxp-switch span::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:transform .15s}
.smbb-uxp-switch input:checked+span{background:var(--a)}
.smbb-uxp-switch input:checked+span::after{transform:translateX(16px)}
.smbb-uxp-switch input:focus-visible+span{outline:2px solid var(--a);outline-offset:2px}
.smbb-uxp-foot{display:flex;align-items:center;justify-content:flex-end;gap:14px;margin:6px 0 0;font-size:12px}
.smbb-uxp-foot:not(:has(a)) .smbb-uxp-status:empty{display:none}
.smbb-uxp-foot:has(.smbb-uxp-status:empty):not(:has(a)){display:none}
.smbb-uxp-try{font-size:12px!important;font-weight:400;text-align:left;padding:0!important;color:var(--a)!important}
.smbb-uxp-status{margin-right:auto;color:var(--mu);font-size:12px}
.smbb-uxp-magic{display:flex;flex-direction:column;gap:10px;margin-top:14px;padding:12px;border:1px solid var(--ln);border-radius:10px;background:var(--fl)}
.smbb-uxp-magic.is-page{flex-direction:row;align-items:center;justify-content:space-between;margin:0 0 20px;background:var(--smbb-t-card,#fff)}
.smbb-uxp-magic-text{display:flex;flex-direction:column;gap:4px;min-width:0}
.smbb-uxp-magic-text>span{color:var(--mu);font-size:12px}
.smbb-uxp-meter{display:block;height:4px;border-radius:4px;background:var(--ln);overflow:hidden;max-width:260px}
.smbb-uxp-meter i{display:block;height:100%;background:var(--a)}
.smbb-uxp-magic-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px}
.smbb-uxp-magic-actions form{margin:0}
.smbb-uxp-magic-btn{display:inline-flex!important;align-items:center;gap:6px}
.smbb-uxp-done{margin:0 0 12px;padding:8px 10px;border-radius:8px;background:var(--as);color:var(--ad);font-weight:600}
@media (max-width:600px){.smbb-uxp-magic.is-page{flex-direction:column;align-items:stretch}.smbb-uxp-row{flex-wrap:wrap}}
CSS;
    }

    public static function script()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->id !== 'dashboard') {
            return;
        }

        $i18n = wp_json_encode(array(
            'saving' => __('Enregistrement…', 'smbb-wpcodetool'),
            'saved' => __('Enregistré', 'smbb-wpcodetool'),
            'error' => __('Pas enregistré, réessayez.', 'smbb-wpcodetool'),
        ));
        ?>
        <script>
        (function () {
            var box = document.querySelector('[data-smbb-uxp]');
            if (!box) { return; }
            var t = <?php echo $i18n; // phpcs:ignore -- JSON. ?>;
            var status = box.querySelector('.smbb-uxp-status');
            var root = document.documentElement;
            var q = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

            function applyMode(mode) {
                root.classList.toggle('smbb-dark', mode === 'dark' || (mode === 'auto' && !!q && q.matches));
                document.querySelectorAll('#wp-admin-bar-smbb-mode .smbb-mode-item').forEach(function (li) {
                    var a = li.querySelector('a');
                    li.classList.toggle('is-current', !!a && a.href.indexOf('mode=' + mode) !== -1);
                });
                var cur = document.querySelector('#wp-admin-bar-smbb-mode .smbb-mode-current');
                if (cur && window.smbbMode && window.smbbMode.icons && window.smbbMode.icons[mode]) { cur.innerHTML = window.smbbMode.icons[mode]; }
            }

            function send(key, value) {
                status.textContent = t.saving;
                var body = new FormData();
                body.append('action', 'smbb_codetool_prefs');
                body.append('key', key);
                body.append('value', value);
                body.append('_ajax_nonce', box.getAttribute('data-nonce'));
                return fetch(box.getAttribute('data-ajax'), { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) { throw new Error(); }
                        status.textContent = t.saved;
                        if (res.data && res.data.reload) { window.location.hash = '<?php echo esc_js(self::WIDGET); ?>'; window.location.reload(); }
                    })
                    .catch(function () { status.textContent = t.error; });
            }

            box.addEventListener('click', function (e) {
                if (e.target.closest('[data-smbb-live-demo]')) {
                    if (window.smbbLiveFeed) { window.smbbLiveFeed.demo(); }
                    return;
                }
                var btn = e.target.closest('button[data-pref]');
                if (!btn || btn.disabled) { return; }
                var key = btn.getAttribute('data-pref'), value = btn.getAttribute('data-value');
                box.querySelectorAll('button[data-pref="' + key + '"]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
                if (key === 'mode') { applyMode(value); }
                if (key === 'live' && window.smbbLiveFeed) { window.smbbLiveFeed.setMode(value); }
                if (key === 'style') {
                    var panel = box.querySelector('[data-smbb-uxp-perso]');
                    if (panel) { panel.hidden = value !== 'perso'; }
                }
                send(key, value);
            });

            box.addEventListener('change', function (e) {
                var el = e.target;
                if (!el.matches('[data-pref]')) { return; }
                var key = el.getAttribute('data-pref');
                if (key === 'motion') {
                    document.body.classList.toggle('smbb-reduce-motion', el.checked);
                    send(key, el.checked ? 'reduce' : '');
                } else {
                    send(key, el.value);
                }
            });
        })();
        </script>
        <?php
    }
}
