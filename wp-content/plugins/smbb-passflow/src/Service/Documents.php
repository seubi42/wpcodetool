<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Documents des participants, réglés dans le menu Documents :
 * - « signature » : un texte à lire et signer au doigt (décharge de responsabilité…) ;
 * - « accord » : une question oui / non (droit à l'image…).
 *
 * Chaque participant reçoit un lien signé (e-mail de confirmation, rappel, Mon compte) vers une page
 * où il remplit tout. Ce qui manque se fait sur place, depuis la gestion mobile, sur le téléphone de
 * l'intervenant. Chaque réponse est une ligne de passflow_document (texte signé, empreinte, date, IP,
 * signature) : la dernière réponse d'un document fait foi.
 *
 * Un document peut joindre un formulaire de CodeTool › Formulaires (infos, pièces à fournir) : ses champs
 * s'affichent dans la page, avant la signature ou la réponse. Les réponses deviennent une entrée Forms
 * (origine passflow, réf. booking:<id>:<doc>) liée par entry_id ; l'empreinte du document les inclut.
 * Type « form » : formulaire seul, sans signature ni oui / non.
 *
 * Rôle « Consentement RGPD » (un seul document) : une question oui / non dont la réponse dit si
 * le participant accepte d'être recontacté (démarchage, demande d'avis). Communication et
 * questionnaire de satisfaction s'en servent pour ne viser que les consentants.
 */
final class Documents
{
    const QUERY = 'passflow_documents';
    const SUBMIT = 'passflow_documents_submit';
    const KINDS = array('signature', 'consent', 'form');
    const SOURCE = 'passflow';

    public static function hooks()
    {
        add_action('template_redirect', array(__CLASS__, 'page'));
        add_filter('smbb_wpcodetool_form_entry_source', array(__CLASS__, 'entrySource'), 10, 2);
        add_filter('smbb_wpcodetool_form_file_allowed', array(__CLASS__, 'fileAllowed'), 10, 2);
    }

    // ------------------------------------------------------------------
    // Réglages
    // ------------------------------------------------------------------

    /**
     * Documents actifs, indexés par clé.
     *
     * @return array<string,array{key:string,title:string,kind:string,text:string,required:bool}>
     */
    public static function all()
    {
        $rows = Settings::get('documents');
        $docs = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            // « active » remplace l'ancien « paused » (inversé) ; une ligne d'avant garde son état.
            $active = is_array($row) && array_key_exists('active', $row) ? !empty($row['active']) : empty($row['paused']);

            if (!is_array($row) || empty($row['title']) || !$active) {
                continue;
            }

            $key = !empty($row['key']) ? sanitize_key($row['key']) : sanitize_key(sanitize_title($row['title']));
            $docs[$key] = array(
                'key' => $key,
                'title' => (string) $row['title'],
                'kind' => isset($row['kind']) && in_array($row['kind'], self::KINDS, true) ? $row['kind'] : 'signature',
                'text' => isset($row['text']) ? (string) $row['text'] : '',
                'required' => !empty($row['required']),
                'form_id' => isset($row['form_id']) ? absint($row['form_id']) : 0,
                'form_notify' => !empty($row['form_notify']),
                'gdpr' => !empty($row['gdpr']),
            );

            // Rôle RGPD : toujours une question oui / non.
            if ($docs[$key]['gdpr']) {
                $docs[$key]['kind'] = 'consent';
            }

            // « Formulaire seul » sans formulaire (supprimé, CodeTool sans Formulaires) : rien à demander.
            if ($docs[$key]['kind'] === 'form' && !self::form($docs[$key])) {
                unset($docs[$key]);
            }
        }

        return $docs;
    }

    /**
     * Module Formulaires de CodeTool disponible (0.4.1 et plus : champs intégrables) ?
     */
    public static function formsAvailable()
    {
        return class_exists('Smbb\\WpCodeTool\\Forms\\Forms') && method_exists('Smbb\\WpCodeTool\\Forms\\Frontend', 'fieldsHtml')
            && \Smbb\WpCodeTool\Forms\Forms::enabled() && \Smbb\WpCodeTool\Forms\Forms::ready();
    }

    /**
     * Formulaire joint au document, ou null (aucun, supprimé, à la corbeille).
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>|null
     */
    public static function form(array $doc)
    {
        if (empty($doc['form_id']) || !self::formsAvailable()) {
            return null;
        }

        static $cache = array();
        $id = (int) $doc['form_id'];

        if (!array_key_exists($id, $cache)) {
            $form = \Smbb\WpCodeTool\Forms\FormRepository::find($id);
            $cache[$id] = $form && $form['status'] !== 'trash' && $form['fields'] ? $form : null;
        }

        return $cache[$id];
    }

    /**
     * Formulaires proposés dans le menu Documents : id => titre.
     *
     * @return array<int,string>
     */
    public static function formChoices()
    {
        return self::formsAvailable() ? \Smbb\WpCodeTool\Forms\FormRepository::choices() : array();
    }

    /**
     * Entrée Forms d'une réponse, ou null (pas de formulaire, entrée supprimée).
     *
     * @param array<string,mixed>|null $answer
     * @return array<string,mixed>|null
     */
    public static function entry($answer)
    {
        if (!$answer || empty($answer['entry_id']) || !self::formsAvailable()) {
            return null;
        }

        return \Smbb\WpCodeTool\Forms\EntryRepository::find((int) $answer['entry_id']);
    }

    /**
     * Origine des entrées remplies ici, pour CodeTool › Formulaires : « Passflow · Décharge · Léa Martin ».
     */
    public static function entrySource($info, $entry)
    {
        if (!is_array($entry) || ($entry['source'] ?? '') !== self::SOURCE) {
            return $info;
        }

        $ref = self::parseRef((string) $entry['source_ref']);
        $booking = $ref ? Bookings::find($ref['booking']) : null;

        if (!$booking) {
            return array('label' => __('Passflow · réservation supprimée', 'smbb-passflow'), 'url' => '');
        }

        $docs = self::all();
        $title = isset($docs[$ref['doc']]) ? $docs[$ref['doc']]['title'] : $ref['doc'];

        return array(
            'label' => sprintf(__('Passflow · %1$s · %2$s', 'smbb-passflow'), $title, Lookup::personName($booking)),
            'url' => \Smbb\Passflow\Admin\View::url('passflow_booking', array('view' => 'details', 'id' => (int) $booking['id'])),
        );
    }

    /**
     * Fichiers d'une entrée remplie ici : l'équipe qui voit la journée peut les ouvrir (sans le droit Formulaires).
     */
    public static function fileAllowed($allowed, $entry)
    {
        if ($allowed || !is_array($entry) || ($entry['source'] ?? '') !== self::SOURCE) {
            return $allowed;
        }

        $ref = self::parseRef((string) $entry['source_ref']);
        $booking = $ref ? Bookings::find($ref['booking']) : null;

        return $booking && \Smbb\Passflow\Admin\Actions::canSeeSlot($booking['slot_id']);
    }

    /**
     * @return array{booking:int,doc:string}|null
     */
    private static function parseRef($ref)
    {
        return preg_match('/^booking:(\d+):([a-z0-9_-]+)$/', $ref, $m) ? array('booking' => (int) $m[1], 'doc' => $m[2]) : null;
    }

    public static function enabled()
    {
        return (bool) self::all();
    }

    // ------------------------------------------------------------------
    // Consentement RGPD
    // ------------------------------------------------------------------

    /**
     * Texte proposé pour un document « Consentement RGPD » laissé vide.
     */
    public static function gdprDefaultText()
    {
        return sprintf(
            __("J'accepte que %s conserve mes coordonnées pour me recontacter : questionnaire de satisfaction après l'événement, informations sur ses prochaines dates et offres. Je peux changer d'avis à tout moment depuis ce lien ou sur simple demande, et les messages urgents liés à ma réservation me parviendront dans tous les cas.", 'smbb-passflow'),
            Settings::businessName()
        );
    }

    /**
     * Document actif qui porte le rôle « Consentement RGPD », ou null.
     *
     * @return array<string,mixed>|null
     */
    public static function gdprDoc()
    {
        foreach (self::all() as $doc) {
            if ($doc['gdpr']) {
                return $doc;
            }
        }

        return null;
    }

    /**
     * Réponse RGPD de chaque inscription : booking_id => 'yes' | 'no' | '' (pas encore répondu).
     * Un jour de pass reprend la réponse de la réservation principale. Sans document RGPD : tout à ''.
     *
     * @param int[] $booking_ids
     * @return array<int,string>
     */
    public static function gdprAnswers(array $booking_ids)
    {
        $doc = self::gdprDoc();
        $answers = $doc ? self::answers($booking_ids) : array();
        $out = array();

        foreach (array_map('intval', $booking_ids) as $id) {
            $row = $doc && isset($answers[$id][$doc['key']]) ? $answers[$id][$doc['key']] : null;
            $out[$id] = $row ? (string) $row['answer'] : '';
        }

        return $out;
    }

    /**
     * Le participant a-t-il répondu « Oui » au consentement RGPD ?
     */
    public static function hasGdprConsent($booking_id)
    {
        $all = self::gdprAnswers(array((int) $booking_id));

        return isset($all[(int) $booking_id]) && $all[(int) $booking_id] === 'yes';
    }

    public static function kinds()
    {
        return array(
            'signature' => __('À lire et signer (décharge…)', 'smbb-passflow'),
            'consent' => __('Question oui / non (droit à l\'image…)', 'smbb-passflow'),
            'form' => __('Formulaire seul (infos, pièces à fournir…)', 'smbb-passflow'),
        );
    }

    // ------------------------------------------------------------------
    // Réponses
    // ------------------------------------------------------------------

    /**
     * Dernière réponse de chaque document pour des inscriptions : array(booking_id => array(doc_key => row)).
     *
     * @param int[] $booking_ids
     * @return array<int,array<string,array<string,mixed>>>
     */
    public static function answers(array $booking_ids)
    {
        global $wpdb;

        $ids = array_filter(array_map('intval', $booking_ids));

        if (!$ids || !\Smbb\Passflow\Support\Schema::ready()) {
            return array();
        }

        // Jours d'un pass : ils reprennent les réponses de la réservation principale.
        $mothers = array();
        $links = $wpdb->get_results('SELECT id, pass_id FROM ' . Lookup::table('passflow_booking') . ' WHERE pass_id > 0 AND id IN (' . implode(',', $ids) . ')', ARRAY_A);

        foreach (is_array($links) ? $links : array() as $link) {
            $mothers[(int) $link['id']] = (int) $link['pass_id'];
        }

        $all = array_unique(array_merge($ids, array_values($mothers)));
        $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('passflow_document') . ' WHERE deleted_flag = 0 AND booking_id IN (' . implode(',', $all) . ') ORDER BY id ASC', ARRAY_A);
        $answers = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $answers[(int) $row['booking_id']][$row['doc_key']] = $row;
        }

        foreach ($mothers as $child => $mother) {
            if (isset($answers[$mother])) {
                $answers[$child] = $answers[$mother];
            }
        }

        return $answers;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function answersFor($booking_id)
    {
        $all = self::answers(array((int) $booking_id));

        return isset($all[(int) $booking_id]) ? $all[(int) $booking_id] : array();
    }

    /**
     * Documents encore à remplir : signature non faite, ou question sans réponse.
     *
     * @param array<string,array<string,mixed>> $answers réponses de l'inscription
     * @return array<string,array<string,mixed>>
     */
    public static function missing(array $answers)
    {
        return array_filter(self::all(), function ($doc) use ($answers) {
            return empty($answers[$doc['key']]);
        });
    }

    /**
     * Résumé court d'une réponse : « Signée », « Oui », « Non », « À signer », « Sans réponse ».
     *
     * @param array<string,mixed>      $doc
     * @param array<string,mixed>|null $answer
     * @return array{label:string,state:string} state : ok, no, todo
     */
    public static function status(array $doc, $answer)
    {
        $d = 'smbb-passflow';

        if (!$answer) {
            $todo = array('signature' => __('À signer', $d), 'form' => __('À remplir', $d));

            return array('label' => isset($todo[$doc['kind']]) ? $todo[$doc['kind']] : __('Sans réponse', $d), 'state' => 'todo');
        }

        if ($answer['answer'] === 'signed') {
            return array('label' => __('Signée', $d), 'state' => 'ok');
        }

        if ($answer['answer'] === 'sent') {
            return array('label' => __('Rempli', $d), 'state' => 'ok');
        }

        return $answer['answer'] === 'yes'
            ? array('label' => __('Oui', $d), 'state' => 'ok')
            : array('label' => __('Non', $d), 'state' => 'no');
    }

    /**
     * Badges « Décharge : signée · Droit à l'image : non » (HTML échappé).
     *
     * @param array<string,array<string,mixed>> $answers
     */
    public static function badges(array $answers, $link = '')
    {
        $html = array();

        foreach (self::all() as $doc) {
            $status = self::status($doc, isset($answers[$doc['key']]) ? $answers[$doc['key']] : null);
            $class = $status['state'] === 'ok' ? 'confirmed' : ($status['state'] === 'no' ? 'absent' : 'pending');
            $title = $doc['gdpr'] ? sprintf(__('%s (consentement RGPD : démarchage, demandes d\'avis)', 'smbb-passflow'), $doc['title']) : $doc['title'];
            $html[] = '<span class="passflow-badge passflow-badge--' . $class . '" title="' . esc_attr($title) . '">' . ($doc['gdpr'] ? '<span class="dashicons dashicons-shield" aria-hidden="true"></span>' : '') . esc_html($doc['title'] . ' : ' . mb_strtolower($status['label'])) . '</span>';
        }

        return implode(' ', $html);
    }

    /**
     * @return string[] titres des documents, pour les colonnes d'export
     */
    public static function titles()
    {
        return array_values(array_map(function ($doc) {
            return $doc['title'];
        }, self::all()));
    }

    /**
     * @param array<string,array<string,mixed>> $answers
     * @return string[]
     */
    public static function csvValues(array $answers)
    {
        $values = array();

        foreach (self::all() as $doc) {
            $values[] = self::status($doc, isset($answers[$doc['key']]) ? $answers[$doc['key']] : null)['label'];
        }

        return $values;
    }

    // ------------------------------------------------------------------
    // Lien et page publique
    // ------------------------------------------------------------------

    public static function token(array $booking)
    {
        // Après un transfert de billet, le lien des documents change aussi (nouvelle personne).
        $rev = Transfers::rev($booking);

        return substr(hash_hmac('sha256', 'documents|' . (int) $booking['id'] . ($rev ? '|r' . $rev : ''), wp_salt('auth')), 0, 24);
    }

    /**
     * Jeton reçu valable ? L'ancien calcul (avec l'e-mail, jusqu'à 0.13.0) reste accepté pour les liens déjà envoyés.
     */
    public static function valid(array $booking, $token)
    {
        $legacy = substr(hash_hmac('sha256', 'documents|' . (int) $booking['id'] . '|' . strtolower((string) $booking['email']), wp_salt('auth')), 0, 24);

        return is_string($token) && $token !== '' && (hash_equals(self::token($booking), $token) || (Transfers::rev($booking) === 0 && hash_equals($legacy, $token)));
    }

    /**
     * Lien vers la page des documents. $onsite : rempli sur place, sur l'appareil de l'équipe.
     */
    public static function url(array $booking, $onsite = false)
    {
        $args = array(self::QUERY => (int) $booking['id'], 'k' => self::token($booking));

        if ($onsite) {
            $args['sur_place'] = 1;
        }

        return add_query_arg($args, home_url('/'));
    }

    /**
     * Messages (e-mail, SMS) partis avec le lien des documents de cette réservation, du plus récent au plus ancien.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function requests(array $booking)
    {
        $id = (int) $booking['id'];

        return array_values(array_filter(\Smbb\Passflow\Repository\Deliveries::forBooking($id, 200), function ($row) use ($id) {
            return (bool) preg_match('/' . self::QUERY . '=' . $id . '(?!\d)/', (string) $row['body']);
        }));
    }

    /**
     * Dernière ouverture du lien par le participant (hors « sur place »), ou ''.
     */
    public static function lastView($booking_id)
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(created_at) FROM ' . Lookup::table('passflow_history') . " WHERE booking_id = %d AND action = 'document_view'",
            (int) $booking_id
        ));
    }

    /**
     * Lien pour les e-mails : seulement s'il reste quelque chose à remplir.
     */
    public static function pendingUrl(array $booking)
    {
        if (!self::enabled() || empty($booking['id']) || !in_array($booking['status'], array('pending', 'confirmed'), true)) {
            return '';
        }

        return self::missing(self::answersFor($booking['id'])) ? self::url($booking) : '';
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

        if (!$booking || !self::valid($booking, $token)) {
            self::render(__('Lien invalide', $d), '<p>' . esc_html__('Ce lien ne correspond à aucune réservation. Utilisez le lien reçu par e-mail ou votre espace client.', $d) . '</p>');
        }

        $onsite = !empty($_GET['sur_place']) && is_user_logged_in() && \Smbb\Passflow\Admin\Actions::canSeeSlot($booking['slot_id']);
        // Ouverte dans la lightbox de l'écran d'appel ou du mode Jour J : pas de lien retour, l'écran parent est prévenu.
        $framed = $onsite && !empty($_GET['cadre']);
        $row_id = $id; // ligne de l'écran parent (le jour du pass, pas la réservation principale)
        $saved = false;
        $saved_ok = false;

        // Jour d'un pass : les documents sont signés une seule fois, sur la réservation principale.
        if (!empty($booking['pass_id'])) {
            $booking = \Smbb\Passflow\Service\Passes::root($booking);
            $id = (int) $booking['id'];
        }

        $docs = self::all();
        $message = '';
        $state = array();

        if (!$docs) {
            self::render(__('Documents', $d), '<p>' . esc_html__("Aucun document n'est demandé pour le moment.", $d) . '</p>');
        }

        // Envoi plus lourd que la limite de PHP (photos) : $_POST arrive vide, sans nonce.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
            $message = '<p class="notice error">' . esc_html(sprintf(__('Les fichiers sont trop lourds pour être envoyés ensemble (%s au plus). Joignez-les en plus petit ou un par un.', $d), size_format(wp_max_upload_size()))) . '</p>';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_passflow_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_passflow_nonce'])), self::SUBMIT . '_' . $id)) {
            $result = self::save($booking, $docs, $onsite);
            $saved = true;
            $saved_ok = !empty($result['success']);
            $message = '<p class="notice ' . ($result['success'] ? 'ok' : 'error') . '">' . esc_html($result['message']) . '</p>';
            $state = $result['state'];
        }

        // Ouverture du lien par le participant (pas par l'équipe) : notée une fois par jour pour la fiche de l'inscription.
        $staff = is_user_logged_in() && \Smbb\Passflow\Admin\Actions::canSeeSlot($booking['slot_id']);

        if (!$onsite && !$staff && $_SERVER['REQUEST_METHOD'] !== 'POST' && substr(self::lastView($id), 0, 10) !== current_time('Y-m-d')) {
            History::record($id, 'document_view', __('Lien des documents ouvert par le participant.', $d), $booking['slot_id'] ? (int) $booking['slot_id'] : null);
        }

        $answers = self::answersFor($id);
        $slot = $booking['slot_id'] ? Slots::find($booking['slot_id']) : null;
        $back = $onsite ? admin_url('admin.php?page=smbb-codetool-passflow_portal&creneau=' . (int) $booking['slot_id']) : '';

        $body = $message
            . '<p class="who"><strong>' . esc_html(Lookup::personName($booking)) . '</strong><br>'
            . esc_html(Lookup::activityName($booking['activity_id']) . ($slot ? ' · ' . Lookup::slotWhen($slot) : '')) . '</p>';

        if ($onsite) {
            $note = trim((string) Settings::get('documents_onsite_note'));
            $body .= $note !== '' ? '<p class="notice warn">' . esc_html($note) . '</p>' : '';
        }

        $fields = '';
        $todo = 0;
        $multipart = false;
        $with_forms = false;

        foreach ($docs as $doc) {
            $answer = isset($answers[$doc['key']]) ? $answers[$doc['key']] : null;
            $again = isset($state[$doc['key']]) ? $state[$doc['key']] : array();
            $name = 'doc[' . esc_attr($doc['key']) . ']';
            $form = self::form($doc);
            $fields .= '<section class="doc"><h2>' . esc_html($doc['title']) . '</h2>';

            if ($doc['text'] !== '') {
                $fields .= '<div class="text">' . wpautop(esc_html($doc['text'])) . '</div>';
            }

            // Signature et formulaire seul : une fois fait, c'est figé.
            if ($answer && in_array($doc['kind'], array('signature', 'form'), true)) {
                $done = $doc['kind'] === 'signature'
                    ? sprintf(__('Signée le %1$s par %2$s.', $d), mysql2date('j F Y à H\hi', $answer['signed_at']), $answer['signer_name'])
                    : sprintf(__('Envoyé le %s, merci.', $d), mysql2date('j F Y à H\hi', $answer['signed_at']));
                $fields .= '<p class="done">' . esc_html($done) . '</p></section>';
                continue;
            }

            // Champs du formulaire joint : avant la signature ou la réponse (une question oui / non déjà répondue les a déjà).
            if ($form && !($answer && $doc['kind'] === 'consent')) {
                $with_forms = true;
                $multipart = $multipart || \Smbb\WpCodeTool\Forms\Frontend::hasFile($form);
                $fields .= \Smbb\WpCodeTool\Forms\Frontend::fieldsHtml($form, array(
                    'name_prefix' => 'doc[' . $doc['key'] . '][form]',
                    'file_prefix' => self::filePrefix($doc),
                    'id_prefix' => 'passflow-doc-' . $doc['key'],
                    'values' => isset($again['values']) ? $again['values'] : array(),
                    'errors' => isset($again['errors']) ? $again['errors'] : array(),
                ));
            }

            if ($doc['kind'] === 'signature') {
                $todo++;
                $signer = isset($again['name']) ? $again['name'] : Lookup::personName($booking);
                $role = isset($again['role']) ? $again['role'] : 'participant';
                $fields .= '<label class="field">' . esc_html__('Nom du signataire', $d) . '<input type="text" name="' . $name . '[name]" value="' . esc_attr($signer) . '" required></label>'
                    . '<label class="field">' . esc_html__('En qualité de', $d) . '<select name="' . $name . '[role]"><option value="participant">' . esc_html__('Participant', $d) . '</option><option value="representant"' . selected($role, 'representant', false) . '>' . esc_html__('Représentant légal (participant mineur)', $d) . '</option></select></label>'
                    . '<p class="label">' . esc_html__('Signez dans le cadre', $d) . ' <button type="button" class="link" data-clear>' . esc_html__('Effacer', $d) . '</button></p>'
                    . '<canvas class="pad" width="600" height="220" data-pad></canvas><input type="hidden" name="' . $name . '[signature]" value="' . esc_attr(isset($again['signature']) ? $again['signature'] : '') . '" data-pad-value>'
                    . '<label class="check"><input type="checkbox" name="' . $name . '[accept]" value="1" required' . (!empty($again['accept']) ? ' checked' : '') . '> ' . esc_html__("J'ai lu ce document et je l'accepte.", $d) . '</label>';
            } elseif ($doc['kind'] === 'form') {
                $todo++;
                $fields .= '<input type="hidden" name="' . $name . '[send]" value="1">';
            } else {
                $current = isset($again['answer']) ? $again['answer'] : ($answer ? $answer['answer'] : '');

                if (!$answer) {
                    $todo++;
                }

                $fields .= '<div class="choice">'
                    . '<label><input type="radio" name="' . $name . '[answer]" value="yes"' . checked($current, 'yes', false) . ($doc['required'] ? ' required' : '') . '> ' . esc_html__('Oui, j\'accepte', $d) . '</label>'
                    . '<label><input type="radio" name="' . $name . '[answer]" value="no"' . checked($current, 'no', false) . '> ' . esc_html__('Non', $d) . '</label>'
                    . '</div>'
                    . ($answer ? '<p class="done">' . esc_html(sprintf(__('Réponse du %s, modifiable.', $d), mysql2date('j F Y', $answer['signed_at']))) . '</p>' : '')
                    . ($doc['gdpr'] ? '<p class="hint">' . esc_html__('Facultatif et modifiable à tout moment : votre réservation et les messages urgents qui la concernent n\'en dépendent pas.', $d) . '</p>' : '');
            }

            $fields .= '</section>';
        }

        $body .= '<form method="post" id="passflow-docs"' . ($multipart ? ' enctype="multipart/form-data"' : '') . ' data-sending="' . esc_attr__('Envoi en cours…', $d) . '">' . wp_nonce_field(self::SUBMIT . '_' . $id, '_passflow_nonce', false, false) . $fields;

        // Tout est rempli : plus de bouton, sauf pour changer une réponse oui / non.
        $editable = array_filter($docs, function ($doc) {
            return $doc['kind'] === 'consent';
        });

        if ($todo > 0) {
            $body .= '<button type="submit" class="primary">' . esc_html__('Enregistrer', $d) . '</button>';
        } elseif ($editable) {
            $body .= '<p class="done">' . esc_html__('Tout est rempli, merci.', $d) . '</p><button type="submit" class="secondary">' . esc_html__('Modifier mes réponses', $d) . '</button>';
        } else {
            $body .= '<p class="done">' . esc_html__('Tout est signé, merci.', $d) . '</p>';
        }

        $body .= '</form>';

        if ($framed) {
            $body .= self::frameScript($row_id, $answers, $saved, $saved_ok);
        } elseif ($back !== '') {
            $body .= '<p class="links"><a href="' . esc_url($back) . '">' . esc_html__("← Retour à l'appel", $d) . '</a></p>';
        } elseif (function_exists('wc_get_account_endpoint_url')) {
            // Client : retour à son compte (Mes ateliers), qui demande la connexion si besoin.
            $body .= '<p class="links"><a class="back" href="' . esc_url(wc_get_account_endpoint_url(\Smbb\Passflow\WooCommerce\Account::ENDPOINT)) . '">' . esc_html__('← Retour à mon compte', $d) . '</a></p>';
        } else {
            $body .= '<p class="links"><a class="back" href="' . esc_url(home_url('/')) . '">' . esc_html__('← Retour au site', $d) . '</a></p>';
        }

        $body .= self::padScript();

        self::render($todo ? __('Documents à remplir', $d) : __('Documents', $d), $body, $with_forms);
    }

    /**
     * Nom des champs fichier d'un document : pf_doc_<clé>_<champ>[] (hors du tableau doc[], comme $_FILES l'aime).
     */
    private static function filePrefix(array $doc)
    {
        return 'pf_doc_' . str_replace('-', '_', $doc['key']) . '__';
    }

    /**
     * @param array<string,mixed>                $booking
     * @param array<string,array<string,mixed>>  $docs
     * @return array{success:bool,message:string,state:array}
     */
    private static function save(array $booking, array $docs, $onsite)
    {
        global $wpdb;

        $d = 'smbb-passflow';
        $posted = isset($_POST['doc']) && is_array($_POST['doc']) ? wp_unslash($_POST['doc']) : array();
        $answers = self::answersFor($booking['id']);
        $saved = 0;
        $errors = array();
        $state = array();
        $lost_files = false;

        foreach ($docs as $doc) {
            $in = isset($posted[$doc['key']]) && is_array($posted[$doc['key']]) ? $posted[$doc['key']] : array();
            $previous = isset($answers[$doc['key']]) ? $answers[$doc['key']] : null;
            $form = self::form($doc);
            $row = null;

            if ($doc['kind'] !== 'consent' && $previous) {
                continue;
            }

            if (!$in) {
                continue;
            }

            // Formulaire joint : contrôlé avec le document (une question oui / non déjà répondue l'a déjà).
            $checked = null;

            if ($form && !($previous && $doc['kind'] === 'consent')) {
                $checked = \Smbb\WpCodeTool\Forms\Submission::validate($form, isset($in['form']) && is_array($in['form']) ? $in['form'] : array(), array(
                    'file_prefix' => self::filePrefix($doc),
                    'page_url' => self::url($booking),
                ));
            }

            $form_errors = $checked && $checked['errors'];

            if ($doc['kind'] === 'signature') {
                $signature = isset($in['signature']) ? (string) $in['signature'] : '';
                $name = isset($in['name']) ? sanitize_text_field($in['name']) : '';
                $role = isset($in['role']) && $in['role'] === 'representant' ? 'representant' : 'participant';
                $pad_ok = strpos($signature, 'data:image/png;base64,') === 0 && strlen($signature) >= 200 && strlen($signature) <= 400000;

                if (empty($in['accept']) || $name === '' || !$pad_ok || $form_errors) {
                    $errors[] = $form_errors
                        ? sprintf(__('%s : quelques réponses sont à revoir.', $d), $doc['title'])
                        : sprintf(__('%s : nom, signature et case « J\'accepte » sont nécessaires.', $d), $doc['title']);
                    $state[$doc['key']] = array(
                        'name' => $name,
                        'role' => $role,
                        'accept' => !empty($in['accept']),
                        'signature' => $pad_ok ? $signature : '',
                        'values' => $checked ? $checked['values'] : array(),
                        'errors' => $checked ? $checked['errors'] : array(),
                    );
                    $lost_files = $lost_files || ($checked && array_filter($checked['files']));
                    continue;
                }

                $row = array('answer' => 'signed', 'signer_name' => $name, 'signer_role' => $role, 'signature' => $signature);
            } elseif ($doc['kind'] === 'form') {
                // Rien de rempli : le formulaire reste à faire, sans erreur (on peut signer le reste avant).
                if ($checked && !array_filter($checked['values'], array(__CLASS__, 'filled')) && !array_filter($checked['files'])) {
                    continue;
                }

                if (!$checked || $form_errors) {
                    $errors[] = sprintf(__('%s : quelques réponses sont à revoir.', $d), $doc['title']);
                    $state[$doc['key']] = array('values' => $checked ? $checked['values'] : array(), 'errors' => $checked ? $checked['errors'] : array());
                    $lost_files = $lost_files || ($checked && array_filter($checked['files']));
                    continue;
                }

                $row = array('answer' => 'sent', 'signer_name' => Lookup::personName($booking), 'signer_role' => 'participant', 'signature' => null);
            } else {
                $value = isset($in['answer']) ? sanitize_key($in['answer']) : '';

                if (!in_array($value, array('yes', 'no'), true) || ($previous && $previous['answer'] === $value)) {
                    // Pas de réponse oui / non : le formulaire rempli attend avec le reste.
                    if ($checked && !$previous && ($form_errors || array_filter($checked['values']) || array_filter($checked['files']))) {
                        $state[$doc['key']] = array('values' => $checked['values'], 'errors' => $checked['errors']);
                    }

                    continue;
                }

                if ($form_errors) {
                    $errors[] = sprintf(__('%s : quelques réponses sont à revoir.', $d), $doc['title']);
                    $state[$doc['key']] = array('answer' => $value, 'values' => $checked['values'], 'errors' => $checked['errors']);
                    $lost_files = $lost_files || array_filter($checked['files']);
                    continue;
                }

                $row = array('answer' => $value, 'signer_name' => Lookup::personName($booking), 'signer_role' => 'participant', 'signature' => null);
            }

            // Réponses du formulaire : une entrée CodeTool › Formulaires, rattachée à la réservation.
            $entry_id = $previous ? (int) $previous['entry_id'] : 0;
            $entry_fields = array();

            if ($checked) {
                $stored = \Smbb\WpCodeTool\Forms\Submission::store($form, $checked, array(
                    'page_url' => self::url($booking),
                    'source' => self::SOURCE,
                    'source_ref' => 'booking:' . (int) $booking['id'] . ':' . $doc['key'],
                    'status' => 'read',
                    'force_store' => true,
                    'notify' => $doc['form_notify'],
                    'context' => array('booking' => $booking),
                ));
                $entry_id = (int) $stored['entry_id'];
                $entry_fields = $stored['fields'];
            } elseif ($entry_id) {
                $entry = self::entry($previous);
                $entry_fields = $entry ? $entry['fields'] : array();
            }

            $wpdb->insert(Lookup::table('passflow_document'), array_merge($row, array(
                'booking_id' => (int) $booking['id'],
                'doc_key' => $doc['key'],
                'doc_title' => $doc['title'],
                'doc_hash' => self::hash($doc, $entry_fields),
                'doc_text' => $doc['text'],
                'form_id' => $form ? (int) $form['id'] : 0,
                'entry_id' => $entry_id,
                'method' => $onsite ? 'onsite' : 'online',
                'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
                'user_id' => get_current_user_id() ?: null,
                'signed_at' => current_time('mysql'),
            )));
            History::record((int) $booking['id'], 'document', sprintf(__('%1$s : %2$s', $d), $doc['title'], self::status($doc, $row)['label']) . ($onsite ? ' ' . __('(sur place)', $d) : ''), $booking['slot_id'] ? (int) $booking['slot_id'] : null);
            $saved++;
        }

        if ($errors) {
            if ($lost_files) {
                $errors[] = __('Les fichiers choisis sont à joindre de nouveau.', $d);
            }

            return array('success' => false, 'message' => implode(' ', $errors), 'state' => $state);
        }

        return array('success' => true, 'message' => $saved ? __('Merci, c\'est enregistré.', $d) : __('Rien à enregistrer.', $d), 'state' => $state);
    }

    /**
     * Réponse non vide (texte, choix, case cochée).
     */
    private static function filled($value)
    {
        return is_array($value) ? (bool) array_filter($value, 'strlen') : trim((string) $value) !== '' && (string) $value !== '0';
    }

    /**
     * Empreinte de ce qui est signé : titre, texte, et réponses du formulaire joint (sans l'emplacement des fichiers).
     *
     * @param array<string,mixed> $doc
     * @param array<int,array<string,mixed>> $fields champs de l'entrée
     */
    private static function hash(array $doc, array $fields)
    {
        $base = $doc['title'] . "\n" . $doc['text'];

        if (!$fields) {
            return hash('sha256', $base);
        }

        $answers = array();

        foreach ($fields as $field) {
            $value = $field['value'] ?? '';

            if (($field['type'] ?? '') === 'file') {
                $value = array_map(static function ($file) {
                    return is_array($file) ? ($file['name'] ?? '') . '|' . (int) ($file['size'] ?? 0) : '';
                }, (array) $value);
            }

            $answers[] = array($field['key'] ?? '', $field['label'] ?? '', $value);
        }

        return hash('sha256', $base . "\n" . wp_json_encode($answers, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Page dans la lightbox (sign-frame.js) : prévient l'écran parent, même origine seulement,
     * de ce qui reste à faire et des badges à jour de la ligne.
     *
     * @param array<string,array<string,mixed>> $answers
     */
    private static function frameScript($row_id, array $answers, $saved, $ok)
    {
        $missing = self::missing($answers);
        $data = array(
            'type' => 'passflow-docs',
            'id' => (int) $row_id,
            'missing' => count($missing),
            'titles' => array_values(array_map(function ($doc) {
                return $doc['title'];
            }, $missing)),
            'badges' => self::badges($answers),
            'saved' => (bool) $saved,
            'ok' => (bool) $ok,
        );

        return '<script>(function(){try{if(window.parent&&window.parent!==window){window.parent.postMessage(' . wp_json_encode($data) . ',location.origin);}}catch(e){}})();</script>';
    }

    /**
     * Lightbox « Faire signer » sur un écran de l'équipe (appel, Jour J) : les liens sur place s'y ouvrent.
     */
    public static function enqueueFrame()
    {
        $d = 'smbb-passflow';
        $path = SMBB_PASSFLOW_PATH . 'assets/sign-frame.';

        wp_enqueue_style('passflow-sign-frame', SMBB_PASSFLOW_URL . 'assets/sign-frame.css', array(), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime($path . 'css'));
        wp_enqueue_script('passflow-sign-frame', SMBB_PASSFLOW_URL . 'assets/sign-frame.js', array(), SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime($path . 'js'), true);
        wp_add_inline_script('passflow-sign-frame', 'window.passflowSignFrameTexts=' . wp_json_encode(array(
            'title' => __('Documents', $d),
            'close' => __('Fermer', $d),
            'done' => __('Tout est signé, merci.', $d),
        )) . ';', 'before');
    }

    private static function padScript()
    {
        // Signature au doigt ou à la souris, enregistrée en PNG dans le champ caché.
        return '<script>(function(){document.querySelectorAll("[data-pad]").forEach(function(c){var x=c.getContext("2d"),down=false,drawn=false,last=null,input=c.nextElementSibling;'
            . 'x.lineWidth=3;x.lineCap="round";x.lineJoin="round";x.strokeStyle="#1d2327";'
            . 'function pos(e){var r=c.getBoundingClientRect();return{x:(e.clientX-r.left)*c.width/r.width,y:(e.clientY-r.top)*c.height/r.height};}'
            . 'c.addEventListener("pointerdown",function(e){down=true;last=pos(e);c.setPointerCapture(e.pointerId);e.preventDefault();});'
            . 'c.addEventListener("pointermove",function(e){if(!down)return;var p=pos(e);x.beginPath();x.moveTo(last.x,last.y);x.lineTo(p.x,p.y);x.stroke();last=p;drawn=true;e.preventDefault();});'
            . '["pointerup","pointercancel","pointerleave"].forEach(function(t){c.addEventListener(t,function(){if(down&&drawn){input.value=c.toDataURL("image/png");}down=false;});});'
            // Envoi refusé (champ du formulaire à revoir) : la signature déjà faite est redessinée.
            . 'if(input.value){var im=new Image();im.onload=function(){x.drawImage(im,0,0,c.width,c.height);drawn=true;};im.src=input.value;}'
            . 'var clear=c.parentNode.querySelector("[data-clear]");var s=c.previousElementSibling;if(s&&s.querySelector("[data-clear]")){clear=s.querySelector("[data-clear]");}'
            . 'if(clear)clear.addEventListener("click",function(){x.clearRect(0,0,c.width,c.height);input.value="";drawn=false;});});'
            . 'var f=document.getElementById("passflow-docs");if(f)f.addEventListener("submit",function(e){var miss=false;f.querySelectorAll("[data-pad-value]").forEach(function(i){if(!i.value)miss=true;});if(miss){e.preventDefault();alert(' . wp_json_encode(__('Merci de signer dans le cadre.', 'smbb-passflow')) . ');return;}'
            // Envoi (photos sur réseau mobile) : bouton bloqué avec « Envoi en cours… », débloqué au retour arrière.
            . 'if(f.dataset.busy){e.preventDefault();return;}f.dataset.busy="1";var b=f.querySelector("button[type=submit]");if(b){b.dataset.label=b.textContent;b.textContent=f.getAttribute("data-sending");b.disabled=true;b.classList.add("busy");}'
            . 'window.addEventListener("pageshow",function(){delete f.dataset.busy;if(b){b.disabled=false;b.classList.remove("busy");b.textContent=b.dataset.label;}},{once:true});});})();</script>';
    }

    public static function render($title, $body, $forms = false)
    {
        // Formulaire CodeTool joint : son rendu (forms.css) et sa logique conditionnelle (forms.js), page sans thème.
        $assets = '';

        if ($forms && defined('SMBB_WPCODETOOL_URL')) {
            $assets = '<link rel="stylesheet" href="' . esc_url(SMBB_WPCODETOOL_URL . 'assets/forms.css?ver=' . rawurlencode(SMBB_WPCODETOOL_VERSION)) . '">'
                . '<script src="' . esc_url(SMBB_WPCODETOOL_URL . 'assets/forms.js?ver=' . rawurlencode(SMBB_WPCODETOOL_VERSION)) . '" defer></script>';
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . esc_html($title) . '</title><style>'
            . 'body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;background:#f6f3ee;color:#2b2420}'
            . 'main{max-width:640px;margin:0 auto;padding:24px 16px 48px}h1{font-size:15px;text-transform:uppercase;letter-spacing:.06em;opacity:.6;margin:0 0 12px}'
            . '.who{margin:0 0 16px}.doc{background:#fff;border-radius:14px;padding:16px;margin:0 0 14px;box-shadow:0 1px 2px rgba(0,0,0,.06)}.doc h2{font-size:18px;margin:0 0 8px}'
            . '.text{max-height:260px;overflow:auto;font-size:14px;background:#faf8f5;border:1px solid #eee5da;border-radius:8px;padding:4px 12px;margin:0 0 12px}'
            . '.field{display:block;font-size:13px;opacity:.85;margin:0 0 10px}.field input,.field select{display:block;width:100%;box-sizing:border-box;font-size:16px;padding:10px;border:1px solid #d6cfc6;border-radius:8px;margin-top:4px;background:#fff}'
            . '.label{font-size:13px;margin:4px 0}.pad{width:100%;height:auto;aspect-ratio:600/220;border:2px dashed #c9bfb3;border-radius:10px;background:#fff;touch-action:none;display:block}'
            . '.link{background:none;border:0;color:#9b4a1c;text-decoration:underline;font-size:13px;padding:0;cursor:pointer}'
            . '.check,.choice label{display:flex;gap:10px;align-items:center;margin:12px 0 0;font-size:16px}.check input,.choice input{width:22px;height:22px}.choice{display:flex;gap:24px}'
            . '.done{color:#1e6b37;font-weight:600;margin:8px 0 0}.hint{font-size:13px;opacity:.75;margin:8px 0 0}.notice{padding:10px 12px;border-radius:8px}.notice.ok{background:#dff1e4}.notice.error{background:#fbe4e4}.notice.warn{background:#fff1d6}'
            . 'button.primary{width:100%;font-size:18px;padding:16px;border-radius:12px;border:0;background:#1e6b37;color:#fff;font-weight:700}button.secondary{width:100%;font-size:16px;padding:12px;border-radius:12px;border:1px solid #c9c2b8;background:#fff;color:#2b2420}.links a{color:#2b2420}.links a.back{display:block;text-align:center;font-size:16px;padding:12px;border-radius:12px;border:1px solid #c9c2b8;background:#fff;text-decoration:none;margin-top:12px}'
            . '.doc .smbb-form{margin:0 0 14px;--smbb-form-accent:#1e6b37;--smbb-form-control-height:48px}.doc .smbb-form input,.doc .smbb-form select,.doc .smbb-form textarea{font-size:16px}'
            . 'button.primary.busy{opacity:.7}'
            . '</style>' . $assets . '</head><body><main><h1>' . esc_html($title) . '</h1>' . $body . '</main></body></html>'; // phpcs:ignore -- échappé ci-dessus.
        exit;
    }
}
