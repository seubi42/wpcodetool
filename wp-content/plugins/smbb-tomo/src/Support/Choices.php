<?php

namespace Smbb\Tomo\Support;

defined('ABSPATH') || exit;

/**
 * Listes de valeurs fermées (statuts, niveaux, sources) et leurs libellés.
 */
final class Choices
{
    public static function atelierStatuses()
    {
        return array(
            'active' => __('Actif', 'smbb-tomo'),
            'archived' => __('Archivé', 'smbb-tomo'),
        );
    }

    public static function intervenantStatuses()
    {
        return array(
            'active' => __('Actif', 'smbb-tomo'),
            'archived' => __('Archivé', 'smbb-tomo'),
        );
    }

    /**
     * « full » est posé et retiré automatiquement selon les places restantes.
     */
    public static function creneauStatuses()
    {
        return array(
            'draft' => __('Brouillon', 'smbb-tomo'),
            'open' => __('En vente', 'smbb-tomo'),
            'full' => __('Complet', 'smbb-tomo'),
            'cancelled' => __('Annulé', 'smbb-tomo'),
        );
    }

    /**
     * Pastille du statut d'un créneau : « Passé » quand la date est dépassée (plus de statut « terminé »).
     */
    public static function creneauBadge(array $creneau)
    {
        if (in_array($creneau['status'], array('open', 'full'), true) && \Smbb\Tomo\Repository\Creneaux::isPast($creneau)) {
            return '<span class="tomo-badge tomo-badge--past">' . esc_html__('Passé', 'smbb-tomo') . '</span>';
        }

        return self::badge('creneauStatuses', (string) $creneau['status']);
    }

    /**
     * Statuts proposés dans le formulaire d'un créneau (complet est automatique).
     */
    public static function creneauEditableStatuses()
    {
        $statuses = self::creneauStatuses();
        unset($statuses['full']);

        return $statuses;
    }

    public static function inscriptionStatuses()
    {
        return array(
            'pending' => __('Réservation à confirmer', 'smbb-tomo'),
            'confirmed' => __('Réservation payée', 'smbb-tomo'),
            'present' => __("Présent à l'atelier", 'smbb-tomo'),
            'absent' => __("Absent à l'atelier", 'smbb-tomo'),
            'cancelled' => __('Réservation annulée', 'smbb-tomo'),
        );
    }

    /**
     * Icône Dashicons de chaque statut d'inscription (pastilles et boutons).
     */
    public static function inscriptionIcons()
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
    public static function inscriptionBadge(array $inscription)
    {
        $html = self::badge('inscriptionStatuses', (string) $inscription['status']);

        if ($inscription['status'] === 'pending' && empty($inscription['creneau_id'])) {
            $html .= ' <span class="tomo-badge tomo-badge--undated"><span class="dashicons dashicons-calendar" aria-hidden="true"></span>' . esc_html__('Sans date', 'smbb-tomo') . '</span>';
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
            'manual' => __('Ajout manuel', 'smbb-tomo'),
            'shop' => __('Boutique en ligne', 'smbb-tomo'),
            'external' => __('Externe (partenaire, billetterie)', 'smbb-tomo'),
            'waitlist' => __("Liste d'attente", 'smbb-tomo'),
            'walkin' => __('Imprévu, ajouté sur place', 'smbb-tomo'),
        );
    }

    public static function levels()
    {
        return array(
            '' => __('Non précisé', 'smbb-tomo'),
            'beginner' => __('Débutant', 'smbb-tomo'),
            'intermediate' => __('Intermédiaire', 'smbb-tomo'),
            'advanced' => __('Confirmé', 'smbb-tomo'),
        );
    }

    public static function waitlistStatuses()
    {
        return array(
            'waiting' => __('En attente', 'smbb-tomo'),
            'notified' => __('Prévenu', 'smbb-tomo'),
            'booked' => __('Inscrit', 'smbb-tomo'),
            'expired' => __('Expiré', 'smbb-tomo'),
            'cancelled' => __('Retiré', 'smbb-tomo'),
        );
    }

    public static function historyActions()
    {
        return array(
            'created' => __('Inscription', 'smbb-tomo'),
            'updated' => __('Modification', 'smbb-tomo'),
            'cancelled' => __('Annulation', 'smbb-tomo'),
            'to_replace' => __('Report', 'smbb-tomo'),
            'postponed' => __('Report', 'smbb-tomo'),
            'moved' => __('Déplacement', 'smbb-tomo'),
            'confirmed' => __('Confirmation', 'smbb-tomo'),
            'presence' => __('Pointage', 'smbb-tomo'),
            'waitlist' => __("Depuis la liste d'attente", 'smbb-tomo'),
            'creneau_cancelled' => __('Créneau annulé', 'smbb-tomo'),
            'walkin' => __('Imprévu ajouté sur place', 'smbb-tomo'),
            'deleted' => __('Suppression', 'smbb-tomo'),
            'document' => __('Document', 'smbb-tomo'),
            'document_view' => __('Documents consultés', 'smbb-tomo'),
        );
    }

    public static function envoiStatuses()
    {
        return array(
            'sent' => __('Envoyé', 'smbb-tomo'),
            'test' => __('Envoyé (mode test)', 'smbb-tomo'),
            'failed' => __('Échec', 'smbb-tomo'),
            'skipped' => __('Non envoyé', 'smbb-tomo'),
        );
    }

    public static function channels()
    {
        return array(
            'email' => __('E-mail', 'smbb-tomo'),
            'sms' => __('SMS', 'smbb-tomo'),
        );
    }

    public static function recipientTypes()
    {
        return array(
            'participant' => __('Participant', 'smbb-tomo'),
            'intervenant' => __('Intervenant', 'smbb-tomo'),
            'attente' => __("Liste d'attente", 'smbb-tomo'),
            'test' => __('Essai', 'smbb-tomo'),
        );
    }

    /**
     * Libellés des messages (réglages) plus l'essai.
     */
    public static function envoiEvents()
    {
        $events = array();

        foreach (Templates::messages() as $key => $message) {
            $events[$key] = $message['label'];
        }

        $events['test'] = __("Message d'essai", 'smbb-tomo');

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
        $icons = $list === 'inscriptionStatuses' ? self::inscriptionIcons() : ($list === 'channels' ? array('email' => 'dashicons-email-alt', 'sms' => 'dashicons-smartphone') : array());
        $icon = isset($icons[$value]) ? '<span class="dashicons ' . esc_attr($icons[$value]) . '" aria-hidden="true"></span>' : '';

        return '<span class="tomo-badge tomo-badge--' . esc_attr(sanitize_html_class((string) $value)) . '">'
            . $icon . esc_html(self::label($list, $value))
            . '</span>';
    }
}
