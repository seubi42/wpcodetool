<?php

namespace Smbb\SignConnectProxima\CodeTool;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Input;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

final class PlanHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('name'));
        $money = array('monthly_fee');
        $ints = array();

        foreach (Levels::all() as $level) {
            $money[] = 'overage_price_' . $level;
            $ints[] = 'included_' . $level;
            $ints[] = 'recharge_size_' . $level;
        }

        $data = Input::decimal($data, $money);

        return Input::integer($data, $ints);
    }

    public function validate(array $data, array $context = array())
    {
        global $wpdb;

        $errors = array();

        if (empty($data['name'])) {
            $errors['name'] = __('Le nom du forfait est obligatoire.', 'smbb-signaconnect-proxima');
        } else {
            $id = isset($context['id']) ? (int) $context['id'] : 0;
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Lookup::table('scp_plan') . ' WHERE deleted_flag = 0 AND name = %s AND id <> %d',
                $data['name'],
                $id
            ));

            if ($exists > 0) {
                $errors['name'] = __('Un forfait porte déjà ce nom.', 'smbb-signaconnect-proxima');
            }
        }

        if (isset($data['status']) && !array_key_exists($data['status'], Choices::planStatuses())) {
            $errors['status'] = __('Statut inconnu.', 'smbb-signaconnect-proxima');
        }

        if (isset($data['monthly_fee']) && !Input::isAmount($data['monthly_fee'])) {
            $errors['monthly_fee'] = __('Montant invalide.', 'smbb-signaconnect-proxima');
        }

        foreach (Levels::all() as $level) {
            if (isset($data['included_' . $level]) && (int) $data['included_' . $level] < 0) {
                $errors['included_' . $level] = __('Valeur invalide.', 'smbb-signaconnect-proxima');
            }

            if (isset($data['overage_price_' . $level]) && !Input::isAmount($data['overage_price_' . $level])) {
                $errors['overage_price_' . $level] = __('Montant invalide.', 'smbb-signaconnect-proxima');
            }

            if (isset($data['recharge_size_' . $level]) && (int) $data['recharge_size_' . $level] < 1) {
                $errors['recharge_size_' . $level] = __('Une recharge contient au moins 1 signature.', 'smbb-signaconnect-proxima');
            }
        }

        return $errors;
    }

    public function afterSave(array $row, array $context = array())
    {
        Lookup::flush();
        $is_create = isset($context['action']) && $context['action'] === 'create';

        AuditLog::record(
            $is_create ? 'plan_created' : 'plan_updated',
            sprintf(
                $is_create ? __('Forfait « %s » créé.', 'smbb-signaconnect-proxima') : __('Forfait « %s » modifié (appliqué au mois en cours).', 'smbb-signaconnect-proxima'),
                isset($row['name']) ? $row['name'] : ''
            ),
            array('plan' => $row)
        );
    }

    public function beforeDelete(array $row, array $context = array())
    {
        $id = isset($row['id']) ? (int) $row['id'] : 0;

        if (Lookup::count('scp_membership', 'plan_id', $id) > 0 || Lookup::count('scp_organization', 'default_plan_id', $id) > 0) {
            return __('Ce forfait est utilisé : archivez-le plutôt que de le supprimer.', 'smbb-signaconnect-proxima');
        }

        AuditLog::record('plan_deleted', sprintf(__('Forfait « %s » supprimé.', 'smbb-signaconnect-proxima'), isset($row['name']) ? $row['name'] : ''));
        Lookup::flush();

        return true;
    }
}
