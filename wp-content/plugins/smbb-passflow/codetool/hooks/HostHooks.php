<?php

namespace Smbb\Passflow\CodeTool;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Accounts;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Input;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

final class HostHooks
{
    /**
     * Nom, e-mail et téléphone viennent du compte WordPress rattaché (le téléphone est billing_phone,
     * celui de WooCommerce). Les colonnes n'en sont qu'une copie, tenue à jour par Accounts::sync.
     */
    public function beforeValidate(array $data, array $context = array())
    {
        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('first_name', 'last_name', 'email'));
        $data = Input::nullableIds($data, array('user_id', 'photo_id'));

        // Ces champs ne sont plus saisis ici : seul le compte fait foi.
        unset($data['first_name'], $data['last_name'], $data['email'], $data['phone']);

        if (!empty($data['user_id'])) {
            $identity = Accounts::identity($data['user_id']);

            if ($identity) {
                $data = array_merge($data, $identity);
            }
        }

        if (isset($data['email'])) {
            $data['email'] = strtolower((string) $data['email']);
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();

        if (array_key_exists('user_id', $data) && empty($data['user_id'])) {
            $errors['user_id'] = __('Choisissez le compte WordPress de l\'intervenant, ou créez-le.', 'smbb-passflow');
        }

        if (isset($data['email']) && !Input::isEmail($data['email'])) {
            $errors['email'] = __('Adresse e-mail invalide.', 'smbb-passflow');
        }

        if (isset($data['status']) && !array_key_exists($data['status'], Choices::hostStatuses())) {
            $errors['status'] = __('Statut inconnu.', 'smbb-passflow');
        }

        if (!empty($data['user_id'])) {
            if (!get_userdata((int) $data['user_id'])) {
                $errors['user_id'] = __('Compte WordPress introuvable.', 'smbb-passflow');
            }

            foreach (Lookup::intervenants() as $id => $row) {
                if ((int) $row['user_id'] === (int) $data['user_id'] && (string) $id !== (string) (isset($context['id']) ? $context['id'] : '')) {
                    $errors['user_id'] = sprintf(__('Ce compte est déjà rattaché à %s.', 'smbb-passflow'), Lookup::personName($row));
                }
            }
        }

        return $errors;
    }

    /**
     * Le compte WordPress rattaché reçoit le rôle « Intervenant Passflow » (sans perdre ses autres rôles).
     */
    public function afterSave(array $row, array $context = array())
    {
        Lookup::flush();

        if (!empty($row['user_id'])) {
            $user = get_userdata((int) $row['user_id']);

            if ($user && !in_array(Roles::ROLE_HOST, (array) $user->roles, true)) {
                $user->add_role(Roles::ROLE_HOST);
            }
        }
    }

    public function beforeDelete(array $row, array $context = array())
    {
        foreach (Slots::upcoming() as $slot) {
            if (in_array((int) $row['id'], Lookup::ids($slot['host_ids']), true)) {
                return __('Cet intervenant anime des créneaux à venir : retirez-le de ces créneaux ou archivez-le.', 'smbb-passflow');
            }
        }

        Lookup::flush();

        return true;
    }
}
