<?php

namespace Smbb\WpCodeTool\Forms;

use Smbb\WpCodeTool\Security\Security;
use Smbb\WpCodeTool\Security\Turnstile;

defined('ABSPATH') || exit;

/**
 * Envoi d'un formulaire par un visiteur.
 *
 * Contrôles : champ piège, jeton de temps (un robot envoie en moins de 2 secondes),
 * Turnstile si coché sur le formulaire, puis chaque champ. Envoi refusé : la page se réaffiche
 * avec les valeurs et les erreurs. Envoi accepté : entrée gardée, notifications, puis redirection
 * (message de confirmation, page du site ou adresse).
 *
 * Pas de nonce WordPress : les pages en cache le rendraient périmé pour les visiteurs.
 */
final class Submission
{
    const MIN_SECONDS = 2;

    /** @var array<int,array{values:array,errors:array,error:string}> */
    private static $state = array();

    public static function state($form_id)
    {
        return isset(self::$state[(int) $form_id]) ? self::$state[(int) $form_id] : array();
    }

    /**
     * Le message de confirmation est-il à afficher pour ce formulaire ?
     */
    public static function sentFor($form_id)
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        $sent = isset($_GET['smbb_form_sent']) ? sanitize_text_field(wp_unslash($_GET['smbb_form_sent'])) : '';

        if ($sent === '' || strpos($sent, '.') === false) {
            return false;
        }

        list($id, $sig) = explode('.', $sent, 2);

        return (int) $id === (int) $form_id && hash_equals(substr(wp_hash('smbb_form_sent|' . (int) $id), 0, 10), $sig);
    }

    /**
     * Contexte des balises du message de confirmation (réponses envoyées, si encore gardées).
     */
    public static function sentContext(array $form)
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        $ref = isset($_GET['smbb_form_ref']) ? sanitize_key(wp_unslash($_GET['smbb_form_ref'])) : '';
        $data = $ref !== '' ? get_transient('smbb_form_reply_' . $ref) : false;
        $context = array('form' => $form);

        if (is_array($data) && (int) $data['form_id'] === (int) $form['id']) {
            $context['fields'] = (array) $data['fields'];
            $context['entry_id'] = (int) $data['entry_id'];
        }

        return $context;
    }

    /**
     * Jeton de temps : heure d'affichage signée.
     */
    public static function token($form_id)
    {
        $time = time();

        return $time . '.' . substr(wp_hash('smbb_form|' . (int) $form_id . '|' . $time), 0, 12);
    }

    /**
     * @return string '' si le jeton est bon, sinon la raison.
     */
    private static function checkToken($form_id, $token)
    {
        $parts = explode('.', (string) $token, 2);

        if (count($parts) !== 2 || !hash_equals(substr(wp_hash('smbb_form|' . (int) $form_id . '|' . (int) $parts[0]), 0, 12), $parts[1])) {
            return 'token';
        }

        if (time() - (int) $parts[0] < self::MIN_SECONDS) {
            return 'too-fast';
        }

        return '';
    }

    /**
     * wp_loaded : traite l'envoi si la requête vient d'un formulaire CodeTool.
     */
    public static function maybeHandle()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (is_admin() || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['smbb_form_id'])) {
            return;
        }

        $form = FormRepository::find(absint($_POST['smbb_form_id']));

        if (!$form || $form['status'] === 'trash') {
            return;
        }

        $posted = isset($_POST['smbb_fields']) && is_array($_POST['smbb_fields']) ? wp_unslash($_POST['smbb_fields']) : array(); // phpcs:ignore -- nettoyé champ par champ.
        $page = isset($_POST['smbb_form_page']) ? esc_url_raw(wp_unslash($_POST['smbb_form_page'])) : '';
        $page = wp_validate_redirect($page, home_url('/'));
        $honeypot = isset($_POST['smbb_form_website']) ? trim((string) wp_unslash($_POST['smbb_form_website'])) : ''; // phpcs:ignore
        $token = isset($_POST['smbb_form_t']) ? sanitize_text_field(wp_unslash($_POST['smbb_form_t'])) : '';
        // phpcs:enable

        $spam = $honeypot !== '' ? 'honeypot' : self::checkToken($form['id'], $token);

        // Un robot (champ piège, envoi instantané) : on fait comme si tout allait bien, sans rien envoyer.
        if ($spam === 'honeypot' || $spam === 'too-fast') {
            self::redirectAfter($form, $page);
        }

        if ($form['settings']['turnstile'] === '1' && Turnstile::active()) {
            $check = Turnstile::verify('form');

            if (is_wp_error($check)) {
                self::$state[$form['id']] = array('values' => $posted, 'errors' => array(), 'error' => $check->get_error_message());

                return;
            }
        }

        $checked = self::validate($form, $posted, array('page_url' => $page));

        if ($checked['errors'] || $spam === 'token') {
            self::$state[$form['id']] = array(
                'values' => $checked['values'],
                'errors' => $checked['errors'],
                'error' => $checked['errors']
                    ? __('Quelques réponses sont à revoir : elles sont signalées ci-dessous.', 'smbb-wpcodetool')
                    : __('La page était ouverte depuis trop longtemps. Vérifiez vos réponses et envoyez à nouveau.', 'smbb-wpcodetool'),
            );

            return;
        }

        $stored = self::store($form, $checked, array('page_url' => $page));
        $context = $stored['context'];

        self::redirectAfter($form, $page, $context);
    }

    /**
     * Contrôle des réponses d'un formulaire, sans rien enregistrer. Sert à l'envoi public et aux
     * plugins qui intègrent les champs dans leur propre <form> (Frontend::fieldsHtml).
     *
     * $args : file_prefix (préfixe des champs fichier, 'smbb_file_' par défaut), page_url.
     *
     * @param array<string,mixed> $posted réponses brutes (déjà sans les barres obliques de WordPress)
     * @return array{values:array,errors:array,files:array,snapshot:array}
     */
    public static function validate(array $form, array $posted, array $args = array())
    {
        $prefix = isset($args['file_prefix']) ? (string) $args['file_prefix'] : 'smbb_file_';
        $page = isset($args['page_url']) ? (string) $args['page_url'] : '';
        $values = array();
        $errors = array();
        $files = array();
        $snapshot = array();

        // Logique conditionnelle : un champ masqué pour ces réponses n'est ni contrôlé ni gardé.
        $raw = array();

        foreach ($form['fields'] as $field) {
            $raw[$field['key']] = array_key_exists($field['key'], $posted) ? $posted[$field['key']] : '';
        }

        $visible = Logic::visibility($form['fields'], $raw);

        foreach ($form['fields'] as $field) {
            if (!FieldTypes::isInput($field['type']) || empty($visible[$field['key']])) {
                continue;
            }

            $key = $field['key'];

            if ($field['type'] === 'file') {
                list($accepted, $error) = Uploads::check($field, $prefix . $key);
                $files[$key] = $accepted;
                $value = array();
            } elseif ($field['type'] === 'hidden') {
                $value = SmartTags::replace($field['default'], array('form' => $form, 'page_url' => $page));
                $error = '';
            } else {
                list($value, $error) = FieldTypes::validate($field, array_key_exists($key, $posted) ? $posted[$key] : '');
            }

            $values[$key] = $value;

            if ($error !== '') {
                $errors[$key] = $error;
            }

            $snapshot[] = array('key' => $key, 'label' => $field['label'], 'type' => $field['type'], 'value' => $value);
        }

        $errors = (array) apply_filters('smbb_wpcodetool_form_errors', $errors, $values, $form);

        return array('values' => $values, 'errors' => $errors, 'files' => $files, 'snapshot' => $snapshot);
    }

    /**
     * Enregistre des réponses contrôlées par validate() : entrée, fichiers, notifications, unité de stockage.
     *
     * $args : page_url, source + source_ref (origine de l'entrée, ex. 'passflow' / 'document:12:decharge'),
     * status (unread par défaut), force_store (entrée gardée même si le formulaire ne garde pas ses entrées),
     * notify (true par défaut : notifications du formulaire), context (balises en plus pour les e-mails).
     *
     * @return array{entry_id:int,fields:array,context:array}
     */
    public static function store(array $form, array $checked, array $args = array())
    {
        $page = isset($args['page_url']) ? (string) $args['page_url'] : '';
        $snapshot = $checked['snapshot'];
        $files = $checked['files'];
        $meta = array(
            'ip' => Security::ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'page_url' => $page,
            'source' => isset($args['source']) ? (string) $args['source'] : '',
            'source_ref' => isset($args['source_ref']) ? (string) $args['source_ref'] : '',
        );

        if (isset($args['status'])) {
            $meta['status'] = (string) $args['status'];
        }

        $entry_id = 0;
        $folder = '';

        if ($form['settings']['store_entries'] === '1' || !empty($args['force_store'])) {
            $entry_id = EntryRepository::insert($form['id'], $snapshot, $meta);
            $folder = (string) $entry_id;
        }

        // Fichiers : dans le dossier de l'entrée, ou dans un dossier temporaire le temps de l'envoi des e-mails.
        if (array_filter($files)) {
            if ($folder === '' || $folder === '0') {
                $folder = 'tmp-' . strtolower(wp_generate_password(10, false, false));
            }

            foreach ($snapshot as $i => $item) {
                if ($item['type'] === 'file' && !empty($files[$item['key']])) {
                    $snapshot[$i]['value'] = Uploads::store($files[$item['key']], $folder);
                }
            }

            if ($entry_id) {
                EntryRepository::updateFields($entry_id, $snapshot);
            }
        }

        $context = array_merge(isset($args['context']) && is_array($args['context']) ? $args['context'] : array(), array(
            'form' => $form,
            'fields' => $snapshot,
            'entry_id' => $entry_id,
            'page_url' => $page,
            'ip' => $meta['ip'],
            'folder' => $folder,
        ));

        if (!isset($args['notify']) || $args['notify']) {
            Notifier::send($form, $context);
        }

        if ($folder !== '' && !$entry_id) {
            Uploads::deleteFolder($folder);
        } elseif ($entry_id && array_filter($files)) {
            // Unité de stockage du formulaire (S3…) : les fichiers y partent une fois les e-mails envoyés.
            $pushed = Uploads::pushToUnit($form, $entry_id, $snapshot);

            if ($pushed !== $snapshot) {
                $snapshot = $pushed;
                EntryRepository::updateFields($entry_id, $snapshot);
            }
        }

        $context['fields'] = $snapshot;
        $entry = array('id' => $entry_id, 'form_id' => $form['id'], 'fields' => $snapshot) + $meta;
        do_action('smbb_wpcodetool_form_submitted', $entry, $form);

        return array('entry_id' => $entry_id, 'fields' => $snapshot, 'context' => $context);
    }

    /**
     * Après un envoi accepté : page de confirmation choisie, ou retour sur la page avec le message.
     */
    private static function redirectAfter(array $form, $page, array $context = array())
    {
        $c = $form['settings']['confirmation'];

        if ($c['type'] === 'page' && (int) $c['page'] > 0 && get_permalink((int) $c['page'])) {
            wp_safe_redirect(get_permalink((int) $c['page']));
            exit;
        }

        if ($c['type'] === 'url' && $c['url'] !== '') {
            // Adresse réglée par l'administrateur : elle peut sortir du site.
            wp_redirect(esc_url_raw(SmartTags::replace($c['url'], $context ?: array('form' => $form)))); // phpcs:ignore WordPress.Security.SafeRedirect
            exit;
        }

        $sig = substr(wp_hash('smbb_form_sent|' . (int) $form['id']), 0, 10);
        $args = array('smbb_form_sent' => (int) $form['id'] . '.' . $sig);

        // Réponses gardées 10 minutes pour les balises {field:…} du message de confirmation.
        if (!empty($context['fields']) && strpos((string) $c['message'], '{') !== false) {
            $ref = strtolower(wp_generate_password(16, false, false));
            set_transient('smbb_form_reply_' . $ref, array('form_id' => (int) $form['id'], 'fields' => $context['fields'], 'entry_id' => $context['entry_id'] ?? 0), 10 * MINUTE_IN_SECONDS);
            $args['smbb_form_ref'] = $ref;
        }

        wp_safe_redirect(add_query_arg($args, $page) . '#smbb-form-' . (int) $form['id']);
        exit;
    }
}
