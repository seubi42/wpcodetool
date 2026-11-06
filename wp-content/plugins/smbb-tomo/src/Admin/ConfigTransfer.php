<?php

namespace Smbb\Tomo\Admin;

use Smbb\Tomo\Support\Roles;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Réglages › Configuration : export et import des réglages Tomo en fichier JSON.
 *
 * Le fichier reprend l'option smbb_tomo_settings (vocabulaire, messages, billets, factures, envoi…)
 * et l'option smbb_tomo_documents (menu Documents). Les clés secrètes (Twilio, wallets) ne partent
 * que si on le demande, en clair : elles sont chiffrées avec la clé du site, illisibles ailleurs.
 *
 * L'import ne garde que les réglages connus, convertit chaque valeur au type attendu, garde les clés
 * secrètes actuelles quand le fichier n'en a pas, et sauvegarde les réglages remplacés : le bouton
 * « Annuler le dernier import » les remet.
 */
final class ConfigTransfer
{
    const EXPORT = 'tomo_config_export';
    const IMPORT = 'tomo_config_import';
    const RESTORE = 'tomo_config_restore';
    const BACKUP_OPTION = 'smbb_tomo_settings_backup';
    const FORMAT = 'smbb-tomo-config';
    const MAX_BYTES = 2097152;

    /** Réglages chiffrés en base. */
    const SECRETS = array('twilio_token', 'apple_private_key', 'apple_key_password', 'google_service_account');

    /** Propres à un site : jamais exportés (identifiant de média). */
    const LOCAL = array('ticket_logo_id');

    public static function hooks()
    {
        add_action('admin_post_' . self::EXPORT, array(__CLASS__, 'export'));
        add_action('admin_post_' . self::IMPORT, array(__CLASS__, 'import'));
        add_action('admin_post_' . self::RESTORE, array(__CLASS__, 'restore'));
    }

    /**
     * Contenu de l'onglet Configuration (HTML). Les champs de l'import pointent vers un formulaire
     * placé hors du formulaire des réglages (form="…"), un formulaire ne pouvant en contenir un autre.
     */
    public static function tabHtml()
    {
        $d = 'smbb-tomo';
        $export = '<p>' . esc_html__('Un fichier JSON avec tous les réglages de cette page et ceux du menu Documents : à garder en sauvegarde, ou à importer sur un autre site (préproduction, autre structure).', $d) . '</p>'
            . '<p><a class="button button-primary" href="' . esc_url(View::actionUrl(self::EXPORT)) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span> ' . esc_html__('Exporter les réglages', $d) . '</a> '
            . (current_user_can('manage_options') ? '<a class="button" href="' . esc_url(View::actionUrl(self::EXPORT, array('secrets' => 1))) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Le fichier contiendra en clair le jeton Twilio et les clés Apple et Google Wallet. Gardez-le en lieu sûr et ne l\'envoyez pas par e-mail. Continuer ?', $d))) . ');">'
            . esc_html__('Exporter avec les clés secrètes', $d) . '</a>' : '') . '</p>'
            . '<p class="description">' . esc_html__('Sans les clés secrètes, le site qui importe le fichier garde les siennes. Le logo des billets n\'est pas exporté : choisissez-le à nouveau sur l\'autre site.', $d) . '</p>';

        $import = '<p>' . esc_html__('Remplace les réglages de ce site par ceux du fichier. Les réglages absents du fichier ne changent pas.', $d) . '</p>'
            . '<p><input type="file" name="tomo_config_file" accept=".json,application/json" form="tomo-config-import" required></p>'
            . '<p><label><input type="checkbox" name="with_documents" value="1" form="tomo-config-import" checked> ' . esc_html__('Importer aussi les documents à faire signer (menu Documents)', $d) . '</label></p>'
            . '<p><button type="submit" class="button" form="tomo-config-import" onclick="return confirm(' . esc_attr(wp_json_encode(__('Remplacer les réglages actuels par ceux du fichier ? Vous pourrez annuler juste après.', $d))) . ');">'
            . '<span class="dashicons dashicons-upload" aria-hidden="true"></span> ' . esc_html__('Importer le fichier', $d) . '</button></p>';

        $backup = get_option(self::BACKUP_OPTION, null);

        if (is_array($backup) && !empty($backup['at'])) {
            $import .= '<p class="description">' . esc_html(sprintf(__('Dernier import le %s.', $d), wp_date(get_option('date_format') . ' H:i', (int) $backup['at']))) . ' '
                . '<a href="' . esc_url(View::actionUrl(self::RESTORE)) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Remettre les réglages tels qu\'ils étaient avant le dernier import ?', $d))) . ');">'
                . esc_html__('Annuler le dernier import', $d) . '</a></p>';
        }

        return View::note(__('Exporter', $d), $export, 'dashicons-download')
            . View::note(__('Importer', $d), $import, 'dashicons-upload');
    }

    /**
     * Formulaire d'import, à placer hors du formulaire des réglages.
     */
    public static function importForm()
    {
        return '<form id="tomo-config-import" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" hidden>'
            . '<input type="hidden" name="action" value="' . esc_attr(self::IMPORT) . '">'
            . wp_nonce_field(self::IMPORT . '_0', '_wpnonce', true, false)
            . '</form>';
    }

    public static function export()
    {
        check_admin_referer(self::EXPORT . '_0');

        if (!Roles::canManage()) {
            self::deny();
        }

        $with_secrets = !empty($_GET['secrets']);

        // Les clés en clair (Twilio, wallets) : administrateurs du site seulement, pas le rôle Gestionnaire Tomo.
        if ($with_secrets && !current_user_can('manage_options')) {
            self::deny();
        }
        $stored = get_option(Settings::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $settings = array();

        foreach (array_merge(Settings::defaults(), $stored) as $key => $value) {
            if (in_array($key, self::LOCAL, true) || !self::known($key)) {
                continue;
            }

            if (in_array($key, self::SECRETS, true)) {
                if ($with_secrets && (string) $value !== '') {
                    $settings[$key] = Settings::secret($key);
                }

                continue;
            }

            $settings[$key] = $value;
        }

        $docs = get_option(Settings::DOCUMENTS_OPTION, null);
        $data = array(
            'format' => self::FORMAT,
            'version' => 1,
            'plugin_version' => defined('SMBB_TOMO_VERSION') ? SMBB_TOMO_VERSION : '',
            'site' => home_url('/'),
            'exported_at' => gmdate('c'),
            'with_secrets' => $with_secrets,
            'settings' => $settings,
        );

        if (is_array($docs)) {
            $data['documents'] = $docs;
        }

        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $host = sanitize_file_name((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $name = 'tomo-reglages-' . ($host !== '' ? $host . '-' : '') . wp_date('Y-m-d') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen((string) $json));
        echo $json; // phpcs:ignore -- fichier JSON téléchargé.
        exit;
    }

    public static function import()
    {
        $d = 'smbb-tomo';
        check_admin_referer(self::IMPORT . '_0');

        if (!Roles::canManage()) {
            self::deny();
        }

        $file = isset($_FILES['tomo_config_file']) && is_array($_FILES['tomo_config_file']) ? $_FILES['tomo_config_file'] : null;

        if (!$file || (int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            self::done(false, __('Aucun fichier reçu. Choisissez le fichier JSON exporté depuis Tomo.', $d));
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            self::done(false, __('Fichier trop lourd pour des réglages (2 Mo au plus).', $d));
        }

        $result = self::parse((string) file_get_contents($file['tmp_name']));

        if (is_wp_error($result)) {
            self::done(false, $result->get_error_message());
        }

        $current = get_option(Settings::OPTION, array());
        $current = is_array($current) ? $current : array();
        $docs_before = get_option(Settings::DOCUMENTS_OPTION, null);
        $with_documents = !empty($_POST['with_documents']) && isset($result['documents']);

        update_option(self::BACKUP_OPTION, array(
            'at' => time(),
            'settings' => $current,
            'documents' => $with_documents ? $docs_before : null,
            'documents_replaced' => $with_documents,
        ), false);

        // Clés secrètes : celles du fichier sont chiffrées ; absentes ou vides, on garde celles du site.
        $settings = $result['settings'];

        foreach (self::SECRETS as $key) {
            if (!isset($settings[$key]) || $settings[$key] === '') {
                unset($settings[$key]);
            } elseif (class_exists('\\Smbb\\WpCodeTool\\Support\\Secrets')) {
                $settings[$key] = \Smbb\WpCodeTool\Support\Secrets::encrypt($settings[$key]);
            }
        }

        update_option(Settings::OPTION, array_merge($current, $settings), false);

        if ($with_documents) {
            update_option(Settings::DOCUMENTS_OPTION, $result['documents'], false);
        }

        $count = count($settings);
        $message = sprintf(_n('%d réglage importé.', '%d réglages importés.', $count, $d), $count);

        if ($result['ignored']) {
            $message .= ' ' . sprintf(__('Ignorés (inconnus de cette version) : %s.', $d), implode(', ', array_slice($result['ignored'], 0, 10)) . (count($result['ignored']) > 10 ? '…' : ''));
        }

        if ($with_documents) {
            $message .= ' ' . __('Documents à faire signer importés.', $d);
        }

        self::done(true, $message);
    }

    public static function restore()
    {
        $d = 'smbb-tomo';
        check_admin_referer(self::RESTORE . '_0');

        if (!Roles::canManage()) {
            self::deny();
        }

        $backup = get_option(self::BACKUP_OPTION, null);

        if (!is_array($backup) || !isset($backup['settings']) || !is_array($backup['settings'])) {
            self::done(false, __('Aucune sauvegarde à remettre.', $d));
        }

        update_option(Settings::OPTION, $backup['settings'], false);

        if (!empty($backup['documents_replaced'])) {
            if (is_array($backup['documents'])) {
                update_option(Settings::DOCUMENTS_OPTION, $backup['documents'], false);
            } else {
                delete_option(Settings::DOCUMENTS_OPTION);
            }
        }

        delete_option(self::BACKUP_OPTION);
        self::done(true, __('Réglages remis tels qu\'avant le dernier import.', $d));
    }

    /**
     * Lit et valide le fichier.
     *
     * @return array{settings:array<string,mixed>,documents?:array<string,mixed>,ignored:string[]}|\WP_Error
     */
    public static function parse($raw)
    {
        $d = 'smbb-tomo';
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return new \WP_Error('tomo_config', __('Ce fichier n\'est pas un JSON valide.', $d));
        }

        if (!isset($data['format']) || $data['format'] !== self::FORMAT || !isset($data['settings']) || !is_array($data['settings'])) {
            return new \WP_Error('tomo_config', __('Ce fichier ne vient pas de l\'export des réglages Tomo.', $d));
        }

        if (isset($data['version']) && (int) $data['version'] > 1) {
            return new \WP_Error('tomo_config', __('Ce fichier vient d\'une version plus récente de Tomo : mettez d\'abord le plugin à jour.', $d));
        }

        $defaults = Settings::defaults();
        $settings = array();
        $ignored = array();

        foreach ($data['settings'] as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::LOCAL, true)) {
                continue;
            }

            if (!self::known($key) || is_array($value) || is_object($value)) {
                $ignored[] = $key;
                continue;
            }

            $settings[$key] = self::cast($value, array_key_exists($key, $defaults) ? $defaults[$key] : '');
        }

        if (!$settings) {
            return new \WP_Error('tomo_config', __('Aucun réglage reconnu dans ce fichier.', $d));
        }

        $out = array('settings' => $settings, 'ignored' => $ignored);

        if (isset($data['documents']) && is_array($data['documents'])) {
            $docs = array();

            if (isset($data['documents']['documents']) && is_array($data['documents']['documents'])) {
                $docs['documents'] = self::cleanTree($data['documents']['documents']);
            }

            if (isset($data['documents']['documents_onsite_note']) && is_scalar($data['documents']['documents_onsite_note'])) {
                $docs['documents_onsite_note'] = self::text($data['documents']['documents_onsite_note']);
            }

            if ($docs) {
                $out['documents'] = $docs;
            }
        }

        return $out;
    }

    /**
     * Réglage connu de cette version : présent dans les valeurs par défaut, ou texte / canal de message.
     */
    private static function known($key)
    {
        return array_key_exists($key, Settings::defaults()) || preg_match('/^(tpl|notify)_[a-z0-9_]+$/', $key) || preg_match('/^vocabulary_[a-z_]+$/', $key);
    }

    /**
     * Valeur ramenée au type de la valeur par défaut.
     */
    private static function cast($value, $default)
    {
        if (is_int($default) || is_bool($default)) {
            return (int) $value;
        }

        if (is_float($default)) {
            return (float) $value;
        }

        return self::text($value);
    }

    private static function text($value)
    {
        $value = is_bool($value) ? ($value ? '1' : '') : (string) $value;
        $value = function_exists('wp_check_invalid_utf8') ? wp_check_invalid_utf8($value) : $value;

        return str_replace("\0", '', $value);
    }

    /**
     * Copie d'un tableau de documents : chaînes nettoyées, nombres et booléens gardés, rien d'autre.
     *
     * @param array<mixed> $tree
     * @return array<mixed>
     */
    private static function cleanTree(array $tree, $depth = 0)
    {
        $out = array();

        if ($depth > 6) {
            return $out;
        }

        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::cleanTree($value, $depth + 1);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $out[$key] = $value;
            } elseif (is_string($value)) {
                $out[$key] = self::text($value);
            }
        }

        return $out;
    }

    private static function deny()
    {
        wp_die(esc_html__('Action non autorisée.', 'smbb-tomo'), '', array('response' => 403));
    }

    private static function done($success, $message)
    {
        View::flash(array('success' => (bool) $success, 'message' => (string) $message));
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=smbb-codetool-tomo'));
        exit;
    }
}
