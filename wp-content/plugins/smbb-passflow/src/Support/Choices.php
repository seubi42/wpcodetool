<?php

namespace Smbb\Passflow\Support;

defined('ABSPATH') || exit;

/**
 * Listes de valeurs fermées (statuts, niveaux, sources) et leurs libellés.
 */
final class Choices
{
    public static function activityStatuses()
    {
        return array(
            'active' => __('Actif', 'smbb-passflow'),
            'archived' => __('Archivé', 'smbb-passflow'),
        );
    }

    public static function hostStatuses()
    {
        return array(
            'active' => __('Actif', 'smbb-passflow'),
            'archived' => __('Archivé', 'smbb-passflow'),
        );
    }

    /**
     * « full » est posé et retiré automatiquement selon les places restantes.
     */
    public static function slotStatuses()
    {
        return array(
            'draft' => __('Brouillon', 'smbb-passflow'),
            'open' => __('En vente', 'smbb-passflow'),
            'full' => __('Complet', 'smbb-passflow'),
            'cancelled' => __('Annulé', 'smbb-passflow'),
        );
    }

    /**
     * Pastille du statut d'un créneau : « Passé » quand la date est dépassée (plus de statut « terminé »).
     */
    public static function slotBadge(array $slot)
    {
        if (in_array($slot['status'], array('open', 'full'), true) && \Smbb\Passflow\Repository\Slots::isPast($slot)) {
            return '<span class="passflow-badge passflow-badge--past">' . esc_html__('Passé', 'smbb-passflow') . '</span>';
        }

        // En vente différée : réservations pas encore ouvertes.
        if ($slot['status'] === 'open' && \Smbb\Passflow\Service\SaleWindow::pending($slot)) {
            return '<span class="passflow-badge passflow-badge--soon"><span class="dashicons dashicons-clock" aria-hidden="true"></span>'
                . esc_html(\Smbb\Passflow\Service\SaleWindow::shortLabel($slot, false)) . '</span>';
        }

        return self::badge('slotStatuses', (string) $slot['status']);
    }

    /**
     * Statuts proposés dans le formulaire d'un créneau (complet est automatique).
     */
    public static function slotEditableStatuses()
    {
        $statuses = self::slotStatuses();
        unset($statuses['full']);

        return $statuses;
    }

    public static function bookingStatuses()
    {
        return array(
            'pending' => __('Réservation à confirmer', 'smbb-passflow'),
            'confirmed' => __('Réservation payée', 'smbb-passflow'),
            'present' => __("Présent à l'atelier", 'smbb-passflow'),
            'absent' => __("Absent à l'atelier", 'smbb-passflow'),
            'cancelled' => __('Réservation annulée', 'smbb-passflow'),
        );
    }

    /**
     * Icône Dashicons de chaque statut d'inscription (pastilles et boutons).
     */
    public static function bookingIcons()
    {
        return array(
            'pending' => 'dashicons-clock',
            'confirmed' => 'dashicons-yes-alt',
            'present' => 'dashicons-groups',
            'absent' => 'dashicons-warning',
            'cancelled' => 'dashicons-dismiss',
        );
    }

    /**
     * Pastille d'une inscription, avec « sans date » quand elle attend un créneau.
     */
    public static function bookingBadge(array $booking)
    {
        $html = self::badge('bookingStatuses', (string) $booking['status']);

        if ($booking['status'] === 'pending' && empty($booking['slot_id'])) {
            $html .= ' <span class="passflow-badge passflow-badge--undated"><span class="dashicons dashicons-calendar" aria-hidden="true"></span>' . esc_html__('Sans date', 'smbb-passflow') . '</span>';
        }

        return $html;
    }

    /**
     * Statuts qui occupent une place sur le créneau (« à confirmer » bloque déjà la place,
     * sauf s'il n'a pas encore de date).
     */
    public static function seatStatuses()
    {
        return array('pending', 'confirmed', 'present', 'absent');
    }

    public static function sources()
    {
        return array(
            'manual' => __('Ajout manuel', 'smbb-passflow'),
            'shop' => __('Boutique en ligne', 'smbb-passflow'),
            'external' => __('Externe (partenaire, billetterie)', 'smbb-passflow'),
            'waitlist' => __("Liste d'attente", 'smbb-passflow'),
            'walkin' => __('Imprévu, ajouté sur place', 'smbb-passflow'),
        );
    }

    public static function levels()
    {
        return array(
            '' => __('Non précisé', 'smbb-passflow'),
            'beginner' => __('Débutant', 'smbb-passflow'),
            'intermediate' => __('Intermédiaire', 'smbb-passflow'),
            'advanced' => __('Confirmé', 'smbb-passflow'),
        );
    }

    public static function waitlistStatuses()
    {
        return array(
            'waiting' => __('En attente', 'smbb-passflow'),
            'notified' => __('Prévenu', 'smbb-passflow'),
            'booked' => __('Inscrit', 'smbb-passflow'),
            'expired' => __('Expiré', 'smbb-passflow'),
            'cancelled' => __('Retiré', 'smbb-passflow'),
        );
    }

    public static function historyActions()
    {
        return array(
            'created' => __('Inscription', 'smbb-passflow'),
            'updated' => __('Modification', 'smbb-passflow'),
            'cancelled' => __('Annulation', 'smbb-passflow'),
            'to_replace' => __('Report', 'smbb-passflow'),
            'postponed' => __('Report', 'smbb-passflow'),
            'moved' => __('Déplacement', 'smbb-passflow'),
            'confirmed' => __('Confirmation', 'smbb-passflow'),
            'presence' => __('Pointage', 'smbb-passflow'),
            'waitlist' => __("Depuis la liste d'attente", 'smbb-passflow'),
            'slot_cancelled' => __('Créneau annulé', 'smbb-passflow'),
            'walkin' => __('Imprévu ajouté sur place', 'smbb-passflow'),
            'deleted' => __('Suppression', 'smbb-passflow'),
            'document' => __('Document', 'smbb-passflow'),
            'document_view' => __('Documents consultés', 'smbb-passflow'),
            'transferred' => __('Billet transféré', 'smbb-passflow'),
        );
    }

    public static function deliveryStatuses()
    {
        return array(
            'sent' => __('Envoyé', 'smbb-passflow'),
            'test' => __('Envoyé (mode test)', 'smbb-passflow'),
            'failed' => __('Échec', 'smbb-passflow'),
            'skipped' => __('Non envoyé', 'smbb-passflow'),
        );
    }

    public static function channels()
    {
        return array(
            'email' => __('E-mail', 'smbb-passflow'),
            'sms' => __('SMS', 'smbb-passflow'),
        );
    }

    public static function recipientTypes()
    {
        return array(
            'participant' => __('Participant', 'smbb-passflow'),
            'intervenant' => __('Intervenant', 'smbb-passflow'),
            'attente' => __("Liste d'attente", 'smbb-passflow'),
            'test' => __('Essai', 'smbb-passflow'),
        );
    }

    /**
     * Libellés des messages (réglages) plus l'essai.
     */
    public static function deliveryEvents()
    {
        $events = array();

        foreach (Templates::messages() as $key => $message) {
            $events[$key] = $message['label'];
        }

        $events['test'] = __("Message d'essai", 'smbb-passflow');
        $events['broadcast'] = __('Communication aux inscrits', 'smbb-passflow');
        $events['review'] = __("Demande d'avis", 'smbb-passflow');

        return $events;
    }

    public static function label($list, $value)
    {
        $values = method_exists(__CLASS__, $list) ? call_user_func(array(__CLASS__, $list)) : array();

        return isset($values[$value]) ? $values[$value] : (string) $value;
    }

    /**
     * Options de select CodeTool : libellé => valeur.
     */
    public static function options($list)
    {
        $values = method_exists(__CLASS__, $list) ? call_user_func(array(__CLASS__, $list)) : array();

        return array_flip($values);
    }

    /**
     * Pastille de statut HTML (déjà échappée).
     */
    public static function badge($list, $value)
    {
        $icons = $list === 'bookingStatuses' ? self::bookingIcons() : ($list === 'channels' ? array('email' => 'dashicons-email-alt', 'sms' => 'dashicons-smartphone') : array());
        $icon = isset($icons[$value]) ? '<span class="dashicons ' . esc_attr($icons[$value]) . '" aria-hidden="true"></span>' : '';

        return '<span class="passflow-badge passflow-badge--' . esc_attr(sanitize_html_class((string) $value)) . '">'
            . $icon . esc_html(self::label($list, $value))
            . '</span>';
    }
}
