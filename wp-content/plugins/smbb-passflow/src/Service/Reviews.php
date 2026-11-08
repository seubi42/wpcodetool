<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Questionnaire de satisfaction après l'événement (Réglages › Avis).
 *
 * Quelques heures après la fin d'une journée, la tâche CodeTool met en file une « demande d'avis »
 * (Broadcasts, kind = review) pour les participants venus, et seulement ceux qui ont répondu « Oui »
 * au document qui porte le rôle Consentement RGPD (vérifié encore au moment de l'envoi).
 * Chacun reçoit un lien personnel {lien_avis} vers une page qui affiche un formulaire de
 * CodeTool › Formulaires. Les réponses sont des entrées Forms (origine passflow_review,
 * réf. booking:<id>), une par réservation, résumées dans le Bilan de la journée.
 *
 * Pass : la demande part après le dernier jour, une seule fois, sur la réservation principale.
 */
final class Reviews
{
    const QUERY = 'passflow_avis';
    const SUBMIT = 'passflow_review_submit';
    const SOURCE = 'passflow_review';
    const EVENT = 'review';
    const CRON = 'smbb_passflow_reviews';
    const HISTORY = 'review_request';
    const MAX_AGE_DAYS = 7;

    public static function hooks()
    {
        add_action('template_redirect', array(__CLASS__, 'page'));
        add_action('smbb_wpcodetool_register_cron_tasks', array(__CLASS__, 'registerCron'));
        add_filter('smbb_wpcodetool_form_entry_source', array(__CLASS__, 'entrySource'), 10, 2);
    }

    /**
     * @param \Smbb\WpCodeTool\Cron\CronRegistry $registry
     */
    public static function registerCron($registry)
    {
        $registry->register(self::CRON, array(
            'label' => __('Passflow : questionnaires de satisfaction', 'smbb-passflow'),
            'description' => __("Envoie la demande d'avis aux participants consentants quelques heures après la fin de chaque journée (Réglages › Avis).", 'smbb-passflow'),
            'schedule' => 'smbb_every_15_minutes',
            'callback' => array(__CLASS__, 'run'),
        ));
    }

    // ------------------------------------------------------------------
    // Réglages
    // ------------------------------------------------------------------

    /**
     * Formulaire choisi dans Réglages › Avis, ou null (aucun, supprimé, CodeTool sans Formulaires).
     *
     * @return array<string,mixed>|null
     */
    public static function form()
    {
        $id = Settings::int('review_form_id');

        return $id ? Documents::form(array('form_id' => $id)) : null;
    }

    /**
     * Formulaire prêt : le modèle « Demande d'avis » et la variable {lien_avis} sont proposés.
     */
    public static function ready()
    {
        return (bool) self::form();
    }

    /**
     * Envoi automatique actif (interrupteur, formulaire, et un document « Consentement RGPD »).
     */
    public static function enabled()
    {
        return Settings::int('review_enabled') && self::ready() && Documents::gdprDoc();
    }

    public static function delayHours()
    {
        return min(168, max(0, Settings::int('review_delay')));
    }

    /**
     * @return string[] canaux de la demande d'avis
     */
    public static function channels()
    {
        return array_values(array_filter(array(
            Settings::int('review_email') ? 'email' : '',
            Settings::int('review_sms') ? 'sms' : '',
        )));
    }

    public static function target()
    {
        return Settings::get('review_target') === 'present' ? 'present' : 'attended';
    }

    /**
     * Crée le questionnaire type dans CodeTool › Formulaires (sans e-mail de notification) et le choisit.
     *
     * @return int id du formulaire, 0 en cas d'échec
     */
    public static function createDefaultForm()
    {
        if (!Documents::formsAvailable() || !method_exists('Smbb\\WpCodeTool\\Forms\\FormRepository', 'createFromTemplate')) {
            return 0;
        }

        $id = \Smbb\WpCodeTool\Forms\FormRepository::createFromTemplate('satisfaction', __('Questionnaire de satisfaction', 'smbb-passflow'), array(
            'notifications' => array(),
            'turnstile' => '0', // lien personnel signé : pas un formulaire public
            'retention' => '0',
            'submit_label' => __('Envoyer mon avis', 'smbb-passflow'),
            'description' => __('Merci de prendre une minute pour nous dire ce que vous en avez pensé.', 'smbb-passflow'),
            'confirmation' => array('type' => 'message', 'message' => __('Merci pour votre avis !', 'smbb-passflow'), 'page' => '0', 'url' => ''),
        ));

        if ($id) {
            $settings = get_option(Settings::OPTION, array());
            $settings = is_array($settings) ? $settings : array();
            $settings['review_form_id'] = $id;
            update_option(Settings::OPTION, $settings);
            Settings::flush();
        }

        return $id;
    }

    // ------------------------------------------------------------------
    // Lien et page publique
    // ------------------------------------------------------------------

    public static function token(array $booking)
    {
        return substr(hash_hmac('sha256', 'review|' . (int) $booking['id'], wp_salt('auth')), 0, 24);
    }

    /**
     * Lien personnel du questionnaire (réservation principale pour un jour de pass), ou '' sans formulaire.
     */
    public static function url(array $booking)
    {
        if (empty($booking['id']) || !self::ready()) {
            return '';
        }

        $root = Passes::root($booking);

        return add_query_arg(array(self::QUERY => (int) $root['id'], 'k' => self::token($root)), home_url('/'));
    }

    private static function ref($booking_id)
    {
        return 'booking:' . (int) $booking_id;
    }

    /**
     * Réponse déjà envoyée pour cette réservation (principale), ou null.
     *
     * @return array<string,mixed>|null
     */
    public static function entryFor($booking_id)
    {
        if (!Documents::formsAvailable() || !method_exists('Smbb\\WpCodeTool\\Forms\\EntryRepository', 'forSource')) {
            return null;
        }

        $entries = \Smbb\WpCodeTool\Forms\EntryRepository::forSource(self::SOURCE, array(self::ref($booking_id)), 1);

        return $entries ? $entries[0] : null;
    }

    public static function page()
    {
        if (empty($_GET[self::QUERY])) {
            return;
        }

        $d = 'smbb-passflow';
        $id = absint($_GET[self::QUERY]);
        $token = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $booking = Bookings::find($id);
        $title = __('Votre avis', $d);

        if (!$booking || !is_string($token) || $token === '' || !hash_equals(self::token($booking), $token)) {
            Documents::render($title, '<p>' . esc_html__('Ce lien ne correspond à aucune réservation. Utilisez le lien reçu par e-mail ou SMS.', $d) . '</p>');
        }

        $form = self::form();
        $slot = $booking['slot_id'] ? Slots::find($booking['slot_id']) : null;
        $who = '<p class="who"><strong>' . esc_html(Lookup::personName($booking)) . '</strong><br>'
            . esc_html(Lookup::activityName($booking['activity_id']) . ($slot ? ' · ' . Lookup::slotWhen($slot) : '')) . '</p>';
        $done = self::entryFor($id);

        if ($done) {
            Documents::render($title, $who . '<section class="doc"><p class="done">' . esc_html(sprintf(__('Votre avis est bien reçu (le %s). Merci beaucoup !', $d), mysql2date('j F Y', get_date_from_gmt($done['created_at'])))) . '</p></section>' . self::backLink());
        }

        if (!$form) {
            Documents::render($title, $who . '<p>' . esc_html__('Le questionnaire n\'est plus disponible. Merci quand même !', $d) . '</p>' . self::backLink());
        }

        $state = array();
        $message = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_passflow_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_passflow_nonce'])), self::SUBMIT . '_' . $id)) {
            $posted = isset($_POST['avis']) && is_array($_POST['avis']) ? wp_unslash($_POST['avis']) : array();
            $checked = \Smbb\WpCodeTool\Forms\Submission::validate($form, $posted, array('file_prefix' => 'pf_avis__', 'page_url' => self::url($booking)));

            if (!$checked['errors']) {
                \Smbb\WpCodeTool\Forms\Submission::store($form, $checked, array(
                    'page_url' => self::url($booking),
                    'source' => self::SOURCE,
                    'source_ref' => self::ref($id),
                    'force_store' => true,
                    'notify' => false,
                    'context' => array('booking' => $booking),
                ));
                History::record($id, 'review', __('Avis reçu (questionnaire de satisfaction).', $d), $booking['slot_id'] ? (int) $booking['slot_id'] : null);

                $thanks = isset($form['settings']['confirmation']['message']) && trim(wp_strip_all_tags((string) $form['settings']['confirmation']['message'])) !== ''
                    ? wp_kses_post(wpautop((string) $form['settings']['confirmation']['message']))
                    : '<p>' . esc_html__('Merci pour votre avis !', $d) . '</p>';

                Documents::render($title, $who . '<section class="doc thanks">' . $thanks . '</section>' . self::backLink());
            }

            $state = $checked;
            $message = '<p class="notice error">' . esc_html__('Quelques réponses sont à revoir.', $d) . '</p>';
        }

        $body = $message . $who
            . '<form method="post" id="passflow-docs" data-sending="' . esc_attr__('Envoi en cours…', $d) . '">' . wp_nonce_field(self::SUBMIT . '_' . $id, '_passflow_nonce', false, false)
            . '<section class="doc">'
            . \Smbb\WpCodeTool\Forms\Frontend::fieldsHtml($form, array(
                'name_prefix' => 'avis',
                'file_prefix' => 'pf_avis__',
                'id_prefix' => 'passflow-avis',
                'values' => isset($state['values']) ? $state['values'] : array(),
                'errors' => isset($state['errors']) ? $state['errors'] : array(),
            ))
            . '</section>'
            . '<button type="submit" class="primary">' . esc_html(!empty($form['settings']['submit_label']) ? $form['settings']['submit_label'] : __('Envoyer mon avis', $d)) . '</button>'
            . '</form>'
            . '<script>(function(){var f=document.getElementById("passflow-docs");if(f)f.addEventListener("submit",function(e){if(f.dataset.busy){e.preventDefault();return;}f.dataset.busy="1";var b=f.querySelector("button[type=submit]");if(b){b.textContent=f.getAttribute("data-sending");b.disabled=true;b.classList.add("busy");}});})();</script>';

        Documents::render($title, $body, true);
    }

    private static function backLink()
    {
        return '<p class="links"><a class="back" href="' . esc_url(home_url('/')) . '">' . esc_html__('← Retour au site', 'smbb-passflow') . '</a></p>';
    }

    /**
     * Origine des réponses pour CodeTool › Formulaires : « Passflow · Avis · Léa Martin ».
     */
    public static function entrySource($info, $entry)
    {
        if (!is_array($entry) || ($entry['source'] ?? '') !== self::SOURCE) {
            return $info;
        }

        $booking = preg_match('/^booking:(\d+)$/', (string) $entry['source_ref'], $m) ? Bookings::find((int) $m[1]) : null;

        if (!$booking) {
            return array('label' => __('Passflow · Avis · réservation supprimée', 'smbb-passflow'), 'url' => '');
        }

        return array(
            'label' => sprintf(__('Passflow · Avis · %1$s · %2$s', 'smbb-passflow'), Lookup::personName($booking), Lookup::activityName($booking['activity_id'])),
            'url' => \Smbb\Passflow\Admin\View::url('passflow_booking', array('view' => 'details', 'id' => (int) $booking['id'])),
        );
    }

    // ------------------------------------------------------------------
    // Envoi
    // ------------------------------------------------------------------

    /**
     * Fin de la journée + délai (horodatage), ou 0 si la date est inconnue.
     */
    public static function dueAt(array $slot)
    {
        if (empty($slot['start_date'])) {
            return 0;
        }

        $end = !Lookup::allDay($slot) && !empty($slot['end_time']) ? substr((string) $slot['end_time'], 0, 5) : '23:59';

        try {
            $date = new \DateTimeImmutable($slot['start_date'] . ' ' . $end, wp_timezone());
        } catch (\Exception $e) {
            return 0;
        }

        return $date->getTimestamp() + self::delayHours() * HOUR_IN_SECONDS;
    }

    /**
     * Tâche CodeTool : journées terminées depuis le délai, pas encore traitées, de moins de 7 jours.
     *
     * @return int demandes mises en file
     */
    public static function run()
    {
        global $wpdb;

        if (!self::enabled() || !\Smbb\Passflow\Support\Schema::ready()) {
            return 0;
        }

        $from = wp_date('Y-m-d', time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Lookup::table('passflow_slot') . " WHERE deleted_flag = 0 AND kind <> 'pass' AND status <> 'cancelled' AND review_sent_at IS NULL AND start_date >= %s AND start_date <= %s",
            $from,
            current_time('Y-m-d')
        ));
        $queued = 0;

        foreach ((array) $ids as $slot_id) {
            $slot = Slots::find((int) $slot_id);

            if ($slot && self::dueAt($slot) > 0 && self::dueAt($slot) <= time()) {
                $result = self::sendFor($slot);
                $queued += isset($result['count']) ? (int) $result['count'] : 0;
            }
        }

        return $queued;
    }

    /**
     * Participants à qui demander un avis pour une journée : venus, consentants, pas encore sollicités
     * ni ayant déjà répondu ; jour de pass : seulement le dernier jour.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recipients(array $slot)
    {
        global $wpdb;

        $out = array();

        foreach (Broadcasts::bookingsFor((int) $slot['id'], self::target(), true) as $booking) {
            $root = Passes::root($booking);

            if (!empty($booking['pass_id'])) {
                $days = Passes::children((int) $booking['pass_id']);
                $last = $days ? end($days) : null;

                if (!$last || (int) $last['slot_id'] !== (int) $slot['id']) {
                    continue;
                }
            }

            $asked = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Lookup::table('passflow_history') . ' WHERE booking_id = %d AND action = %s', (int) $root['id'], self::HISTORY));

            if ($asked || self::entryFor((int) $root['id'])) {
                continue;
            }

            $out[] = $booking;
        }

        return $out;
    }

    /**
     * Met en file la demande d'avis d'une journée (une communication par canal) et note la journée comme traitée.
     *
     * @return array{success:bool,message:string,count:int} count : communications mises en file (une par canal)
     */
    public static function sendFor(array $slot)
    {
        global $wpdb;

        $d = 'smbb-passflow';
        $wpdb->update(Lookup::table('passflow_slot'), array('review_sent_at' => current_time('mysql')), array('id' => (int) $slot['id']));

        if (!self::ready()) {
            return array('success' => false, 'message' => __('Choisissez d\'abord le questionnaire dans Réglages › Avis.', $d), 'count' => 0);
        }

        if (!Documents::gdprDoc()) {
            return array('success' => false, 'message' => __('Aucun document ne porte le rôle « Consentement RGPD » : la demande d\'avis ne peut viser personne.', $d), 'count' => 0);
        }

        $bookings = self::recipients($slot);
        $channels = self::channels();

        if (!$bookings || !$channels) {
            return array('success' => true, 'message' => __('Aucun participant consentant à solliciter pour cette journée.', $d), 'count' => 0);
        }

        $count = 0;
        $texts = array(
            'email' => array((string) Settings::get('review_subject'), (string) Settings::get('review_body')),
            'sms' => array('', (string) Settings::get('review_sms_text')),
        );

        foreach ($channels as $channel) {
            $result = Broadcasts::create($slot, self::target(), array($channel), $texts[$channel][0], $texts[$channel][1], true, array('kind' => Broadcasts::KIND_REVIEW, 'bookings' => $bookings));

            if (!empty($result['success'])) {
                $count++;
            }
        }

        foreach ($bookings as $booking) {
            $root = Passes::root($booking);
            History::record((int) $root['id'], self::HISTORY, __("Demande d'avis envoyée (questionnaire de satisfaction).", $d), (int) $slot['id']);
        }

        return array(
            'success' => true,
            'count' => $count,
            'message' => sprintf(_n("Demande d'avis en file pour %d participant.", "Demande d'avis en file pour %d participants.", count($bookings), $d), count($bookings)),
        );
    }

    // ------------------------------------------------------------------
    // Bilan
    // ------------------------------------------------------------------

    /**
     * Résultats du questionnaire pour une journée.
     *
     * @param array<int,array<string,mixed>> $seats inscriptions occupant une place
     * @return array<string,mixed>
     */
    public static function stats(array $slot, array $seats)
    {
        $roots = array();

        foreach ($seats as $b) {
            $root = !empty($b['pass_id']) ? (int) $b['pass_id'] : (int) $b['id'];
            $roots[$root] = $b;
        }

        $entries = $roots && Documents::formsAvailable() && method_exists('Smbb\\WpCodeTool\\Forms\\EntryRepository', 'forSource')
            ? \Smbb\WpCodeTool\Forms\EntryRepository::forSource(self::SOURCE, array_map(array(__CLASS__, 'ref'), array_keys($roots)))
            : array();
        $asked = Broadcasts::sentCount((int) $slot['id'], Broadcasts::KIND_REVIEW);
        $fields = array();
        $answers = array();

        foreach ($entries as $entry) {
            $booking_id = preg_match('/^booking:(\d+)$/', $entry['source_ref'], $m) ? (int) $m[1] : 0;
            $booking = isset($roots[$booking_id]) ? $roots[$booking_id] : null;
            $answers[] = array('entry' => $entry, 'booking' => $booking);

            foreach ($entry['fields'] as $f) {
                $type = (string) ($f['type'] ?? '');
                $key = (string) ($f['key'] ?? '');
                $value = $f['value'] ?? '';

                if ($key === '' || in_array($type, array('hidden', 'file', 'gdpr', 'section', 'html'), true)) {
                    continue;
                }

                if (!isset($fields[$key])) {
                    $fields[$key] = array('label' => (string) ($f['label'] ?? $key), 'type' => $type, 'count' => 0, 'sum' => 0, 'dist' => array(), 'texts' => array());
                }

                if ($value === '' || $value === array()) {
                    continue;
                }

                $fields[$key]['count']++;

                if ($type === 'rating' || $type === 'number') {
                    $fields[$key]['sum'] += (float) $value;
                    $fields[$key]['dist'][(string) $value] = ($fields[$key]['dist'][(string) $value] ?? 0) + 1;
                } elseif (in_array($type, array('radio', 'select', 'checkbox'), true)) {
                    foreach ((array) $value as $choice) {
                        $fields[$key]['dist'][(string) $choice] = ($fields[$key]['dist'][(string) $choice] ?? 0) + 1;
                    }
                } else {
                    $fields[$key]['texts'][] = array('text' => is_array($value) ? implode(', ', $value) : (string) $value, 'booking' => $booking);
                }
            }
        }

        $average = null;

        foreach ($fields as $key => $f) {
            $fields[$key]['avg'] = $f['count'] && in_array($f['type'], array('rating', 'number'), true) ? round($f['sum'] / $f['count'], 1) : null;

            if ($average === null && $f['type'] === 'rating' && $f['count']) {
                $average = $fields[$key]['avg'];
            }
        }

        return array(
            'enabled' => self::enabled(),
            'ready' => self::ready(),
            'gdpr' => (bool) Documents::gdprDoc(),
            'sent_at' => isset($slot['review_sent_at']) ? (string) $slot['review_sent_at'] : '',
            'due' => self::dueAt($slot),
            'asked' => $asked,
            'answered' => count($entries),
            'rate' => $asked > 0 ? (int) round(min($asked, count($entries)) * 100 / $asked) : 0,
            'average' => $average,
            'fields' => $fields,
            'answers' => $answers,
        );
    }
}
