<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Admin\WpContent;
use Smbb\WpCodeTool\Media\MediaCategories;
use Smbb\WpCodeTool\Media\MediaTools;

defined('ABSPATH') || exit;

/**
 * Page « Contenu WP » des réglages CodeTool : couper les briques de contenu de WordPress
 * dont le site n'a pas besoin (blog, commentaires…). Voir Admin\WpContent.
 * Section « Médiathèque » : remplacement de fichier et SVG, voir Media\MediaTools.
 */
final class ContentPage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-content';
    const NONCE = 'smbb_codetool_content';

    public static function handle()
    {
        if (!isset($_POST['smbb_codetool_content_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_codetool_content_nonce'])), self::NONCE)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier les réglages de contenu de CodeTool.', 'smbb-wpcodetool'));
        }

        $values = array();

        foreach (array_keys(WpContent::SWITCHES) as $key) {
            $values[$key] = !empty($_POST[$key]) ? 1 : 0;
        }

        WpContent::save($values);

        // Médiathèque (Media\MediaTools) : remplacement de fichier et SVG.
        $roles = array();

        foreach (array_keys(MediaTools::uploadRoles()) as $role) {
            if (!empty($_POST['svg_role_' . $role])) {
                $roles[] = $role;
            }
        }

        MediaTools::save(array(
            'replace' => !empty($_POST['media_replace']) ? 1 : 0,
            'svg' => !empty($_POST['svg_upload']) ? 1 : 0,
            'svg_roles' => $roles,
        ));

        // Catégories de médias (Media\MediaCategories).
        MediaCategories::setEnabled(!empty($_POST['media_cats']));

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&saved=1'));
        exit;
    }

    public function render()
    {
        $manager = $this->manager();
        $s = WpContent::settings();
        $media = MediaTools::settings();
        $notices = array();

        if (isset($_GET['saved'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $notices[] = '<div class="smbb-codetool-notice is-success"><p class="smbb-codetool-notice-message">' . esc_html__('Réglages de contenu enregistrés.', 'smbb-wpcodetool') . '</p></div>';
        }

        $posts = wp_count_posts('post');
        $published = $posts ? (int) $posts->publish + (int) $posts->draft + (int) $posts->future + (int) $posts->private : 0;
        $comments = wp_count_comments();
        $comment_count = $comments ? (int) $comments->total_comments : 0;
        $blog_home = get_option('show_on_front') === 'posts';
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
                __('Contenu WP', 'smbb-wpcodetool'),
                __('Coupez les briques de WordPress dont le site n\'a pas besoin : elles disparaissent de l\'admin et du site. Rien n\'est supprimé en base, il suffit de recocher pour tout retrouver.', 'smbb-wpcodetool'),
                'dashicons-media-document',
                $notices
            );
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">
                <?php wp_nonce_field(self::NONCE, 'smbb_codetool_content_nonce'); ?>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-admin-post"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Blog et commentaires', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Pour les sites vitrine, boutiques ou applis qui n\'ont pas de blog.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php
                        AppearancePage::toggle('posts_off', $s['posts_off'], __('Pas de blog : couper les articles', 'smbb-wpcodetool'), __('Admin : plus de menu Articles (ni catégories, étiquettes, réglages d\'écriture), plus de « Article » dans « + Nouveau », plus de Brouillon rapide ni d\'Activité à l\'accueil, écrans des articles redirigés. Site : articles seuls, catégories, étiquettes et archives en 404, flux RSS renvoyés vers l\'accueil, articles retirés de la recherche, du plan du site, des menus et des widgets. Les pages, produits et autres contenus ne bougent pas.', 'smbb-wpcodetool'));

                        if ($published > 0) :
                            ?>
                            <p class="description"><?php echo esc_html(sprintf(_n('%d article existe déjà : il reste en base mais ne sera plus visible.', '%d articles existent déjà : ils restent en base mais ne seront plus visibles.', $published, 'smbb-wpcodetool'), $published)); ?></p>
                            <?php
                        endif;

                        if ($blog_home) :
                            ?>
                            <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('La page d\'accueil du site affiche « les derniers articles ». Sans blog, choisissez une page fixe dans Réglages › Lecture.', 'smbb-wpcodetool'); ?> <a href="<?php echo esc_url(admin_url('options-reading.php')); ?>"><?php esc_html_e('Ouvrir', 'smbb-wpcodetool'); ?></a></p>
                            <?php
                        endif;

                        AppearancePage::toggle('comments_off', $s['comments_off'], __('Couper les commentaires partout', 'smbb-wpcodetool'), __('Plus de formulaire ni de commentaires affichés sur le site, plus d\'envoi possible (formulaire, API, pings), et plus rien dans l\'admin. Les commentaires existants restent en base.', 'smbb-wpcodetool'));

                        if ($comment_count > 0) :
                            ?>
                            <p class="description"><?php echo esc_html(sprintf(_n('%d commentaire en base.', '%d commentaires en base.', $comment_count, 'smbb-wpcodetool'), $comment_count)); ?></p>
                            <?php
                        endif;
                        ?>
                    </div>
                </section>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-shield"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Pages et fonctions inutiles', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Ce que WordPress publie tout seul et dont on se passe presque toujours. Moins de pages fantômes, moins de prise pour les robots.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php
                        AppearancePage::toggle('attachment_pages_off', $s['attachment_pages_off'], __('Pas de page par média', 'smbb-wpcodetool'), __('WordPress crée une page pour chaque image envoyée. Elle redirige maintenant vers le fichier lui-même.', 'smbb-wpcodetool'));
                        AppearancePage::toggle('author_archives_off', $s['author_archives_off'], __('Pas de pages auteur', 'smbb-wpcodetool'), __('Les pages /author/… et ?author=1 répondent 404, le plan du site ne liste plus les comptes, et l\'API ne donne plus la liste des comptes aux visiteurs (elle reste pour les personnes connectées). Les identifiants de connexion ne se devinent plus.', 'smbb-wpcodetool'));
                        AppearancePage::toggle('xmlrpc_off', $s['xmlrpc_off'], __('Couper XML-RPC', 'smbb-wpcodetool'), __('Vieille API de WordPress, remplacée par l\'API REST, et cible favorite des robots qui testent les mots de passe. À laisser si vous publiez depuis l\'ancienne appli mobile WordPress ou Jetpack.', 'smbb-wpcodetool'));
                        AppearancePage::toggle('emojis_off', $s['emojis_off'], __('Pas de script d\'émojis', 'smbb-wpcodetool'), __('WordPress charge un script et des styles sur chaque page pour remplacer les émojis par des images. Les navigateurs savent les afficher seuls : une requête et un peu de code en moins.', 'smbb-wpcodetool'));
                        ?>
                    </div>
                </section>
                <section class="smbb-codetool-section">
                    <header class="smbb-codetool-section-header">
                        <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-admin-media"></span></span>
                        <div class="smbb-codetool-section-heading">
                            <h3><?php esc_html_e('Médiathèque', 'smbb-wpcodetool'); ?></h3>
                            <p class="description"><?php esc_html_e('Ce qui manque à la médiathèque de WordPress.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </header>
                    <div class="smbb-codetool-section-body">
                        <?php
                        AppearancePage::toggle('media_replace', $media['replace'], __('Remplacer un fichier sans changer son adresse', 'smbb-wpcodetool'), __('Action « Remplacer le fichier » dans la liste des médias, la fenêtre des médias et l\'écran d\'édition. Le média garde son ID, son URL et son nom : partout où il est utilisé, le nouveau fichier s\'affiche. Miniatures recalculées, caches vidés. Autre format (PNG à la place d\'un JPG) : conversion pour garder l\'URL, ou nouvelle extension avec liens corrigés et redirection.', 'smbb-wpcodetool'));
                        AppearancePage::toggle('media_cats', MediaCategories::enabled() ? 1 : 0, __('Catégories de médias colorées', 'smbb-wpcodetool'), __('Panneau d\'arborescence dans la médiathèque (grille et liste) : catégories et sous-catégories avec une couleur chacune, filtre au clic, classement en glissant les médias dessus ou en masse avec « Classer… ». Un média peut être dans plusieurs catégories ; une pastille par catégorie sur sa vignette, son nom au survol. Couper le réglage cache tout, le classement reste en base.', 'smbb-wpcodetool'));
                        AppearancePage::toggle('svg_upload', $media['svg'], __('Autoriser l\'envoi de SVG', 'smbb-wpcodetool'), __('WordPress refuse les SVG parce qu\'ils peuvent contenir du code. Ici chaque SVG est nettoyé à l\'envoi : scripts, événements, liens externes et contenus HTML retirés ; les fichiers douteux (entités XML, SVGZ) sont refusés. Réservé aux rôles cochés ci-dessous.', 'smbb-wpcodetool'));
                        ?>
                        <div class="smbb-media-svg-roles">
                            <p class="description"><strong><?php esc_html_e('Rôles autorisés à envoyer des SVG', 'smbb-wpcodetool'); ?></strong> · <?php esc_html_e('seuls les rôles qui peuvent envoyer des fichiers sont proposés.', 'smbb-wpcodetool'); ?></p>
                            <?php
                            foreach (MediaTools::uploadRoles() as $role => $label) {
                                AppearancePage::toggle('svg_role_' . $role, in_array($role, $media['svg_roles'], true) ? 1 : 0, $label);
                            }
                            ?>
                        </div>
                    </div>
                </section>
                <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button></p>
            </form>
        </div>
        <?php
    }
}
