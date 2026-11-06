<?php

namespace Smbb\Tomo\CodeTool;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Accounts;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Input;
use Smbb\Tomo\Support\Roles;

defined('ABSPATH') || exit;

final class IntervenantHooks
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
            $errors['user_id'] = __('Choisissez le compte WordPress de l\'intervenant, ou créez-le.', 'smbb-tomo');
        }

        if (isset($data['email']) && !Input::isEmail($data['email'])) {
            $errors['email'] = __('Adresse e-mail invalide.', 'smbb-tomo');
        }

        if (isset($data['status']) && !array_key_exists($data['status'], Choices::intervenantStatuses())) {
            $errors['status'] = __('Statut inconnu.', 'smbb-tomo');
        }

        if (!empty($data['user_id'])) {
            if (!get_userdata((int) $data['user_id'])) {
                $errors['user_id'] = __('Compte WordPress introuvable.', 'smbb-tomo');
            }

            foreach (Lookup::intervenants() as $id => $row) {
                if ((int) $row['user_id'] === (int) $data['user_id'] && (string) $id !== (string) (isset($context['id']) ? $context['id'] : '')) {
                    $errors['user_id'] = sprintf(__('Ce compte est déjà rattaché à %s.', 'smbb-tomo'), Lookup::personName($row));
                }
            }
        }

        return $errors;
    }

    /**
     * Le compte WordPress rattaché reçoit le rôle « Intervenant Tomo » (sans perdre ses autres rôles).
     */
    public function afterSave(array $row, array $context = array())
    {
        Lookup::flush();

        if (!empty($row['user_id'])) {
            $user = get_userdata((int) $row['user_id']);

            if ($user && !in_array(Roles::ROLE_INTERVENANT, (array) $user->roles, true)) {
                $user->add_role(Roles::ROLE_INTERVENANT);
            }
        }
    }

    public function beforeDelete(array $row, array $context = array())
    {
        foreach (Creneaux::upcoming() as $creneau) {
            if (in_array((int) $row['id'], Lookup::ids($creneau['intervenant_ids']), true)) {
                return __('Cet intervenant anime des créneaux à venir : retirez-le de ces créneaux ou archivez-le.', 'smbb-tomo');
            }
        }

        Lookup::flush();

        return true;
    }
}
