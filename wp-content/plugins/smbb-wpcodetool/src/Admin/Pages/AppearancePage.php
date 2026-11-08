<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Admin\Skin;
use Smbb\WpCodeTool\Admin\WpSkin;

defined('ABSPATH') || exit;

/**
 * Page « Apparence » : thème de couleurs de tous les écrans CodeTool (et des plugins bâtis
 * dessus, comme Tomo). Chaque utilisateur peut choisir autre chose dans son profil.
 */
final class AppearancePage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-appearance';
    const NONCE = 'smbb_codetool_appearance';

    /**
     * Enregistrement (admin_init, avant tout affichage).
     */
    public static function handle()
    {
        if (!isset($_POST['smbb_codetool_appearance_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_codetool_appearance_nonce'])), self::NONCE)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier l\'apparence de CodeTool.', 'smbb-wpcodetool'));
        }

        $theme = isset($_POST['theme']) ? sanitize_key(wp_unslash($_POST['theme'])) : '';
        $accent = isset($_POST['accent']) ? sanitize_hex_color(wp_unslash($_POST['accent'])) : '';
        $current = Skin::settings();

        if (($theme === Skin::CUSTOM && !$accent) || $theme === Skin::CLASSIC) {
            $theme = $current['theme'];
        }

        $extra = array(
            'background' => isset($_POST['background']) ? sanitize_key(wp_unslash($_POST['background'])) : 'tinted',
            'nav' => isset($_POST['nav']) ? sanitize_key(wp_unslash($_POST['nav'])) : 'top',
            'login_logo' => isset($_POST['login_logo']) ? absint($_POST['login_logo']) : 0,
            'menu_style' => isset($_POST['menu_style']) ? sanitize_key(wp_unslash($_POST['menu_style'])) : 'light',
            'login_shape' => isset($_POST['login_shape']) ? sanitize_key(wp_unslash($_POST['login_shape'])) : 'none',
            'density' => isset($_POST['density']) ? sanitize_key(wp_unslash($_POST['density'])) : 'comfortable',
            'mode' => isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'light',
            'preset' => isset($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : 'galet',
            'footer_text' => isset($_POST['footer_text']) ? sanitize_text_field(wp_unslash($_POST['footer_text'])) : '',
        );

        foreach (array_keys(Skin::SWITCHES) as $key) {
            $extra[$key] = !empty($_POST[$key]) ? 1 : 0;
        }

        Skin::saveSettings($theme, $accent ?: $current['accent'], $extra);

        wp_safe_redirect(add_query_arg(array('page' => self::SLUG, 'saved' => 1), admin_url('admin.php')));
        exit;
    }

    public function render()
    {
        $manager = $this->manager();
        $themes = Skin::themes();
        $settings = Skin::settings();
        $site = Skin::sitePalette();
        $accent = $settings['accent'] !== '' ? $settings['accent'] : '#a8406a';
        $notice = isset($_GET['saved']) // phpcs:ignore WordPress.Security.NonceVerification
            ? '<div class="smbb-codetool-notice is-success"><p class="smbb-codetool-notice-message">' . esc_html__('Apparence enregistrée.', 'smbb-wpcodetool') . '</p></div>'
            : '';
        unset($themes[Skin::CUSTOM]);
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
                __('Apparence', 'smbb-wpcodetool'),
                __('Thème de couleurs de tous les écrans CodeTool et des plugins bâtis dessus. La typo reste celle de WordPress.', 'smbb-wpcodetool'),
                'dashicons-admin-appearance',
                $notice
            );
            // Bouton magique : toutes les optimisations UX d'un coup (formulaire à part, avant celui de la page).
            \Smbb\WpCodeTool\Admin\UxPrefs::magicBox('page');
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">
                <?php wp_nonce_field(self::NONCE, 'smbb_codetool_appearance_nonce'); ?>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-star-filled"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Style', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Coupé, tout le monde retrouve l\'apparence classique de WordPress. Chaque utilisateur peut aussi y revenir dans son profil.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php self::toggle('modern', $settings['modern'], __('Style moderne', 'smbb-wpcodetool'), __('Interrupteur général de l\'habillage : couleurs, barre d\'app, menu, écrans, effets, connexion, listes, Ctrl K et pages WooCommerce. Coupé, tout le monde a l\'admin WordPress d\'origine, sans widget ni réglages de style dans le profil. La marque blanche et le ménage restent à part.', 'smbb-wpcodetool')); ?>
                        <?php if (!$settings['modern']) : ?>
                            <p class="notice notice-warning inline" style="margin:8px 0;padding:8px 12px"><?php esc_html_e('Style moderne coupé : les réglages ci-dessous sont gardés mais sans effet tant qu\'il n\'est pas rallumé.', 'smbb-wpcodetool'); ?></p>
                        <?php endif; ?>
                        <div class="smbb-preset-field">
                            <label class="smbb-codetool-field-label" for="smbb-preset"><?php esc_html_e('Allure', 'smbb-wpcodetool'); ?></label>
                            <select id="smbb-preset" name="preset">
                                <?php foreach (Skin::presets() as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['preset'], $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" data-preset-note="galet"<?php echo $settings['preset'] === 'galet' ? '' : ' hidden'; ?>><?php esc_html_e('Le style d\'origine : cartes douces et ombrées, coins bien arrondis, dégradés pour les palettes qui en ont.', 'smbb-wpcodetool'); ?></p>
                            <p class="description" data-preset-note="porsche"<?php echo $settings['preset'] === 'porsche' ? '' : ' hidden'; ?>><?php esc_html_e('Les codes du Porsche Design System : typo Porsche Next (ou Arial) en 16 px, titres fins, espacements généreux, aplats sans ombre ni dégradé, boutons à l\'encre, focus bleu. Va avec la palette « Encre », mais marche avec toutes.', 'smbb-wpcodetool'); ?></p>
                        </div>
                        <p class="description" data-preset-note="cristal"<?php echo $settings['preset'] === 'cristal' ? '' : ' hidden'; ?>><?php esc_html_e('Minimaliste et net : fond neutre à grille fine, surfaces blanches à liseré, barres en verre dépoli, typo Geist avec chiffres et intitulés en Geist Mono. La palette ne sert plus qu\'aux accents.', 'smbb-wpcodetool'); ?></p>
                        <div data-preset-only="cristal"<?php echo $settings['preset'] === 'cristal' ? '' : ' hidden'; ?>>
                            <?php self::toggle('cristal_font', $settings['cristal_font'], __('Polices Geist et Geist Mono', 'smbb-wpcodetool'), __('Chargées depuis Google Fonts (licence libre OFL). Décoché : police système de l\'admin.', 'smbb-wpcodetool')); ?>
                        </div>
                        <div data-preset-only="porsche"<?php echo $settings['preset'] === 'porsche' ? '' : ' hidden'; ?>>
                            <?php self::toggle('porsche_font', $settings['porsche_font'], __('Police Porsche Next', 'smbb-wpcodetool'), __('Chargée depuis le CDN de Porsche. Sa licence la réserve aux sites et outils faits pour Porsche : à cocher seulement sur ces projets. Sinon : Arial Narrow, puis Arial.', 'smbb-wpcodetool')); ?>
                        </div>
                        <script>
                            (function () {
                                var select = document.getElementById('smbb-preset');
                                if (!select) { return; }
                                select.addEventListener('change', function () {
                                    document.querySelectorAll('[data-preset-note], [data-preset-only]').forEach(function (el) {
                                        el.hidden = (el.getAttribute('data-preset-note') || el.getAttribute('data-preset-only')) !== select.value;
                                    });
                                    // Allure Porsche : on propose la palette « Encre », libre ensuite d'en choisir une autre.
                                    var radio = document.querySelector('input[name="theme"][value="' + (select.value === 'porsche' ? 'porsche' : '') + '"]');
                                    if (radio && select.value === 'porsche') { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
                                });
                            })();
                        </script>
                    </div>
                </section>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-art"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Palette de couleurs', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Appliquée à tout le monde. Chaque utilisateur peut en choisir une autre dans son profil WordPress.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <div class="smbb-theme-grid" role="radiogroup">
                            <?php foreach ($themes as $key => $theme) : ?>
                                <?php self::card($key, $theme['label'], array('accent' => $theme['accent'], 'accent2' => $theme['accent_2'], 'soft' => $theme['accent_soft'], 'bg' => $theme['bg']), $site === $key); ?>
                            <?php endforeach; ?>

                            <?php
                            $custom = Skin::mix($accent, '#ffffff', 0.86);
                            self::card(Skin::CUSTOM, __('Personnalisé', 'smbb-wpcodetool'), array('accent' => $accent, 'soft' => $custom, 'bg' => Skin::mix($accent, '#f6f6f8', 0.96)), $site === Skin::CUSTOM,
                                '<input type="color" class="smbb-theme-picker" name="accent" value="' . esc_attr($accent) . '" aria-label="' . esc_attr__('Couleur personnalisée', 'smbb-wpcodetool') . '">');
                            ?>
                        </div>
                        <script>
                            (function () {
                                var picker = document.querySelector('.smbb-theme-picker');
                                if (!picker) { return; }
                                picker.addEventListener('input', function () {
                                    var card = picker.closest('.smbb-theme-card');
                                    card.querySelector('.smbb-theme-preview').style.setProperty('--p-accent', picker.value);
                                    card.querySelector('input[type="radio"]').checked = true;
                                });
                            })();
                        </script>
                        <p class="smbb-codetool-field-label"><?php esc_html_e('Mode d\'affichage', 'smbb-wpcodetool'); ?> <span class="smbb-theme-note"><?php esc_html_e('— par défaut pour tous ; Auto suit le réglage clair/sombre de l\'appareil. Chacun peut changer dans son profil ou d\'un clic dans la barre du haut.', 'smbb-wpcodetool'); ?></span></p>
                        <div class="smbb-codetool-density" role="radiogroup">
                            <?php foreach (Skin::modes() as $key => $label) : ?>
                                <label><input type="radio" name="mode" value="<?php echo esc_attr($key); ?>" <?php checked($settings['mode'], $key); ?>> <?php echo esc_html($label); ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php self::toggle('mode_toggle', $settings['mode_toggle'], __('Bascule dans la barre du haut', 'smbb-wpcodetool'), __('Une icône soleil / lune à gauche du compte : Clair, Sombre ou Auto en un clic, sans recharger la page. Le choix est gardé dans le profil.', 'smbb-wpcodetool')); ?>
                    </div>
                </section>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-layout"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Mise en page', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Écrans CodeTool et plugins bâtis dessus.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <p class="smbb-codetool-field-label"><?php esc_html_e('Fond des pages', 'smbb-wpcodetool'); ?></p>
                        <div class="smbb-choice-grid" role="radiogroup">
                            <?php
                            self::choice('background', 'tinted', $settings['background'], __('Teinté', 'smbb-wpcodetool'), __('Un voile de la couleur du thème derrière les cartes.', 'smbb-wpcodetool'), 'is-tinted');
                            self::choice('background', 'white', $settings['background'], __('Blanc', 'smbb-wpcodetool'), __('Fond blanc, gris neutres.', 'smbb-wpcodetool'), 'is-white');
                            ?>
                        </div>

                        <p class="smbb-codetool-field-label"><?php esc_html_e('Navigation', 'smbb-wpcodetool'); ?></p>
                        <div class="smbb-choice-grid" role="radiogroup">
                            <?php
                            self::choice('nav', 'top', $settings['nav'], __('Barre en haut', 'smbb-wpcodetool'), __('Groupes en onglets, recherche Ctrl K, « + Créer ». Le menu latéral se réduit à une ligne par app.', 'smbb-wpcodetool'), 'is-top');
                            self::choice('nav', 'side', $settings['nav'], __('Menu latéral', 'smbb-wpcodetool'), __('Le menu WordPress habituel, toutes les pages dépliées à gauche.', 'smbb-wpcodetool'), 'is-side');
                            ?>
                        </div>

                        <?php self::toggle('icons', $settings['icons'], __('Icônes dans la barre en haut', 'smbb-wpcodetool')); ?>
                    </div>
                </section>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-wordpress"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Reste de l\'admin WordPress', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Même thème sur les écrans de WordPress et des autres extensions. L\'éditeur de blocs et les écrans WooCommerce modernes gardent leur style.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php
                        self::toggle('wp_chrome', $settings['wp_chrome'], __('Barre du haut et menu de gauche', 'smbb-wpcodetool'), __('Aux couleurs du thème, dans le style de menu choisi ci-dessous.', 'smbb-wpcodetool'));
                        ?>
                        <p class="smbb-codetool-field-label"><?php esc_html_e('Style du menu de gauche', 'smbb-wpcodetool'); ?> <span class="smbb-theme-note"><?php esc_html_e('— par défaut pour tous, chacun peut en choisir un autre dans son profil.', 'smbb-wpcodetool'); ?></span></p>
                        <div class="smbb-choice-grid smbb-menu-grid" role="radiogroup">
                            <?php
                            $notes = self::menuNotes();
                            foreach (Skin::menuStyles() as $key => $label) {
                                self::menuChoice($key, $settings['menu_style'], $label, $notes[$key]);
                            }
                            ?>
                        </div>
                        <?php
                        self::toggle('wp_screens', $settings['wp_screens'], __('Écrans de WordPress', 'smbb-wpcodetool'), __('Articles, pages, utilisateurs, réglages, extensions : boutons, champs, tableaux, cartes et notices.', 'smbb-wpcodetool'));
                        self::toggle('list_ux', $settings['list_ux'], __('Listes plus confortables', 'smbb-wpcodetool'), __('Dès qu\'on coche des lignes, une barre flottante en bas propose les actions groupées en boutons. Les dates deviennent lisibles (« Aujourd\'hui, 14:05 », « Hier », « Il y a 3 jours »), la date complète au survol.', 'smbb-wpcodetool'));
                        self::toggle('wp_media', $settings['wp_media'], __('Médiathèque et éditeur', 'smbb-wpcodetool'), __('Médiathèque et fenêtre « Ajouter un média » arrondies, sélection à la couleur du thème ; l\'éditeur de blocs prend la couleur du thème à la place du bleu WordPress.', 'smbb-wpcodetool'));
                        self::toggle('wp_notices', $settings['wp_notices'], __('Messages des extensions dans une cloche', 'smbb-wpcodetool'), __('Les bandeaux en haut des écrans vont dans une cloche de la barre du haut. Les confirmations (« Réglages enregistrés »…) restent à leur place.', 'smbb-wpcodetool'));
                        self::toggle('wp_profile', $settings['wp_profile'], __('Page profil et comptes utilisateurs rangée', 'smbb-wpcodetool'), __('Chaque groupe de réglages dans une carte titrée, avec un sommaire pour passer d\'une section à l\'autre.', 'smbb-wpcodetool'));
                        ?>
                    </div>
                </section>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-star-filled"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Effets', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Pour toute l\'administration. Ils se coupent d\'eux-mêmes si l\'utilisateur a demandé moins d\'animations à son système.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php
                        self::toggle('fx_glass', $settings['fx_glass'], __('Verre dépoli', 'smbb-wpcodetool'), __('La barre du haut, les menus déroulants et les panneaux deviennent translucides et floutent ce qui passe dessous.', 'smbb-wpcodetool'));
                        self::toggle('fx_transitions', $settings['fx_transitions'], __('Transitions entre les pages', 'smbb-wpcodetool'), __('La barre et le menu restent en place, le contenu glisse : vers la gauche quand on ouvre une fiche, vers la droite quand on revient à la liste, de côté d\'un onglet à l\'autre. L\'onglet actif glisse jusqu\'au nouveau. Onglets, fenêtres et volets s\'ouvrent en douceur. Chrome et Edge ; ailleurs, simple fondu ou rien.', 'smbb-wpcodetool'));
                        self::toggle('fx_toasts', $settings['fx_toasts'], __('Confirmations en bulles', 'smbb-wpcodetool'), __('« Réglages enregistrés », « Article mis à jour »… s\'affichent en bulle en bas de l\'écran et disparaissent seuls. Les erreurs restent dans la page.', 'smbb-wpcodetool'));
                        self::toggle('fx_skeleton', $settings['fx_skeleton'], __('Squelette de chargement', 'smbb-wpcodetool'), __('Un fin trait de progression part dès le clic. Si la page tarde, sa silhouette s\'affiche avec son titre : liste, fiche ou tableau de bord, selon ce qu\'elle contenait la dernière fois.', 'smbb-wpcodetool'));
                        self::toggle('fx_prefetch', $settings['fx_prefetch'], __('Pages instantanées', 'smbb-wpcodetool'), __('Le navigateur prépare la page d\'un lien dès qu\'on le survole : au clic, elle s\'ouvre presque aussitôt. Jamais pour les liens qui agissent (supprimer, exporter, se déconnecter…). Chrome et Edge.', 'smbb-wpcodetool'));
                        ?>
                        <p class="smbb-codetool-field-label"><?php esc_html_e('Densité', 'smbb-wpcodetool'); ?> <span class="smbb-theme-note"><?php esc_html_e('— par défaut pour tous, chacun peut en choisir une autre dans son profil.', 'smbb-wpcodetool'); ?></span></p>
                        <div class="smbb-codetool-density" role="radiogroup">
                            <?php foreach (Skin::densities() as $key => $label) : ?>
                                <label><input type="radio" name="density" value="<?php echo esc_attr($key); ?>" <?php checked($settings['density'], $key); ?>> <?php echo esc_html($label); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-store"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Côté site', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Les pages que voient vos clients, quel que soit le thème du site.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php
                        self::toggle('wc_account', $settings['wc_account'], __('Page « Mon compte » de WooCommerce', 'smbb-wpcodetool'), __('Menu en pastilles avec icônes, tableau de bord en tuiles (bonjour, dernière commande, raccourcis), commandes, adresses et formulaires en cartes.', 'smbb-wpcodetool'));
                        self::toggle('wc_thanks', $settings['wc_thanks'], __('Page « Commande reçue » de WooCommerce', 'smbb-wpcodetool'), __('Après le paiement : carte « Merci pour votre commande » avec un pouce animé, récapitulatif, réservations (billet, agenda, Wallet), détail et adresses en cartes.', 'smbb-wpcodetool'));
                        ?>
                    </div>
                </section>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Page de connexion', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Aux couleurs du thème, et le logo ramène au site au lieu de wordpress.org.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php self::toggle('login', $settings['login'], __('Personnaliser la page de connexion', 'smbb-wpcodetool')); ?>
                        <?php self::logoField($settings); ?>
                        <?php self::toggle('login_clock', $settings['login_clock'], __('Heure et date en direct', 'smbb-wpcodetool'), __('Comme l\'écran de verrouillage d\'un téléphone : la grande heure au-dessus du logo, la date dessous, mises à jour toutes seules. Heure de l\'appareil du visiteur, date en toutes lettres dans la langue du site.', 'smbb-wpcodetool')); ?>
                        <?php self::toggle('favicon', $settings['favicon'], __('Ce logo comme favicon', 'smbb-wpcodetool'), __('L\'icône de l\'onglet du navigateur, dans l\'admin, sur la page de connexion et sur le site. Sans logo choisi : le sigle, à la couleur du thème.', 'smbb-wpcodetool')); ?>
                    </div>
                </section>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-admin-generic"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Marque blanche et ménage', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Ce que les agences retouchent d\'habitude : l\'admin parle de votre client, pas de WordPress.', 'smbb-wpcodetool'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=' . ContentPage::SLUG)); ?>"><?php esc_html_e('Articles, commentaires et pages inutiles : CodeTool › Contenu WP', 'smbb-wpcodetool'); ?></a></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php
                        self::toggle('wl_brand', $settings['wl_brand'], __('Marque blanche', 'smbb-wpcodetool'), __('Le logo du site remplace celui de WordPress en haut à gauche, le pied de page affiche votre texte et plus le numéro de version.', 'smbb-wpcodetool'));
                        ?>
                        <p class="smbb-codetool-field-label"><label for="smbb-footer-text"><?php esc_html_e('Texte du pied de page', 'smbb-wpcodetool'); ?></label> <span class="smbb-theme-note"><?php esc_html_e('— vide : le nom du site.', 'smbb-wpcodetool'); ?></span></p>
                        <p><input type="text" id="smbb-footer-text" class="regular-text" name="footer_text" value="<?php echo esc_attr($settings['footer_text']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name', 'display')); ?>"></p>
                        <?php
                        self::toggle('adminbar_clean', $settings['adminbar_clean'], __('Barre du haut allégée', 'smbb-wpcodetool'), __('Sans « + Nouveau », sans la bulle des commentaires ni le menu WordPress.', 'smbb-wpcodetool'));
                        self::toggle('dashboard_clean', $settings['dashboard_clean'], __('Accueil sans les widgets de WordPress', 'smbb-wpcodetool'), __('Bienvenue, Brouillon rapide, Événements, D\'un coup d\'œil, Activité, Santé du site : retirés. Les widgets des extensions (Passflow…) restent.', 'smbb-wpcodetool'));
                        self::toggle('list_columns', $settings['list_columns'], __('Vignette et ID dans les listes', 'smbb-wpcodetool'), __('Articles, pages et contenus : l\'image mise en avant en petit devant le titre, et l\'ID en fin de ligne.', 'smbb-wpcodetool'));
                        self::toggle('list_duplicate', $settings['list_duplicate'], __('Dupliquer les contenus', 'smbb-wpcodetool'), __('Lien « Dupliquer » sous le titre dans les listes d\'articles, de pages et de contenus, action groupée, et bouton dans la barre du haut quand on modifie un contenu. La copie est un brouillon avec les métas (image, modèle, champs) et les catégories. Marche aussi sans le style moderne ; les produits WooCommerce gardent leur propre « Dupliquer ».', 'smbb-wpcodetool'));
                        self::toggle('list_children', $settings['list_children'], __('Filtre « Enfants de… »', 'smbb-wpcodetool'), __('Pages et contenus hiérarchiques : un bouton en haut de la liste ouvre l\'arbre des contenus qui ont des enfants ; on en choisit un et la liste n\'affiche plus que ses enfants et petits-enfants.', 'smbb-wpcodetool'));
                        self::toggle('palette', $settings['palette'], __('Recherche Ctrl K partout', 'smbb-wpcodetool'), __('Ctrl K (ou ⌘ K) ouvre une recherche dans toute l\'admin : pages du menu, créations et contenus (articles, pages, produits…). Aussi un bouton en haut.', 'smbb-wpcodetool'));
                        ?>
                    </div>
                </section>
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Interrupteur : libellé et explication à gauche, bascule à droite. Le champ caché envoie 0.
     */
    public static function toggle($name, $checked, $label, $note = '')
    {
        $id = 'smbb-appearance-' . $name;
        ?>
        <div class="smbb-appearance-toggle">
            <label class="smbb-appearance-toggle-text" for="<?php echo esc_attr($id); ?>">
                <span class="smbb-theme-name"><?php echo esc_html($label); ?></span>
                <?php if ($note !== '') : ?><span class="smbb-theme-note"><?php echo esc_html($note); ?></span><?php endif; ?>
            </label>
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0">
            <label class="smbb-codetool-toggle">
                <input class="smbb-codetool-toggle-input" type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked((int) $checked, 1); ?>>
                <span class="smbb-codetool-toggle-track" aria-hidden="true"><span class="smbb-codetool-toggle-thumb"></span></span>
            </label>
        </div>
        <?php
    }

    /**
     * Logo de la page de connexion : aperçu en direct, cadre (aucun / arrondi / rond),
     * médiathèque WordPress. Sans logo choisi : le sigle SMBB animé.
     */
    private static function logoField(array $settings)
    {
        $id = $settings['login_logo'];
        $shapes = array(
            'none' => __('Sans cadre', 'smbb-wpcodetool'),
            'rounded' => __('Boîte arrondie', 'smbb-wpcodetool'),
            'circle' => __('Rond', 'smbb-wpcodetool'),
        );
        ?>
        <div class="smbb-appearance-logo" data-smbb-login-logo>
            <span class="smbb-appearance-logo-stage" aria-hidden="true"><?php echo WpSkin::logoHtml($settings); // phpcs:ignore -- HTML construit et échappé par WpSkin. ?></span>
            <div class="smbb-appearance-logo-side">
                <span class="smbb-appearance-logo-text">
                    <span class="smbb-theme-name"><?php esc_html_e('Logo', 'smbb-wpcodetool'); ?></span>
                    <span class="smbb-theme-note"><?php esc_html_e('Sans logo choisi : le sigle SMBB, traversé par un reflet.', 'smbb-wpcodetool'); ?></span>
                </span>
                <div class="smbb-appearance-shapes" role="radiogroup" aria-label="<?php esc_attr_e('Cadre du logo', 'smbb-wpcodetool'); ?>">
                    <?php foreach ($shapes as $value => $label) : ?>
                        <label class="smbb-appearance-shape">
                            <input type="radio" name="login_shape" value="<?php echo esc_attr($value); ?>" <?php checked($settings['login_shape'], $value); ?>>
                            <span class="smbb-appearance-shape-icon is-<?php echo esc_attr($value); ?>" aria-hidden="true"></span>
                            <span><?php echo esc_html($label); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="smbb-appearance-logo-actions">
                    <input type="hidden" name="login_logo" value="<?php echo esc_attr((string) $id); ?>">
                    <button type="button" class="button" data-smbb-logo-pick data-title="<?php esc_attr_e('Logo de la page de connexion', 'smbb-wpcodetool'); ?>" data-button="<?php esc_attr_e('Utiliser ce logo', 'smbb-wpcodetool'); ?>"><?php esc_html_e('Choisir un logo…', 'smbb-wpcodetool'); ?></button>
                    <button type="button" class="button button-link" data-smbb-logo-clear<?php echo $id ? '' : ' hidden'; ?>><?php esc_html_e('Revenir au sigle SMBB', 'smbb-wpcodetool'); ?></button>
                </div>
            </div>
            <template data-smbb-mark><?php echo WpSkin::mark(); // phpcs:ignore -- SVG fixe. ?></template>
        </div>
        <script>
            (function () {
                var box = document.querySelector('[data-smbb-login-logo]');
                if (!box) { return; }
                var input = box.querySelector('input[name="login_logo"]');
                var stage = box.querySelector('.smbb-appearance-logo-stage');
                var clear = box.querySelector('[data-smbb-logo-clear]');
                var pick = box.querySelector('[data-smbb-logo-pick]');
                var mark = box.querySelector('template[data-smbb-mark]');
                var frame = null;
                function logo() { return stage.querySelector('.smbb-login-logo'); }
                function show(src) {
                    var el = logo();
                    el.innerHTML = '';
                    el.classList.toggle('has-image', !!src);
                    el.classList.toggle('has-mark', !src);
                    if (src) {
                        var img = document.createElement('img');
                        img.src = src;
                        img.alt = '';
                        el.appendChild(img);
                    } else {
                        el.appendChild(mark.content.cloneNode(true));
                    }
                }
                box.querySelectorAll('input[name="login_shape"]').forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        var el = logo();
                        el.classList.remove('is-none', 'is-rounded', 'is-circle');
                        el.classList.add('is-' + radio.value);
                    });
                });
                pick.addEventListener('click', function () {
                    if (!window.wp || !window.wp.media) { return; }
                    frame = frame || window.wp.media({ title: pick.getAttribute('data-title'), button: { text: pick.getAttribute('data-button') }, library: { type: 'image' }, multiple: false });
                    frame.off('select').on('select', function () {
                        var a = frame.state().get('selection').first().toJSON();
                        input.value = a.id;
                        show(a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url);
                        clear.hidden = false;
                    });
                    frame.open();
                });
                clear.addEventListener('click', function () {
                    input.value = '0';
                    show('');
                    clear.hidden = true;
                });
            })();
        </script>
        <?php
    }

    public static function menuChoice($value, $current, $label, $note, $name = 'menu_style')
    {
        ?>
        <label class="smbb-choice">
            <input class="smbb-theme-radio" type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($current, $value); ?>>
            <span class="smbb-theme-check dashicons dashicons-yes" aria-hidden="true"></span>
            <span class="smbb-menu-preview is-<?php echo esc_attr($value); ?>" aria-hidden="true"><i class="s"></i><i class="p"></i><b></b><b></b></span>
            <span class="smbb-theme-name"><?php echo esc_html($label); ?></span>
            <span class="smbb-theme-note"><?php echo esc_html($note); ?></span>
        </label>
        <?php
    }

    public static function choice($name, $value, $current, $label, $note, $class)
    {
        ?>
        <label class="smbb-choice<?php echo $current === $value ? ' is-selected' : ''; ?>">
            <input class="smbb-theme-radio" type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($current, $value); ?>>
            <span class="smbb-theme-check dashicons dashicons-yes" aria-hidden="true"></span>
            <span class="smbb-choice-preview <?php echo esc_attr($class); ?>" aria-hidden="true"><i></i><b></b><b></b></span>
            <span class="smbb-theme-name"><?php echo esc_html($label); ?></span>
            <span class="smbb-theme-note"><?php echo esc_html($note); ?></span>
        </label>
        <?php
    }

    /**
     * Carte de thème : une mini-fenêtre aux couleurs du thème (barre, pastille, lignes, bouton).
     *
     * @param array{accent:string,soft:string,bg:string} $colors
     */
    public static function card($key, $label, array $colors, $selected, $extra = '', $note = '', $name = 'theme')
    {
        $style = '--p-accent:' . $colors['accent'] . ';--p-soft:' . $colors['soft'] . ';--p-bg:' . $colors['bg'] . ';';
        $second = !empty($colors['accent2']) ? $colors['accent2'] : $colors['accent'];
        $style .= '--p-grad:linear-gradient(135deg,' . $colors['accent'] . ',' . $second . ');';
        ?>
        <label class="smbb-theme-card<?php echo $selected ? ' is-selected' : ''; ?>">
            <input class="smbb-theme-radio" type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($key); ?>" <?php checked($selected); ?>>
            <span class="smbb-theme-preview" style="<?php echo esc_attr($style); ?>" aria-hidden="true">
                <span class="smbb-theme-preview-bar"><i></i><i></i><i></i></span>
                <span class="smbb-theme-preview-card"><b></b><s></s><s></s></span>
                <span class="smbb-theme-preview-btn"></span>
            </span>
            <span class="smbb-theme-label">
                <span class="smbb-theme-name"><?php echo esc_html($label); ?></span>
                <?php if ($note !== '') : ?><span class="smbb-theme-note"><?php echo esc_html($note); ?></span><?php endif; ?>
                <?php echo $extra; // phpcs:ignore -- échappé par l'appelant. ?>
            </span>
            <span class="smbb-theme-check dashicons dashicons-yes" aria-hidden="true"></span>
        </label>
        <?php
    }

    /** Description de chaque style de menu (Apparence et profil). */
    public static function menuNotes()
    {
        return array(
            'light' => __('Menu blanc, entrée courante en pastille teintée.', 'smbb-wpcodetool'),
            'dark' => __('Le menu WordPress connu, en noir teinté par le thème.', 'smbb-wpcodetool'),
            'rail' => __('Icônes des apps à gauche, pages de l\'app ouverte dans un panneau.', 'smbb-wpcodetool'),
            'float' => __('Le menu en carte détachée du bord, coins arrondis.', 'smbb-wpcodetool'),
        );
    }
}
