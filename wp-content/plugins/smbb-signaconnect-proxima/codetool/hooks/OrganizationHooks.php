<?php

namespace Smbb\SignConnectProxima\CodeTool;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Input;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

final class OrganizationHooks
{
    /** Colonnes "secret" du modèle : chiffrées en base par CodeTool, affichées en clair dans le formulaire. */
    const SECRETS = array('universign_api_key', 'openai_api_key', 'twilio_token', 's3_secret_key');

    public function beforeValidate(array $data, array $context = array())
    {
        $data = Input::stripManaged($data);
        unset($data['webhook_key'], $data['webhook_last_received_at']);
        $data = Input::trim($data, array('name', 'reminder_days', 'universign_workspace_id', 'openai_model', 'twilio_sid', 'twilio_from', 's3_endpoint', 's3_region', 's3_bucket', 's3_access_key', 's3_prefix', 'storage_unit', 'billing_email', 'billing_siret', 'billing_vat_number', 'customer_reference'));
        $data = Input::checkboxList($data, 'allowed_levels', Levels::all());
        $data = Input::checkboxList($data, 'internal_channels', array_keys(Choices::channels()));
        $data = Input::decimal($data, array('fixed_monthly_fee', 'discount_subscription_pct', 'discount_overage_pct'));
        $data = Input::integer($data, array('send_validity_days', 'retention_months'));
        $data = Input::nullableIds($data, array('default_plan_id', 'logo_attachment_id', 'stamp_attachment_id'));

        if (isset($data['reminder_days'])) {
            $days = array_filter(array_map('intval', preg_split('/[^0-9]+/', (string) $data['reminder_days'])));
            sort($days);
            $data['reminder_days'] = implode(',', array_unique($days));
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();

        if (empty($data['name'])) {
            $errors['name'] = __("Le nom de l'organisation est obligatoire.", 'smbb-signaconnect-proxima');
        }

        if (isset($data['status']) && !array_key_exists($data['status'], Choices::organizationStatuses())) {
            $errors['status'] = __('Statut inconnu.', 'smbb-signaconnect-proxima');
        }

        $allowed = isset($data['allowed_levels']) && is_array($data['allowed_levels']) ? $data['allowed_levels'] : array();

        if (!$allowed) {
            $errors['allowed_levels'] = __('Autorisez au moins un niveau de signature.', 'smbb-signaconnect-proxima');
        } elseif (!empty($data['default_level']) && !in_array($data['default_level'], $allowed, true)) {
            $errors['default_level'] = __('Le niveau par défaut doit faire partie des niveaux autorisés.', 'smbb-signaconnect-proxima');
        }

        if (!empty($data['ai_detection_enabled']) && empty($data['openai_api_key'])) {
            $errors['openai_api_key'] = __('La détection IA nécessite une clé OpenAI.', 'smbb-signaconnect-proxima');
        }

        $channels = isset($data['internal_channels']) && is_array($data['internal_channels']) ? $data['internal_channels'] : array();

        if (in_array(Levels::INTERNAL, $allowed, true) && !$channels) {
            $errors['internal_channels'] = __('Choisissez au moins un canal pour le niveau Simple.', 'smbb-signaconnect-proxima');
        }

        if (in_array('sms', $channels, true) && (empty($data['twilio_sid']) || empty($data['twilio_token']) || empty($data['twilio_from']))) {
            $errors['twilio_sid'] = __("L'envoi par SMS nécessite les identifiants Twilio complets.", 'smbb-signaconnect-proxima');
        }

        foreach (array('discount_subscription_pct', 'discount_overage_pct') as $key) {
            if (isset($data[$key]) && !Input::isPercent($data[$key])) {
                $errors[$key] = __('Une remise doit être comprise entre 0 et 100 %.', 'smbb-signaconnect-proxima');
            }
        }

        if (isset($data['fixed_monthly_fee']) && !Input::isAmount($data['fixed_monthly_fee'])) {
            $errors['fixed_monthly_fee'] = __('Montant invalide.', 'smbb-signaconnect-proxima');
        }

        if (isset($data['send_validity_days']) && ((int) $data['send_validity_days'] < 1 || (int) $data['send_validity_days'] > 180)) {
            $errors['send_validity_days'] = __('La validité doit être comprise entre 1 et 180 jours.', 'smbb-signaconnect-proxima');
        }

        if (isset($data['retention_months']) && (int) $data['retention_months'] < 1) {
            $errors['retention_months'] = __('La durée de conservation doit être d\'au moins 1 mois.', 'smbb-signaconnect-proxima');
        }

        if (!empty($data['default_plan_id']) && !array_key_exists((int) $data['default_plan_id'], Lookup::plans())) {
            $errors['default_plan_id'] = __('Forfait introuvable.', 'smbb-signaconnect-proxima');
        }

        if (!empty($data['billing_email']) && !is_email($data['billing_email'])) {
            $errors['billing_email'] = __('Adresse email invalide.', 'smbb-signaconnect-proxima');
        }

        $unit = isset($data['storage_unit']) ? (string) $data['storage_unit'] : '';

        if ($unit !== '' && class_exists('Smbb\\WpCodeTool\\Storage\\Storage') && !\Smbb\WpCodeTool\Storage\Storage::exists($unit)) {
            $errors['storage_unit'] = __('Unité de stockage introuvable dans CodeTool.', 'smbb-signaconnect-proxima');
        }

        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();
        $has_storage = $unit !== '' || (!array_key_exists('storage_unit', $data) && \Smbb\SignConnectProxima\Repository\Organizations::hasStorage(array_merge($current, $data)))
            || (!class_exists('Smbb\\WpCodeTool\\Storage\\Storage') && !empty($data['s3_bucket']));

        if (!empty($data['copy_universign_to_s3']) && !$has_storage) {
            $errors['copy_universign_to_s3'] = __('La copie des PDF Universign nécessite une unité de stockage.', 'smbb-signaconnect-proxima');
        }

        return $errors;
    }

    public function beforeSave(array $data, array $context = array())
    {
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        if (empty($current['webhook_key'])) {
            // Clé non devinable placée dans l'URL du webhook Universign de cette organisation.
            $data['webhook_key'] = wp_generate_password(40, false, false);
        }

        foreach (array('allowed_levels', 'internal_channels') as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = wp_json_encode(array_values($data[$key]));
            }
        }

        return $data;
    }

    public function afterSave(array $row, array $context = array())
    {
        Lookup::flush();
        $is_create = isset($context['action']) && $context['action'] === 'create';
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();
        $changes = array();

        foreach (array('status', 'default_plan_id', 'fixed_monthly_fee', 'discount_subscription_pct', 'discount_overage_pct', 'allowed_levels', 'universign_environment') as $key) {
            $before = isset($current[$key]) ? $current[$key] : null;
            $after = isset($row[$key]) ? $row[$key] : null;

            if (wp_json_encode($before) !== wp_json_encode($after)) {
                $changes[$key] = array('avant' => $before, 'après' => $after);
            }
        }

        AuditLog::record(
            $is_create ? 'organization_created' : 'organization_updated',
            $is_create ? __('Organisation créée.', 'smbb-signaconnect-proxima') : __('Organisation modifiée.', 'smbb-signaconnect-proxima'),
            $changes,
            isset($row['id']) ? (int) $row['id'] : null
        );
    }

    public function beforeDelete(array $row, array $context = array())
    {
        $id = isset($row['id']) ? (int) $row['id'] : 0;

        if (Lookup::count('scp_document', 'organization_id', $id) > 0) {
            return __("Cette organisation a des documents : passez-la en statut Bloquée plutôt que de la supprimer.", 'smbb-signaconnect-proxima');
        }

        if (Lookup::count('scp_team', 'organization_id', $id) > 0 || Lookup::count('scp_membership', 'organization_id', $id) > 0) {
            return __("Supprimez d'abord les équipes et les rattachements de cette organisation.", 'smbb-signaconnect-proxima');
        }

        AuditLog::record('organization_deleted', __('Organisation supprimée.', 'smbb-signaconnect-proxima'), array('name' => isset($row['name']) ? $row['name'] : ''), $id);
        Lookup::flush();

        return true;
    }
}
