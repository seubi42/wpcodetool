<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Memberships;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Repository\Templates;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\Schema;

defined('ABSPATH') || exit;

/**
 * Modèles de détection gérés depuis l'app : par les admins d'organisation
 * et par les utilisateurs qui ont le droit « Modèles ».
 */
final class TemplateService
{
    /** Méta posée sur un PDF d'exemple importé depuis l'app : organisation autorisée à le lire. */
    const SAMPLE_META = '_scp_template_sample_org';

    /**
     * Organisations dont l'utilisateur gère les modèles.
     *
     * @return array<int,string> id => nom
     */
    public static function organizations($user_id)
    {
        $organizations = array();

        foreach (Memberships::forUser($user_id) as $membership) {
            if ($membership['organization_status'] === 'blocked') {
                continue;
            }

            if ($membership['role'] === 'org_admin' || (int) $membership['can_manage_templates'] === 1) {
                $organizations[(int) $membership['organization_id']] = (string) $membership['organization_name'];
            }
        }

        return $organizations;
    }

    public static function canManage($user_id, $organization_id)
    {
        return isset(self::organizations($user_id)[(int) $organization_id]);
    }

    /**
     * Modèles d'une organisation, actifs ou non.
     */
    public static function listFor($organization_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('scp_template') . ' WHERE organization_id = %d AND deleted_flag = 0 ORDER BY is_active DESC, name ASC',
            (int) $organization_id
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Modèle modifiable par l'utilisateur, sinon exception.
     */
    public static function find($user_id, $template_id)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_template') . ' WHERE id = %d AND deleted_flag = 0', (int) $template_id), ARRAY_A);

        if (!$row || !self::canManage($user_id, (int) $row['organization_id'])) {
            throw new \RuntimeException(__('Modèle introuvable.', 'smbb-signaconnect-proxima'));
        }

        return $row;
    }

    /**
     * Création ou mise à jour depuis le formulaire de l'app.
     *
     * @return int id du modèle
     */
    public static function save($user_id, array $input)
    {
        global $wpdb;

        $template_id = isset($input['template_id']) ? absint($input['template_id']) : 0;
        $existing = $template_id ? self::find($user_id, $template_id) : null;
        $organization_id = $existing ? (int) $existing['organization_id'] : (isset($input['organization_id']) ? absint($input['organization_id']) : 0);

        if (!self::canManage($user_id, $organization_id)) {
            throw new \RuntimeException(__("Vous ne gérez pas les modèles de cette organisation.", 'smbb-signaconnect-proxima'));
        }

        $organization = Organizations::find($organization_id);
        $available = $organization ? Organizations::availableLevels($organization) : array();
        $name = mb_substr(trim(sanitize_text_field(isset($input['name']) ? $input['name'] : '')), 0, 190);
        $level = Levels::normalize(isset($input['suggested_level']) ? $input['suggested_level'] : '', '');
        $sample = isset($input['sample_pdf_attachment_id']) ? absint($input['sample_pdf_attachment_id']) : 0;
        $errors = array();

        if ($name === '') {
            $errors[] = __('Donnez un nom au modèle.', 'smbb-signaconnect-proxima');
        }

        if ($level === '' || ($available && !in_array($level, $available, true))) {
            $errors[] = __("Ce niveau de signature n'est pas disponible pour votre organisation.", 'smbb-signaconnect-proxima');
        }

        if ($sample && $sample !== (int) ($existing ? $existing['sample_pdf_attachment_id'] : 0) && !self::sampleAllowed($sample, $organization_id)) {
            $errors[] = __("Ce PDF d'exemple n'est pas utilisable.", 'smbb-signaconnect-proxima');
        }

        $zones = array();

        foreach (array('field_zones', 'detection_zones') as $key) {
            $raw = isset($input[$key]) ? trim((string) $input[$key]) : '';
            $decoded = $raw === '' ? array() : json_decode($raw, true);

            if (!is_array($decoded)) {
                $errors[] = __('Les zones du modèle sont illisibles : rechargez la page.', 'smbb-signaconnect-proxima');
                $decoded = array();
            }

            $zones[$key] = wp_json_encode(array_values($decoded));
        }

        $cc_raw = isset($input['signed_mail_cc']) ? trim((string) $input['signed_mail_cc']) : '';

        if ($cc_raw !== '' && array_filter(preg_split('/[\s,;]+/', $cc_raw), function ($email) {
            return $email !== '' && !is_email($email);
        })) {
            $errors[] = __("Une adresse en copie de l'email du document signé n'est pas valide.", 'smbb-signaconnect-proxima');
        }

        if ($errors) {
            throw new \InvalidArgumentException(implode("\n", $errors));
        }

        Schema::ensure();
        $now = current_time('mysql');
        $data = array(
            'name' => $name,
            'description' => sanitize_textarea_field(isset($input['description']) ? $input['description'] : ''),
            'suggested_level' => $level,
            'level_enforced' => empty($input['level_enforced']) ? 0 : 1,
            'is_active' => empty($input['is_active']) ? 0 : 1,
            'sample_pdf_attachment_id' => $sample ?: null,
            'field_zones' => $zones['field_zones'],
            'detection_zones' => $zones['detection_zones'],
            'default_message' => sanitize_textarea_field(isset($input['default_message']) ? $input['default_message'] : ''),
            'skip_preview' => empty($input['skip_preview']) ? 0 : 1,
            'signed_mail_enabled' => empty($input['signed_mail_enabled']) ? 0 : 1,
            'signed_mail_subject' => mb_substr(sanitize_text_field(isset($input['signed_mail_subject']) ? $input['signed_mail_subject'] : ''), 0, 255),
            'signed_mail_body' => sanitize_textarea_field(isset($input['signed_mail_body']) ? $input['signed_mail_body'] : ''),
            'signed_mail_cc' => implode(', ', Templates::emails(isset($input['signed_mail_cc']) ? $input['signed_mail_cc'] : '')),
            'custom_fields' => wp_json_encode(Templates::customFields(isset($input['custom_fields']) ? (string) $input['custom_fields'] : '')),
            'naming_pattern' => mb_substr(sanitize_text_field(isset($input['naming_pattern']) ? $input['naming_pattern'] : ''), 0, 255),
            'lastupdate_date' => $now,
            'lastupdate_by' => (int) $user_id,
        );

        if ($existing) {
            $ok = $wpdb->update(Lookup::table('scp_template'), $data, array('id' => (int) $existing['id']));
        } else {
            $ok = $wpdb->insert(Lookup::table('scp_template'), array_merge($data, array(
                'organization_id' => $organization_id,
                'match_count' => 0,
                'creation_date' => $now,
                'creation_by' => (int) $user_id,
            )));
            $template_id = (int) $wpdb->insert_id;
        }

        if ($ok === false) {
            throw new \RuntimeException(sprintf(__('Erreur base de données : %s', 'smbb-signaconnect-proxima'), $wpdb->last_error ?: '?'));
        }

        AuditLog::record(
            $existing ? 'template_updated' : 'template_created',
            sprintf($existing ? __('Modèle « %s » modifié depuis l\'app.', 'smbb-signaconnect-proxima') : __('Modèle « %s » créé depuis l\'app.', 'smbb-signaconnect-proxima'), $name),
            array('template_id' => $template_id),
            $organization_id,
            null,
            'user',
            $user_id
        );

        return $template_id;
    }

    public static function delete($user_id, $template_id)
    {
        global $wpdb;

        $template = self::find($user_id, $template_id);
        $wpdb->update(Lookup::table('scp_template'), array(
            'deleted_flag' => 1,
            'deleted_date' => current_time('mysql'),
            'is_active' => 0,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => (int) $user_id,
        ), array('id' => (int) $template['id']));
        AuditLog::record('template_deleted', sprintf(__('Modèle « %s » supprimé depuis l\'app.', 'smbb-signaconnect-proxima'), $template['name']), array('template_id' => (int) $template['id']), (int) $template['organization_id'], null, 'user', $user_id);

        return $template;
    }

    /**
     * PDF d'exemple importé depuis l'app : rangé dans la médiathèque, marqué pour l'organisation.
     *
     * @return array{id:int,url:string,name:string}
     */
    public static function uploadSample($user_id, $organization_id, array $file)
    {
        if (!self::canManage($user_id, $organization_id)) {
            throw new \RuntimeException(__("Vous ne gérez pas les modèles de cette organisation.", 'smbb-signaconnect-proxima'));
        }

        SendService::assertUpload($file);

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $moved = wp_handle_upload($file, array('test_form' => false, 'mimes' => array('pdf' => 'application/pdf')));

        if (!empty($moved['error']) || empty($moved['file'])) {
            throw new \RuntimeException(!empty($moved['error']) ? $moved['error'] : __("Le fichier n'a pas été reçu. Réessayez.", 'smbb-signaconnect-proxima'));
        }

        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'application/pdf',
            'post_title' => sanitize_text_field(preg_replace('/\.pdf$/i', '', (string) $file['name'])),
            'post_status' => 'inherit',
            'post_author' => (int) $user_id,
        ), $moved['file']);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            throw new \RuntimeException(__("Le PDF d'exemple n'a pas pu être enregistré.", 'smbb-signaconnect-proxima'));
        }

        update_post_meta($attachment_id, self::SAMPLE_META, (int) $organization_id);

        return array('id' => (int) $attachment_id, 'url' => (string) wp_get_attachment_url($attachment_id), 'name' => (string) $file['name']);
    }

    /**
     * URL d'un PDF d'exemple, si l'utilisateur gère l'organisation à laquelle il appartient.
     */
    public static function sampleUrl($user_id, $attachment_id)
    {
        global $wpdb;

        $attachment_id = (int) $attachment_id;

        if ($attachment_id <= 0 || get_post_mime_type($attachment_id) !== 'application/pdf') {
            return '';
        }

        $org_ids = array_keys(self::organizations($user_id));
        $allowed = in_array((int) get_post_meta($attachment_id, self::SAMPLE_META, true), $org_ids, true);

        if (!$allowed && $org_ids) {
            $allowed = (bool) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Lookup::table('scp_template') . ' WHERE deleted_flag = 0 AND sample_pdf_attachment_id = %d AND organization_id IN (' . implode(',', array_map('intval', $org_ids)) . ')',
                $attachment_id
            ));
        }

        return $allowed ? (string) wp_get_attachment_url($attachment_id) : '';
    }

    private static function sampleAllowed($attachment_id, $organization_id)
    {
        return get_post_mime_type($attachment_id) === 'application/pdf'
            && (int) get_post_meta($attachment_id, self::SAMPLE_META, true) === (int) $organization_id;
    }
}
