<?php

namespace Smbb\WpCodeTool\Forms;

defined('ABSPATH') || exit;

/**
 * Lecture et écriture des formulaires (table smbb_forms).
 *
 * Un formulaire lu : id, title, status, fields (liste de champs), settings (réglages, notifications,
 * confirmation), legacy_source, legacy_id, created_at, updated_at.
 */
final class FormRepository
{
    /** @var array<int,array> */
    private static $cache = array();

    public static function defaults()
    {
        return array(
            'submit_label' => __('Envoyer', 'smbb-wpcodetool'),
            'description' => '',
            'show_title' => '0',
            'style' => 'boxed',
            'store_entries' => '1',
            'retention' => '0',
            // Coché d'office : la protection suit les réglages généraux (CodeTool › Sécurité › Turnstile).
            'turnstile' => '1',
            'sms' => '0',
            'sms_to' => '',
            'css_class' => '',
            // Unité de stockage des pièces jointes (CodeTool › Réglages › Stockage) : 'wp' = dossier privé du site.
            'storage' => 'wp',
            // Par défaut : « Nouveau contact » pour l'équipe et accusé de réception pour le visiteur.
            'notifications' => array(self::defaultNotification(), self::receiptNotification()),
            'confirmation' => array(
                'type' => 'message',
                'message' => __('Merci, votre message est bien envoyé. Nous revenons vers vous rapidement.', 'smbb-wpcodetool'),
                'page' => '0',
                'url' => '',
            ),
        );
    }

    /**
     * Turnstile coché d'office sur les nouveaux formulaires et les formulaires repris (décision Seb) :
     * ce sont les réglages généraux qui décident. Dès que Turnstile est configuré dans CodeTool › Sécurité,
     * le formulaire est protégé, sauf si on a décoché la case de ce formulaire.
     * Filtre smbb_wpcodetool_forms_turnstile_default (false = décoché d'office).
     */
    public static function turnstileByDefault()
    {
        return (bool) apply_filters('smbb_wpcodetool_forms_turnstile_default', true);
    }

    /**
     * Notification « Nouveau contact » pour l'équipe (base des notifications : clés et valeurs par défaut).
     */
    public static function defaultNotification()
    {
        return array(
            'name' => __('Nouveau contact (équipe)', 'smbb-wpcodetool'),
            'enabled' => '1',
            'to' => '{admin_email}',
            'subject' => __('Nouveau contact : {form_name}', 'smbb-wpcodetool'),
            'reply_to' => '{visitor_email}',
            'from_name' => '',
            'message' => __("Nouveau contact reçu par le formulaire « {form_name} » le {date}.\n\n{all_fields}\n\nVoir l'entrée dans l'admin : {entry_url}", 'smbb-wpcodetool'),
            // Fichiers joints au mail de l'équipe (jusqu'à Notifier::attachMax(), au-delà : liens seulement).
            'attach_files' => '1',
        );
    }

    /**
     * Accusé de réception envoyé au visiteur (à l'adresse saisie dans le formulaire).
     */
    public static function receiptNotification()
    {
        return array(
            'name' => __('Accusé de réception (visiteur)', 'smbb-wpcodetool'),
            'enabled' => '1',
            'to' => '{visitor_email}',
            'subject' => __('{site_name} : nous avons bien reçu votre message', 'smbb-wpcodetool'),
            'reply_to' => '{admin_email}',
            'from_name' => '{site_name}',
            'message' => __("Bonjour,\n\nMerci pour votre message, il nous est bien parvenu. Nous revenons vers vous rapidement.\n\nPour mémoire, voici ce que vous nous avez envoyé :\n\n{all_fields}\n\nÀ bientôt,\nL'équipe {site_name}", 'smbb-wpcodetool'),
            'attach_files' => '0',
        );
    }

    public static function styles()
    {
        return array(
            'boxed' => __('Encadré', 'smbb-wpcodetool'),
            'underline' => __('Souligné', 'smbb-wpcodetool'),
            'filled' => __('Plein', 'smbb-wpcodetool'),
        );
    }

    public static function retentions()
    {
        return array(
            '0' => __('Toujours (jusqu\'à suppression)', 'smbb-wpcodetool'),
            '30' => __('30 jours', 'smbb-wpcodetool'),
            '90' => __('3 mois', 'smbb-wpcodetool'),
            '180' => __('6 mois', 'smbb-wpcodetool'),
            '365' => __('1 an', 'smbb-wpcodetool'),
            '730' => __('2 ans', 'smbb-wpcodetool'),
            '1095' => __('3 ans', 'smbb-wpcodetool'),
        );
    }

    /**
     * Modèles proposés à la création.
     *
     * @return array<string,array{label:string,fields:array}>
     */
    public static function templates()
    {
        $f = static function ($type, $label, array $extra = array()) {
            return array_merge(array('type' => $type, 'label' => $label), $extra);
        };

        return array(
            'contact' => array(
                'label' => __('Contact', 'smbb-wpcodetool'),
                'fields' => array(
                    $f('text', __('Prénom', 'smbb-wpcodetool'), array('required' => '1', 'width' => 'half')),
                    $f('text', __('Nom', 'smbb-wpcodetool'), array('required' => '1', 'width' => 'half')),
                    $f('email', __('E-mail', 'smbb-wpcodetool'), array('required' => '1', 'width' => 'half')),
                    $f('phone', __('Téléphone', 'smbb-wpcodetool'), array('width' => 'half')),
                    $f('textarea', __('Votre message', 'smbb-wpcodetool'), array('required' => '1')),
                    $f('gdpr', __('J\'accepte que mes données servent à traiter ma demande.', 'smbb-wpcodetool'), array('required' => '1')),
                ),
            ),
            'quote' => array(
                'label' => __('Demande de devis', 'smbb-wpcodetool'),
                'fields' => array(
                    $f('section', __('Vos coordonnées', 'smbb-wpcodetool'), array('help' => __('Nous revenons vers vous sous 48 h.', 'smbb-wpcodetool'))),
                    $f('text', __('Nom et prénom', 'smbb-wpcodetool'), array('required' => '1', 'width' => 'half')),
                    $f('text', __('Société', 'smbb-wpcodetool'), array('width' => 'half')),
                    $f('email', __('E-mail', 'smbb-wpcodetool'), array('required' => '1', 'width' => 'half')),
                    $f('phone', __('Téléphone', 'smbb-wpcodetool'), array('width' => 'half')),
                    $f('section', __('Votre projet', 'smbb-wpcodetool')),
                    $f('select', __('Prestation', 'smbb-wpcodetool'), array('required' => '1', 'choices' => __("Atelier\nÉvénement\nAutre", 'smbb-wpcodetool'))),
                    $f('number', __('Nombre de personnes', 'smbb-wpcodetool'), array('width' => 'third')),
                    $f('date', __('Date souhaitée', 'smbb-wpcodetool'), array('width' => 'third')),
                    $f('text', __('Budget', 'smbb-wpcodetool'), array('width' => 'third')),
                    $f('textarea', __('Détails', 'smbb-wpcodetool')),
                    $f('file', __('Document utile', 'smbb-wpcodetool'), array('help' => __('Cahier des charges, photo… facultatif.', 'smbb-wpcodetool'))),
                    $f('gdpr', __('J\'accepte que mes données servent à traiter ma demande.', 'smbb-wpcodetool'), array('required' => '1')),
                ),
            ),
            'satisfaction' => array(
                'label' => __('Questionnaire de satisfaction', 'smbb-wpcodetool'),
                'fields' => array(
                    $f('rating', __('Votre note globale', 'smbb-wpcodetool'), array('required' => '1')),
                    $f('radio', __('Recommanderiez-vous cette expérience à un proche ?', 'smbb-wpcodetool'), array('choices' => __("Oui, sans hésiter\nPeut-être\nNon", 'smbb-wpcodetool'))),
                    $f('textarea', __("Ce que vous avez préféré", 'smbb-wpcodetool'), array('width' => 'half')),
                    $f('textarea', __('Ce que nous pourrions améliorer', 'smbb-wpcodetool'), array('width' => 'half')),
                ),
            ),
            'blank' => array(
                'label' => __('Vide', 'smbb-wpcodetool'),
                'fields' => array(),
            ),
        );
    }

    /**
     * @return array|null
     */
    public static function find($id)
    {
        $id = (int) $id;

        if ($id <= 0 || !Forms::ready()) {
            return null;
        }

        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Forms::formsTable() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore

        return self::$cache[$id] = $row ? self::hydrate($row) : null;
    }

    public static function findByLegacy($source, $legacy_id)
    {
        if (!Forms::ready()) {
            return null;
        }

        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Forms::formsTable() . ' WHERE legacy_source = %s AND legacy_id = %d ORDER BY id DESC LIMIT 1', $source, (int) $legacy_id)); // phpcs:ignore

        return $id ? self::find($id) : null;
    }

    /**
     * @return array[]
     */
    public static function all()
    {
        if (!Forms::ready()) {
            return array();
        }

        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . Forms::formsTable() . " WHERE status <> 'trash' ORDER BY title ASC, id ASC", ARRAY_A); // phpcs:ignore

        return array_map(array(__CLASS__, 'hydrate'), (array) $rows);
    }

    /**
     * Liste courte pour les sélecteurs (bloc, filtres) : id => titre.
     *
     * @return array<int,string>
     */
    public static function choices()
    {
        if (!Forms::ready()) {
            return array();
        }

        global $wpdb;
        $rows = $wpdb->get_results('SELECT id, title FROM ' . Forms::formsTable() . " WHERE status <> 'trash' ORDER BY title ASC", ARRAY_A); // phpcs:ignore
        $out = array();

        foreach ((array) $rows as $row) {
            $out[(int) $row['id']] = $row['title'] !== '' ? $row['title'] : sprintf(__('Formulaire %d', 'smbb-wpcodetool'), $row['id']);
        }

        return $out;
    }

    public static function hydrate(array $row)
    {
        $fields = json_decode((string) $row['fields'], true);
        $settings = json_decode((string) $row['settings'], true);

        return array(
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'status' => (string) $row['status'],
            'fields' => is_array($fields) ? $fields : array(),
            'settings' => self::settings(is_array($settings) ? $settings : array()),
            'legacy_source' => (string) $row['legacy_source'],
            'legacy_id' => (int) $row['legacy_id'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        );
    }

    /**
     * Réglages complétés par les valeurs par défaut.
     */
    public static function settings(array $settings)
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, array_intersect_key($settings, $defaults));
        $out['confirmation'] = array_merge($defaults['confirmation'], isset($settings['confirmation']) && is_array($settings['confirmation']) ? $settings['confirmation'] : array());
        $out['notifications'] = isset($settings['notifications']) && is_array($settings['notifications']) ? array_values($settings['notifications']) : $defaults['notifications'];

        foreach ($out['notifications'] as $i => $notification) {
            $out['notifications'][$i] = array_merge(self::defaultNotification(), is_array($notification) ? $notification : array());
        }

        return $out;
    }

    /**
     * Nettoie les réglages postés (une section à la fois : les autres clés restent telles quelles).
     */
    public static function sanitizeSettings(array $posted, array $current)
    {
        $out = $current;
        $text = static function ($value, $max = 500) {
            return is_scalar($value) ? mb_substr(sanitize_text_field((string) $value), 0, $max) : '';
        };
        $flag = static function ($value) {
            return !empty($value) && $value !== '0' ? '1' : '0';
        };

        foreach (array('submit_label' => 100, 'sms_to' => 300, 'css_class' => 200) as $key => $max) {
            if (array_key_exists($key, $posted)) {
                $out[$key] = $text($posted[$key], $max);
            }
        }

        if (isset($out['css_class'])) {
            $out['css_class'] = implode(' ', array_filter(array_map('sanitize_html_class', explode(' ', $out['css_class']))));
        }

        if (array_key_exists('description', $posted)) {
            $out['description'] = is_scalar($posted['description']) ? wp_kses_post((string) $posted['description']) : '';
        }

        foreach (array('show_title', 'store_entries', 'turnstile', 'sms') as $key) {
            if (array_key_exists($key, $posted)) {
                $out[$key] = $flag($posted[$key]);
            }
        }

        if (isset($posted['style'])) {
            $out['style'] = array_key_exists((string) $posted['style'], self::styles()) ? (string) $posted['style'] : 'boxed';
        }

        if (isset($posted['storage'])) {
            $unit = sanitize_key((string) $posted['storage']);
            $out['storage'] = class_exists(\Smbb\WpCodeTool\Storage\Storage::class) && \Smbb\WpCodeTool\Storage\Storage::exists($unit) ? $unit : 'wp';
        }

        if (isset($posted['retention'])) {
            $out['retention'] = array_key_exists((string) $posted['retention'], self::retentions()) ? (string) $posted['retention'] : '0';
        }

        if (array_key_exists('notifications', $posted)) {
            $out['notifications'] = array();

            foreach (is_array($posted['notifications']) ? array_values($posted['notifications']) : array() as $n) {
                if (!is_array($n)) {
                    continue;
                }

                $out['notifications'][] = array(
                    'name' => $text($n['name'] ?? '', 150) ?: __('Notification', 'smbb-wpcodetool'),
                    'enabled' => $flag($n['enabled'] ?? ''),
                    'to' => $text($n['to'] ?? '', 1000),
                    'subject' => $text($n['subject'] ?? '', 300),
                    'reply_to' => $text($n['reply_to'] ?? '', 300),
                    'from_name' => $text($n['from_name'] ?? '', 150),
                    'message' => is_scalar($n['message'] ?? '') ? wp_kses_post((string) ($n['message'] ?? '')) : '',
                    'attach_files' => $flag($n['attach_files'] ?? ''),
                );
            }
        }

        if (isset($posted['confirmation']) && is_array($posted['confirmation'])) {
            $c = $posted['confirmation'];
            $type = isset($c['type']) ? sanitize_key((string) $c['type']) : 'message';
            $out['confirmation'] = array(
                'type' => in_array($type, array('message', 'page', 'url'), true) ? $type : 'message',
                'message' => is_scalar($c['message'] ?? '') ? wp_kses_post((string) ($c['message'] ?? '')) : '',
                'page' => (string) absint($c['page'] ?? 0),
                'url' => isset($c['url']) && is_scalar($c['url']) ? esc_url_raw((string) $c['url']) : '',
            );
        }

        return self::settings($out);
    }

    /**
     * Crée un formulaire depuis un modèle (templates()), pour un plugin : clés lisibles tirées des libellés.
     * $settings remplace les réglages par défaut (ex. notifications vides, message de confirmation).
     *
     * @return int id du formulaire, 0 en cas d'échec
     */
    public static function createFromTemplate($template, $title = '', array $settings = array())
    {
        $templates = self::templates();

        if (!isset($templates[$template])) {
            return 0;
        }

        $fields = $templates[$template]['fields'];
        $used = array();

        foreach ($fields as $i => $field) {
            $key = $field['type'] === 'gdpr' ? 'consentement' : ($field['type'] === 'email' ? 'email' : FieldTypes::normalizeKey((string) ($field['label'] ?? '')));
            $key = $key !== '' ? substr($key, 0, 30) : 'f' . ($i + 1);
            $base = $key;
            $n = 2;

            while (isset($used[$key])) {
                $key = $base . '_' . $n++;
            }

            $used[$key] = true;
            $fields[$i]['key'] = $key;
        }

        $id = self::save(array(
            'title' => $title !== '' ? $title : $templates[$template]['label'],
            'fields' => $fields,
            'settings' => array_merge(self::defaults(), array('turnstile' => self::turnstileByDefault() ? '1' : '0'), $settings),
        ));

        if ($id) {
            $form = self::find($id);
            Forms::log('form_created', sprintf(__('Formulaire créé : %s', 'smbb-wpcodetool'), $form ? $form['title'] : $title), $form ?: array());
        }

        return $id;
    }

    /**
     * Crée ou met à jour un formulaire. Retourne son id (0 en cas d'échec).
     */
    public static function save(array $form)
    {
        if (!Forms::ready()) {
            Forms::install();
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $id = isset($form['id']) ? (int) $form['id'] : 0;
        $data = array(
            'title' => mb_substr(sanitize_text_field((string) ($form['title'] ?? '')), 0, 190),
            'status' => isset($form['status']) && $form['status'] === 'draft' ? 'draft' : 'publish',
            'fields' => (string) wp_json_encode(FieldTypes::sanitizeList($form['fields'] ?? array()), JSON_UNESCAPED_UNICODE),
            'settings' => (string) wp_json_encode(self::settings(isset($form['settings']) && is_array($form['settings']) ? $form['settings'] : array()), JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        );

        if ($data['title'] === '') {
            $data['title'] = __('Formulaire sans titre', 'smbb-wpcodetool');
        }

        unset(self::$cache[$id]);

        if ($id > 0) {
            $wpdb->update(Forms::formsTable(), $data, array('id' => $id)); // phpcs:ignore

            return $id;
        }

        $data['created_at'] = $now;
        $data['legacy_source'] = isset($form['legacy_source']) ? sanitize_key((string) $form['legacy_source']) : '';
        $data['legacy_id'] = isset($form['legacy_id']) ? (int) $form['legacy_id'] : 0;

        return $wpdb->insert(Forms::formsTable(), $data) ? (int) $wpdb->insert_id : 0; // phpcs:ignore
    }

    /**
     * Copie d'un formulaire (sans ses entrées).
     */
    public static function duplicate($id)
    {
        $form = self::find($id);

        if (!$form) {
            return 0;
        }

        unset($form['id'], $form['legacy_source'], $form['legacy_id']);
        $form['title'] = sprintf(__('%s (copie)', 'smbb-wpcodetool'), $form['title']);

        return self::save($form);
    }

    /**
     * Supprime un formulaire et ses entrées (fichiers compris).
     */
    public static function delete($id)
    {
        global $wpdb;
        $id = (int) $id;

        if ($id <= 0 || !Forms::ready()) {
            return false;
        }

        EntryRepository::deleteForForm($id);
        unset(self::$cache[$id]);

        return (bool) $wpdb->delete(Forms::formsTable(), array('id' => $id)); // phpcs:ignore
    }

    /**
     * Champ d'un formulaire par sa clé.
     */
    public static function field(array $form, $key)
    {
        foreach ($form['fields'] as $field) {
            if (isset($field['key']) && $field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }
}
