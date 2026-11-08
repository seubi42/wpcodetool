<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Le thème CodeTool appliqué au reste de WordPress, option par option (CodeTool › Apparence) :
 *
 * - wp_chrome  : barre noire du haut et menu de gauche, clairs et aux couleurs du thème ;
 * - wp_screens : écrans natifs (articles, pages, réglages, extensions…) : boutons, champs,
 *                tableaux, cartes et notices. L'éditeur de blocs et les écrans WooCommerce
 *                en React gardent leur style, ils changent trop souvent ;
 * - wp_notices : messages des autres extensions rangés derrière une cloche dans la barre du haut ;
 * - login      : page de connexion aux couleurs du thème, logo choisi (sinon le sigle SMBB animé)
 *                dans un cadre au choix, qui mène au site au lieu de wordpress.org.
 *
 * Tout est en CSS (assets/wp-skin.css) sauf la cloche (assets/wp-notices.js). Un utilisateur en
 * apparence classique (profil) ou un site sans « style moderne » ne reçoit rien.
 */
final class WpSkin
{
    /** Tracé du sigle SMBB (viewBox 0 0 1000 1000), partagé avec le favicon. */
    const MARK_PATH = 'M925.14,255.72v489.35l-425,244.96l-38.34-21.88c0-148.71,0-296.9,0-445.61l-148.97-85.86v172.24l71.75,41.55v88.03l-148.96-85.84V392.41l-83.25-48.1v357.02l232.21,133.95v88.59L75.14,745.07V255.72l38.34-21.86c141.85,81.46,283.69,162.92,425,244.39v216.52l148.97-85.84V392.41L500.13,284.15l-72.84,42.1l-76.67-44.28l149.52-85.84l226.19,130.12l83.8-48.66L500.13,99.35L266.82,233.86l-76.67-44.3l309.99-178.8L925.14,255.72z M764.66,392.41v260.26L538.47,782.81v96.78l309.45-178.26V343.75L764.66,392.41z';

    public static function hooks()
    {
        add_filter('admin_body_class', array(__CLASS__, 'bodyClass'), 20);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('admin_bar_menu', array(__CLASS__, 'noticesNode'), 1000);
        add_action('wp_ajax_smbb_menu_essentials', array(__CLASS__, 'saveEssentials'));
        add_action('admin_init', array(__CLASS__, 'colorScheme'));
        add_action('admin_print_footer_scripts', array(__CLASS__, 'prefetchRules'), 99);
        add_action('admin_head', array(__CLASS__, 'transitionHead'), 2);
    }

    /**
     * Transitions entre les pages : sens du glissé. Posé tout en haut (admin_head) parce que
     * l'événement pagereveal part avant le premier affichage, avant les scripts du pied de page.
     * wp-fx.js note au clic le sens voulu (smbb-forward : on ouvre une fiche, smbb-back : retour à
     * la liste, smbb-next / smbb-prev : onglet voisin, smbb-save : enregistrement) ; ici on le
     * relit et on le donne à la transition (types de View Transitions, lus par wp-skin.css § 5.2).
     * Précédent / Suivant du navigateur : sens déduit de l'historique.
     */
    public static function transitionHead()
    {
        if (!self::on('fx_transitions') || self::excludedScreen()) {
            return;
        }

        echo "<script id=\"smbb-vt-head\">(function(){if(!('onpagereveal' in window))return;"
            . "window.addEventListener('pagereveal',function(e){var vt=e.viewTransition;if(!vt||!vt.types)return;"
            . "var t='smbb-fade',a=window.navigation&&navigation.activation,s=null;"
            . "try{s=JSON.parse(sessionStorage.getItem('smbb-vt')||'null');sessionStorage.removeItem('smbb-vt');}catch(x){}"
            . "if(a&&a.navigationType==='traverse'&&a.from&&a.entry){t=a.entry.index<a.from.index?'smbb-back':'smbb-forward';}"
            . "else if(a&&a.navigationType==='reload'){t='smbb-save';}"
            . "else if(s&&Date.now()-s.at<20000&&(s.form||String(s.url).split('#')[0]===location.href.split('#')[0])){t=s.type;}"
            . "vt.types.add(t);});})();</script>\n";
    }

    /**
     * « Pages instantanées » : règles de préchargement (Speculation Rules, Chrome et Edge).
     * Le navigateur télécharge la page d'un lien de l'admin survolé ~200 ms (ou au doigt posé) ;
     * au clic, elle est déjà là. Préchargement seulement (pas de rendu caché : aucun script ne
     * tourne), et jamais pour un lien qui agit : jeton _wpnonce, action=, export, déconnexion,
     * nouvel article (WordPress crée un brouillon dès l'ouverture), admin-post / admin-ajax.
     * Un lien peut s'exclure avec data-smbb-no-prefetch ; filtre smbb_codetool_prefetch_rules.
     */
    public static function prefetchRules()
    {
        if (!self::on('fx_prefetch') || self::excludedScreen()) {
            return;
        }

        $base = (string) wp_parse_url(admin_url(), PHP_URL_PATH);
        $base = $base !== '' ? $base : '/wp-admin/';
        $not_paths = array('admin-post.php', 'admin-ajax.php', 'post-new.php', 'media-new.php', 'update-core.php', 'update.php', 'customize.php', 'export.php', 'site-editor.php');
        $not_search = array('_wpnonce=', 'action=', 'export', 'download', 'logout', 'delete', 'trash', 'wizard', 'smbb_copy=', 'skip=', 'doing_wp_cron');
        $not = array();

        foreach ($not_paths as $path) {
            $not[] = array('href_matches' => array('pathname' => $base . $path));
        }

        foreach ($not_search as $part) {
            $not[] = array('href_matches' => array('pathname' => $base . '*', 'search' => '*' . $part . '*'));
        }

        $not[] = array('selector_matches' => '[download], [target="_blank"], [data-smbb-no-prefetch], [data-smbb-no-prefetch] a, .submitdelete, .delete a, .trash a, a[onclick]');

        $rules = array(
            'prefetch' => array(
                array(
                    'where' => array(
                        'and' => array_merge(
                            array(array('href_matches' => array('pathname' => $base . '*'))),
                            array_map(function ($rule) {
                                return array('not' => $rule);
                            }, $not)
                        ),
                    ),
                    'eagerness' => 'moderate',
                ),
            ),
        );
        $rules = apply_filters('smbb_codetool_prefetch_rules', $rules);

        if (!$rules) {
            return;
        }

        echo '<script type="speculationrules">' . wp_json_encode($rules, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    /**
     * Menu CodeTool actif : le « Jeu de couleurs de l'administration » de WordPress ne sert plus.
     * On le retire du profil et on reste sur les couleurs par défaut, pour qu'aucun reste
     * (« Minuit », « Café »…) ne ressorte sur un bouton ou une case à cocher.
     */
    public static function colorScheme()
    {
        if (!self::on('wp_chrome')) {
            return;
        }

        remove_action('admin_color_scheme_picker', 'admin_color_scheme_picker');
        add_filter('get_user_option_admin_color', '__return_empty_string');
    }

    /** Écran profil / modification d'un compte. */
    private static function profileScreen()
    {
        global $pagenow;

        return in_array($pagenow, array('profile.php', 'user-edit.php'), true);
    }

    /** Un des effets (ou la densité compacte) demande la feuille wp-skin.css ? */
    private static function fxClasses()
    {
        $add = array();

        foreach (array('fx_glass' => 'smbb-fx-glass', 'fx_transitions' => 'smbb-fx-transitions', 'fx_toasts' => 'smbb-fx-toasts', 'fx_skeleton' => 'smbb-fx-skeleton') as $key => $class) {
            if (self::on($key)) {
                $add[] = $class;
            }
        }

        if (Skin::active() && Skin::density() === 'compact') {
            $add[] = 'smbb-density-compact';
        }

        if (self::on('wp_profile') && self::profileScreen()) {
            $add[] = 'smbb-wp-profile';
        }

        return $add;
    }

    const ESSENTIALS_META = 'smbb_codetool_menu_essentials';

    /**
     * Menu « l'essentiel » de l'utilisateur : mode (all|essentials) et ce qu'il cache.
     * top = id des entrées de niveau 1 (menu-posts, toplevel_page_…), sub = href des sous-pages.
     *
     * @return array{mode:string,top:string[],sub:string[]}
     */
    public static function essentials($user_id = 0)
    {
        $saved = get_user_meta($user_id ? $user_id : get_current_user_id(), self::ESSENTIALS_META, true);
        $saved = is_array($saved) ? $saved : array();

        return array(
            'mode' => isset($saved['mode']) && $saved['mode'] === 'essentials' ? 'essentials' : 'all',
            'top' => isset($saved['top']) && is_array($saved['top']) ? array_values($saved['top']) : array(),
            'sub' => isset($saved['sub']) && is_array($saved['sub']) ? array_values($saved['sub']) : array(),
        );
    }

    /**
     * Règles qui cachent les entrées choisies quand body a la classe smbb-ess-on.
     * La page ouverte (et son entrée parente) reste toujours visible. Même logique que wp-menu.js.
     */
    public static function essentialsCss(array $state)
    {
        $quote = static function ($value) {
            return '"' . str_replace(array('\\', '"', "\n", "\r", '<'), array('\\\\', '\\"', ' ', ' ', '\\3c '), (string) $value) . '"';
        };
        $rules = array();

        foreach ($state['top'] as $id) {
            $rules[] = 'body.smbb-ess-on #adminmenu > li[id=' . $quote($id) . ']:not(.current):not(.wp-has-current-submenu)';
        }

        foreach ($state['sub'] as $href) {
            $rules[] = 'body.smbb-ess-on #adminmenu .wp-submenu li:not(.current):has(> a[href=' . $quote($href) . '])';
        }

        return $rules ? implode(",\n", $rules) . "{display:none!important}" : '';
    }

    /**
     * AJAX : enregistre le mode et/ou la liste de ce qui est caché en mode essentiel.
     */
    public static function saveEssentials()
    {
        check_ajax_referer('smbb_menu_essentials');

        if (!is_user_logged_in()) {
            wp_send_json_error(null, 403);
        }

        $current = self::essentials();
        $clean = function ($list) {
            $out = array();

            foreach (is_array($list) ? $list : array() as $item) {
                $item = sanitize_text_field((string) $item);

                if ($item !== '' && count($out) < 300) {
                    $out[] = $item;
                }
            }

            return array_values(array_unique($out));
        };

        if (isset($_POST['mode'])) {
            $current['mode'] = sanitize_key(wp_unslash($_POST['mode'])) === 'essentials' ? 'essentials' : 'all';
        }

        if (isset($_POST['hidden'])) {
            $hidden = json_decode(wp_unslash($_POST['hidden']), true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé juste après.
            $current['top'] = $clean(isset($hidden['top']) ? $hidden['top'] : array());
            $current['sub'] = $clean(isset($hidden['sub']) ? $hidden['sub'] : array());
        }

        update_user_meta(get_current_user_id(), self::ESSENTIALS_META, $current);
        wp_send_json_success($current);
    }

    /**
     * Branché hors admin (la page de connexion n'est pas is_admin()).
     */
    public static function loginHooks()
    {
        add_action('login_enqueue_scripts', array(__CLASS__, 'loginStyles'));
        add_action('login_head', array(__CLASS__, 'loginHead'), 0);
        add_filter('login_body_class', array(__CLASS__, 'loginBodyClass'));
        add_filter('login_headerurl', array(__CLASS__, 'loginUrl'));
        add_filter('login_headertext', array(__CLASS__, 'loginText'));
        add_action('login_header', array(__CLASS__, 'loginClock'));
        add_action('login_footer', array(__CLASS__, 'loginClockScript'));
    }

    /**
     * Cloche des messages active (CodeTool › Apparence) : d'autres briques y posent leur pastille.
     */
    public static function bellOn()
    {
        return self::on('wp_notices');
    }

    private static function on($key)
    {
        // « Réduire les animations » (widget Mes préférences) : pas de fondu ni de squelette pour cet utilisateur.
        if (($key === 'fx_transitions' || $key === 'fx_skeleton') && UxPrefs::reducedMotion()) {
            return false;
        }

        return Skin::active() && !empty(Skin::settings()[$key]);
    }

    /**
     * Écran où l'on ne touche pas aux contenus : éditeur de blocs, éditeur de site, WooCommerce React.
     */
    private static function excludedScreen()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
            return true;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        return $page === 'wc-admin' || ($screen && in_array($screen->id, array('site-editor', 'woocommerce_page_wc-admin'), true));
    }

    public static function bodyClass($classes)
    {
        $classes = (string) $classes;
        $add = array();

        if (self::on('wp_chrome')) {
            $add[] = 'smbb-wp-chrome smbb-menu-' . sanitize_html_class(Skin::menuStyle());

            if (self::essentials()['mode'] === 'essentials') {
                $add[] = 'smbb-ess-on';
            }
        }

        if (self::on('wp_screens') && !self::excludedScreen()) {
            $add[] = 'smbb-wp-screens';
        }

        // Cloche : les bandeaux à ranger sont masqués en CSS dès l'affichage (wp-skin.css), pas après le script.
        if (self::on('wp_notices')) {
            $add[] = 'smbb-wp-bell';
        }

        $add = array_merge($add, self::fxClasses());

        if (!$add) {
            return $classes;
        }

        // Les variables du thème sont posées sur body.smbb-skin (déjà là sur les pages CodeTool).
        if (strpos(' ' . $classes . ' ', ' smbb-skin ') === false) {
            $add[] = 'smbb-skin smbb-theme-' . sanitize_html_class(Skin::current());
        }

        return trim($classes . ' ' . implode(' ', $add));
    }

    public static function enqueue()
    {
        // Page Apparence : l'aperçu du logo de connexion utilise les styles du cadre.
        if (isset($_GET['page']) && $_GET['page'] === Pages\AppearancePage::SLUG) { // phpcs:ignore WordPress.Security.NonceVerification
            wp_enqueue_style('smbb-wpcodetool-wp-skin', SMBB_WPCODETOOL_URL . 'assets/wp-skin.css', array(), self::version('wp-skin.css'));
        }

        // Profil : le bloc « Apparence » reprend les cartes de CodeTool › Apparence (admin.css).
        if (self::profileScreen() && !wp_style_is('smbb-wpcodetool-admin', 'enqueued')) {
            wp_enqueue_style('smbb-wpcodetool-admin', SMBB_WPCODETOOL_URL . 'assets/admin.css', array(), self::version('admin.css'));

            if (!wp_style_is('smbb-wpcodetool-wp-skin', 'enqueued')) {
                wp_enqueue_style('smbb-wpcodetool-wp-skin', SMBB_WPCODETOOL_URL . 'assets/wp-skin.css', array(), self::version('wp-skin.css'));
                wp_add_inline_style('smbb-wpcodetool-wp-skin', Skin::css());
            }
        }

        if (!Skin::active()) {
            return;
        }

        // Mode sombre : une couche à part, sur toutes les pages de l'admin (sauf l'éditeur de blocs).
        // Chargé même en clair : la bascule de la barre du haut change de mode sans recharger.
        wp_enqueue_style('smbb-wpcodetool-dark', SMBB_WPCODETOOL_URL . 'assets/dark.css', array(), self::version('dark.css'));

        $chrome = self::on('wp_chrome');
        $screens = self::on('wp_screens');
        $fx = self::fxClasses();

        if ($chrome || $screens || $fx || self::on('wp_notices')) {
            wp_enqueue_style('smbb-wpcodetool-wp-skin', SMBB_WPCODETOOL_URL . 'assets/wp-skin.css', array(), self::version('wp-skin.css'));

            // Hors pages CodeTool, theme.css n'est pas chargé : on pose les variables ici.
            if (!wp_style_is('smbb-wpcodetool-theme', 'enqueued')) {
                wp_add_inline_style('smbb-wpcodetool-wp-skin', Skin::css());
            }
        }

        // Ni theme.css ni wp-skin.css : le mode sombre a quand même besoin des variables.
        if (!wp_style_is('smbb-wpcodetool-theme', 'enqueued') && !wp_style_is('smbb-wpcodetool-wp-skin', 'enqueued')) {
            wp_add_inline_style('smbb-wpcodetool-dark', Skin::css());
        }

        // « Tout | L'essentiel » en bas du menu, quel que soit son style.
        if ($chrome) {
            // Posé en CSS dès le chargement : pas d'entrées qui clignotent avant le script.
            wp_add_inline_style('smbb-wpcodetool-wp-skin', self::essentialsCss(self::essentials()));
            wp_enqueue_script('smbb-wpcodetool-wp-menu', SMBB_WPCODETOOL_URL . 'assets/wp-menu.js', array(), self::version('wp-menu.js'), true);
            wp_localize_script('smbb-wpcodetool-wp-menu', 'smbbWpMenu', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('smbb_menu_essentials'),
                'state' => self::essentials(),
                'i18n' => array(
                    'all' => __('Tout', 'smbb-wpcodetool'),
                    'essentials' => __('L\'essentiel', 'smbb-wpcodetool'),
                    'group' => __('Affichage du menu', 'smbb-wpcodetool'),
                    'edit' => __('Choisir l\'essentiel', 'smbb-wpcodetool'),
                    'title' => __('L\'essentiel du menu', 'smbb-wpcodetool'),
                    'help' => __('Décochez ce que vous ne voulez pas voir en mode « L\'essentiel ». La page ouverte reste toujours visible.', 'smbb-wpcodetool'),
                    'checkAll' => __('Tout cocher', 'smbb-wpcodetool'),
                    'cancel' => __('Annuler', 'smbb-wpcodetool'),
                    'save' => __('Enregistrer', 'smbb-wpcodetool'),
                    'here' => __('page ouverte', 'smbb-wpcodetool'),
                ),
            ));
        }

        if (self::on('wp_notices')) {
            wp_enqueue_script('smbb-wpcodetool-wp-notices', SMBB_WPCODETOOL_URL . 'assets/wp-notices.js', array(), self::version('wp-notices.js'), true);
            wp_localize_script('smbb-wpcodetool-wp-notices', 'smbbWpNotices', array(
                'title' => __('Messages des extensions', 'smbb-wpcodetool'),
                'empty' => __('Aucun message', 'smbb-wpcodetool'),
            ));
        }

        // Fondu entre les pages : la règle @view-transition ne se pose pas sous une classe de body.
        if (self::on('fx_transitions')) {
            wp_add_inline_style('smbb-wpcodetool-wp-skin', '@media (prefers-reduced-motion: no-preference){@view-transition{navigation:auto}}');
        }

        // Bulles, squelette, et glissés entre écrans (sens de la transition décidé au clic) : un seul script.
        if (self::on('fx_toasts') || self::on('fx_skeleton') || self::on('fx_transitions')) {
            wp_enqueue_script('smbb-wpcodetool-wp-fx', SMBB_WPCODETOOL_URL . 'assets/wp-fx.js', array(), self::version('wp-fx.js'), true);
        }

        if (self::on('wp_profile') && self::profileScreen()) {
            wp_enqueue_script('smbb-wpcodetool-wp-profile', SMBB_WPCODETOOL_URL . 'assets/wp-profile.js', array(), self::version('wp-profile.js'), true);
            wp_localize_script('smbb-wpcodetool-wp-profile', 'smbbWpProfile', array(
                'nav' => __('Sections', 'smbb-wpcodetool'),
                'identity' => __('Identité', 'smbb-wpcodetool'),
                'addresses' => __('Adresses', 'smbb-wpcodetool'),
                'preferences' => __('Préférences', 'smbb-wpcodetool'),
                'security' => __('Sécurité', 'smbb-wpcodetool'),
                'other' => __('Autres', 'smbb-wpcodetool'),
            ));
        }
    }

    /**
     * Cloche dans la barre du haut ; le script y range les messages et l'affiche s'il y en a.
     */
    public static function noticesNode($bar)
    {
        if (!is_admin() || !self::on('wp_notices')) {
            return;
        }

        $bar->add_node(array(
            'id' => 'smbb-notices',
            'parent' => 'top-secondary',
            'title' => '<span class="ab-icon dashicons dashicons-bell" aria-hidden="true"></span><span class="smbb-notices-count">0</span><span class="screen-reader-text">' . esc_html__('Messages des extensions', 'smbb-wpcodetool') . '</span>',
            'href' => '#',
            'meta' => array('class' => 'smbb-notices-node hidden'),
        ));
    }

    /* Page de connexion */

    private static function loginOn()
    {
        return Skin::active() && !empty(Skin::settings()['login']);
    }

    public static function loginBodyClass($classes)
    {
        if (self::loginOn()) {
            $classes[] = 'smbb-login';

            if (self::clockOn()) {
                $classes[] = 'smbb-login-has-clock';
            }
        }

        return $classes;
    }

    private static function clockOn()
    {
        return self::loginOn() && !empty(Skin::settings()['login_clock']);
    }

    /**
     * Heure et date façon écran de verrouillage, au-dessus du logo (action login_header, avant #login).
     * Le serveur écrit déjà l'heure du site pour éviter un cadre vide ; le script passe ensuite
     * à l'heure de l'appareil du visiteur et la met à jour à chaque minute.
     */
    public static function loginClock()
    {
        if (!self::clockOn()) {
            return;
        }

        $now = current_datetime();
        $hours = wp_date('H', $now->getTimestamp());
        $minutes = wp_date('i', $now->getTimestamp());
        $date = wp_date('l j F', $now->getTimestamp());
        ?>
        <div class="smbb-login-clock" data-smbb-login-clock data-locale="<?php echo esc_attr(str_replace('_', '-', determine_locale())); ?>" aria-hidden="true">
            <div class="smbb-login-clock-time"><span class="smbb-login-clock-h"><?php echo esc_html($hours); ?></span><span class="smbb-login-clock-sep">:</span><span class="smbb-login-clock-m"><?php echo esc_html($minutes); ?></span></div>
            <div class="smbb-login-clock-date"><?php echo esc_html(self::ucfirst($date)); ?></div>
        </div>
        <?php
    }

    /** Mise à jour de l'horloge : à chaque changement de minute, chiffres qui glissent, date dans la langue du site. */
    public static function loginClockScript()
    {
        if (!self::clockOn()) {
            return;
        }
        ?>
        <script>
        (function () {
            var box = document.querySelector('[data-smbb-login-clock]');
            if (!box) { return; }
            var h = box.querySelector('.smbb-login-clock-h');
            var m = box.querySelector('.smbb-login-clock-m');
            var d = box.querySelector('.smbb-login-clock-date');
            var locale = box.getAttribute('data-locale') || undefined;
            var dateFmt;
            try {
                dateFmt = new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long' });
            } catch (e) {
                dateFmt = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
            }
            function pad(n) { return (n < 10 ? '0' : '') + n; }
            function set(el, value) {
                if (el.textContent === value) { return; }
                el.textContent = value;
                el.classList.remove('is-tick');
                void el.offsetWidth;
                el.classList.add('is-tick');
            }
            function tick() {
                var now = new Date();
                var date = dateFmt.format(now);
                set(h, pad(now.getHours()));
                set(m, pad(now.getMinutes()));
                if (d.textContent !== date.charAt(0).toUpperCase() + date.slice(1)) {
                    d.textContent = date.charAt(0).toUpperCase() + date.slice(1);
                }
                setTimeout(tick, 60000 - (now.getSeconds() * 1000 + now.getMilliseconds()) + 20);
            }
            tick();
            box.classList.add('is-live');
        })();
        </script>
        <?php
    }

    private static function ucfirst($text)
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1)
            : ucfirst($text);
    }

    public static function loginUrl($url)
    {
        return self::loginOn() ? home_url('/') : $url;
    }

    /**
     * Le lien du logo : le logo choisi (ou le sigle SMBB animé) dans son cadre, et le nom du site
     * pour les lecteurs d'écran. WordPress affiche ce texte tel quel dans le <a> du <h1>.
     */
    public static function loginText($text)
    {
        if (!self::loginOn()) {
            return $text;
        }

        return self::logoHtml(Skin::settings()) . '<span class="smbb-login-name">' . esc_html(get_bloginfo('name', 'display')) . '</span>';
    }

    /**
     * Logo dans son cadre (none / rounded / circle) : l'image choisie, sinon le sigle SMBB animé.
     */
    public static function logoHtml(array $settings)
    {
        $url = self::loginLogoUrl();
        $inner = $url ? '<img src="' . esc_url($url) . '" alt="">' : self::mark();

        return '<span class="smbb-login-logo is-' . sanitize_html_class($settings['login_shape']) . ($url ? ' has-image' : ' has-mark') . '">' . $inner . '</span>';
    }

    /**
     * Logo choisi dans Apparence (médiathèque), ou chaîne vide.
     */
    public static function loginLogoUrl()
    {
        $id = Skin::settings()['login_logo'];
        $url = $id ? wp_get_attachment_image_url($id, 'medium') : '';

        return $url ? $url : '';
    }

    /**
     * Sigle SMBB plein, aux couleurs du thème (rouge SMBB par défaut) : il apparaît en douceur,
     * puis un reflet lumineux le traverse en diagonale toutes les 6 secondes, comme sur un badge.
     * Statique si l'utilisateur a demandé moins d'animations.
     */
    public static function mark()
    {
        static $n = 0;
        $n++;
        $clip = 'smbb-mark-clip-' . $n;
        $shine = 'smbb-mark-shine-' . $n;
        $d = self::MARK_PATH;
        $css = '.smbb-mark{overflow:visible;color:var(--smbb-t-accent,#db1f26)}'
            . '.smbb-mark .m-shape{fill:currentColor;transform-origin:50% 50%;animation:smbb-m-in .9s cubic-bezier(.2,.8,.2,1) both}'
            . '.smbb-mark .m-sweep{transform:translateX(-950px);animation:smbb-m-sweep 6s 1s cubic-bezier(.4,0,.6,1) infinite}'
            . '@keyframes smbb-m-in{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:none}}'
            . '@keyframes smbb-m-sweep{0%{transform:translateX(-950px)}35%,100%{transform:translateX(950px)}}'
            . '@media (prefers-reduced-motion:reduce){.smbb-mark .m-shape{animation:none}.smbb-mark .m-sweep{display:none}}';

        return '<svg class="smbb-mark" viewBox="0 0 1000 1000" aria-hidden="true" focusable="false">'
            . '<style>' . $css . '</style>'
            . '<defs>'
            . '<clipPath id="' . $clip . '"><path d="' . $d . '"/></clipPath>'
            . '<linearGradient id="' . $shine . '" x1="0" y1="0" x2="1" y2="0">'
            . '<stop offset="0" stop-color="#fff" stop-opacity="0"/>'
            . '<stop offset=".35" stop-color="#fff" stop-opacity=".25"/>'
            . '<stop offset=".5" stop-color="#fff" stop-opacity=".75"/>'
            . '<stop offset=".65" stop-color="#fff" stop-opacity=".25"/>'
            . '<stop offset="1" stop-color="#fff" stop-opacity="0"/>'
            . '</linearGradient>'
            . '</defs>'
            . '<path class="m-shape" d="' . $d . '"/>'
            . '<g clip-path="url(#' . $clip . ')"><g class="m-sweep">'
            . '<rect x="250" y="-300" width="500" height="1600" fill="url(#' . $shine . ')" transform="rotate(20 500 500)"/>'
            . '</g></g>'
            . '</svg>';
    }

    public static function loginStyles()
    {
        if (!self::loginOn()) {
            return;
        }

        wp_enqueue_style('smbb-wpcodetool-wp-skin', SMBB_WPCODETOOL_URL . 'assets/wp-skin.css', array('login'), self::version('wp-skin.css'));
        wp_add_inline_style('smbb-wpcodetool-wp-skin', Skin::css());

        if (Skin::active() && Skin::mode() !== 'light') {
            wp_enqueue_style('smbb-wpcodetool-dark', SMBB_WPCODETOOL_URL . 'assets/dark.css', array('smbb-wpcodetool-wp-skin'), self::version('dark.css'));
        }
    }

    /** Mode sombre sur la page de connexion (classe posée avant l'affichage). */
    public static function loginHead()
    {
        if (self::loginOn()) {
            Skin::modeScript(true);
        }
    }

    private static function version($file)
    {
        $path = SMBB_WPCODETOOL_PATH . 'assets/' . $file;

        return SMBB_WPCODETOOL_VERSION . (file_exists($path) ? '.' . filemtime($path) : '');
    }
}
