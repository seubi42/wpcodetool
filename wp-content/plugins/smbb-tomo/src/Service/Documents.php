<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Documents des participants, réglés dans le menu Documents :
 * - « signature » : un texte à lire et signer au doigt (décharge de responsabilité…) ;
 * - « accord » : une question oui / non (droit à l'image…).
 *
 * Chaque participant reçoit un lien signé (e-mail de confirmation, rappel, Mon compte) vers une page
 * où il remplit tout. Ce qui manque se fait sur place, depuis la gestion mobile, sur le téléphone de
 * l'intervenant. Chaque réponse est une ligne de tomo_document (texte signé, empreinte, date, IP,
 * signature) : la dernière réponse d'un document fait foi.
 */
final class Documents
{
    const QUERY = 'tomo_documents';
    const SUBMIT = 'tomo_documents_submit';
    const KINDS = array('signature', 'consent');

    public static function hooks()
    {
        add_action('template_redirect', array(__CLASS__, 'page'));
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
            );
        }

        return $docs;
    }

    public static function enabled()
    {
        return (bool) self::all();
    }

    public static function kinds()
    {
        return array(
            'signature' => __('À lire et signer (décharge…)', 'smbb-tomo'),
            'consent' => __('Question oui / non (droit à l\'image…)', 'smbb-tomo'),
        );
    }

    // ------------------------------------------------------------------
    // Réponses
    // ------------------------------------------------------------------

    /**
     * Dernière réponse de chaque document pour des inscriptions : array(inscription_id => array(doc_key => row)).
     *
     * @param int[] $inscription_ids
     * @return array<int,array<string,array<string,mixed>>>
     */
    public static function answers(array $inscription_ids)
    {
        global $wpdb;

        $ids = array_filter(array_map('intval', $inscription_ids));

        if (!$ids || !\Smbb\Tomo\Support\Schema::ready()) {
            return array();
        }

        // Jours d'un pass : ils reprennent les réponses de la réservation principale.
        $mothers = array();
        $links = $wpdb->get_results('SELECT id, pass_id FROM ' . Lookup::table('tomo_inscription') . ' WHERE pass_id > 0 AND id IN (' . implode(',', $ids) . ')', ARRAY_A);

        foreach (is_array($links) ? $links : array() as $link) {
            $mothers[(int) $link['id']] = (int) $link['pass_id'];
        }

        $all = array_unique(array_merge($ids, array_values($mothers)));
        $rows = $wpdb->get_results('SELECT * FROM ' . Lookup::table('tomo_document') . ' WHERE deleted_flag = 0 AND inscription_id IN (' . implode(',', $all) . ') ORDER BY id ASC', ARRAY_A);
        $answers = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $answers[(int) $row['inscription_id']][$row['doc_key']] = $row;
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
    public static function answersFor($inscription_id)
    {
        $all = self::answers(array((int) $inscription_id));

        return isset($all[(int) $inscription_id]) ? $all[(int) $inscription_id] : array();
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
        $d = 'smbb-tomo';

        if (!$answer) {
            return array('label' => $doc['kind'] === 'signature' ? __('À signer', $d) : __('Sans réponse', $d), 'state' => 'todo');
        }

        if ($answer['answer'] === 'signed') {
            return array('label' => __('Signée', $d), 'state' => 'ok');
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
            $html[] = '<span class="tomo-badge tomo-badge--' . $class . '" title="' . esc_attr($doc['title']) . '">' . esc_html($doc['title'] . ' : ' . mb_strtolower($status['label'])) . '</span>';
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

    public static function token(array $inscription)
    {
        return substr(hash_hmac('sha256', 'documents|' . (int) $inscription['id'], wp_salt('auth')), 0, 24);
    }

    /**
     * Jeton reçu valable ? L'ancien calcul (avec l'e-mail, jusqu'à 0.13.0) reste accepté pour les liens déjà envoyés.
     */
    public static function valid(array $inscription, $token)
    {
        $legacy = substr(hash_hmac('sha256', 'documents|' . (int) $inscription['id'] . '|' . strtolower((string) $inscription['email']), wp_salt('auth')), 0, 24);

        return is_string($token) && $token !== '' && (hash_equals(self::token($inscription), $token) || hash_equals($legacy, $token));
    }

    /**
     * Lien vers la page des documents. $onsite : rempli sur place, sur l'appareil de l'équipe.
     */
    public static function url(array $inscription, $onsite = false)
    {
        $args = array(self::QUERY => (int) $inscription['id'], 'k' => self::token($inscription));

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
    public static function requests(array $inscription)
    {
        $id = (int) $inscription['id'];

        return array_values(array_filter(\Smbb\Tomo\Repository\Envois::forInscription($id, 200), function ($row) use ($id) {
            return (bool) preg_match('/' . self::QUERY . '=' . $id . '(?!\d)/', (string) $row['body']);
        }));
    }

    /**
     * Dernière ouverture du lien par le participant (hors « sur place »), ou ''.
     */
    public static function lastView($inscription_id)
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(created_at) FROM ' . Lookup::table('tomo_historique') . " WHERE inscription_id = %d AND action = 'document_view'",
            (int) $inscription_id
        ));
    }

    /**
     * Lien pour les e-mails : seulement s'il reste quelque chose à remplir.
     */
    public static function pendingUrl(array $inscription)
    {
        if (!self::enabled() || empty($inscription['id']) || !in_array($inscription['status'], array('pending', 'confirmed'), true)) {
            return '';
        }

        return self::missing(self::answersFor($inscription['id'])) ? self::url($inscription) : '';
    }

    public static function page()
    {
        if (empty($_GET[self::QUERY])) {
            return;
        }

        $d = 'smbb-tomo';
        $id = absint($_GET[self::QUERY]);
        $token = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $inscription = Inscriptions::find($id);

        if (!$inscription || !self::valid($inscription, $token)) {
            self::render(__('Lien invalide', $d), '<p>' . esc_html__('Ce lien ne correspond à aucune réservation. Utilisez le lien reçu par e-mail ou votre espace client.', $d) . '</p>');
        }

        $onsite = !empty($_GET['sur_place']) && is_user_logged_in() && \Smbb\Tomo\Admin\Actions::canSeeCreneau($inscription['creneau_id']);

        // Jour d'un pass : les documents sont signés une seule fois, sur la réservation principale.
        if (!empty($inscription['pass_id'])) {
            $inscription = \Smbb\Tomo\Service\Passes::root($inscription);
            $id = (int) $inscription['id'];
        }

        $docs = self::all();
        $message = '';

        if (!$docs) {
            self::render(__('Documents', $d), '<p>' . esc_html__("Aucun document n'est demandé pour le moment.", $d) . '</p>');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_tomo_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_tomo_nonce'])), self::SUBMIT . '_' . $id)) {
            $result = self::save($inscription, $docs, $onsite);
            $message = '<p class="notice ' . ($result['success'] ? 'ok' : 'error') . '">' . esc_html($result['message']) . '</p>';
        }

        // Ouverture du lien par le participant (pas par l'équipe) : notée une fois par jour pour la fiche de l'inscription.
        $staff = is_user_logged_in() && \Smbb\Tomo\Admin\Actions::canSeeCreneau($inscription['creneau_id']);

        if (!$onsite && !$staff && $_SERVER['REQUEST_METHOD'] !== 'POST' && substr(self::lastView($id), 0, 10) !== current_time('Y-m-d')) {
            History::record($id, 'document_view', __('Lien des documents ouvert par le participant.', $d), $inscription['creneau_id'] ? (int) $inscription['creneau_id'] : null);
        }

        $answers = self::answersFor($id);
        $creneau = $inscription['creneau_id'] ? Creneaux::find($inscription['creneau_id']) : null;
        $back = $onsite ? admin_url('admin.php?page=smbb-codetool-tomo_espace&creneau=' . (int) $inscription['creneau_id']) : '';

        $body = $message
            . '<p class="who"><strong>' . esc_html(Lookup::personName($inscription)) . '</strong><br>'
            . esc_html(Lookup::atelierName($inscription['atelier_id']) . ($creneau ? ' · ' . Lookup::creneauWhen($creneau) : '')) . '</p>';

        if ($onsite) {
            $note = trim((string) Settings::get('documents_onsite_note'));
            $body .= $note !== '' ? '<p class="notice warn">' . esc_html($note) . '</p>' : '';
        }

        $body .= '<form method="post" id="tomo-docs">' . wp_nonce_field(self::SUBMIT . '_' . $id, '_tomo_nonce', false, false);
        $todo = 0;

        foreach ($docs as $doc) {
            $answer = isset($answers[$doc['key']]) ? $answers[$doc['key']] : null;
            $name = 'doc[' . esc_attr($doc['key']) . ']';
            $body .= '<section class="doc"><h2>' . esc_html($doc['title']) . '</h2>';

            if ($doc['text'] !== '') {
                $body .= '<div class="text">' . wpautop(esc_html($doc['text'])) . '</div>';
            }

            if ($doc['kind'] === 'signature') {
                if ($answer) {
                    $body .= '<p class="done">' . esc_html(sprintf(__('Signée le %1$s par %2$s.', $d), mysql2date('j F Y à H\hi', $answer['signed_at']), $answer['signer_name'])) . '</p>';
                    $body .= '</section>';
                    continue;
                }

                $todo++;
                $body .= '<label class="field">' . esc_html__('Nom du signataire', $d) . '<input type="text" name="' . $name . '[name]" value="' . esc_attr(Lookup::personName($inscription)) . '" required></label>'
                    . '<label class="field">' . esc_html__('En qualité de', $d) . '<select name="' . $name . '[role]"><option value="participant">' . esc_html__('Participant', $d) . '</option><option value="representant">' . esc_html__('Représentant légal (participant mineur)', $d) . '</option></select></label>'
                    . '<p class="label">' . esc_html__('Signez dans le cadre', $d) . ' <button type="button" class="link" data-clear>' . esc_html__('Effacer', $d) . '</button></p>'
                    . '<canvas class="pad" width="600" height="220" data-pad></canvas><input type="hidden" name="' . $name . '[signature]" data-pad-value>'
                    . '<label class="check"><input type="checkbox" name="' . $name . '[accept]" value="1" required> ' . esc_html__("J'ai lu ce document et je l'accepte.", $d) . '</label>';
            } else {
                $current = $answer ? $answer['answer'] : '';

                if (!$answer) {
                    $todo++;
                }

                $body .= '<div class="choice">'
                    . '<label><input type="radio" name="' . $name . '[answer]" value="yes"' . checked($current, 'yes', false) . ($doc['required'] ? ' required' : '') . '> ' . esc_html__('Oui, j\'accepte', $d) . '</label>'
                    . '<label><input type="radio" name="' . $name . '[answer]" value="no"' . checked($current, 'no', false) . '> ' . esc_html__('Non', $d) . '</label>'
                    . '</div>'
                    . ($answer ? '<p class="done">' . esc_html(sprintf(__('Réponse du %s, modifiable.', $d), mysql2date('j F Y', $answer['signed_at']))) . '</p>' : '');
            }

            $body .= '</section>';
        }

        // Tout est rempli : plus de bouton, sauf pour changer une réponse oui / non.
        $editable = array_filter($docs, function ($doc) {
            return $doc['kind'] !== 'signature';
        });

        if ($todo > 0) {
            $body .= '<button type="submit" class="primary">' . esc_html__('Enregistrer', $d) . '</button>';
        } elseif ($editable) {
            $body .= '<p class="done">' . esc_html__('Tout est rempli, merci.', $d) . '</p><button type="submit" class="secondary">' . esc_html__('Modifier mes réponses', $d) . '</button>';
        } else {
            $body .= '<p class="done">' . esc_html__('Tout est signé, merci.', $d) . '</p>';
        }

        $body .= '</form>';

        if ($back !== '') {
            $body .= '<p class="links"><a href="' . esc_url($back) . '">' . esc_html__("← Retour à l'appel", $d) . '</a></p>';
        } elseif (function_exists('wc_get_account_endpoint_url')) {
            // Client : retour à son compte (Mes ateliers), qui demande la connexion si besoin.
            $body .= '<p class="links"><a class="back" href="' . esc_url(wc_get_account_endpoint_url(\Smbb\Tomo\WooCommerce\Account::ENDPOINT)) . '">' . esc_html__('← Retour à mon compte', $d) . '</a></p>';
        } else {
            $body .= '<p class="links"><a class="back" href="' . esc_url(home_url('/')) . '">' . esc_html__('← Retour au site', $d) . '</a></p>';
        }

        $body .= self::padScript();

        self::render($todo ? __('Documents à remplir', $d) : __('Documents', $d), $body);
    }

    /**
     * @param array<string,mixed>                $inscription
     * @param array<string,array<string,mixed>>  $docs
     * @return array{success:bool,message:string}
     */
    private static function save(array $inscription, array $docs, $onsite)
    {
        global $wpdb;

        $d = 'smbb-tomo';
        $posted = isset($_POST['doc']) && is_array($_POST['doc']) ? wp_unslash($_POST['doc']) : array();
        $answers = self::answersFor($inscription['id']);
        $saved = 0;
        $errors = array();

        foreach ($docs as $doc) {
            $in = isset($posted[$doc['key']]) && is_array($posted[$doc['key']]) ? $posted[$doc['key']] : array();
            $previous = isset($answers[$doc['key']]) ? $answers[$doc['key']] : null;
            $row = null;

            if ($doc['kind'] === 'signature') {
                if ($previous || !$in) {
                    continue;
                }

                $signature = isset($in['signature']) ? (string) $in['signature'] : '';
                $name = isset($in['name']) ? sanitize_text_field($in['name']) : '';

                if (empty($in['accept']) || $name === '' || strpos($signature, 'data:image/png;base64,') !== 0 || strlen($signature) < 200 || strlen($signature) > 400000) {
                    $errors[] = sprintf(__('%s : nom, signature et case « J\'accepte » sont nécessaires.', $d), $doc['title']);
                    continue;
                }

                $row = array('answer' => 'signed', 'signer_name' => $name, 'signer_role' => isset($in['role']) && $in['role'] === 'representant' ? 'representant' : 'participant', 'signature' => $signature);
            } else {
                $value = isset($in['answer']) ? sanitize_key($in['answer']) : '';

                if (!in_array($value, array('yes', 'no'), true) || ($previous && $previous['answer'] === $value)) {
                    continue;
                }

                $row = array('answer' => $value, 'signer_name' => Lookup::personName($inscription), 'signer_role' => 'participant', 'signature' => null);
            }

            $wpdb->insert(Lookup::table('tomo_document'), array_merge($row, array(
                'inscription_id' => (int) $inscription['id'],
                'doc_key' => $doc['key'],
                'doc_title' => $doc['title'],
                'doc_hash' => hash('sha256', $doc['title'] . "\n" . $doc['text']),
                'doc_text' => $doc['text'],
                'method' => $onsite ? 'onsite' : 'online',
                'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
                'user_id' => get_current_user_id() ?: null,
                'signed_at' => current_time('mysql'),
            )));
            History::record((int) $inscription['id'], 'document', sprintf(__('%1$s : %2$s', $d), $doc['title'], self::status($doc, $row)['label']) . ($onsite ? ' ' . __('(sur place)', $d) : ''), $inscription['creneau_id'] ? (int) $inscription['creneau_id'] : null);
            $saved++;
        }

        if ($errors) {
            return array('success' => false, 'message' => implode(' ', $errors));
        }

        return array('success' => true, 'message' => $saved ? __('Merci, c\'est enregistré.', $d) : __('Rien à enregistrer.', $d));
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
            . 'var clear=c.parentNode.querySelector("[data-clear]");var s=c.previousElementSibling;if(s&&s.querySelector("[data-clear]")){clear=s.querySelector("[data-clear]");}'
            . 'if(clear)clear.addEventListener("click",function(){x.clearRect(0,0,c.width,c.height);input.value="";drawn=false;});});'
            . 'var f=document.getElementById("tomo-docs");if(f)f.addEventListener("submit",function(e){var miss=false;f.querySelectorAll("[data-pad-value]").forEach(function(i){if(!i.value)miss=true;});if(miss){e.preventDefault();alert(' . wp_json_encode(__('Merci de signer dans le cadre.', 'smbb-tomo')) . ');}});})();</script>';
    }

    private static function render($title, $body)
    {
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
            . '.done{color:#1e6b37;font-weight:600;margin:8px 0 0}.notice{padding:10px 12px;border-radius:8px}.notice.ok{background:#dff1e4}.notice.error{background:#fbe4e4}.notice.warn{background:#fff1d6}'
            . 'button.primary{width:100%;font-size:18px;padding:16px;border-radius:12px;border:0;background:#1e6b37;color:#fff;font-weight:700}button.secondary{width:100%;font-size:16px;padding:12px;border-radius:12px;border:1px solid #c9c2b8;background:#fff;color:#2b2420}.links a{color:#2b2420}.links a.back{display:block;text-align:center;font-size:16px;padding:12px;border-radius:12px;border:1px solid #c9c2b8;background:#fff;text-decoration:none;margin-top:12px}'
            . '</style></head><body><main><h1>' . esc_html($title) . '</h1>' . $body . '</main></body></html>'; // phpcs:ignore -- échappé ci-dessus.
        exit;
    }
}
