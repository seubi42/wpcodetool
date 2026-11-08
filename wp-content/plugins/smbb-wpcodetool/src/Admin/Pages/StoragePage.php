<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Storage\Storage;
use Smbb\WpCodeTool\Support\Secrets;

defined('ABSPATH') || exit;

/**
 * Page « Stockage » des réglages CodeTool : les unités de stockage où les modules et plugins rangent
 * leurs fichiers (pièces jointes des formulaires, documents SignaConnect…). Voir Storage\Storage.
 *
 * - Liste : unité WordPress (toujours là) puis les unités ajoutées, avec « Tester », « Modifier », « Supprimer »
 *   et l'endroit où chacune sert.
 * - Ajout / modification : ?unit=<id> ou ?add=<pilote>.
 */
final class StoragePage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-storage';
    const NONCE = 'smbb_codetool_storage';

    public static function url(array $args = array())
    {
        return add_query_arg($args, admin_url('admin.php?page=' . self::SLUG));
    }

    public static function handle()
    {
        if (!isset($_POST['smbb_storage_action'])) {
            return;
        }

        if (!current_user_can('manage_options')
            || !isset($_POST['smbb_storage_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_storage_nonce'])), self::NONCE)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier les unités de stockage.', 'smbb-wpcodetool'));
        }

        $action = sanitize_key(wp_unslash($_POST['smbb_storage_action']));
        $id = isset($_POST['unit']) ? sanitize_key(wp_unslash($_POST['unit'])) : '';

        try {
            switch ($action) {
                case 'save':
                    $driver = isset($_POST['driver']) ? sanitize_key(wp_unslash($_POST['driver'])) : '';
                    $settings = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : array(); // phpcs:ignore -- nettoyé par Storage::save.
                    $id = Storage::save($id, isset($_POST['label']) ? wp_unslash($_POST['label']) : '', $driver, (array) $settings); // phpcs:ignore
                    $test = Storage::test($id);
                    self::flash($test['ok'] ? 'success' : 'error', $test['ok']
                        ? __('Unité enregistrée et testée : écriture, lecture et suppression réussies.', 'smbb-wpcodetool')
                        : sprintf(__('Unité enregistrée, mais le test a échoué : %s', 'smbb-wpcodetool'), $test['message']));
                    self::log('storage_saved', $id);
                    wp_safe_redirect($test['ok'] ? self::url() : self::url(array('unit' => $id)));
                    exit;

                case 'test':
                    $test = Storage::test($id);
                    self::flash($test['ok'] ? 'success' : 'error', sprintf('%s : %s', Storage::label($id), $test['message']));
                    // Le résultat reste affiché sur la ligne de l'unité (la notification verte, elle, s'efface).
                    wp_safe_redirect(self::url(array('tested' => $id)) . '#unit-' . $id);
                    exit;

                case 'delete':
                    $label = Storage::label($id);
                    self::log('storage_deleted', $id);
                    Storage::delete($id);
                    self::flash('success', sprintf(__('Unité « %s » supprimée. Les fichiers déjà rangés dessus n\'ont pas été touchés.', 'smbb-wpcodetool'), $label));
                    break;
            }
        } catch (\Throwable $e) {
            self::flash('error', $e->getMessage());

            if ($action === 'save') {
                // On garde la saisie (hors secrets) pour ne pas tout retaper.
                set_transient('smbb_storage_draft_' . get_current_user_id(), array(
                    'label' => isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '',
                    'settings' => isset($_POST['settings']) && is_array($_POST['settings']) ? array_map('sanitize_text_field', wp_unslash($_POST['settings'])) : array(), // phpcs:ignore
                ), 120);
                $driver = isset($_POST['driver']) ? sanitize_key(wp_unslash($_POST['driver'])) : 's3';
                wp_safe_redirect($id !== '' ? self::url(array('unit' => $id)) : self::url(array('add' => $driver)));
                exit;
            }
        }

        wp_safe_redirect(self::url());
        exit;
    }

    private static function log($action, $id)
    {
        do_action('smbb_wpcodetool_activity', 'settings.' . $action, array(
            'category' => 'settings',
            'level' => $action === 'storage_deleted' ? 'notice' : 'info',
            'message' => $action === 'storage_deleted' ? __('Unité de stockage supprimée', 'smbb-wpcodetool') : __('Unité de stockage enregistrée', 'smbb-wpcodetool'),
            'object_type' => 'smbb_storage_unit',
            'object_label' => Storage::label($id),
            'context' => array('unit' => $id),
            'source' => 'smbb-wpcodetool',
        ));
    }

    private static function flash($type, $message)
    {
        set_transient('smbb_storage_flash_' . get_current_user_id(), array($type, $message), 60);
    }

    private function notices()
    {
        $key = 'smbb_storage_flash_' . get_current_user_id();
        $flash = get_transient($key);

        if (!is_array($flash)) {
            return array();
        }

        delete_transient($key);

        return array('<div class="smbb-codetool-notice ' . ($flash[0] === 'success' ? 'is-success' : 'is-error') . '"><p class="smbb-codetool-notice-message">' . esc_html($flash[1]) . '</p></div>');
    }

    public function render()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $unit = isset($_GET['unit']) ? sanitize_key(wp_unslash($_GET['unit'])) : '';
        $add = isset($_GET['add']) ? sanitize_key(wp_unslash($_GET['add'])) : '';
        // phpcs:enable
        $drivers = Storage::drivers();
        ?>
        <div class="wrap smbb-codetool smbb-storage">
            <?php
            echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
                __('Stockage', 'smbb-wpcodetool'),
                __('Les endroits où CodeTool et vos plugins rangent leurs fichiers : pièces jointes des formulaires, documents signés… WordPress (dossier privé de uploads) est toujours là ; ajoutez un bucket S3 pour sortir les fichiers du serveur.', 'smbb-wpcodetool'),
                'dashicons-database',
                $this->notices()
            );

            if ($unit !== '' && $unit !== Storage::DEFAULT_UNIT && Storage::get($unit)) {
                $this->renderForm(Storage::get($unit), $drivers);
            } elseif ($add !== '' && isset($drivers[$add])) {
                $this->renderForm(array('id' => '', 'label' => '', 'driver' => $add, 'settings' => array()), $drivers);
            } else {
                $this->renderList($drivers);
            }
            ?>
        </div>
        <?php
        $this->css();
    }

    private function renderList(array $drivers)
    {
        ?>
        <section class="smbb-codetool-section">
            <header class="smbb-codetool-section-header">
                <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-database"></span></span>
                <div class="smbb-codetool-section-heading">
                    <h3><?php esc_html_e('Unités de stockage', 'smbb-wpcodetool'); ?></h3>
                    <p class="description"><?php esc_html_e('Une unité qui sert quelque part ne peut pas être supprimée : choisissez d\'abord une autre unité là-bas.', 'smbb-wpcodetool'); ?></p>
                </div>
            </header>
            <div class="smbb-codetool-section-body">
                <form method="post" action="<?php echo esc_url(self::url()); ?>">
                    <?php wp_nonce_field(self::NONCE, 'smbb_storage_nonce'); ?>
                    <ul class="smbb-storage-units">
                        <?php foreach (Storage::units() as $id => $u) : ?>
                            <?php
                            $usage = Storage::usage($id);
                            $driver = $u['builtin'] ? null : ($drivers[$u['driver']] ?? null);
                            $icon = $u['builtin'] ? 'dashicons-wordpress' : ($driver['icon'] ?? 'dashicons-warning');
                            ?>
                            <li class="smbb-storage-unit<?php echo isset($_GET['tested']) && sanitize_key(wp_unslash($_GET['tested'])) === $id ? ' is-tested' : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>" id="unit-<?php echo esc_attr($id); ?>">
                                <span class="smbb-storage-unit-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr($icon); ?>"></span></span>
                                <div class="smbb-storage-unit-main">
                                    <strong><?php echo esc_html($u['label']); ?></strong>
                                    <?php if ($u['builtin']) : ?>
                                        <span class="smbb-storage-chip"><?php esc_html_e('Par défaut', 'smbb-wpcodetool'); ?></span>
                                    <?php endif; ?>
                                    <span class="smbb-storage-unit-meta"><?php echo esc_html($this->describe($u, $driver)); ?></span>
                                    <?php $last = Storage::lastTest($id); ?>
                                    <?php if ($last) : ?>
                                        <span class="smbb-storage-unit-test <?php echo !empty($last['ok']) ? 'is-ok' : 'is-bad'; ?>" title="<?php echo esc_attr(wp_date(get_option('date_format') . ' H:i', (int) $last['at'])); ?>">
                                            <span class="dashicons <?php echo !empty($last['ok']) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
                                            <?php echo esc_html(sprintf(!empty($last['ok']) ? __('Testée il y a %1$s : %2$s', 'smbb-wpcodetool') : __('Échec du test il y a %1$s : %2$s', 'smbb-wpcodetool'), human_time_diff((int) $last['at'], time()), $last['message'])); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="smbb-storage-unit-usage">
                                        <?php echo esc_html($usage ? sprintf(__('Sert à : %s', 'smbb-wpcodetool'), implode(', ', $usage)) : __('Ne sert nulle part pour l\'instant.', 'smbb-wpcodetool')); ?>
                                    </span>
                                </div>
                                <div class="smbb-storage-unit-actions">
                                    <button type="submit" class="button" name="smbb_storage_action" value="test" onclick="this.form.unit.value='<?php echo esc_attr($id); ?>'"><?php esc_html_e('Tester', 'smbb-wpcodetool'); ?></button>
                                    <?php if (!$u['builtin']) : ?>
                                        <a class="button" href="<?php echo esc_url(self::url(array('unit' => $id))); ?>"><?php esc_html_e('Modifier', 'smbb-wpcodetool'); ?></a>
                                        <?php if (!$usage) : ?>
                                            <button type="submit" class="button-link button-link-delete" name="smbb_storage_action" value="delete" onclick="if(!confirm('<?php echo esc_js(__('Supprimer cette unité ? Les fichiers déjà rangés dessus ne sont pas effacés.', 'smbb-wpcodetool')); ?>'))return false;this.form.unit.value='<?php echo esc_attr($id); ?>'"><?php esc_html_e('Supprimer', 'smbb-wpcodetool'); ?></button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <input type="hidden" name="unit" value="">
                </form>
                <p class="smbb-storage-add">
                    <?php foreach ($drivers as $key => $driver) : ?>
                        <a class="button button-primary" href="<?php echo esc_url(self::url(array('add' => $key))); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php echo esc_html(sprintf(__('Ajouter une unité %s', 'smbb-wpcodetool'), $driver['label'])); ?></a>
                    <?php endforeach; ?>
                </p>
            </div>
        </section>
        <?php
    }

    private function describe(array $unit, $driver)
    {
        if ($unit['builtin']) {
            return __('Dossier privé dans wp-content/uploads, fermé au web. Les fichiers restent sur ce serveur (et dans ses sauvegardes).', 'smbb-wpcodetool');
        }

        if (!$driver) {
            return sprintf(__('Pilote « %s » introuvable (plugin désactivé ?).', 'smbb-wpcodetool'), $unit['driver']);
        }

        $s = $unit['settings'];

        if ($unit['driver'] === 's3') {
            $host = wp_parse_url((string) ($s['endpoint'] ?? ''), PHP_URL_HOST);

            return trim(sprintf('%s · %s%s%s', $driver['label'], $host ? $host : 's3.amazonaws.com', ' · ' . ($s['bucket'] ?? ''), ($s['prefix'] ?? '') !== '' ? '/' . trim($s['prefix'], '/') : ''));
        }

        return $driver['label'];
    }

    private function renderForm(array $unit, array $drivers)
    {
        $driver = $drivers[$unit['driver']];
        $draft = get_transient('smbb_storage_draft_' . get_current_user_id());
        delete_transient('smbb_storage_draft_' . get_current_user_id());
        $label = is_array($draft) ? $draft['label'] : $unit['label'];
        $values = is_array($draft) ? array_merge($unit['settings'], $draft['settings']) : $unit['settings'];
        ?>
        <form method="post" action="<?php echo esc_url(self::url()); ?>" class="smbb-storage-form">
            <?php wp_nonce_field(self::NONCE, 'smbb_storage_nonce'); ?>
            <input type="hidden" name="smbb_storage_action" value="save">
            <input type="hidden" name="unit" value="<?php echo esc_attr($unit['id']); ?>">
            <input type="hidden" name="driver" value="<?php echo esc_attr($unit['driver']); ?>">
            <section class="smbb-codetool-section">
                <header class="smbb-codetool-section-header">
                    <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr($driver['icon']); ?>"></span></span>
                    <div class="smbb-codetool-section-heading">
                        <h3><?php echo esc_html($unit['id'] === '' ? sprintf(__('Nouvelle unité %s', 'smbb-wpcodetool'), $driver['label']) : $unit['label']); ?></h3>
                        <p class="description"><?php esc_html_e('À l\'enregistrement, CodeTool écrit, relit puis supprime un petit fichier pour vérifier l\'accès.', 'smbb-wpcodetool'); ?></p>
                    </div>
                </header>
                <div class="smbb-codetool-section-body">
                    <p class="smbb-storage-field">
                        <label for="smbb-storage-label"><strong><?php esc_html_e('Nom de l\'unité', 'smbb-wpcodetool'); ?></strong> <span class="smbb-form-required">*</span></label>
                        <input type="text" class="regular-text" id="smbb-storage-label" name="label" value="<?php echo esc_attr($label); ?>" required placeholder="<?php esc_attr_e('S3 Proxima (OVH)', 'smbb-wpcodetool'); ?>">
                        <span class="description"><?php esc_html_e('C\'est ce nom qui apparaît dans les listes des formulaires et des plugins.', 'smbb-wpcodetool'); ?></span>
                    </p>
                    <?php
                    foreach ($driver['fields'] as $key => $field) {
                        $type = $field['type'] ?? 'text';
                        $name = 'settings[' . $key . ']';
                        $value = $values[$key] ?? '';

                        if ($type === 'toggle') {
                            AppearancePage::toggle($name, (int) $value, $field['label'], $field['help'] ?? '');
                            continue;
                        }

                        $is_secret = in_array($key, (array) $driver['secrets'], true);
                        $stored = $is_secret && $unit['id'] !== '' && (string) ($unit['settings'][$key] ?? '') !== '';
                        ?>
                        <p class="smbb-storage-field">
                            <label for="smbb-storage-<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($field['label']); ?></strong><?php echo !empty($field['required']) ? ' <span class="smbb-form-required">*</span>' : ''; ?></label>
                            <input
                                type="<?php echo $is_secret ? 'password' : 'text'; ?>"
                                class="regular-text code"
                                id="smbb-storage-<?php echo esc_attr($key); ?>"
                                name="<?php echo esc_attr($name); ?>"
                                value="<?php echo $is_secret ? '' : esc_attr((string) $value); ?>"
                                autocomplete="<?php echo $is_secret ? 'new-password' : 'off'; ?>"
                                spellcheck="false"
                                <?php echo !empty($field['required']) && !$stored ? 'required' : ''; ?>
                                placeholder="<?php echo esc_attr($stored ? __('•••••••• enregistrée, laisser vide pour la garder', 'smbb-wpcodetool') : (string) ($field['placeholder'] ?? '')); ?>"
                            >
                            <?php if (!empty($field['help'])) : ?><span class="description"><?php echo esc_html($field['help']); ?></span><?php endif; ?>
                            <?php if ($is_secret) : ?>
                                <span class="description"><?php echo esc_html(Secrets::available()
                                    ? __('Chiffrée en base comme les autres secrets (CodeTool › Chiffrement).', 'smbb-wpcodetool')
                                    : __('Chiffrement indisponible sur ce serveur : la clé sera gardée en clair.', 'smbb-wpcodetool')); ?></span>
                            <?php endif; ?>
                        </p>
                        <?php
                    }
                    ?>
                </div>
            </section>
            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer et tester', 'smbb-wpcodetool'); ?></button>
                <a class="button" href="<?php echo esc_url(self::url()); ?>"><?php esc_html_e('Annuler', 'smbb-wpcodetool'); ?></a>
            </p>
        </form>
        <?php
    }

    private function css()
    {
        ?>
        <style>
            .smbb-storage-units{margin:0;padding:0;list-style:none;border:1px solid var(--smbb-t-line,#dcdcde);border-radius:12px;overflow:hidden}
            .smbb-storage-unit{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:14px;align-items:center;margin:0;padding:14px 16px;border-top:1px solid var(--smbb-t-line,#dcdcde)}
            .smbb-storage-unit:first-child{border-top:0}
            .smbb-storage-unit-icon{display:grid;place-items:center;width:44px;height:44px;border-radius:12px;background:var(--smbb-t-fill,#f6f7f7);color:var(--smbb-t-accent,#2271b1)}
            .smbb-storage-unit-icon .dashicons{font-size:22px;width:22px;height:22px}
            .smbb-storage-unit-main strong{font-size:14px}
            .smbb-storage-unit-meta,.smbb-storage-unit-usage{display:block;margin-top:2px;font-size:12px;color:var(--smbb-t-muted,#646970);word-break:break-word}
            .smbb-storage-unit-test{display:flex;gap:4px;align-items:flex-start;margin-top:6px;font-size:12px;font-weight:600;word-break:break-word}
            .smbb-storage-unit-test .dashicons{font-size:16px;width:16px;height:16px;flex:none}
            .smbb-storage-unit-test.is-ok{color:#1a7f4b}
            .smbb-storage-unit-test.is-bad{color:#c62828}
            html.smbb-dark .smbb-storage-unit-test.is-ok{color:#5cc98a}
            html.smbb-dark .smbb-storage-unit-test.is-bad{color:#f07a70}
            .smbb-storage-unit.is-tested{animation:smbb-storage-flash 1.6s ease}
            @keyframes smbb-storage-flash{from{background:color-mix(in srgb,#1a7f4b 14%,transparent)}to{background:transparent}}
            .smbb-storage-chip{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;background:var(--smbb-t-fill,#f0f0f1);font-size:11px;font-weight:600}
            .smbb-storage-unit-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end}
            .smbb-storage-add{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0 0}
            .smbb-storage-add .dashicons{font-size:16px;width:16px;height:16px;vertical-align:text-bottom}
            .smbb-storage-field{display:flex;flex-direction:column;gap:4px;margin:0 0 16px;max-width:560px}
            .smbb-storage-field input{width:100%}
            @media (max-width:782px){.smbb-storage-unit{grid-template-columns:44px minmax(0,1fr)}.smbb-storage-unit-actions{grid-column:1/-1;justify-content:flex-start}}
        </style>
        <?php
    }
}
