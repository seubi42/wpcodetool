<?php

namespace Smbb\WpCodeTool\Forms;

defined('ABSPATH') || exit;

/**
 * Reprise des formulaires WPForms (lite ou pro), sans dépendre de leur code : lecture directe
 * des articles « wpforms » (JSON dans post_content) et de la table {prefix}wpforms_entries.
 *
 * WPForms n'est jamais modifié. Un formulaire déjà repris n'est pas recréé, seules ses nouvelles
 * entrées sont ajoutées. Les clés des champs repris sont « w » + l'id WPForms (w3 pour le champ 3),
 * ce qui permet de convertir {field_id="3"} en {field:w3}.
 */
final class WpFormsImport
{
    const SOURCE = 'wpforms';

    /**
     * Correspondance type WPForms => type CodeTool.
     */
    private static function typeMap()
    {
        return array(
            'text' => 'text',
            'name' => 'text',
            'textarea' => 'textarea',
            'email' => 'email',
            'phone' => 'phone',
            'number' => 'number',
            'number-slider' => 'number',
            'date-time' => 'date',
            'select' => 'select',
            'radio' => 'radio',
            'checkbox' => 'checkbox',
            'payment-select' => 'select',
            'payment-multiple' => 'radio',
            'payment-checkbox' => 'checkbox',
            'url' => 'url',
            'file-upload' => 'file',
            'hidden' => 'hidden',
            'gdpr-checkbox' => 'gdpr',
            'divider' => 'section',
            'html' => 'html',
            'content' => 'html',
            'address' => 'textarea',
            'rating' => 'number',
            'likert_scale' => 'textarea',
            'net_promoter_score' => 'number',
        );
    }

    /**
     * Types sans équivalent : ignorés (le pagebreak devient simplement une suite de champs).
     */
    private static function skipped()
    {
        return array('pagebreak', 'captcha', 'entry-preview', 'layout', 'repeater', 'payment-total', 'payment-single', 'credit-card', 'stripe-credit-card', 'square', 'authorize_net', 'paypal-commerce', 'internal-information', 'password', 'signature', 'richtext', 'camera');
    }

    public static function available()
    {
        return (bool) self::detect();
    }

    private static function entriesTable()
    {
        global $wpdb;

        return $wpdb->prefix . 'wpforms_entries';
    }

    private static function hasEntriesTable()
    {
        static $has = null;

        if ($has === null) {
            global $wpdb;
            $table = self::entriesTable();
            $has = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table; // phpcs:ignore
        }

        return $has;
    }

    /**
     * @return array<int,array> id, title, data (JSON décodé)
     */
    private static function posts(array $ids = array())
    {
        global $wpdb;
        $sql = "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type = 'wpforms' AND post_status NOT IN ('trash','auto-draft')";

        if ($ids) {
            $sql .= ' AND ID IN (' . implode(',', array_map('intval', $ids)) . ')';
        }

        $rows = $wpdb->get_results($sql . ' ORDER BY ID ASC', ARRAY_A); // phpcs:ignore
        $out = array();

        foreach ((array) $rows as $row) {
            $data = json_decode((string) $row['post_content'], true);

            if (!is_array($data)) {
                $data = json_decode(wp_unslash((string) $row['post_content']), true);
            }

            $title = isset($data['settings']['form_title']) && $data['settings']['form_title'] !== '' ? (string) $data['settings']['form_title'] : (string) $row['post_title'];
            $out[] = array('id' => (int) $row['ID'], 'title' => $title, 'data' => is_array($data) ? $data : array());
        }

        return $out;
    }

    /**
     * Formulaires trouvés, pour l'écran de reprise.
     *
     * @return array<int,array{id:int,title:string,fields:int,entries:?int,imported:int,unsupported:string[]}>
     */
    public static function detect()
    {
        global $wpdb;
        $counts = array();

        if (self::hasEntriesTable()) {
            foreach ((array) $wpdb->get_results('SELECT form_id, COUNT(*) AS n FROM ' . self::entriesTable() . ' GROUP BY form_id', ARRAY_A) as $row) { // phpcs:ignore
                $counts[(int) $row['form_id']] = (int) $row['n'];
            }
        }

        $out = array();

        foreach (self::posts() as $post) {
            $fields = isset($post['data']['fields']) && is_array($post['data']['fields']) ? $post['data']['fields'] : array();
            $unsupported = array();

            foreach ($fields as $field) {
                $type = (string) ($field['type'] ?? '');

                if (!isset(self::typeMap()[$type]) && $type !== 'pagebreak') {
                    $unsupported[] = $type;
                }
            }

            $existing = FormRepository::findByLegacy(self::SOURCE, $post['id']);
            $out[] = array(
                'id' => $post['id'],
                'title' => $post['title'],
                'fields' => count($fields),
                'entries' => self::hasEntriesTable() ? ($counts[$post['id']] ?? 0) : null,
                'imported' => $existing ? (int) $existing['id'] : 0,
                'unsupported' => array_values(array_unique($unsupported)),
            );
        }

        return $out;
    }

    /**
     * @param int[] $ids
     * @return array{forms:int,entries:int,notes:string[]}
     */
    public static function import(array $ids, $with_entries = true)
    {
        if (!Forms::ready()) {
            Forms::install();
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore
        }

        $report = array('forms' => 0, 'entries' => 0, 'notes' => array());

        foreach (self::posts($ids) as $post) {
            $existing = FormRepository::findByLegacy(self::SOURCE, $post['id']);
            $notes = array();

            if ($existing) {
                $form_id = (int) $existing['id'];
            } else {
                $form = self::convertForm($post, $notes);
                $form_id = FormRepository::save($form);

                if (!$form_id) {
                    $report['notes'][] = sprintf(__('« %s » : la reprise a échoué.', 'smbb-wpcodetool'), $post['title']);
                    continue;
                }

                $report['forms']++;
            }

            foreach ($notes as $note) {
                $report['notes'][] = sprintf('« %1$s » : %2$s', $post['title'], $note);
            }

            if ($with_entries && self::hasEntriesTable()) {
                $report['entries'] += self::importEntries($post, FormRepository::find($form_id));
            }
        }

        return $report;
    }

    /**
     * Formulaire WPForms => formulaire CodeTool (tableau prêt pour FormRepository::save).
     */
    private static function convertForm(array $post, array &$notes)
    {
        $data = $post['data'];
        $s = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : array();
        $fields = array();
        $map = self::typeMap();

        foreach ((isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : array()) as $wf) {
            $type = (string) ($wf['type'] ?? '');

            if ($type === 'pagebreak') {
                if (!empty($wf['title'])) {
                    $fields[] = array('key' => 'w' . (int) $wf['id'], 'type' => 'section', 'label' => (string) $wf['title']);
                }
                continue;
            }

            if (!isset($map[$type])) {
                $notes[] = sprintf(__('champ « %1$s » (%2$s) non repris.', 'smbb-wpcodetool'), (string) ($wf['label'] ?? ''), $type);
                continue;
            }

            $fields[] = self::convertField($wf, $map[$type], $notes, isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : array());
        }

        $settings = array(
            'submit_label' => (string) ($s['submit_text'] ?? __('Envoyer', 'smbb-wpcodetool')),
            'description' => (string) ($s['form_desc'] ?? ''),
            'css_class' => (string) ($s['form_class'] ?? ''),
            'store_entries' => !empty($s['disable_entries']) ? '0' : '1',
            'turnstile' => (!empty($s['recaptcha']) || !empty($s['turnstile']) || FormRepository::turnstileByDefault()) ? '1' : '0',
            'notifications' => array(),
            'confirmation' => self::convertConfirmation($s, $notes),
        );

        $enabled = !isset($s['notification_enable']) || $s['notification_enable'] === '1' || $s['notification_enable'] === 1;

        foreach ((isset($s['notifications']) && is_array($s['notifications']) ? $s['notifications'] : array()) as $n) {
            if (!is_array($n)) {
                continue;
            }

            $settings['notifications'][] = array(
                'name' => (string) ($n['notification_name'] ?? __('Notification', 'smbb-wpcodetool')),
                'enabled' => $enabled ? '1' : '0',
                'to' => self::tags((string) ($n['email'] ?? '{admin_email}')),
                'subject' => self::tags((string) ($n['subject'] ?? '')),
                'reply_to' => self::tags((string) ($n['replyto'] ?? '')),
                'from_name' => self::tags((string) ($n['sender_name'] ?? '')),
                'message' => self::tags((string) ($n['message'] ?? '{all_fields}')),
                'attach_files' => !empty($n['file_upload_attachment_enable']) ? '1' : '0',
            );

            if (!empty($n['conditional_logic'])) {
                $notes[] = sprintf(__('la notification « %s » avait une condition : elle part désormais à chaque envoi.', 'smbb-wpcodetool'), (string) ($n['notification_name'] ?? ''));
            }
        }

        if (!$settings['notifications']) {
            $settings['notifications'] = array(FormRepository::defaultNotification());
            $settings['notifications'][0]['enabled'] = '0';
        }


        return array(
            'title' => $post['title'],
            'status' => 'publish',
            'fields' => $fields,
            'settings' => FormRepository::settings($settings),
            'legacy_source' => self::SOURCE,
            'legacy_id' => $post['id'],
        );
    }

    private static function convertField(array $wf, $type, array &$notes, array $all = array())
    {
        $css = (string) ($wf['css'] ?? '');
        $width = 'full';

        if (strpos($css, 'wpforms-one-half') !== false) {
            $width = 'half';
        } elseif (strpos($css, 'wpforms-one-third') !== false) {
            $width = 'third';
        } elseif (strpos($css, 'wpforms-two-thirds') !== false) {
            $width = 'two-thirds';
        }

        $field = array(
            'key' => 'w' . (int) ($wf['id'] ?? 0),
            'type' => $type,
            'label' => (string) ($wf['label'] ?? ''),
            'required' => !empty($wf['required']) ? '1' : '0',
            'help' => wp_strip_all_tags((string) ($wf['description'] ?? '')),
            'placeholder' => (string) ($wf['placeholder'] ?? ''),
            'width' => $width,
            'default' => (string) ($wf['default_value'] ?? ''),
        );

        if (in_array($type, array('select', 'radio', 'checkbox'), true) && !empty($wf['choices']) && is_array($wf['choices'])) {
            $field['choices'] = implode("\n", array_filter(array_map(static function ($c) {
                return is_array($c) ? trim((string) ($c['label'] ?? '')) : '';
            }, $wf['choices']), 'strlen'));
        }

        if (!empty($wf['conditional_logic']) && !empty($wf['conditionals']) && is_array($wf['conditionals'])) {
            $field = self::convertLogic($wf, $field, $notes, $all);
        }

        switch ((string) $wf['type']) {
            case 'gdpr-checkbox':
                $choice = is_array($wf['choices'] ?? null) ? reset($wf['choices']) : null;
                $text = is_array($choice) ? (string) ($choice['label'] ?? '') : '';
                $field['label'] = $text !== '' ? $text : $field['label'];
                $field['required'] = '1';
                break;
            case 'divider':
                // Titre de section : le texte d'accompagnement reste dans l'aide (affichée sous le titre).
                break;
            case 'html':
            case 'content':
                $field['content'] = (string) ($wf['code'] ?? ($wf['content'] ?? ''));
                break;
            case 'file-upload':
                $field['file_types'] = (string) ($wf['extensions'] ?? '');
                $field['file_max'] = !empty($wf['max_size']) ? (string) (int) ceil((float) $wf['max_size']) : '10';
                break;
            case 'name':
                if (($wf['format'] ?? '') === 'first-last' && $field['placeholder'] === '') {
                    $field['placeholder'] = trim((string) ($wf['first_placeholder'] ?? '') . ' ' . (string) ($wf['last_placeholder'] ?? ''));
                }
                break;
            case 'hidden':
                $field['default'] = self::tags($field['default']);
                break;
            case 'address':
                $notes[] = sprintf(__('« %s » (adresse) devient une zone de texte.', 'smbb-wpcodetool'), $field['label']);
                break;
            case 'date-time':
                if (($wf['format'] ?? 'date') !== 'date') {
                    $notes[] = sprintf(__('« %s » : seule la date est reprise (pas l\'heure).', 'smbb-wpcodetool'), $field['label']);
                }
                break;
        }

        return $field;
    }

    /**
     * Conditions WPForms (groupes « ou » de règles « et ») => une règle CodeTool (la première).
     */
    private static function convertLogic(array $wf, array $field, array &$notes, array $all)
    {
        $groups = array_values(array_filter($wf['conditionals'], 'is_array'));
        $rules = $groups ? array_values(array_filter($groups[0], 'is_array')) : array();
        $rule = $rules ? $rules[0] : null;

        if (!$rule || empty($rule['field'])) {
            return $field;
        }

        $ops = array('==' => 'is', '!=' => 'is_not', 'c' => 'contains', '!c' => 'is_not', '^' => 'contains', '~' => 'contains', 'e' => 'empty', '!e' => 'not_empty', '>' => 'not_empty', '<' => 'not_empty');
        $op = (string) ($rule['operator'] ?? '==');
        $value = (string) ($rule['value'] ?? '');
        $ref = $all[$rule['field']] ?? null;

        // Champ à choix : WPForms garde le n° du choix, on reprend son libellé.
        if (is_array($ref) && !empty($ref['choices'][$value]['label'])) {
            $value = (string) $ref['choices'][$value]['label'];
        }

        $field['logic'] = ($wf['conditional_type'] ?? 'show') === 'hide' ? 'hide' : 'show';
        $field['logic_field'] = 'w' . (int) $rule['field'];
        $field['logic_op'] = $ops[$op] ?? 'is';
        $field['logic_value'] = $value;

        if (count($groups) > 1 || count($rules) > 1 || !isset($ops[$op]) || in_array($op, array('!c', '^', '~', '>', '<'), true)) {
            $notes[] = sprintf(__('« %s » : condition simplifiée (une seule règle reprise), à vérifier.', 'smbb-wpcodetool'), $field['label']);
        }

        return $field;
    }

    private static function convertConfirmation(array $s, array &$notes)
    {
        $list = isset($s['confirmations']) && is_array($s['confirmations']) ? $s['confirmations'] : array();
        $c = $list ? reset($list) : $s;

        if (count($list) > 1) {
            $notes[] = __('plusieurs confirmations : seule la première est reprise.', 'smbb-wpcodetool');
        }

        $type = (string) ($c['confirmation_type'] ?? ($c['type'] ?? 'message'));
        $out = array(
            'type' => 'message',
            'message' => self::tags((string) ($c['confirmation_message'] ?? ($c['message'] ?? ''))),
            'page' => '0',
            'url' => '',
        );

        if ($type === 'page' && !empty($c['confirmation_page'] ?? $c['page'] ?? '')) {
            $out['type'] = 'page';
            $out['page'] = (string) absint($c['confirmation_page'] ?? $c['page']);
        } elseif ($type === 'redirect' && !empty($c['confirmation_redirect'] ?? $c['redirect'] ?? '')) {
            $out['type'] = 'url';
            $out['url'] = esc_url_raw((string) ($c['confirmation_redirect'] ?? $c['redirect']));
        }

        if ($out['message'] === '') {
            $out['message'] = FormRepository::defaults()['confirmation']['message'];
        }

        return $out;
    }

    /**
     * Balises WPForms => balises CodeTool.
     */
    public static function tags($text)
    {
        $text = (string) $text;
        $text = preg_replace('/\{field_id="(\d+)"\}/', '{field:w$1}', $text);
        $text = preg_replace('/\{field_value_id="(\d+)"\}/', '{field:w$1}', $text);
        $text = preg_replace('/\{field_html_id="(\d+)"\}/', '{field:w$1}', $text);

        return strtr($text, array(
            '{url_referer}' => '{page_url}',
            '{page_title}' => '{form_name}',
            '{user_display}' => '{user_name}',
            '{user_full_name}' => '{user_name}',
            '{date format="d/m/Y"}' => '{date}',
        ));
    }

    /**
     * Copie les entrées d'un formulaire WPForms (sans doublon).
     */
    private static function importEntries(array $post, $form)
    {
        if (!$form) {
            return 0;
        }

        global $wpdb;
        $count = 0;
        $last = 0;
        $by_key = array();

        foreach ($form['fields'] as $field) {
            $by_key[$field['key']] = $field;
        }

        do {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::entriesTable() . ' WHERE form_id = %d AND entry_id > %d ORDER BY entry_id ASC LIMIT 200', $post['id'], $last), ARRAY_A); // phpcs:ignore

            foreach ((array) $rows as $row) {
                $last = (int) $row['entry_id'];

                if (EntryRepository::legacyImported($form['id'], $last)) {
                    continue;
                }

                $raw = json_decode((string) $row['fields'], true);
                $snapshot = array();

                foreach (is_array($raw) ? $raw : array() as $id => $wf) {
                    if (!is_array($wf)) {
                        continue;
                    }

                    $key = 'w' . (int) ($wf['id'] ?? $id);
                    $field = $by_key[$key] ?? null;
                    $type = $field ? $field['type'] : (self::typeMap()[$wf['type'] ?? ''] ?? 'text');
                    $snapshot[] = array(
                        'key' => $key,
                        'label' => $field ? $field['label'] : (string) ($wf['name'] ?? $key),
                        'type' => $type,
                        'value' => self::entryValue($wf, $type),
                    );
                }

                $status = (string) ($row['status'] ?? '');
                $date = (string) ($row['date'] ?? '');
                $gmt = $date !== '' ? get_gmt_from_date($date) : current_time('mysql', true);

                $ok = EntryRepository::insert($form['id'], $snapshot, array(
                    'status' => $status === 'spam' ? 'spam' : (!empty($row['viewed']) ? 'read' : 'unread'),
                    'starred' => !empty($row['starred']),
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'ip' => (string) ($row['ip_address'] ?? ''),
                    'user_agent' => (string) ($row['user_agent'] ?? ''),
                    'page_url' => '',
                    'legacy_id' => $last,
                    'created_at' => $gmt,
                ));

                if ($ok) {
                    $count++;
                }
            }
        } while ($rows && count($rows) === 200);

        return $count;
    }

    private static function entryValue(array $wf, $type)
    {
        $value = $wf['value'] ?? '';

        if ($type === 'file') {
            $files = array();

            if (!empty($wf['value_raw']) && is_array($wf['value_raw'])) {
                foreach ($wf['value_raw'] as $file) {
                    if (is_array($file) && !empty($file['value'])) {
                        $files[] = array('name' => (string) ($file['file_user_name'] ?? ($file['name'] ?? wp_basename((string) $file['value']))), 'url' => esc_url_raw((string) $file['value']));
                    }
                }
            } elseif (is_string($value) && $value !== '') {
                foreach (preg_split('/\r\n|\r|\n/', $value) as $url) {
                    $url = trim($url);

                    if ($url !== '') {
                        $files[] = array('name' => wp_basename($url), 'url' => esc_url_raw($url));
                    }
                }
            }

            return $files;
        }

        if ($type === 'checkbox') {
            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $value)), 'strlen'));
        }

        if ($type === 'gdpr') {
            return $value !== '' ? '1' : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
