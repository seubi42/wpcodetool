<?php

namespace Smbb\SignConnectProxima\CodeTool;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Input;

defined('ABSPATH') || exit;

final class TeamHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('name', 'code'));
        $data = Input::decimal($data, array('fixed_monthly_fee', 'discount_subscription_pct', 'discount_overage_pct'));
        $data = Input::nullableIds($data, array('stamp_attachment_id'));

        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        // Une équipe ne change pas d'organisation : ses documents et sa consommation y restent attachés.
        if (!empty($current['organization_id'])) {
            $data['organization_id'] = $current['organization_id'];
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();

        if (empty($data['organization_id']) || !array_key_exists((int) $data['organization_id'], Lookup::organizations())) {
            $errors['organization_id'] = __('Choisissez une organisation.', 'smbb-signaconnect-proxima');
        }

        if (empty($data['name'])) {
            $errors['name'] = __("Le nom de l'équipe est obligatoire.", 'smbb-signaconnect-proxima');
        }

        if (isset($data['status']) && !array_key_exists($data['status'], Choices::teamStatuses())) {
            $errors['status'] = __('Statut inconnu.', 'smbb-signaconnect-proxima');
        }

        foreach (array('discount_subscription_pct', 'discount_overage_pct') as $key) {
            if (isset($data[$key]) && !Input::isPercent($data[$key])) {
                $errors[$key] = __('Une remise doit être comprise entre 0 et 100 %.', 'smbb-signaconnect-proxima');
            }
        }

        if (isset($data['fixed_monthly_fee']) && !Input::isAmount($data['fixed_monthly_fee'])) {
            $errors['fixed_monthly_fee'] = __('Montant invalide.', 'smbb-signaconnect-proxima');
        }

        return $errors;
    }

    public function afterSave(array $row, array $context = array())
    {
        Lookup::flush();
        $is_create = isset($context['action']) && $context['action'] === 'create';

        AuditLog::record(
            $is_create ? 'team_created' : 'team_updated',
            sprintf(
                $is_create ? __('Équipe « %s » créée.', 'smbb-signaconnect-proxima') : __('Équipe « %s » modifiée.', 'smbb-signaconnect-proxima'),
                isset($row['name']) ? $row['name'] : ''
            ),
            array('team_id' => isset($row['id']) ? (int) $row['id'] : 0),
            isset($row['organization_id']) ? (int) $row['organization_id'] : null
        );
    }

    public function beforeDelete(array $row, array $context = array())
    {
        global $wpdb;

        $id = isset($row['id']) ? (int) $row['id'] : 0;

        if (Lookup::count('scp_document', 'team_id', $id) > 0) {
            return __('Cette équipe a des documents : archivez-la plutôt que de la supprimer.', 'smbb-signaconnect-proxima');
        }

        $memberships = $wpdb->get_results('SELECT team_ids FROM ' . Lookup::table('scp_membership') . ' WHERE deleted_flag = 0 AND organization_id = ' . (int) $row['organization_id'], ARRAY_A);

        foreach (is_array($memberships) ? $memberships : array() as $membership) {
            $team_ids = json_decode((string) $membership['team_ids'], true);

            if (is_array($team_ids) && in_array((string) $id, array_map('strval', $team_ids), true)) {
                return __('Des utilisateurs sont encore rattachés à cette équipe.', 'smbb-signaconnect-proxima');
            }
        }

        AuditLog::record('team_deleted', sprintf(__('Équipe « %s » supprimée.', 'smbb-signaconnect-proxima'), isset($row['name']) ? $row['name'] : ''), array('team_id' => $id), (int) $row['organization_id']);
        Lookup::flush();

        return true;
    }
}
