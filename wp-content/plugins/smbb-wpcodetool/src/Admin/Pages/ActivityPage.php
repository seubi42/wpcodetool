<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Activity\ActivityLog;

defined('ABSPATH') || exit;

/**
 * Pages « Journal d'activité » et « Réglages du journal » du groupe Sécurité de CodeTool.
 * Deux pages sœurs : la barre d'app les montre en 2e niveau (pas d'onglets maison).
 *
 * - Journal : qui a fait quoi et quand, filtres (catégorie, gravité, personne, dates),
 *   recherche, détail des changements (avant / après), historique d'un objet, export CSV.
 * - Réglages : durée de conservation, ce qui est noté, adresses IP, purge et effacement.
 */
final class ActivityPage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-activity';
    const SETTINGS_SLUG = 'smbb-wpcodetool-activity-settings';
    const NONCE = 'smbb_codetool_activity';
    const PER_PAGE = 50;

    /**
     * @return string[]
     */
    public static function slugs()
    {
        return array(self::SLUG, self::SETTINGS_SLUG);
    }

    /**
     * Adresse d'une vue : '' (journal) ou 'settings'.
     */
    public static function url($view = '', array $args = array())
    {
        return add_query_arg($args, admin_url('admin.php?page=' . ($view === 'settings' ? self::SETTINGS_SLUG : self::SLUG)));
    }

    // ------------------------------------------------------------------ Actions

    public static function handle()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            self::export();
        }

        if (empty($_POST['smbb_activity_do'])) {
            return;
        }
        // phpcs:enable

        if (!current_user_can(ActivityLog::capability())
            || !isset($_POST['smbb_activity_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_activity_nonce'])), self::NONCE)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de régler le journal d\'activité.', 'smbb-wpcodetool'));
        }

        $do = sanitize_key(wp_unslash($_POST['smbb_activity_do']));

        if ($do === 'save') {
            self::save();
        } elseif ($do === 'purge') {
            $count = ActivityLog::purge();
            ActivityLog::log('activity.purged', array(
                'level' => 'notice',
                'message' => sprintf(__('Purge manuelle du journal : %d événement(s) effacé(s)', 'smbb-wpcodetool'), $count),
                'context' => array('rows' => $count, 'days' => ActivityLog::days()),
            ));
            self::flash('success', sprintf(_n('%d événement effacé.', '%d événements effacés.', $count, 'smbb-wpcodetool'), $count));
        } elseif ($do === 'clear') {
            $confirm = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash($_POST['confirm'])) : '';

            if (strtoupper($confirm) !== 'EFFACER') {
                self::flash('error', __('Tapez EFFACER pour confirmer l\'effacement du journal.', 'smbb-wpcodetool'));
            } else {
                $count = ActivityLog::clear();
                self::flash('success', sprintf(_n('Journal effacé (%d événement). L\'effacement lui-même reste noté.', 'Journal effacé (%d événements). L\'effacement lui-même reste noté.', $count, 'smbb-wpcodetool'), $count));
            }
        }

        wp_safe_redirect(self::url('settings'));
        exit;
    }

    private static function save()
    {
        $before = ActivityLog::settings();
        $categories = isset($_POST['categories']) && is_array($_POST['categories']) ? wp_unslash($_POST['categories']) : array(); // phpcs:ignore -- clés et 0/1 nettoyés par saveSettings.
        $after = ActivityLog::saveSettings(array(
            'days' => isset($_POST['days']) ? absint($_POST['days']) : 90,
            'ip' => isset($_POST['ip']) ? sanitize_key(wp_unslash($_POST['ip'])) : 'full',
            'failed_logins' => !empty($_POST['failed_logins']),
            'orders_all' => !empty($_POST['orders_all']),
            'customers' => !empty($_POST['customers']),
            'categories' => array_map('absint', (array) $categories),
        ));

        $changes = array();
        $labels = array(
            'days' => __('Conservation', 'smbb-wpcodetool'),
            'ip' => __('Adresses IP', 'smbb-wpcodetool'),
            'failed_logins' => __('Échecs de connexion', 'smbb-wpcodetool'),
            'orders_all' => __('Commandes des clients et automatiques', 'smbb-wpcodetool'),
            'customers' => __('Comptes clients', 'smbb-wpcodetool'),
        );

        foreach ($labels as $key => $label) {
            if ((string) $before[$key] !== (string) $after[$key]) {
                $changes[$label] = array((string) $before[$key], (string) $after[$key]);
            }
        }

        foreach ($after['categories'] as $key => $on) {
            if ((int) ($before['categories'][$key] ?? 1) !== (int) $on) {
                $changes[ActivityLog::category($key)[0]] = array($on ? 'non noté' : 'noté', $on ? 'noté' : 'non noté');
            }
        }

        if ($changes) {
            ActivityLog::log('activity.settings_saved', array(
                // Couper une catégorie = moins de traces : à voir.
                'level' => 'warning',
                'message' => __('Réglages du journal d\'activité modifiés', 'smbb-wpcodetool'),
                'context' => array('changes' => $changes),
            ));
        }

        self::flash('success', __('Réglages du journal enregistrés.', 'smbb-wpcodetool'));
    }

    private static function filtersFromRequest()
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        return ActivityLog::normalizeFilters(array_map(static function ($value) {
            return is_scalar($value) ? (string) wp_unslash($value) : '';
        }, $_GET));
    }

    /**
     * Export CSV de la vue filtrée (50 000 lignes au plus). L'export est lui-même noté.
     */
    private static function export()
    {
        if (!current_user_can(ActivityLog::capability()) || !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::NONCE . '_csv')) {
            wp_die(esc_html__('Lien d\'export expiré : rechargez la page du journal.', 'smbb-wpcodetool'));
        }

        $filters = self::filtersFromRequest();
        ActivityLog::log('activity.exported', array(
            'level' => 'notice',
            'message' => __('Export CSV du journal d\'activité', 'smbb-wpcodetool'),
            'context' => array('filters' => array_filter($filters)),
        ));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="journal-activite-' . wp_date('Y-m-d-His') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8.
        fputcsv($out, array('id', 'date', 'personne', 'identifiant', 'rôle', 'catégorie', 'gravité', 'événement', 'message', 'objet', 'id objet', 'ip', 'via', 'répétitions', 'dernière fois', 'détails'), ';');

        foreach (ActivityLog::iterate($filters) as $row) {
            fputcsv($out, array(
                $row['id'],
                get_date_from_gmt($row['created_at'], 'Y-m-d H:i:s'),
                $row['user_name'],
                $row['user_login'],
                $row['user_role'],
                ActivityLog::category($row['category'])[0],
                $row['level'],
                $row['event'],
                $row['message'],
                $row['object_label'] !== '' ? $row['object_label'] : $row['object_type'],
                $row['object_id'],
                $row['ip'],
                $row['via'],
                $row['hits'],
                (int) $row['hits'] > 1 ? get_date_from_gmt($row['last_at'], 'Y-m-d H:i:s') : '',
                $row['context'],
            ), ';');
        }

        fclose($out);
        exit;
    }

    private static function flash($type, $message)
    {
        set_transient('smbb_activity_flash_' . get_current_user_id(), array($type, $message), 120);
    }

    private function notices()
    {
        $key = 'smbb_activity_flash_' . get_current_user_id();
        $flash = get_transient($key);
        $notices = array();

        if (is_array($flash)) {
            delete_transient($key);
            $notices[] = '<div class="smbb-codetool-notice is-' . esc_attr($flash[0]) . '"><p class="smbb-codetool-notice-message">' . esc_html($flash[1]) . '</p></div>';
        }

        if (!ActivityLog::ready()) {
            $notices[] = '<div class="smbb-codetool-notice is-info"><p class="smbb-codetool-notice-message">' . esc_html__('La table du journal se crée au prochain chargement d\'une page d\'admin.', 'smbb-wpcodetool') . '</p></div>';
        }

        return $notices;
    }

    // ------------------------------------------------------------------ Rendu

    public function render($view = '')
    {
        if (!current_user_can(ActivityLog::capability())) {
            wp_die(esc_html__('Vous n\'avez pas le droit de lire le journal d\'activité.', 'smbb-wpcodetool'));
        }

        $settings = $view === 'settings';
        $days = ActivityLog::days();
        ?>
        <div class="wrap smbb-codetool smbb-activity-page">
            <?php
            echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
                $settings ? __('Réglages du journal', 'smbb-wpcodetool') : __('Journal d\'activité', 'smbb-wpcodetool'),
                $settings
                    ? __('Ce qui est noté, combien de temps on le garde, et le ménage.', 'smbb-wpcodetool')
                    : ($days > 0
                        ? sprintf(__('Qui a fait quoi et quand dans l\'administration : connexions, contenus, extensions, comptes, réglages, commandes. Gardé %d jours.', 'smbb-wpcodetool'), $days)
                        : __('Qui a fait quoi et quand dans l\'administration : connexions, contenus, extensions, comptes, réglages, commandes.', 'smbb-wpcodetool')),
                $settings ? 'dashicons-admin-settings' : 'dashicons-list-view',
                $this->notices()
            );

            if ($settings) {
                $this->renderSettings();
            } else {
                $this->renderLog();
            }
            ?>
        </div>
        <?php
    }

    private function renderLog()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable
        $filters = self::filtersFromRequest();
        $data = ActivityLog::query($filters, $paged, self::PER_PAGE);
        $counts = ActivityLog::countsByCategory($filters);
        $categories = ActivityLog::categories();
        $pages = max(1, (int) ceil($data['total'] / self::PER_PAGE));
        $keep = array_filter($filters, 'strlen');
        $active = array_diff_key($keep, array('category' => 1));

        // Catégories inconnues mais présentes (plugin désactivé depuis).
        foreach (array_keys($counts) as $key) {
            if (!isset($categories[$key])) {
                $categories[$key] = ActivityLog::category($key);
            }
        }
        ?>
        <nav class="smbb-activity-cats" aria-label="<?php esc_attr_e('Catégories', 'smbb-wpcodetool'); ?>">
            <a class="smbb-activity-cat<?php echo $filters['category'] === '' ? ' is-active' : ''; ?>" href="<?php echo esc_url(self::url('', array_diff_key($keep, array('category' => 1)))); ?>">
                <span class="dashicons dashicons-screenoptions" aria-hidden="true"></span><?php esc_html_e('Tout', 'smbb-wpcodetool'); ?>
                <span class="smbb-activity-cat-count"><?php echo esc_html(number_format_i18n(array_sum($counts))); ?></span>
            </a>
            <?php foreach ($categories as $key => $def) : ?>
                <?php if (empty($counts[$key]) && $filters['category'] !== $key) { continue; } ?>
                <a class="smbb-activity-cat<?php echo $filters['category'] === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url(self::url('', array('category' => $key) + $keep)); ?>">
                    <span class="dashicons <?php echo esc_attr($def[1]); ?>" aria-hidden="true"></span><?php echo esc_html($def[0]); ?>
                    <span class="smbb-activity-cat-count"><?php echo esc_html(number_format_i18n((int) ($counts[$key] ?? 0))); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <form method="get" class="smbb-activity-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <?php if ($filters['category'] !== '') : ?><input type="hidden" name="category" value="<?php echo esc_attr($filters['category']); ?>"><?php endif; ?>
            <?php if ($filters['object_type'] !== '') : ?>
                <input type="hidden" name="object_type" value="<?php echo esc_attr($filters['object_type']); ?>">
                <input type="hidden" name="object_id" value="<?php echo esc_attr($filters['object_id']); ?>">
            <?php endif; ?>
            <label class="smbb-activity-search">
                <span class="screen-reader-text"><?php esc_html_e('Rechercher', 'smbb-wpcodetool'); ?></span>
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <input type="search" name="s" value="<?php echo esc_attr($filters['s']); ?>" placeholder="<?php esc_attr_e('Action, personne, objet, IP…', 'smbb-wpcodetool'); ?>">
            </label>
            <label>
                <span class="smbb-activity-filter-label"><?php esc_html_e('Qui', 'smbb-wpcodetool'); ?></span>
                <select name="user">
                    <option value=""><?php esc_html_e('Tout le monde', 'smbb-wpcodetool'); ?></option>
                    <option value="0"<?php selected($filters['user'], '0'); ?>><?php esc_html_e('Visiteurs et système', 'smbb-wpcodetool'); ?></option>
                    <?php foreach (ActivityLog::people() as $id => $name) : ?>
                        <option value="<?php echo esc_attr($id); ?>"<?php selected($filters['user'], (string) $id); ?>><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="smbb-activity-filter-label"><?php esc_html_e('Gravité', 'smbb-wpcodetool'); ?></span>
                <select name="level">
                    <option value=""><?php esc_html_e('Toutes', 'smbb-wpcodetool'); ?></option>
                    <?php foreach (ActivityLog::levels() as $key => $label) : ?>
                        <?php if ($key === 'info') { continue; } ?>
                        <option value="<?php echo esc_attr($key); ?>"<?php selected($filters['level'], $key); ?>><?php echo esc_html(sprintf(__('%s et plus', 'smbb-wpcodetool'), $label)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="smbb-activity-filter-label"><?php esc_html_e('Du', 'smbb-wpcodetool'); ?></span>
                <input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>">
            </label>
            <label>
                <span class="smbb-activity-filter-label"><?php esc_html_e('Au', 'smbb-wpcodetool'); ?></span>
                <input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>">
            </label>
            <span class="smbb-activity-filter-actions">
                <button class="button button-primary"><?php esc_html_e('Filtrer', 'smbb-wpcodetool'); ?></button>
                <?php if ($active) : ?>
                    <a class="button" href="<?php echo esc_url(self::url('', $filters['category'] !== '' ? array('category' => $filters['category']) : array())); ?>"><?php esc_html_e('Effacer les filtres', 'smbb-wpcodetool'); ?></a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(self::url('', $keep + array('export' => 'csv')), self::NONCE . '_csv')); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e('CSV', 'smbb-wpcodetool'); ?></a>
            </span>
        </form>

        <?php if ($filters['object_type'] !== '') : ?>
            <p class="smbb-activity-object-filter">
                <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                <?php echo esc_html(sprintf(__('Historique de : %s', 'smbb-wpcodetool'), self::objectName($filters['object_type'], $filters['object_id'], $data['rows']))); ?>
                <a href="<?php echo esc_url(self::url('', array_diff_key($keep, array('object_type' => 1, 'object_id' => 1)))); ?>"><?php esc_html_e('voir tout le journal', 'smbb-wpcodetool'); ?></a>
            </p>
        <?php endif; ?>

        <div class="tablenav top">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html(sprintf(_n('%s événement', '%s événements', $data['total'], 'smbb-wpcodetool'), number_format_i18n($data['total']))); ?></span>
                <?php
                if ($pages > 1) {
                    echo wp_kses_post(paginate_links(array(
                        'base' => add_query_arg('paged', '%#%', self::url('', $keep)),
                        'format' => '',
                        'current' => $paged,
                        'total' => $pages,
                    )));
                }
                ?>
            </div>
        </div>

        <div class="smbb-activity-list">
            <?php if (!$data['rows']) : ?>
                <p class="smbb-activity-empty"><?php echo esc_html($keep ? __('Aucun événement ne correspond.', 'smbb-wpcodetool') : __('Rien n\'a encore été noté.', 'smbb-wpcodetool')); ?></p>
            <?php endif; ?>
            <?php
            $day = '';

            foreach ($data['rows'] as $row) {
                $row_day = get_date_from_gmt($row['created_at'], 'Y-m-d');

                if ($row_day !== $day) {
                    $day = $row_day;
                    echo '<h3 class="smbb-activity-day">' . esc_html(self::dayLabel($row['created_at'])) . '</h3>';
                }

                $this->renderRow($row, $keep);
            }
            ?>
        </div>

        <?php if ($pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(paginate_links(array(
                        'base' => add_query_arg('paged', '%#%', self::url('', $keep)),
                        'format' => '',
                        'current' => $paged,
                        'total' => $pages,
                    )));
                    ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    private static function dayLabel($gmt)
    {
        $day = get_date_from_gmt($gmt, 'Y-m-d');
        $today = wp_date('Y-m-d');
        $yesterday = wp_date('Y-m-d', time() - DAY_IN_SECONDS);

        if ($day === $today) {
            return __('Aujourd\'hui', 'smbb-wpcodetool');
        }

        if ($day === $yesterday) {
            return __('Hier', 'smbb-wpcodetool');
        }

        return wp_date('l j F Y', strtotime($gmt . ' UTC'));
    }

    private static function objectName($type, $id, array $rows)
    {
        foreach ($rows as $row) {
            if ($row['object_label'] !== '') {
                return $row['object_label'];
            }
        }

        return $type . ($id !== '' ? ' #' . $id : '');
    }

    /**
     * Lien vers l'objet touché (filtre smbb_wpcodetool_activity_object_link pour les objets des plugins).
     */
    public static function objectLink(array $row)
    {
        $id = (string) $row['object_id'];
        $url = '';

        switch ($row['object_type']) {
            case 'post':
                $url = get_post((int) $id) ? (string) get_edit_post_link((int) $id, 'raw') : '';
                break;
            case 'user':
                $url = get_userdata((int) $id) ? get_edit_user_link((int) $id) : '';
                break;
            case 'order':
                if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'get_order_admin_edit_url')) {
                    $url = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url((int) $id);
                } elseif ($id !== '') {
                    $url = admin_url('post.php?post=' . (int) $id . '&action=edit');
                }
                break;
            case 'term':
                $context = json_decode((string) $row['context'], true);
                $taxonomy = is_array($context) && !empty($context['taxonomy']) ? (string) $context['taxonomy'] : '';
                $link = $taxonomy !== '' && term_exists((int) $id, $taxonomy) ? get_edit_term_link((int) $id, $taxonomy) : '';
                $url = is_string($link) ? $link : '';
                break;
            case 'menu':
                $url = admin_url('nav-menus.php?action=edit&menu=' . (int) $id);
                break;
            case 'plugin':
                $url = admin_url('plugins.php');
                break;
            case 'theme':
                $url = admin_url('themes.php');
                break;
            case 'role':
                $url = class_exists(RolesPage::class) && get_role($id) ? RolesPage::url('edit', array('role' => $id)) : '';
                break;
        }

        return (string) apply_filters('smbb_wpcodetool_activity_object_link', $url, $row);
    }

    private function renderRow(array $row, array $keep)
    {
        $category = ActivityLog::category($row['category']);
        $levels = ActivityLog::levels();
        $level = isset($levels[$row['level']]) ? $row['level'] : 'info';
        $context = json_decode((string) $row['context'], true);
        $context = is_array($context) ? $context : array();
        $changes = isset($context['changes']) && is_array($context['changes']) ? $context['changes'] : array();
        unset($context['changes']);
        $link = self::objectLink($row);
        $hits = (int) $row['hits'];
        ?>
        <article class="smbb-activity-row is-<?php echo esc_attr($level); ?>">
            <time class="smbb-activity-time" datetime="<?php echo esc_attr(mysql2date('c', $row['created_at'] . ' +00:00')); ?>" title="<?php echo esc_attr(get_date_from_gmt($row['created_at'], 'd/m/Y H:i:s')); ?>">
                <?php echo esc_html(get_date_from_gmt($row['created_at'], 'H:i')); ?>
            </time>
            <span class="smbb-activity-icon" title="<?php echo esc_attr($category[0]); ?>"><span class="dashicons <?php echo esc_attr($category[1]); ?>" aria-hidden="true"></span></span>
            <div class="smbb-activity-main">
                <p class="smbb-activity-message">
                    <?php echo esc_html($row['message']); ?>
                    <?php if ($level !== 'info') : ?><span class="smbb-activity-level is-<?php echo esc_attr($level); ?>"><?php echo esc_html($levels[$level]); ?></span><?php endif; ?>
                    <?php if ($hits > 1) : ?><span class="smbb-activity-hits" title="<?php echo esc_attr(sprintf(__('Dernière fois : %s', 'smbb-wpcodetool'), get_date_from_gmt($row['last_at'], 'd/m/Y H:i:s'))); ?>">× <?php echo esc_html(number_format_i18n($hits)); ?></span><?php endif; ?>
                </p>
                <p class="smbb-activity-meta">
                    <?php $this->renderWho($row); ?>
                    <span class="smbb-activity-cat-name"><?php echo esc_html($category[0]); ?></span>
                    <?php if ($link !== '') : ?>
                        <a href="<?php echo esc_url($link); ?>"><?php esc_html_e('Ouvrir', 'smbb-wpcodetool'); ?></a>
                    <?php endif; ?>
                    <?php if ($row['object_type'] !== '' && $row['object_id'] !== '' && empty($keep['object_type'])) : ?>
                        <a href="<?php echo esc_url(self::url('', array('object_type' => $row['object_type'], 'object_id' => $row['object_id']))); ?>"><?php esc_html_e('Historique', 'smbb-wpcodetool'); ?></a>
                    <?php endif; ?>
                </p>
                <details class="smbb-activity-details">
                    <summary><?php echo esc_html($changes ? sprintf(_n('%d changement', '%d changements', count($changes), 'smbb-wpcodetool'), count($changes)) : __('Détails', 'smbb-wpcodetool')); ?></summary>
                    <?php if ($changes) : ?>
                        <table class="smbb-activity-changes">
                            <thead><tr><th><?php esc_html_e('Champ', 'smbb-wpcodetool'); ?></th><th><?php esc_html_e('Avant', 'smbb-wpcodetool'); ?></th><th><?php esc_html_e('Après', 'smbb-wpcodetool'); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($changes as $field => $pair) : ?>
                                    <?php $pair = is_array($pair) ? array_values($pair) : array('', $pair); ?>
                                    <tr>
                                        <th scope="row"><?php echo esc_html((string) $field); ?></th>
                                        <td class="is-before"><?php echo esc_html(self::plain($pair[0] ?? '')); ?></td>
                                        <td class="is-after"><?php echo esc_html(self::plain($pair[1] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <dl class="smbb-activity-tech">
                        <?php foreach ($context as $key => $value) : ?>
                            <dt><?php echo esc_html((string) $key); ?></dt><dd><?php echo esc_html(self::plain($value)); ?></dd>
                        <?php endforeach; ?>
                        <dt><?php esc_html_e('Événement', 'smbb-wpcodetool'); ?></dt><dd><code><?php echo esc_html($row['event']); ?></code><?php echo $row['source'] !== '' && $row['source'] !== 'core' ? ' · ' . esc_html($row['source']) : ''; ?></dd>
                        <dt><?php esc_html_e('Date', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(get_date_from_gmt($row['created_at'], 'd/m/Y H:i:s')); ?><?php echo $hits > 1 ? ' → ' . esc_html(get_date_from_gmt($row['last_at'], 'd/m/Y H:i:s')) : ''; ?></dd>
                        <?php if ($row['ip'] !== '') : ?><dt><?php esc_html_e('Adresse IP', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($row['ip']); ?></dd><?php endif; ?>
                        <dt><?php esc_html_e('Par', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(self::viaLabel($row['via'])); ?></dd>
                        <?php if ($row['url'] !== '') : ?><dt><?php esc_html_e('Adresse', 'smbb-wpcodetool'); ?></dt><dd><code><?php echo esc_html($row['url']); ?></code></dd><?php endif; ?>
                        <?php if ($row['user_agent'] !== '') : ?><dt><?php esc_html_e('Navigateur', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($row['user_agent']); ?></dd><?php endif; ?>
                    </dl>
                </details>
            </div>
        </article>
        <?php
    }

    private function renderWho(array $row)
    {
        if ((int) $row['user_id'] > 0) {
            $exists = (bool) get_userdata((int) $row['user_id']);
            $name = $row['user_name'] !== '' ? $row['user_name'] : $row['user_login'];
            ?>
            <a class="smbb-activity-who" href="<?php echo esc_url(self::url('', array('user' => (int) $row['user_id']))); ?>" title="<?php echo esc_attr(sprintf(__('Tout ce qu\'a fait %s', 'smbb-wpcodetool'), $name)); ?>">
                <?php echo $exists ? get_avatar((int) $row['user_id'], 20, '', '', array('class' => 'smbb-activity-avatar')) : ''; // phpcs:ignore -- HTML de WordPress. ?>
                <strong><?php echo esc_html($name); ?></strong>
                <?php if ($row['user_role'] !== '') : ?><span class="smbb-activity-role"><?php echo esc_html(self::roleList($row['user_role'])); ?></span><?php endif; ?>
                <?php if (!$exists) : ?><span class="smbb-activity-role"><?php esc_html_e('compte supprimé', 'smbb-wpcodetool'); ?></span><?php endif; ?>
            </a>
            <?php
            return;
        }

        $labels = array(
            'cron' => __('Tâche planifiée', 'smbb-wpcodetool'),
            'cli' => __('WP-CLI', 'smbb-wpcodetool'),
        );
        $label = isset($labels[$row['via']]) ? $labels[$row['via']] : __('Visiteur', 'smbb-wpcodetool');
        ?>
        <span class="smbb-activity-who is-anonymous"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><strong><?php echo esc_html($label); ?></strong><?php echo $row['ip'] !== '' ? ' <span class="smbb-activity-role">' . esc_html($row['ip']) . '</span>' : ''; ?></span>
        <?php
    }

    private static function roleList($roles)
    {
        $names = wp_roles()->get_names();
        $out = array();

        foreach (array_filter(explode(',', (string) $roles)) as $role) {
            $out[] = isset($names[$role]) ? translate_user_role($names[$role]) : $role;
        }

        return implode(', ', $out);
    }

    private static function viaLabel($via)
    {
        $labels = array(
            'admin' => __('administration', 'smbb-wpcodetool'),
            'web' => __('site public / page de connexion', 'smbb-wpcodetool'),
            'rest' => __('API REST (éditeur de blocs, application)', 'smbb-wpcodetool'),
            'ajax' => __('requête AJAX', 'smbb-wpcodetool'),
            'cron' => __('tâche planifiée', 'smbb-wpcodetool'),
            'cli' => __('WP-CLI', 'smbb-wpcodetool'),
            'xmlrpc' => __('XML-RPC', 'smbb-wpcodetool'),
        );

        return isset($labels[$via]) ? $labels[$via] : (string) $via;
    }

    private static function plain($value)
    {
        if (is_bool($value)) {
            return $value ? __('oui', 'smbb-wpcodetool') : __('non', 'smbb-wpcodetool');
        }

        if (is_array($value)) {
            $flat = array_filter($value, 'is_scalar') === $value && array_keys($value) === range(0, count($value) - 1);

            return $flat ? implode(', ', array_map('strval', $value)) : (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = (string) $value;

        return $value === '' ? '—' : $value;
    }

    // ------------------------------------------------------------------ Réglages

    private function sectionStart($icon, $title, $copy = '')
    {
        ?>
        <section class="smbb-codetool-section">
            <header class="smbb-codetool-section-header">
                <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr($icon); ?>"></span></span>
                <div class="smbb-codetool-section-heading">
                    <h3><?php echo esc_html($title); ?></h3>
                    <?php if ($copy !== '') : ?><span class="description"><?php echo esc_html($copy); ?></span><?php endif; ?>
                </div>
            </header>
            <div class="smbb-codetool-section-body">
        <?php
    }

    private function sectionEnd()
    {
        echo '</div></section>';
    }

    private function renderSettings()
    {
        $settings = ActivityLog::settings();
        $stats = ActivityLog::stats();
        $descriptions = array(
            'login' => __('Connexions, déconnexions, échecs, mots de passe oubliés et réinitialisés.', 'smbb-wpcodetool'),
            'security' => __('Double authentification, blocages, protections de la page de connexion.', 'smbb-wpcodetool'),
            'content' => __('Pages, articles, produits, médias, catégories, menus : création, modification, publication, corbeille.', 'smbb-wpcodetool'),
            'plugins' => __('Activation, désactivation, installation, mise à jour, suppression, changement de thème.', 'smbb-wpcodetool'),
            'users' => __('Comptes créés, modifiés, supprimés, rôles et droits.', 'smbb-wpcodetool'),
            'settings' => __('Réglages de WordPress, de WooCommerce et des plugins CodeTool (avant / après).', 'smbb-wpcodetool'),
            'woocommerce' => __('Statut, modifications par l\'équipe, remboursements, corbeille, suppression.', 'smbb-wpcodetool'),
        );
        ?>
        <form method="post" action="<?php echo esc_url(self::url('settings')); ?>" class="smbb-activity-settings">
            <?php wp_nonce_field(self::NONCE, 'smbb_activity_nonce'); ?>
            <input type="hidden" name="smbb_activity_do" value="save">

            <?php $this->sectionStart('dashicons-clock', __('Conservation', 'smbb-wpcodetool'), __('Au-delà, les événements sont effacés tout seuls chaque nuit (tâche cron « Purge du journal d\'activité »).', 'smbb-wpcodetool')); ?>
                <p>
                    <label class="smbb-codetool-field-label" for="smbb-activity-days"><?php esc_html_e('Garder les événements', 'smbb-wpcodetool'); ?></label><br>
                    <select id="smbb-activity-days" name="days">
                        <?php foreach (ActivityLog::durations() as $days => $label) : ?>
                            <option value="<?php echo esc_attr($days); ?>"<?php selected((int) $settings['days'], $days); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="description"><?php echo esc_html(sprintf(__('Dans tous les cas, au plus %s événements (les plus anciens partent d\'abord).', 'smbb-wpcodetool'), number_format_i18n(ActivityLog::maxRows()))); ?></span>
                </p>
            <?php $this->sectionEnd(); ?>

            <?php $this->sectionStart('dashicons-visibility', __('Ce qui est noté', 'smbb-wpcodetool'), __('Les opérations sur le journal lui-même (réglages, purge, effacement, export) sont toujours notées.', 'smbb-wpcodetool')); ?>
                <?php foreach (ActivityLog::categories() as $key => $def) : ?>
                    <?php if ($key === 'activity') { continue; } ?>
                    <?php AppearancePage::toggle('categories[' . $key . ']', !empty($settings['categories'][$key]), $def[0], isset($descriptions[$key]) ? $descriptions[$key] : __('Événements envoyés par une extension.', 'smbb-wpcodetool')); ?>
                <?php endforeach; ?>
                <hr>
                <?php AppearancePage::toggle('failed_logins', $settings['failed_logins'], __('Échecs de connexion', 'smbb-wpcodetool'), __('Les essais répétés depuis la même adresse sont regroupés sur une ligne (× n) pendant 15 minutes.', 'smbb-wpcodetool')); ?>
                <?php AppearancePage::toggle('orders_all', $settings['orders_all'], __('Commandes passées par les clients et les paiements', 'smbb-wpcodetool'), __('Coupé : seuls les changements faits par l\'équipe sont notés. Allumé : aussi les changements de statut automatiques (paiement reçu, échec, annulation par le client).', 'smbb-wpcodetool')); ?>
                <?php AppearancePage::toggle('customers', $settings['customers'], __('Comptes clients', 'smbb-wpcodetool'), __('Coupé : les inscriptions des clients et les modifications de leur propre compte ne sont pas notées (sauf e-mail et mot de passe). Un compte à droits créé sans être connecté est toujours noté.', 'smbb-wpcodetool')); ?>
            <?php $this->sectionEnd(); ?>

            <?php $this->sectionStart('dashicons-privacy', __('Vie privée', 'smbb-wpcodetool'), __('Le journal contient des données personnelles (noms, adresses IP) : gardez-le le temps utile, pas plus.', 'smbb-wpcodetool')); ?>
                <p>
                    <label class="smbb-codetool-field-label" for="smbb-activity-ip"><?php esc_html_e('Adresses IP', 'smbb-wpcodetool'); ?></label><br>
                    <select id="smbb-activity-ip" name="ip">
                        <option value="full"<?php selected($settings['ip'], 'full'); ?>><?php esc_html_e('Complètes', 'smbb-wpcodetool'); ?></option>
                        <option value="masked"<?php selected($settings['ip'], 'masked'); ?>><?php esc_html_e('Masquées (dernier bloc à zéro)', 'smbb-wpcodetool'); ?></option>
                        <option value="none"<?php selected($settings['ip'], 'none'); ?>><?php esc_html_e('Pas notées', 'smbb-wpcodetool'); ?></option>
                    </select>
                    <span class="description"><?php esc_html_e('Les mots de passe, jetons et clés ne sont jamais écrits : seulement « modifié ».', 'smbb-wpcodetool'); ?></span>
                </p>
            <?php $this->sectionEnd(); ?>

            <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button></p>
        </form>

        <?php $this->sectionStart('dashicons-database', __('Entretien', 'smbb-wpcodetool')); ?>
            <ul class="smbb-activity-stats">
                <li><strong><?php echo esc_html(number_format_i18n($stats['rows'])); ?></strong> <?php esc_html_e('événements', 'smbb-wpcodetool'); ?></li>
                <li><strong><?php echo esc_html($stats['oldest'] !== '' ? get_date_from_gmt($stats['oldest'], 'd/m/Y') : '—'); ?></strong> <?php esc_html_e('le plus ancien', 'smbb-wpcodetool'); ?></li>
                <li><strong><?php echo esc_html($stats['bytes'] ? size_format($stats['bytes']) : '—'); ?></strong> <?php esc_html_e('en base', 'smbb-wpcodetool'); ?></li>
            </ul>
            <form method="post" action="<?php echo esc_url(self::url('settings')); ?>" class="smbb-activity-inline">
                <?php wp_nonce_field(self::NONCE, 'smbb_activity_nonce'); ?>
                <input type="hidden" name="smbb_activity_do" value="purge">
                <button type="submit" class="button"><?php esc_html_e('Purger maintenant', 'smbb-wpcodetool'); ?></button>
                <span class="description"><?php esc_html_e('Applique tout de suite la durée de conservation enregistrée.', 'smbb-wpcodetool'); ?></span>
            </form>
            <form method="post" action="<?php echo esc_url(self::url('settings')); ?>" class="smbb-activity-inline is-danger">
                <?php wp_nonce_field(self::NONCE, 'smbb_activity_nonce'); ?>
                <input type="hidden" name="smbb_activity_do" value="clear">
                <label for="smbb-activity-confirm"><?php esc_html_e('Tout effacer : tapez EFFACER', 'smbb-wpcodetool'); ?></label>
                <input type="text" id="smbb-activity-confirm" name="confirm" autocomplete="off" size="10">
                <button type="submit" class="button button-link-delete"><?php esc_html_e('Effacer le journal', 'smbb-wpcodetool'); ?></button>
            </form>
        <?php $this->sectionEnd(); ?>

        <details class="smbb-codetool-section smbb-activity-dev">
            <summary class="smbb-codetool-section-header">
                <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-editor-code"></span></span>
                <span class="smbb-codetool-section-heading">
                    <h3><?php esc_html_e('Pour les développeurs : écrire dans le journal', 'smbb-wpcodetool'); ?></h3>
                    <span class="description"><?php esc_html_e('Une action WordPress, sans dépendance : rien ne se passe si CodeTool est absent.', 'smbb-wpcodetool'); ?></span>
                </span>
            </summary>
            <div class="smbb-codetool-section-body">
<pre class="smbb-activity-code">do_action('smbb_wpcodetool_activity', 'passflow.pass_cancelled', array(
    'category'     => 'passflow',      // défaut : le préfixe avant le point
    'level'        => 'notice',        // info | notice | warning | critical
    'message'      => 'Pass annulé',
    'object_type'  => 'passflow_pass',
    'object_id'    => 42,
    'object_label' => 'Pass 10 cours de Jeanne',
    'context'      => array('changes' => array('Statut' => array('actif', 'annulé'))),
    'source'       => 'smbb-passflow',
));

// Catégorie propre (pastille et case dans ces réglages) :
add_filter('smbb_wpcodetool_activity_categories', function ($categories) {
    $categories['passflow'] = array('Passflow', 'dashicons-tickets-alt');
    return $categories;
});

// Lien « Ouvrir » vers vos objets :
add_filter('smbb_wpcodetool_activity_object_link', function ($url, $row) {
    return $row['object_type'] === 'passflow_pass' ? admin_url('admin.php?page=…&id=' . $row['object_id']) : $url;
}, 10, 2);</pre>
            </div>
        </details>
        <?php
    }
}
