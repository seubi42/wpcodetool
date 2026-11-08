<?php

namespace Smbb\Passflow\CodeTool;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Input;

defined('ABSPATH') || exit;

final class WaitlistHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('first_name', 'last_name', 'email', 'notes'));
        $data = Input::integer($data, array('slot_id', 'places'));
        $data = Input::nullableIds($data, array('customer_id', 'booking_id'));

        if (isset($data['email'])) {
            $data['email'] = strtolower((string) $data['email']);
        }

        if (isset($data['phone'])) {
            $data['phone'] = Input::phone($data['phone']);
        }

        if (empty($context['current']) && empty($data['status'])) {
            $data['status'] = 'waiting';
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();
        $merged = array_merge($current, $data);

        if (empty($merged['slot_id']) || !Slots::find($merged['slot_id'])) {
            $errors['slot_id'] = __('Choisissez un créneau.', 'smbb-passflow');
        }

        if (empty($merged['last_name'])) {
            $errors['last_name'] = __('Le nom est obligatoire.', 'smbb-passflow');
        }

        if (isset($data['email']) && !Input::isEmail($data['email'])) {
            $errors['email'] = __('Adresse e-mail invalide.', 'smbb-passflow');
        }

        if (empty($merged['email']) && empty($merged['phone'])) {
            $errors['email'] = __('Indiquez au moins un e-mail ou un téléphone pour pouvoir prévenir la personne.', 'smbb-passflow');
        }

        if (isset($data['places']) && ((int) $data['places'] < 1 || (int) $data['places'] > 20)) {
            $errors['places'] = __('Entre 1 et 20 places.', 'smbb-passflow');
        }

        if (isset($data['status']) && !array_key_exists($data['status'], Choices::waitlistStatuses())) {
            $errors['status'] = __('Statut inconnu.', 'smbb-passflow');
        }

        return $errors;
    }
}
