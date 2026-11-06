<?php

namespace Smbb\Tomo\CodeTool;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Capacity;
use Smbb\Tomo\Service\History;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Input;

defined('ABSPATH') || exit;

/**
 * Inscriptions saisies ou modifiées depuis le formulaire CodeTool.
 * Les annulations, déplacements et pointages passent par Service\Booking (boutons des fiches).
 */
final class InscriptionHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('first_name', 'last_name', 'email', 'allergies', 'notes'));
        $data = Input::nullableIds($data, array('creneau_id', 'customer_id', 'order_id', 'order_item_id'));
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
        $creneau_id = array_key_exists('creneau_id', $data) ? $data['creneau_id'] : (isset($current['creneau_id']) ? $current['creneau_id'] : null);

        if ($creneau_id) {
            $creneau = Creneaux::find($creneau_id);

            if ($creneau) {
                $data['atelier_id'] = (string) (int) $creneau['atelier_id'];
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
            $errors['first_name'] = __('Le prénom est obligatoire.', 'smbb-tomo');
        }

        if (empty($merged['last_name'])) {
            $errors['last_name'] = __('Le nom est obligatoire.', 'smbb-tomo');
        }

        if (isset($data['email']) && !Input::isEmail($data['email'])) {
            $errors['email'] = __('Adresse e-mail invalide.', 'smbb-tomo');
        }

        if (empty($merged['email']) && empty($merged['phone'])) {
            $errors['email'] = __('Indiquez au moins un e-mail ou un téléphone pour pouvoir prévenir le participant.', 'smbb-tomo');
        }

        if (!array_key_exists($status, Choices::inscriptionStatuses())) {
            $errors['status'] = __('Statut inconnu.', 'smbb-tomo');
        }

        if (isset($data['level']) && !array_key_exists((string) $data['level'], Choices::levels())) {
            $errors['level'] = __('Niveau inconnu.', 'smbb-tomo');
        }

        if (isset($data['age']) && $data['age'] !== null && ((int) $data['age'] < 1 || (int) $data['age'] > 120)) {
            $errors['age'] = __('Âge invalide.', 'smbb-tomo');
        }

        if (isset($data['source']) && !array_key_exists($data['source'], Choices::sources())) {
            $errors['source'] = __('Source inconnue.', 'smbb-tomo');
        }

        $takes_seat = in_array($status, Choices::seatStatuses(), true) && !empty($merged['creneau_id']);

        // Seule une réservation « à confirmer » (ou annulée) peut rester sans date.
        if (empty($merged['creneau_id']) && !in_array($status, array('pending', 'cancelled'), true)) {
            $errors['creneau_id'] = __('Choisissez un créneau, ou laissez la réservation « à confirmer » en attendant une date.', 'smbb-tomo');
        } elseif (empty($merged['creneau_id']) && empty($merged['atelier_id'])) {
            $errors['atelier_id'] = __("Sans date, indiquez au moins l'atelier.", 'smbb-tomo');
        }

        // Contrôle de jauge si l'inscription prend une nouvelle place.
        $was_seat = !empty($current) && !empty($current['creneau_id']) && in_array($current['status'], Choices::seatStatuses(), true);
        $same_creneau = !empty($current) && (int) $current['creneau_id'] === (int) $merged['creneau_id'];

        if ($takes_seat && !empty($merged['creneau_id']) && !($was_seat && $same_creneau)) {
            $error = Capacity::check($merged['creneau_id'], 1, isset($context['id']) ? (int) $context['id'] : 0);

            if ($error !== '') {
                $errors['creneau_id'] = $error . ' ' . __("Proposez la liste d'attente, ou augmentez les places du créneau.", 'smbb-tomo');
            }
        }

        return $errors;
    }

    public function afterSave(array $row, array $context = array())
    {
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();
        $creneau = !empty($row['creneau_id']) ? Creneaux::find($row['creneau_id']) : null;
        $label = $creneau ? Lookup::creneauLabel($creneau) : Lookup::atelierName($row['atelier_id']);

        if (empty($current)) {
            History::record($row['id'], 'created', sprintf(__('Inscription (%1$s) sur %2$s.', 'smbb-tomo'), Choices::label('sources', $row['source']), $label), $row['creneau_id'] ?: null);
        } elseif ((int) $current['creneau_id'] !== (int) $row['creneau_id'] && !empty($row['creneau_id'])) {
            $from = !empty($current['creneau_id']) ? Creneaux::find($current['creneau_id']) : null;
            History::record($row['id'], 'moved', $from ? sprintf(__('Déplacée de %1$s vers %2$s.', 'smbb-tomo'), Lookup::creneauLabel($from), $label) : sprintf(__('Placée sur %s.', 'smbb-tomo'), $label), $row['creneau_id'], $current['creneau_id'] ?: null);
        } elseif ($current['status'] !== $row['status']) {
            History::record($row['id'], $row['status'] === 'cancelled' ? 'cancelled' : (empty($row['creneau_id']) && !empty($current['creneau_id']) ? 'postponed' : 'updated'), sprintf(__('Statut : %1$s → %2$s.', 'smbb-tomo'), Choices::label('inscriptionStatuses', $current['status']), Choices::label('inscriptionStatuses', $row['status'])), $row['creneau_id'] ?: null, $current['creneau_id'] ?: null);
        } else {
            History::record($row['id'], 'updated', __('Fiche modifiée.', 'smbb-tomo'), $row['creneau_id'] ?: null);
        }

        foreach (array_unique(array_filter(array((int) $row['creneau_id'], !empty($current['creneau_id']) ? (int) $current['creneau_id'] : 0))) as $creneau_id) {
            Capacity::refresh($creneau_id);
        }

        // Pass plusieurs jours : une place par jour, qui suit la réservation principale.
        if (empty($row['pass_id'])) {
            if (empty($current)) {
                \Smbb\Tomo\Service\Passes::createChildren($row['id']);
            } else {
                $changed = array('status' => $row['status']);

                if ((int) $current['creneau_id'] !== (int) $row['creneau_id']) {
                    $changed['creneau_id'] = $row['creneau_id'];
                }

                \Smbb\Tomo\Service\Passes::cascade($row['id'], $changed);
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
        $old_creneau = !empty($current['creneau_id']) ? (int) $current['creneau_id'] : 0;
        $new_creneau = !empty($row['creneau_id']) ? (int) $row['creneau_id'] : 0;

        if (empty($current)) {
            if ($row['status'] === 'confirmed' && $new_creneau) {
                do_action('smbb_tomo_inscription_confirmed', $id);
            }

            return;
        }

        if ($row['status'] === 'cancelled' && $current['status'] !== 'cancelled') {
            do_action('smbb_tomo_inscription_cancelled', $id, 'cancelled', $old_creneau, '');
        } elseif ($new_creneau && $new_creneau !== $old_creneau && $row['status'] !== 'cancelled') {
            do_action('smbb_tomo_inscription_moved', $id, $old_creneau, $new_creneau, $row['status'] === 'confirmed');
        } elseif (!$new_creneau && $old_creneau && $row['status'] === 'pending') {
            do_action('smbb_tomo_inscription_cancelled', $id, 'pending', $old_creneau, '');
        } elseif ($row['status'] === 'confirmed' && $current['status'] === 'pending' && $new_creneau) {
            do_action('smbb_tomo_inscription_confirmed', $id);
        }
    }

    public function beforeDelete(array $row, array $context = array())
    {
        History::record($row['id'], 'deleted', sprintf(__('Inscription de %s supprimée.', 'smbb-tomo'), Lookup::personName($row)), $row['creneau_id'] ?: null);

        // La place est libérée une fois la suppression faite.
        if (!empty($row['creneau_id'])) {
            $creneau_id = (int) $row['creneau_id'];
            add_action('shutdown', function () use ($creneau_id) {
                Capacity::refresh($creneau_id);
            });
        }

        return true;
    }
}
