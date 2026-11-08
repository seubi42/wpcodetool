<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Roles\Capabilities;
use Smbb\WpCodeTool\Roles\Roles;

defined('ABSPATH') || exit;

/**
 * Page « Rôles » de CodeTool : liste des rôles, création / copie / renommage / suppression,
 * droits d'un rôle à cocher, comparaison de deux rôles façon outil de fusion, historique.
 * Remplace l'extension User Role Editor. La logique et les garde-fous sont dans Roles\Roles.
 */
final class RolesPage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-roles';
    /** Pages sœurs du groupe « Comptes » : la barre d'app les montre en 2e niveau. */
    const COMPARE_SLUG = 'smbb-wpcodetool-roles-compare';
    const HISTORY_SLUG = 'smbb-wpcodetool-roles-history';
    const NONCE = 'smbb_codetool_roles';

    /**
     * Slugs des trois pages (Rôles, Comparer, Historique).
     *
     * @return string[]
     */
    public static function slugs()
    {
        return array(self::SLUG, self::COMPARE_SLUG, self::HISTORY_SLUG);
    }

    /**
     * Adresse d'une vue : list | edit (page Rôles), compare, history (pages sœurs).
     */
    public static function url($view = '', array $args = array())
    {
        if ($view === 'compare') {
            $slug = self::COMPARE_SLUG;
        } elseif ($view === 'history') {
            $slug = self::HISTORY_SLUG;
        } else {
            $slug = self::SLUG;

            if ($view === 'edit') {
                $args = array('view' => 'edit') + $args;
            }
        }

        return add_query_arg($args, admin_url('admin.php?page=' . $slug));
    }

    // ------------------------------------------------------------------ Actions

    public static function handle()
    {
        if (empty($_POST['smbb_roles_action'])) {
            return;
        }

        if (!Roles::allowed()
            || !isset($_POST['smbb_roles_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_roles_nonce'])), self::NONCE)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de gérer les rôles.', 'smbb-wpcodetool'));
        }

        $action = sanitize_key(wp_unslash($_POST['smbb_roles_action']));
        $post = function ($key) {
            return isset($_POST[$key]) && !is_array($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        };
        $list = function ($key) {
            return isset($_POST[$key]) && is_array($_POST[$key]) ? array_map('sanitize_text_field', wp_unslash($_POST[$key])) : array();
        };
        $back = self::url();

        switch ($action) {
            case 'create':
                $result = Roles::create(sanitize_key($post('role_id')), $post('role_name'), sanitize_key($post('from')));

                if (is_wp_error($result)) {
                    self::flash('error', $result->get_error_message());
                } else {
                    $message = $post('from') !== ''
                        ? sprintf(__('Rôle « %s » créé, avec les droits du rôle copié.', 'smbb-wpcodetool'), $post('role_name'))
                        : sprintf(__('Rôle « %s » créé. Cochez maintenant ses droits.', 'smbb-wpcodetool'), $post('role_name'));

                    if (!empty($result['refused'])) {
                        $message .= ' ' . sprintf(__('Droits non copiés car vous ne les avez pas vous-même : %s.', 'smbb-wpcodetool'), implode(', ', $result['refused']));
                    }

                    self::flash('success', $message);
                    $back = self::url('edit', array('role' => $result['id']));
                }
                break;

            case 'rename':
                $old = sanitize_key($post('role'));
                $new_id = strtolower($post('role_id'));
                $result = Roles::rename($old, $post('role_name'), $new_id);

                if (is_wp_error($result)) {
                    self::flash('error', $result->get_error_message());
                } elseif ($result['id'] !== $old) {
                    self::flash('success', sprintf(_n('Rôle renommé : identifiant %1$s → %2$s, %3$d compte basculé.', 'Rôle renommé : identifiant %1$s → %2$s, %3$d comptes basculés.', $result['users'], 'smbb-wpcodetool'), $old, $result['id'], $result['users']));
                } else {
                    self::flash('success', __('Rôle renommé.', 'smbb-wpcodetool'));
                }

                if ($post('return') === 'edit' && !is_wp_error($result)) {
                    $back = self::url('edit', array('role' => $result['id']));
                }
                break;

            case 'delete':
                $role = sanitize_key($post('role'));
                $result = Roles::delete($role, sanitize_key($post('reassign')));

                if (is_wp_error($result)) {
                    self::flash('error', $result->get_error_message());
                } else {
                    self::flash('success', $result['users']
                        ? sprintf(_n('Rôle %1$s supprimé, %2$d compte basculé.', 'Rôle %1$s supprimé, %2$d comptes basculés.', $result['users'], 'smbb-wpcodetool'), $role, $result['users'])
                        : sprintf(__('Rôle %s supprimé.', 'smbb-wpcodetool'), $role));
                }
                break;

            case 'default':
                $result = Roles::setDefault(sanitize_key($post('role')));
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : __('Rôle par défaut des nouveaux comptes changé.', 'smbb-wpcodetool'));
                break;

            case 'caps':
                $role = sanitize_key($post('role'));
                $result = Roles::setCaps($role, $list('caps'), $list('known'));
                self::flashCaps($result);
                $back = self::url('edit', array('role' => $role) + self::keepArgs());
                break;

            case 'compare':
                $a = sanitize_key($post('a'));
                $b = sanitize_key($post('b'));
                $known = $list('known');
                $messages = array();
                $type = 'success';

                foreach (array('a' => $a, 'b' => $b) as $side => $role) {
                    if (!$list('touched_' . $side)) {
                        continue;
                    }

                    $result = Roles::setCaps($role, $list('caps_' . $side), $known);

                    if (is_wp_error($result)) {
                        $messages[] = $role . ' : ' . $result->get_error_message();
                        $type = 'error';
                        continue;
                    }

                    $messages[] = sprintf(__('%1$s : %2$d ajouté(s), %3$d retiré(s)', 'smbb-wpcodetool'), $role, count($result['added']), count($result['removed']));

                    if ($result['refused']) {
                        $type = 'error';
                        $messages[] = sprintf(__('refusés sur %1$s : %2$s', 'smbb-wpcodetool'), $role, implode(', ', array_keys($result['refused'])));
                    }
                }

                self::flash($type, $messages ? implode(' · ', $messages) . '.' : __('Rien n\'a changé.', 'smbb-wpcodetool'));
                $back = self::url('compare', array('a' => $a, 'b' => $b));
                break;

            case 'add_cap':
                $role = sanitize_key($post('role'));
                $result = Roles::addCustomCap($role, $post('cap'));
                self::flashCaps($result, __('Droit ajouté et accordé à ce rôle.', 'smbb-wpcodetool'));
                $back = self::url('edit', array('role' => $role));
                break;

            case 'remove_cap':
                $result = Roles::removeCustomCap(sanitize_text_field($post('cap')));
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : __('Droit retiré de tous les rôles et du site.', 'smbb-wpcodetool'));
                $back = self::url('edit', array('role' => sanitize_key($post('role'))));
                break;

            case 'restore':
                $result = Roles::restore($post('which'));
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : __('Rôles restaurés. Les comptes n\'ont pas bougé : vérifiez la liste.', 'smbb-wpcodetool'));
                $back = self::url(is_wp_error($result) ? 'history' : '');
                break;

            case 'adopt':
                $result = Roles::adoptOrphans(sanitize_key($post('missing')), sanitize_key($post('target')));
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : sprintf(_n('%d compte rattaché.', '%d comptes rattachés.', (int) $result, 'smbb-wpcodetool'), (int) $result));
                break;
        }

        wp_safe_redirect($back);
        exit;
    }

    private static function keepArgs()
    {
        $args = array();

        foreach (array('filter', 'q') as $key) {
            if (!empty($_POST['keep_' . $key])) {
                $args[$key] = sanitize_text_field(wp_unslash($_POST['keep_' . $key]));
            }
        }

        return $args;
    }

    private static function flashCaps($result, $ok = '')
    {
        if (is_wp_error($result)) {
            self::flash('error', $result->get_error_message());
            return;
        }

        $message = $ok !== '' ? $ok : sprintf(__('Droits enregistrés : %1$d ajouté(s), %2$d retiré(s).', 'smbb-wpcodetool'), count($result['added']), count($result['removed']));

        if ($result['refused']) {
            $parts = array();

            foreach ($result['refused'] as $cap => $why) {
                $parts[] = $cap . ' (' . $why . ')';
            }

            self::flash('error', $message . ' ' . __('Non appliqués :', 'smbb-wpcodetool') . ' ' . implode(', ', $parts));
            return;
        }

        self::flash('success', $message);
    }

    private static function flash($type, $message)
    {
        set_transient('smbb_roles_flash_' . get_current_user_id(), array($type, $message), 120);
    }

    private function flashNotices()
    {
        $key = 'smbb_roles_flash_' . get_current_user_id();
        $flash = get_transient($key);
        $notices = array();

        if (is_array($flash)) {
            delete_transient($key);
            $notices[] = $this->notice($flash[0], $flash[1]);
        }

        if (!Roles::writable()) {
            $notices[] = $this->notice('error', Roles::editBlocker(''));
        }

        return $notices;
    }

    private function notice($type, $message)
    {
        return '<div class="smbb-codetool-notice is-' . esc_attr($type) . '"><p class="smbb-codetool-notice-message">' . esc_html($message) . '</p></div>';
    }

    // ------------------------------------------------------------------ Rendu

    /**
     * @param string $view '' (page Rôles : liste ou fiche d'un rôle), 'compare' ou 'history'.
     */
    public function render($view = '')
    {
        if (!Roles::allowed()) {
            wp_die(esc_html__('Vous n\'avez pas le droit de gérer les rôles.', 'smbb-wpcodetool'));
        }

        // phpcs:disable WordPress.Security.NonceVerification
        if ($view === '') {
            $view = isset($_GET['view']) && $_GET['view'] === 'edit' ? 'edit' : 'list';
        }
        $role = isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : '';
        // phpcs:enable

        if ($view === 'edit' && !Roles::get($role)) {
            $view = 'list';
        }

        $subtitles = array(
            'list' => __('Les types de comptes du site et ce que chacun a le droit de faire. Créez, copiez, renommez ou supprimez un rôle, puis cochez ses droits.', 'smbb-wpcodetool'),
            'edit' => __('Cochez ce que ce rôle a le droit de faire. Rien n\'est enregistré tant que vous n\'avez pas cliqué sur Enregistrer.', 'smbb-wpcodetool'),
            'compare' => __('Deux rôles côte à côte : ce qui les différencie saute aux yeux, et les flèches recopient un droit d\'un côté à l\'autre.', 'smbb-wpcodetool'),
            'history' => __('Une photo des rôles est prise avant chaque modification faite ici. Restaurer remet les rôles et leurs droits, pas les comptes.', 'smbb-wpcodetool'),
        );
        ?>
        <div class="wrap smbb-codetool smbb-roles-page">
            <?php
            echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
                $view === 'compare' ? __('Comparer les rôles', 'smbb-wpcodetool') : ($view === 'history' ? __('Historique des rôles', 'smbb-wpcodetool') : __('Rôles et droits', 'smbb-wpcodetool')),
                isset($subtitles[$view]) ? $subtitles[$view] : $subtitles['list'],
                'dashicons-groups',
                $this->flashNotices()
            );
            ?>
            <?php
            if ($view === 'edit') {
                $this->renderEdit($role);
            } elseif ($view === 'compare') {
                $this->renderCompare();
            } elseif ($view === 'history') {
                $this->renderHistory();
            } else {
                $this->renderList();
            }

            $this->renderDialogs();
            ?>
        </div>
        <?php
    }

    private function hidden($action)
    {
        wp_nonce_field(self::NONCE, 'smbb_roles_nonce');
        echo '<input type="hidden" name="smbb_roles_action" value="' . esc_attr($action) . '">';
    }

    private function badges($id, $role)
    {
        $out = '';

        if ($id === 'administrator') {
            $out .= '<span class="smbb-role-badge is-admin" title="' . esc_attr__('Ne se supprime pas, garde ses droits vitaux.', 'smbb-wpcodetool') . '"><span class="dashicons dashicons-lock" aria-hidden="true"></span>' . esc_html__('Protégé', 'smbb-wpcodetool') . '</span>';
        }

        if (Roles::isDefault($id)) {
            $out .= '<span class="smbb-role-badge is-default" title="' . esc_attr__('Rôle donné aux nouveaux comptes (Réglages › Général).', 'smbb-wpcodetool') . '">' . esc_html__('Par défaut', 'smbb-wpcodetool') . '</span>';
        }

        if (Roles::isMine($id)) {
            $out .= '<span class="smbb-role-badge is-mine">' . esc_html__('Votre rôle', 'smbb-wpcodetool') . '</span>';
        }

        if (Roles::isBuiltin($id)) {
            $out .= '<span class="smbb-role-badge">' . esc_html__('WordPress', 'smbb-wpcodetool') . '</span>';
        } elseif (in_array($id, array('customer', 'shop_manager'), true)) {
            $out .= '<span class="smbb-role-badge">' . esc_html__('WooCommerce', 'smbb-wpcodetool') . '</span>';
        }

        return $out;
    }

    /**
     * Données d'un rôle pour les boîtes de dialogue (JS).
     */
    private function roleData($id, $role)
    {
        return array(
            'id' => $id,
            'name' => $role['raw_name'],
            'label' => $role['name'],
            'users' => $role['users'],
            'canDelete' => Roles::deleteBlocker($id) === '',
            'deleteWhy' => Roles::deleteBlocker($id),
            'canRename' => Roles::editBlocker($id) === '',
            'idLocked' => $id === 'administrator',
        );
    }

    // ------------------------------------------------------------------ Liste

    private function renderList()
    {
        $roles = Roles::all();
        $max = 1;

        foreach ($roles as $role) {
            $max = max($max, count(array_filter($role['caps'])));
        }

        $orphans = Roles::orphanCounts();
        ?>
        <div class="smbb-roles-toolbar">
            <button type="button" class="button button-primary" data-smbb-role-dialog="create"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e('Nouveau rôle', 'smbb-wpcodetool'); ?></button>
            <a class="button" href="<?php echo esc_url(self::url('compare')); ?>"><span class="dashicons dashicons-image-flip-horizontal" aria-hidden="true"></span> <?php esc_html_e('Comparer deux rôles', 'smbb-wpcodetool'); ?></a>
            <span class="smbb-roles-count"><?php echo esc_html(sprintf(_n('%d rôle', '%d rôles', count($roles), 'smbb-wpcodetool'), count($roles))); ?></span>
        </div>

        <?php if ($orphans) : ?>
            <section class="smbb-codetool-section smbb-roles-orphans">
                <div class="smbb-codetool-section-body">
                    <p><strong><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e('Des comptes ont un rôle qui n\'existe plus : ils n\'ont plus aucun droit lié à ce rôle.', 'smbb-wpcodetool'); ?></strong></p>
                    <?php foreach ($orphans as $missing => $count) : ?>
                        <form method="post" class="smbb-roles-inline-form">
                            <?php $this->hidden('adopt'); ?>
                            <input type="hidden" name="missing" value="<?php echo esc_attr($missing); ?>">
                            <code><?php echo esc_html($missing); ?></code>
                            <?php echo esc_html(sprintf(_n('%d compte', '%d comptes', $count, 'smbb-wpcodetool'), $count)); ?> →
                            <?php $this->roleSelect('target', get_option('default_role'), array()); ?>
                            <button class="button" type="submit"><?php esc_html_e('Rattacher', 'smbb-wpcodetool'); ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <div class="smbb-roles-list">
            <?php foreach ($roles as $id => $role) :
                $granted = count(array_filter($role['caps']));
                $data = $this->roleData($id, $role);
                $edit_url = self::url('edit', array('role' => $id));
                ?>
                <article class="smbb-role-card" data-role="<?php echo esc_attr(wp_json_encode($data)); ?>">
                    <a class="smbb-role-main" href="<?php echo esc_url($edit_url); ?>">
                        <span class="smbb-role-avatar" aria-hidden="true"><?php echo esc_html(self::initials($role['name'])); ?></span>
                        <span class="smbb-role-text">
                            <span class="smbb-role-name"><?php echo esc_html($role['name']); ?></span>
                            <code class="smbb-role-id"><?php echo esc_html($id); ?></code>
                        </span>
                    </a>
                    <div class="smbb-role-badges"><?php echo $this->badges($id, $role); // phpcs:ignore -- échappé dans badges(). ?></div>
                    <div class="smbb-role-stats">
                        <a class="smbb-role-stat" href="<?php echo esc_url(admin_url('users.php?role=' . rawurlencode($id))); ?>" title="<?php esc_attr_e('Voir les comptes', 'smbb-wpcodetool'); ?>">
                            <span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php echo esc_html(sprintf(_n('%d compte', '%d comptes', $role['users'], 'smbb-wpcodetool'), $role['users'])); ?>
                        </a>
                        <span class="smbb-role-stat smbb-role-capsbar" title="<?php esc_attr_e('Droits accordés', 'smbb-wpcodetool'); ?>">
                            <span class="smbb-role-bar"><span style="width:<?php echo esc_attr((string) max(3, round($granted * 100 / $max))); ?>%"></span></span>
                            <?php echo esc_html(sprintf(_n('%d droit', '%d droits', $granted, 'smbb-wpcodetool'), $granted)); ?>
                        </span>
                    </div>
                    <div class="smbb-role-actions">
                        <a class="button button-small" href="<?php echo esc_url($edit_url); ?>"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e('Droits', 'smbb-wpcodetool'); ?></a>
                        <button type="button" class="button button-small" data-smbb-role-dialog="duplicate"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> <?php esc_html_e('Dupliquer', 'smbb-wpcodetool'); ?></button>
                        <details class="smbb-role-more">
                            <summary class="button button-small" aria-label="<?php esc_attr_e('Plus d\'actions', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-ellipsis" aria-hidden="true"></span></summary>
                            <div class="smbb-role-menu">
                                <button type="button" data-smbb-role-dialog="rename"<?php disabled(!$data['canRename']); ?>><span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e('Renommer', 'smbb-wpcodetool'); ?></button>
                                <a href="<?php echo esc_url(self::url('compare', array('a' => $id))); ?>"><span class="dashicons dashicons-image-flip-horizontal" aria-hidden="true"></span> <?php esc_html_e('Comparer avec…', 'smbb-wpcodetool'); ?></a>
                                <?php if (!Roles::isDefault($id) && $id !== 'administrator') : ?>
                                    <form method="post">
                                        <?php $this->hidden('default'); ?>
                                        <input type="hidden" name="role" value="<?php echo esc_attr($id); ?>">
                                        <button type="submit"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span> <?php esc_html_e('Rôle par défaut des nouveaux comptes', 'smbb-wpcodetool'); ?></button>
                                    </form>
                                <?php endif; ?>
                                <button type="button" class="is-danger" data-smbb-role-dialog="delete"<?php echo $data['canDelete'] ? '' : ' disabled title="' . esc_attr($data['deleteWhy']) . '"'; ?>><span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e('Supprimer', 'smbb-wpcodetool'); ?></button>
                            </div>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function initials($name)
    {
        $words = preg_split('/[\s_\-]+/u', trim((string) $name));
        $out = '';

        foreach (array_slice(array_filter($words), 0, 2) as $word) {
            $out .= function_exists('mb_substr') ? mb_strtoupper(mb_substr($word, 0, 1)) : strtoupper(substr($word, 0, 1));
        }

        return $out !== '' ? $out : '?';
    }

    private function roleSelect($name, $selected, array $exclude, $empty = '')
    {
        echo '<select name="' . esc_attr($name) . '">';

        if ($empty !== '') {
            echo '<option value="">' . esc_html($empty) . '</option>';
        }

        foreach (Roles::all() as $id => $role) {
            if (in_array($id, $exclude, true)) {
                continue;
            }

            echo '<option value="' . esc_attr($id) . '"' . selected($selected, $id, false) . '>' . esc_html($role['name'] . ' (' . $id . ')') . '</option>';
        }

        echo '</select>';
    }

    // ------------------------------------------------------------------ Dialogues

    private function renderDialogs()
    {
        $default = (string) get_option('default_role');
        ?>
        <dialog class="smbb-roles-dialog" data-smbb-dialog="create">
            <form method="post">
                <?php $this->hidden('create'); ?>
                <header><h2 data-title-create><?php esc_html_e('Nouveau rôle', 'smbb-wpcodetool'); ?></h2><h2 data-title-duplicate hidden><?php esc_html_e('Dupliquer un rôle', 'smbb-wpcodetool'); ?></h2><button type="button" class="smbb-roles-dialog-close" data-close aria-label="<?php esc_attr_e('Fermer', 'smbb-wpcodetool'); ?>">×</button></header>
                <div class="smbb-roles-dialog-body">
                    <label class="smbb-roles-field"><span><?php esc_html_e('Nom affiché', 'smbb-wpcodetool'); ?></span>
                        <input type="text" name="role_name" required maxlength="80" data-slug-source placeholder="<?php esc_attr_e('Ex. Responsable atelier', 'smbb-wpcodetool'); ?>"></label>
                    <label class="smbb-roles-field"><span><?php esc_html_e('Identifiant', 'smbb-wpcodetool'); ?></span>
                        <input type="text" name="role_id" required maxlength="60" pattern="[a-z0-9_\-]*[a-z_\-][a-z0-9_\-]*" data-slug-target class="code">
                        <small><?php esc_html_e('Lettres sans accent, chiffres, _ et -. C\'est ce que le code et les extensions utilisent.', 'smbb-wpcodetool'); ?></small></label>
                    <label class="smbb-roles-field"><span><?php esc_html_e('Droits de départ', 'smbb-wpcodetool'); ?></span>
                        <?php $this->roleSelect('from', '', array(), __('Aucun (seulement l\'accès à l\'admin)', 'smbb-wpcodetool')); ?></label>
                </div>
                <footer><button type="button" class="button" data-close><?php esc_html_e('Annuler', 'smbb-wpcodetool'); ?></button><button type="submit" class="button button-primary"><?php esc_html_e('Créer le rôle', 'smbb-wpcodetool'); ?></button></footer>
            </form>
        </dialog>

        <dialog class="smbb-roles-dialog" data-smbb-dialog="rename">
            <form method="post">
                <?php $this->hidden('rename'); ?>
                <input type="hidden" name="role" value="">
                <input type="hidden" name="return" value="">
                <header><h2><?php esc_html_e('Renommer le rôle', 'smbb-wpcodetool'); ?></h2><button type="button" class="smbb-roles-dialog-close" data-close aria-label="<?php esc_attr_e('Fermer', 'smbb-wpcodetool'); ?>">×</button></header>
                <div class="smbb-roles-dialog-body">
                    <label class="smbb-roles-field"><span><?php esc_html_e('Nom affiché', 'smbb-wpcodetool'); ?></span>
                        <input type="text" name="role_name" required maxlength="80"></label>
                    <label class="smbb-roles-field"><span><?php esc_html_e('Identifiant', 'smbb-wpcodetool'); ?></span>
                        <input type="text" name="role_id" required maxlength="60" pattern="[a-z0-9_\-]*[a-z_\-][a-z0-9_\-]*" class="code" data-id-input>
                        <small data-id-locked hidden><?php esc_html_e('L\'identifiant du rôle Administrateur ne change pas.', 'smbb-wpcodetool'); ?></small></label>
                    <div class="smbb-roles-warn" data-id-warning hidden>
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <div>
                            <strong data-id-warning-users></strong>
                            <p><?php esc_html_e('Chaque compte garde sa place (rôle principal ou secondaire) et le rôle par défaut suit. Le code ou les réglages d\'extensions qui citent l\'ancien identifiant ne sont pas modifiés : vérifiez-les. Une photo des rôles est gardée dans l\'historique.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </div>
                </div>
                <footer><button type="button" class="button" data-close><?php esc_html_e('Annuler', 'smbb-wpcodetool'); ?></button><button type="submit" class="button button-primary"><?php esc_html_e('Renommer', 'smbb-wpcodetool'); ?></button></footer>
            </form>
        </dialog>

        <dialog class="smbb-roles-dialog" data-smbb-dialog="delete">
            <form method="post">
                <?php $this->hidden('delete'); ?>
                <input type="hidden" name="role" value="">
                <header><h2><?php esc_html_e('Supprimer le rôle', 'smbb-wpcodetool'); ?></h2><button type="button" class="smbb-roles-dialog-close" data-close aria-label="<?php esc_attr_e('Fermer', 'smbb-wpcodetool'); ?>">×</button></header>
                <div class="smbb-roles-dialog-body">
                    <p data-delete-text></p>
                    <div data-delete-users hidden>
                        <label class="smbb-roles-field"><span data-delete-users-label></span>
                            <?php $this->roleSelect('reassign', $default, array()); ?></label>
                        <small><?php esc_html_e('Les comptes qui ont aussi un autre rôle perdent seulement celui-ci.', 'smbb-wpcodetool'); ?></small>
                    </div>
                    <p class="smbb-roles-muted"><?php esc_html_e('Une photo des rôles est gardée : vous pourrez le recréer depuis l\'historique.', 'smbb-wpcodetool'); ?></p>
                </div>
                <footer><button type="button" class="button" data-close><?php esc_html_e('Annuler', 'smbb-wpcodetool'); ?></button><button type="submit" class="button button-primary smbb-roles-danger"><?php esc_html_e('Supprimer', 'smbb-wpcodetool'); ?></button></footer>
            </form>
        </dialog>
        <script type="application/json" id="smbb-roles-i18n"><?php echo wp_json_encode(array(
            'users' => __('%d compte(s) vont passer au nouvel identifiant.', 'smbb-wpcodetool'),
            'noUsers' => __('Aucun compte n\'a ce rôle : seul le rôle change d\'identifiant.', 'smbb-wpcodetool'),
            'deleteText' => __('Le rôle « %s » va être supprimé.', 'smbb-wpcodetool'),
            'deleteUsers' => __('%d compte(s) ont ce rôle. Les basculer vers :', 'smbb-wpcodetool'),
            'copyOf' => __('%s (copie)', 'smbb-wpcodetool'),
            'dirty' => __('Des droits ont été modifiés sans être enregistrés.', 'smbb-wpcodetool'),
            'changes' => __('%d modification(s) à enregistrer', 'smbb-wpcodetool'),
            'none' => __('Aucune modification', 'smbb-wpcodetool'),
        )); ?></script>
        <?php
    }

    // ------------------------------------------------------------------ Droits d'un rôle

    private function renderEdit($id)
    {
        $role = Roles::get($id);
        $catalogue = Capabilities::catalogue();
        $locked = Roles::lockedCaps($id);
        $blocker = Roles::editBlocker($id);
        $custom = array_flip(Capabilities::customCaps());
        $by_group = array();

        foreach ($catalogue['caps'] as $cap => $def) {
            $by_group[$def['group']][$cap] = $def;
        }

        $granted = count(array_filter($role['caps']));
        // phpcs:disable WordPress.Security.NonceVerification
        $filter = isset($_GET['filter']) ? sanitize_key(wp_unslash($_GET['filter'])) : 'all';
        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        // phpcs:enable
        $data = $this->roleData($id, $role);
        ?>
        <div class="smbb-role-head" data-role="<?php echo esc_attr(wp_json_encode($data)); ?>">
            <a class="smbb-role-back" href="<?php echo esc_url(self::url()); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> <?php esc_html_e('Tous les rôles', 'smbb-wpcodetool'); ?></a>
            <div class="smbb-role-head-main">
                <span class="smbb-role-avatar is-large" aria-hidden="true"><?php echo esc_html(self::initials($role['name'])); ?></span>
                <div>
                    <h2><?php echo esc_html($role['name']); ?> <code class="smbb-role-id"><?php echo esc_html($id); ?></code></h2>
                    <div class="smbb-role-badges">
                        <?php echo $this->badges($id, $role); // phpcs:ignore -- échappé dans badges(). ?>
                        <a class="smbb-role-badge is-link" href="<?php echo esc_url(admin_url('users.php?role=' . rawurlencode($id))); ?>"><?php echo esc_html(sprintf(_n('%d compte', '%d comptes', $role['users'], 'smbb-wpcodetool'), $role['users'])); ?></a>
                    </div>
                </div>
            </div>
            <div class="smbb-role-head-actions">
                <button type="button" class="button" data-smbb-role-dialog="rename" data-return="edit"<?php disabled($blocker !== ''); ?>><span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e('Renommer', 'smbb-wpcodetool'); ?></button>
                <button type="button" class="button" data-smbb-role-dialog="duplicate"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> <?php esc_html_e('Dupliquer', 'smbb-wpcodetool'); ?></button>
                <a class="button" href="<?php echo esc_url(self::url('compare', array('a' => $id))); ?>"><span class="dashicons dashicons-image-flip-horizontal" aria-hidden="true"></span> <?php esc_html_e('Comparer', 'smbb-wpcodetool'); ?></a>
            </div>
        </div>

        <?php if ($blocker !== '') : ?>
            <div class="smbb-roles-warn"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong><?php echo esc_html($blocker); ?></strong></div></div>
        <?php endif; ?>

        <form method="post" class="smbb-caps-form" data-smbb-caps-form>
            <?php $this->hidden('caps'); ?>
            <input type="hidden" name="role" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" name="keep_filter" value="<?php echo esc_attr($filter); ?>" data-keep-filter>
            <input type="hidden" name="keep_q" value="<?php echo esc_attr($q); ?>" data-keep-q>

            <div class="smbb-caps-bar">
                <label class="smbb-caps-search"><span class="dashicons dashicons-search" aria-hidden="true"></span>
                    <input type="search" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Chercher un droit…', 'smbb-wpcodetool'); ?>" data-caps-search></label>
                <div class="smbb-caps-segment" role="group" aria-label="<?php esc_attr_e('Filtre', 'smbb-wpcodetool'); ?>">
                    <?php foreach (array('all' => __('Tous', 'smbb-wpcodetool'), 'on' => __('Accordés', 'smbb-wpcodetool'), 'off' => __('Non accordés', 'smbb-wpcodetool')) as $key => $label) : ?>
                        <button type="button" data-caps-filter="<?php echo esc_attr($key); ?>" class="<?php echo $filter === $key ? 'is-active' : ''; ?>"><?php echo esc_html($label); ?></button>
                    <?php endforeach; ?>
                </div>
                <label class="smbb-caps-check"><input type="checkbox" data-caps-deprecated> <?php esc_html_e('Anciens droits', 'smbb-wpcodetool'); ?></label>
                <span class="smbb-caps-total"><strong data-caps-count><?php echo esc_html((string) $granted); ?></strong> <?php esc_html_e('accordés', 'smbb-wpcodetool'); ?></span>
                <span class="smbb-caps-dirty" data-caps-dirty><?php esc_html_e('Aucune modification', 'smbb-wpcodetool'); ?></span>
                <button type="submit" class="button button-primary" data-caps-save<?php disabled($blocker !== ''); ?>><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button>
            </div>

            <div class="smbb-caps-layout">
                <nav class="smbb-caps-nav" aria-label="<?php esc_attr_e('Groupes de droits', 'smbb-wpcodetool'); ?>">
                    <?php foreach ($catalogue['groups'] as $group => $def) :
                        if (empty($by_group[$group])) {
                            continue;
                        }
                        $on = count(array_filter(array_keys($by_group[$group]), function ($cap) use ($role) {
                            return !empty($role['caps'][$cap]);
                        }));
                        ?>
                        <a href="#smbb-caps-<?php echo esc_attr($group); ?>" data-caps-nav="<?php echo esc_attr($group); ?>"<?php echo $group === 'deprecated' ? ' data-deprecated' : ''; ?>>
                            <span class="dashicons <?php echo esc_attr($def[1]); ?>" aria-hidden="true"></span>
                            <span class="smbb-caps-nav-label"><?php echo esc_html($def[0]); ?></span>
                            <span class="smbb-caps-nav-count" data-caps-nav-count><?php echo esc_html($on . '/' . count($by_group[$group])); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="smbb-caps-groups">
                    <?php foreach ($catalogue['groups'] as $group => $def) :
                        if (empty($by_group[$group])) {
                            continue;
                        }
                        ?>
                        <section class="smbb-caps-group" id="smbb-caps-<?php echo esc_attr($group); ?>" data-caps-group="<?php echo esc_attr($group); ?>"<?php echo $group === 'deprecated' ? ' data-deprecated hidden' : ''; ?>>
                            <header>
                                <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr($def[1]); ?>"></span></span>
                                <h3><?php echo esc_html($def[0]); ?></h3>
                                <span class="smbb-caps-group-count" data-caps-group-count></span>
                                <span class="smbb-caps-group-actions">
                                    <button type="button" class="button-link" data-caps-all="1"><?php esc_html_e('Tout cocher', 'smbb-wpcodetool'); ?></button>
                                    <button type="button" class="button-link" data-caps-all="0"><?php esc_html_e('Tout décocher', 'smbb-wpcodetool'); ?></button>
                                </span>
                            </header>
                            <div class="smbb-caps-grid">
                                <?php foreach ($by_group[$group] as $cap => $cap_def) :
                                    $has = !empty($role['caps'][$cap]);
                                    $denied = array_key_exists($cap, $role['caps']) && !$role['caps'][$cap];
                                    $lock = isset($locked[$cap]) && $has ? $locked[$cap] : '';
                                    $cannot = !$has && !Roles::canGrant($cap) ? __('Vous n\'avez pas ce droit vous-même : vous ne pouvez pas le donner.', 'smbb-wpcodetool') : '';
                                    $disabled = $blocker !== '' || $lock !== '' || $cannot !== '';
                                    $why = $lock !== '' ? $lock : $cannot;
                                    ?>
                                    <label class="smbb-cap<?php echo $has ? ' is-on' : ''; ?><?php echo $disabled ? ' is-disabled' : ''; ?>" data-cap="<?php echo esc_attr($cap); ?>" data-search="<?php echo esc_attr(strtolower(remove_accents($cap . ' ' . $cap_def['label']))); ?>"<?php echo $why !== '' ? ' title="' . esc_attr($why) . '"' : ''; ?>>
                                        <input type="hidden" name="known[]" value="<?php echo esc_attr($cap); ?>">
                                        <?php if ($lock !== '') : ?><input type="hidden" name="caps[]" value="<?php echo esc_attr($cap); ?>"><?php endif; ?>
                                        <span class="smbb-codetool-toggle">
                                            <input class="smbb-codetool-toggle-input" type="checkbox" name="caps[]" value="<?php echo esc_attr($cap); ?>" <?php checked($has); ?> <?php disabled($disabled); ?> data-initial="<?php echo $has ? '1' : '0'; ?>">
                                            <span class="smbb-codetool-toggle-track" aria-hidden="true"><span class="smbb-codetool-toggle-thumb"></span></span>
                                        </span>
                                        <span class="smbb-cap-text">
                                            <span class="smbb-cap-label"><?php echo esc_html($cap_def['label']); ?>
                                                <?php if ($lock !== '') : ?><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php endif; ?>
                                                <?php if ($denied) : ?><span class="smbb-role-badge is-denied" title="<?php esc_attr_e('Ce rôle refuse explicitement ce droit, même si un autre rôle du compte le donne.', 'smbb-wpcodetool'); ?>"><?php esc_html_e('refusé', 'smbb-wpcodetool'); ?></span><?php endif; ?>
                                            </span>
                                            <code><?php echo esc_html($cap); ?></code>
                                        </span>
                                        <?php if (isset($custom[$cap]) && $blocker === '') : ?>
                                            <button type="submit" form="smbb-roles-remove-cap" name="cap" value="<?php echo esc_attr($cap); ?>" class="smbb-cap-remove" title="<?php esc_attr_e('Retirer ce droit de tous les rôles et du site', 'smbb-wpcodetool'); ?>" data-confirm="<?php echo esc_attr(sprintf(__('Retirer le droit %s de tous les rôles ?', 'smbb-wpcodetool'), $cap)); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                    <p class="smbb-caps-empty" data-caps-empty hidden><?php esc_html_e('Aucun droit ne correspond.', 'smbb-wpcodetool'); ?></p>
                </div>
            </div>
        </form>

        <?php if ($blocker === '') : ?>
            <form method="post" class="smbb-codetool-section smbb-caps-add">
                <?php $this->hidden('add_cap'); ?>
                <input type="hidden" name="role" value="<?php echo esc_attr($id); ?>">
                <div class="smbb-codetool-section-body">
                    <label class="smbb-roles-field"><span><?php esc_html_e('Ajouter un droit nouveau', 'smbb-wpcodetool'); ?></span>
                        <span class="smbb-caps-add-row"><input type="text" name="cap" class="code" required pattern="[a-z0-9_\-]{2,100}" placeholder="<?php esc_attr_e('ex. voir_rapports', 'smbb-wpcodetool'); ?>"><button type="submit" class="button"><?php esc_html_e('Ajouter et accorder', 'smbb-wpcodetool'); ?></button></span>
                        <small><?php esc_html_e('Pour un droit que votre code teste avec current_user_can(). Il est accordé à ce rôle et apparaît ensuite pour tous les rôles.', 'smbb-wpcodetool'); ?></small></label>
                </div>
            </form>
            <form method="post" id="smbb-roles-remove-cap" hidden>
                <?php $this->hidden('remove_cap'); ?>
                <input type="hidden" name="role" value="<?php echo esc_attr($id); ?>">
            </form>
        <?php endif; ?>
        <?php
    }

    // ------------------------------------------------------------------ Comparer

    private function renderCompare()
    {
        $roles = Roles::all();
        $ids = array_keys($roles);
        // phpcs:disable WordPress.Security.NonceVerification
        $a = isset($_GET['a']) ? sanitize_key(wp_unslash($_GET['a'])) : '';
        $b = isset($_GET['b']) ? sanitize_key(wp_unslash($_GET['b'])) : '';
        // phpcs:enable

        if (!isset($roles[$a])) {
            $a = isset($roles['editor']) ? 'editor' : (string) reset($ids);
        }

        if (!isset($roles[$b]) || $b === $a) {
            $b = '';

            foreach (array('shop_manager', 'author', 'administrator') as $guess) {
                if ($guess !== $a && isset($roles[$guess])) {
                    $b = $guess;
                    break;
                }
            }

            if ($b === '') {
                foreach ($ids as $other) {
                    if ($other !== $a) {
                        $b = $other;
                        break;
                    }
                }
            }
        }
        ?>
        <form method="get" class="smbb-compare-pick">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::COMPARE_SLUG); ?>">
            <label class="smbb-compare-side is-a"><span><?php esc_html_e('Rôle A', 'smbb-wpcodetool'); ?></span><?php $this->roleSelect('a', $a, array()); ?></label>
            <a class="button smbb-compare-swap" href="<?php echo esc_url(self::url('compare', array('a' => $b, 'b' => $a))); ?>" title="<?php esc_attr_e('Inverser', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-randomize" aria-hidden="true"></span></a>
            <label class="smbb-compare-side is-b"><span><?php esc_html_e('Rôle B', 'smbb-wpcodetool'); ?></span><?php $this->roleSelect('b', $b, array()); ?></label>
            <button type="submit" class="button"><?php esc_html_e('Comparer', 'smbb-wpcodetool'); ?></button>
        </form>
        <?php
        if ($b === '' || !isset($roles[$a], $roles[$b])) {
            echo '<p>' . esc_html__('Il faut au moins deux rôles pour comparer.', 'smbb-wpcodetool') . '</p>';
            return;
        }

        $diff = Roles::diff($a, $b);
        $catalogue = Capabilities::catalogue();
        $rows = array();
        $only_a = $only_b = $both = 0;

        foreach ($diff as $cap => $pair) {
            $group = isset($catalogue['caps'][$cap]) ? $catalogue['caps'][$cap]['group'] : 'custom';
            $rows[$group][$cap] = $pair;

            if ($pair[0] && $pair[1]) {
                $both++;
            } elseif ($pair[0]) {
                $only_a++;
            } elseif ($pair[1]) {
                $only_b++;
            }
        }

        $block_a = Roles::editBlocker($a);
        $block_b = Roles::editBlocker($b);
        $locked_a = Roles::lockedCaps($a);
        $locked_b = Roles::lockedCaps($b);
        $total = $only_a + $only_b + $both;
        $similar = $total ? round($both * 100 / $total) : 100;
        ?>
        <div class="smbb-compare-summary">
            <div class="smbb-compare-stat is-a"><strong><?php echo esc_html((string) $only_a); ?></strong><span><?php echo esc_html(sprintf(__('seulement %s', 'smbb-wpcodetool'), $roles[$a]['name'])); ?></span></div>
            <div class="smbb-compare-stat is-both"><strong><?php echo esc_html((string) $both); ?></strong><span><?php esc_html_e('en commun', 'smbb-wpcodetool'); ?></span></div>
            <div class="smbb-compare-stat is-b"><strong><?php echo esc_html((string) $only_b); ?></strong><span><?php echo esc_html(sprintf(__('seulement %s', 'smbb-wpcodetool'), $roles[$b]['name'])); ?></span></div>
            <div class="smbb-compare-meter" title="<?php esc_attr_e('Ressemblance', 'smbb-wpcodetool'); ?>">
                <span class="smbb-compare-meter-bar"><span class="is-a" style="flex:<?php echo esc_attr((string) $only_a); ?>"></span><span class="is-both" style="flex:<?php echo esc_attr((string) $both); ?>"></span><span class="is-b" style="flex:<?php echo esc_attr((string) $only_b); ?>"></span></span>
                <span><?php echo esc_html(sprintf(__('%d %% de droits communs', 'smbb-wpcodetool'), $similar)); ?></span>
            </div>
        </div>

        <form method="post" class="smbb-compare-form" data-smbb-compare-form>
            <?php $this->hidden('compare'); ?>
            <input type="hidden" name="a" value="<?php echo esc_attr($a); ?>">
            <input type="hidden" name="b" value="<?php echo esc_attr($b); ?>">
            <input type="hidden" name="touched_a[]" value="" data-touched="a" disabled>
            <input type="hidden" name="touched_b[]" value="" data-touched="b" disabled>

            <div class="smbb-caps-bar">
                <div class="smbb-caps-segment" role="group">
                    <button type="button" class="is-active" data-compare-filter="diff"><?php esc_html_e('Différences', 'smbb-wpcodetool'); ?></button>
                    <button type="button" data-compare-filter="all"><?php esc_html_e('Tout', 'smbb-wpcodetool'); ?></button>
                </div>
                <label class="smbb-caps-search"><span class="dashicons dashicons-search" aria-hidden="true"></span><input type="search" placeholder="<?php esc_attr_e('Chercher un droit…', 'smbb-wpcodetool'); ?>" data-caps-search></label>
                <?php if ($block_b === '') : ?><button type="button" class="button" data-compare-all="ab"><?php echo esc_html(sprintf(__('Tout copier vers %s', 'smbb-wpcodetool'), $roles[$b]['name'])); ?> →</button><?php endif; ?>
                <?php if ($block_a === '') : ?><button type="button" class="button" data-compare-all="ba">← <?php echo esc_html(sprintf(__('Tout copier vers %s', 'smbb-wpcodetool'), $roles[$a]['name'])); ?></button><?php endif; ?>
                <span class="smbb-caps-dirty" data-caps-dirty><?php esc_html_e('Aucune modification', 'smbb-wpcodetool'); ?></span>
                <button type="submit" class="button button-primary" data-caps-save><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button>
            </div>

            <table class="smbb-compare-table">
                <thead>
                    <tr>
                        <th class="is-a"><a href="<?php echo esc_url(self::url('edit', array('role' => $a))); ?>"><?php echo esc_html($roles[$a]['name']); ?></a><code><?php echo esc_html($a); ?></code></th>
                        <th class="smbb-compare-arrow"></th>
                        <th class="smbb-compare-cap"><?php esc_html_e('Droit', 'smbb-wpcodetool'); ?></th>
                        <th class="smbb-compare-arrow"></th>
                        <th class="is-b"><a href="<?php echo esc_url(self::url('edit', array('role' => $b))); ?>"><?php echo esc_html($roles[$b]['name']); ?></a><code><?php echo esc_html($b); ?></code></th>
                    </tr>
                </thead>
                <?php foreach ($catalogue['groups'] + array('custom' => array(__('Autres droits', 'smbb-wpcodetool'), 'dashicons-admin-network')) as $group => $def) :
                    if (empty($rows[$group])) {
                        continue;
                    }
                    ?>
                    <tbody data-compare-group>
                        <tr class="smbb-compare-group"><th colspan="5"><span class="dashicons <?php echo esc_attr($def[1]); ?>" aria-hidden="true"></span> <?php echo esc_html($def[0]); ?></th></tr>
                        <?php foreach ($rows[$group] as $cap => $pair) :
                            $state = $pair[0] === $pair[1] ? 'same' : ($pair[0] ? 'only-a' : 'only-b');
                            $label = isset($catalogue['caps'][$cap]) ? $catalogue['caps'][$cap]['label'] : $cap;
                            $dis_a = $block_a !== '' || (isset($locked_a[$cap]) && $pair[0]) || (!$pair[0] && !Roles::canGrant($cap));
                            $dis_b = $block_b !== '' || (isset($locked_b[$cap]) && $pair[1]) || (!$pair[1] && !Roles::canGrant($cap));
                            ?>
                            <tr class="smbb-compare-row is-<?php echo esc_attr($state); ?>" data-cap="<?php echo esc_attr($cap); ?>" data-search="<?php echo esc_attr(strtolower(remove_accents($cap . ' ' . $label))); ?>"<?php echo $state === 'same' ? ' hidden' : ''; ?>>
                                <td class="is-a">
                                    <input type="hidden" name="known[]" value="<?php echo esc_attr($cap); ?>">
                                    <?php if ($dis_a && $pair[0]) : ?><input type="hidden" name="caps_a[]" value="<?php echo esc_attr($cap); ?>"><?php endif; ?>
                                    <label class="smbb-codetool-toggle"><input class="smbb-codetool-toggle-input" type="checkbox" name="caps_a[]" value="<?php echo esc_attr($cap); ?>" data-side="a" data-initial="<?php echo $pair[0] ? '1' : '0'; ?>" <?php checked($pair[0]); ?> <?php disabled($dis_a); ?>><span class="smbb-codetool-toggle-track" aria-hidden="true"><span class="smbb-codetool-toggle-thumb"></span></span></label>
                                </td>
                                <td class="smbb-compare-arrow"><?php if (!$dis_b) : ?><button type="button" data-copy="ab" title="<?php esc_attr_e('Copier vers B', 'smbb-wpcodetool'); ?>">→</button><?php endif; ?></td>
                                <td class="smbb-compare-cap"><span class="smbb-cap-label"><?php echo esc_html($label); ?></span><code><?php echo esc_html($cap); ?></code></td>
                                <td class="smbb-compare-arrow"><?php if (!$dis_a) : ?><button type="button" data-copy="ba" title="<?php esc_attr_e('Copier vers A', 'smbb-wpcodetool'); ?>">←</button><?php endif; ?></td>
                                <td class="is-b">
                                    <?php if ($dis_b && $pair[1]) : ?><input type="hidden" name="caps_b[]" value="<?php echo esc_attr($cap); ?>"><?php endif; ?>
                                    <label class="smbb-codetool-toggle"><input class="smbb-codetool-toggle-input" type="checkbox" name="caps_b[]" value="<?php echo esc_attr($cap); ?>" data-side="b" data-initial="<?php echo $pair[1] ? '1' : '0'; ?>" <?php checked($pair[1]); ?> <?php disabled($dis_b); ?>><span class="smbb-codetool-toggle-track" aria-hidden="true"><span class="smbb-codetool-toggle-thumb"></span></span></label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
            <p class="smbb-caps-empty" data-caps-empty<?php echo ($only_a + $only_b) ? ' hidden' : ''; ?>><?php esc_html_e('Ces deux rôles ont exactement les mêmes droits.', 'smbb-wpcodetool'); ?></p>
        </form>
        <?php
    }

    // ------------------------------------------------------------------ Historique

    private function renderHistory()
    {
        $history = Roles::history();
        $origin = Roles::origin();
        $entries = array();

        if ($origin) {
            $entries['origin'] = $origin;
        }

        foreach ($history as $index => $entry) {
            $entries[(string) $index] = $entry;
        }

        if (!$entries) {
            echo '<section class="smbb-codetool-section"><div class="smbb-codetool-section-body"><p>' . esc_html__('Aucune modification pour l\'instant. Dès la première, une photo des rôles d\'origine est gardée ici.', 'smbb-wpcodetool') . '</p></div></section>';
            return;
        }

        $current = wp_roles()->roles;
        ?>
        <div class="smbb-roles-history">
            <?php foreach ($entries as $which => $entry) :
                $user = $entry['user'] ? get_userdata($entry['user']) : null;
                $changes = self::countChanges($entry['roles'], $current);
                ?>
                <article class="smbb-history-item<?php echo $which === 'origin' ? ' is-origin' : ''; ?>">
                    <span class="smbb-history-dot dashicons <?php echo $which === 'origin' ? 'dashicons-flag' : 'dashicons-backup'; ?>" aria-hidden="true"></span>
                    <div class="smbb-history-text">
                        <strong><?php echo esc_html($entry['label']); ?></strong>
                        <span><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $entry['time'])); ?><?php echo $user ? ' · ' . esc_html($user->display_name) : ''; ?> · <?php echo esc_html(sprintf(_n('%d rôle', '%d rôles', count($entry['roles']), 'smbb-wpcodetool'), count($entry['roles']))); ?> · <?php echo esc_html($changes ? sprintf(_n('%d écart avec aujourd\'hui', '%d écarts avec aujourd\'hui', $changes, 'smbb-wpcodetool'), $changes) : __('identique à aujourd\'hui', 'smbb-wpcodetool')); ?></span>
                    </div>
                    <?php if ($changes && Roles::fullAdmin()) : ?>
                        <form method="post" data-confirm="<?php esc_attr_e('Remettre les rôles dans cet état ? L\'état actuel sera gardé dans l\'historique.', 'smbb-wpcodetool'); ?>">
                            <?php $this->hidden('restore'); ?>
                            <input type="hidden" name="which" value="<?php echo esc_attr((string) $which); ?>">
                            <button type="submit" class="button"><?php esc_html_e('Restaurer cet état', 'smbb-wpcodetool'); ?></button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Nombre d'écarts (rôle en plus / en moins, droit en plus / en moins, nom changé).
     */
    private static function countChanges(array $old, array $now)
    {
        $count = 0;

        foreach (array_unique(array_merge(array_keys($old), array_keys($now))) as $id) {
            if (!isset($old[$id]) || !isset($now[$id])) {
                $count++;
                continue;
            }

            if ($old[$id]['name'] !== $now[$id]['name']) {
                $count++;
            }

            $count += count(array_diff_assoc((array) $old[$id]['capabilities'], (array) $now[$id]['capabilities']));
            $count += count(array_diff_key((array) $now[$id]['capabilities'], (array) $old[$id]['capabilities']));
        }

        return $count;
    }
}
