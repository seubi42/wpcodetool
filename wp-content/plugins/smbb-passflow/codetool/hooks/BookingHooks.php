<?php

namespace Smbb\Passflow\CodeTool;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Capacity;
use Smbb\Passflow\Service\History;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Input;

defined('ABSPATH') || exit;

/**
 * Inscriptions saisies ou modifiées depuis le formulaire CodeTool.
 * Les annulations, déplacements et pointages passent par Service\Booking (boutons des fiches).
 */
final class BookingHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('first_name', 'last_name', 'email', 'allergies', 'notes'));
        $data = Input::nullableIds($data, array('slot_id', 'customer_id', 'order_id', 'order_item_id'));
        $data = Input::integer($data, array('age'));
        $data = Input::decimal($data, array('price_paid'));

        if (isset($data['email'])) {
            $data['email'] = strtolower((string) $data['email']);
        }

        if (isset($data['phone'])) {
            $data['phone'] = Input::phone($data['phone']);
        }

        if (empty($current) && empty($data['source'])) {
            $data['source'] = 'manual';
        }

        if (empty($current) && empty($data['status'])) {
            $data['status'] = 'confirmed';
        }

        // L'atelier suit toujours le créneau choisi.
        $slot_id = array_key_exists('slot_id', $data) ? $data['slot_id'] : (isset($current['slot_id']) ? $current['slot_id'] : null);

        if ($slot_id) {
            $slot = Slots::find($slot_id);

            if ($slot) {
                $data['activity_id'] = (string) (int) $slot['activity_id'];
            }
        }


        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();
        $merged = array_merge($current, $data);
        $status = isset($merged['status']) ? $merged['status'] : 'confirmed';

        if (empty($merged['first_name'])) {
            $errors['first_name'] = __('Le prénom est obligatoire.', 'smbb-passflow');
        }

        if (empty($merged['last_name'])) {
            $errors['last_name'] = __('Le nom est obligatoire.', 'smbb-passflow');
        }

        if (isset($data['email']) && !Input::isEmail($data['email'])) {
            $errors['email'] = __('Adresse e-mail invalide.', 'smbb-passflow');
        }

        if (empty($merged['email']) && empty($merged['phone'])) {
            $errors['email'] = __('Indiquez au moins un e-mail ou un téléphone pour pouvoir prévenir le participant.', 'smbb-passflow');
        }

        if (!array_key_exists($status, Choices::bookingStatuses())) {
            $errors['status'] = __('Statut inconnu.', 'smbb-passflow');
        }

        if (isset($data['level']) && !array_key_exists((string) $data['level'], Choices::levels())) {
            $errors['level'] = __('Niveau inconnu.', 'smbb-passflow');
        }

        if (isset($data['age']) && $data['age'] !== null && ((int) $data['age'] < 1 || (int) $data['age'] > 120)) {
            $errors['age'] = __('Âge invalide.', 'smbb-passflow');
        }

        if (isset($data['source']) && !array_key_exists($data['source'], Choices::sources())) {
            $errors['source'] = __('Source inconnue.', 'smbb-passflow');
        }

        $takes_seat = in_array($status, Choices::seatStatuses(), true) && !empty($merged['slot_id']);

        // Seule une réservation « à confirmer » (ou annulée) peut rester sans date.
        if (empty($merged['slot_id']) && !in_array($status, array('pending', 'cancelled'), true)) {
            $errors['slot_id'] = __('Choisissez un créneau, ou laissez la réservation « à confirmer » en attendant une date.', 'smbb-passflow');
        } elseif (empty($merged['slot_id']) && empty($merged['activity_id'])) {
            $errors['activity_id'] = __("Sans date, indiquez au moins l'atelier.", 'smbb-passflow');
        }

        // Contrôle de jauge si l'inscription prend une nouvelle place.
        $was_seat = !empty($current) && !empty($current['slot_id']) && in_array($current['status'], Choices::seatStatuses(), true);
        $same_slot = !empty($current) && (int) $current['slot_id'] === (int) $merged['slot_id'];

        if ($takes_seat && !empty($merged['slot_id']) && !($was_seat && $same_slot)) {
            $error = Capacity::check($merged['slot_id'], 1, isset($context['id']) ? (int) $context['id'] : 0);

            if ($error !== '') {
                $errors['slot_id'] = $error . ' ' . __("Proposez la liste d'attente, ou augmentez les places du créneau.", 'smbb-passflow');
            }
        }

        return $errors;
    }

    public function afterSave(array $row, array $context = array())
    {
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();
        $slot = !empty($row['slot_id']) ? Slots::find($row['slot_id']) : null;
        $label = $slot ? Lookup::slotLabel($slot) : Lookup::activityName($row['activity_id']);

        if (empty($current)) {
            History::record($row['id'], 'created', sprintf(__('Inscription (%1$s) sur %2$s.', 'smbb-passflow'), Choices::label('sources', $row['source']), $label), $row['slot_id'] ?: null);
        } elseif ((int) $current['slot_id'] !== (int) $row['slot_id'] && !empty($row['slot_id'])) {
            $from = !empty($current['slot_id']) ? Slots::find($current['slot_id']) : null;
            History::record($row['id'], 'moved', $from ? sprintf(__('Déplacée de %1$s vers %2$s.', 'smbb-passflow'), Lookup::slotLabel($from), $label) : sprintf(__('Placée sur %s.', 'smbb-passflow'), $label), $row['slot_id'], $current['slot_id'] ?: null);
        } elseif ($current['status'] !== $row['status']) {
            History::record($row['id'], $row['status'] === 'cancelled' ? 'cancelled' : (empty($row['slot_id']) && !empty($current['slot_id']) ? 'postponed' : 'updated'), sprintf(__('Statut : %1$s → %2$s.', 'smbb-passflow'), Choices::label('bookingStatuses', $current['status']), Choices::label('bookingStatuses', $row['status'])), $row['slot_id'] ?: null, $current['slot_id'] ?: null);
        } else {
            History::record($row['id'], 'updated', __('Fiche modifiée.', 'smbb-passflow'), $row['slot_id'] ?: null);
        }

        foreach (array_unique(array_filter(array((int) $row['slot_id'], !empty($current['slot_id']) ? (int) $current['slot_id'] : 0))) as $slot_id) {
            Capacity::refresh($slot_id);
        }

        // Pass plusieurs jours : une place par jour, qui suit la réservation principale.
        if (empty($row['pass_id'])) {
            if (empty($current)) {
                \Smbb\Passflow\Service\Passes::createChildren($row['id']);
            } else {
                $changed = array('status' => $row['status']);

                if ((int) $current['slot_id'] !== (int) $row['slot_id']) {
                    $changed['slot_id'] = $row['slot_id'];
                }

                \Smbb\Passflow\Service\Passes::cascade($row['id'], $changed);
            }
        }

        self::announce($row, $current);
    }

    /**
     * Déclenche les mêmes événements que les actions de fiche, pour que les notifications partent
     * aussi quand on passe par le formulaire.
     */
    private static function announce(array $row, array $current)
    {
        $id = (int) $row['id'];
        $old_slot = !empty($current['slot_id']) ? (int) $current['slot_id'] : 0;
        $new_slot = !empty($row['slot_id']) ? (int) $row['slot_id'] : 0;

        if (empty($current)) {
            if ($row['status'] === 'confirmed' && $new_slot) {
                do_action('smbb_passflow_booking_confirmed', $id);
            }

            return;
        }

        if ($row['status'] === 'cancelled' && $current['status'] !== 'cancelled') {
            do_action('smbb_passflow_booking_cancelled', $id, 'cancelled', $old_slot, '');
        } elseif ($new_slot && $new_slot !== $old_slot && $row['status'] !== 'cancelled') {
            do_action('smbb_passflow_booking_moved', $id, $old_slot, $new_slot, $row['status'] === 'confirmed');
        } elseif (!$new_slot && $old_slot && $row['status'] === 'pending') {
            do_action('smbb_passflow_booking_cancelled', $id, 'pending', $old_slot, '');
        } elseif ($row['status'] === 'confirmed' && $current['status'] === 'pending' && $new_slot) {
            do_action('smbb_passflow_booking_confirmed', $id);
        }
    }

    public function beforeDelete(array $row, array $context = array())
    {
        History::record($row['id'], 'deleted', sprintf(__('Inscription de %s supprimée.', 'smbb-passflow'), Lookup::personName($row)), $row['slot_id'] ?: null);

        // La place est libérée une fois la suppression faite.
        if (!empty($row['slot_id'])) {
            $slot_id = (int) $row['slot_id'];
            add_action('shutdown', function () use ($slot_id) {
                Capacity::refresh($slot_id);
            });
        }

        return true;
    }
}
