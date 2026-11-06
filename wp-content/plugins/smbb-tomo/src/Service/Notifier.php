<?php

namespace Smbb\Tomo\Service;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Settings;
use Smbb\Tomo\Support\Templates;
use Smbb\WpCodeTool\Connector\SmbbTwilioSmsClient;

defined('ABSPATH') || exit;

/**
 * Envoi des e-mails (wp_mail) et SMS (Twilio) en réponse aux événements Tomo, avec journal.
 *
 * Les messages déclenchés par une action de l'équipe sont mis en file et partent à la fin de la
 * requête, après la réponse au navigateur quand le serveur le permet : l'écran ne attend pas Twilio.
 */
final class Notifier
{
    /** @var array<int,array<string,mixed>> */
    private static $queue = array();

    private static $shutdown = false;

    private static $muted = 0;

    /** @var string */
    private static $mail_error = '';

    public static function hooks()
    {
        add_action('smbb_tomo_inscription_confirmed', array(__CLASS__, 'onConfirmed'), 10, 1);
        add_action('smbb_tomo_inscription_moved', array(__CLASS__, 'onMoved'), 10, 4);
        add_action('smbb_tomo_inscription_cancelled', array(__CLASS__, 'onCancelled'), 10, 4);
        add_action('smbb_tomo_creneau_cancelled', array(__CLASS__, 'onCreneauCancelled'), 10, 3);
        add_action('smbb_tomo_waitlist_booked', array(__CLASS__, 'onWaitlistBooked'), 10, 2);
        add_action('smbb_tomo_intervenants_assigned', array(__CLASS__, 'onAssigned'), 10, 2);
        add_action('smbb_tomo_seat_freed', array(Waitlist::class, 'notifyNext'), 10, 1);
    }

    /**
     * Coupe les notifications le temps d'un traitement groupé (commande annulée, etc.).
     */
    public static function mute()
    {
        self::$muted++;
    }

    public static function unmute()
    {
        self::$muted = max(0, self::$muted - 1);
    }

    // ------------------------------------------------------------------
    // Événements
    // ------------------------------------------------------------------

    public static function onConfirmed($inscription_id)
    {
        $inscription = Inscriptions::find($inscription_id);
        $creneau = $inscription && $inscription['creneau_id'] ? Creneaux::find($inscription['creneau_id']) : null;

        if ($creneau) {
            self::toParticipant('confirmed', $inscription, $creneau);
        }
    }

    public static function onMoved($inscription_id, $from_id = 0, $to_id = 0, $confirmed = true)
    {
        unset($confirmed);
        $inscription = Inscriptions::find($inscription_id);
        $creneau = $to_id ? Creneaux::find($to_id) : null;

        if ($inscription && $creneau) {
            self::toParticipant('moved', $inscription, $creneau, array('old' => $from_id ? Creneaux::find($from_id) : null));
        }
    }

    public static function onCancelled($inscription_id, $status = 'cancelled', $old_creneau_id = 0, $reason = '')
    {
        $inscription = Inscriptions::find($inscription_id);

        if (!$inscription) {
            return;
        }

        $old = $old_creneau_id ? Creneaux::find($old_creneau_id) : null;
        self::toParticipant($status === 'pending' ? 'postponed' : 'cancelled', $inscription, null, array('old' => $old, 'motif' => $reason));
    }

    /**
     * @param array<int,int> $inscription_ids réservations retirées du créneau
     */
    public static function onCreneauCancelled($creneau_id, $reason = '', $inscription_ids = array())
    {
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau) {
            return;
        }

        foreach ((array) $inscription_ids as $id) {
            $inscription = Inscriptions::find($id);

            if ($inscription) {
                self::toParticipant('creneau_cancelled', $inscription, null, array('old' => $creneau, 'motif' => $reason));
            }
        }

        foreach (Lookup::ids($creneau['intervenant_ids']) as $intervenant_id) {
            self::toIntervenant('creneau_cancelled_intervenant', $intervenant_id, $creneau, array('motif' => $reason));
        }
    }

    public static function onWaitlistBooked($attente_id, $inscription_id)
    {
        unset($attente_id);
        self::onConfirmed($inscription_id);
    }

    /**
     * @param array<int,int> $intervenant_ids
     */
    public static function onAssigned($creneau_id, $intervenant_ids)
    {
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau || $creneau['status'] === 'cancelled' || Creneaux::isPast($creneau)) {
            return;
        }

        foreach ((array) $intervenant_ids as $intervenant_id) {
            self::toIntervenant('assigned', $intervenant_id, $creneau);
        }
    }

    // ------------------------------------------------------------------
    // Destinataires
    // ------------------------------------------------------------------

    /**
     * @param array|null $creneau créneau de la réservation (null si elle n'en a plus)
     * @param array<string,mixed> $extra old (créneau d'avant), motif
     * @param string $dedup clé anti-doublon (rappels)
     * @param array<int,string>|null $channels canaux forcés (renvoi manuel), sinon ceux des réglages
     * @return int nombre de messages programmés
     */
    public static function toParticipant($key, array $inscription, $creneau, array $extra = array(), $dedup = '', $channels = null)
    {
        $recipient = array(
            'type' => 'participant',
            'first_name' => $inscription['first_name'],
            'last_name' => $inscription['last_name'],
            'email' => $inscription['email'],
            'phone' => $inscription['phone'],
            'inscription_id' => (int) $inscription['id'],
            'creneau_id' => $creneau ? (int) $creneau['id'] : (!empty($extra['old']['id']) ? (int) $extra['old']['id'] : null),
            'atelier_id' => (int) $inscription['atelier_id'],
        );

        return self::dispatch($key, $recipient, self::vars($creneau, $recipient, $extra), $dedup, $channels);
    }

    /**
     * Message à une personne hors inscription (client d'une vente sur place…), envoyé tout de suite.
     *
     * @param array{first_name:string,last_name:string,email:string,phone:string} $person
     * @param array<string,string> $extra_vars variables propres au message
     * @return array<int,array{channel:string,to:string,status:string,error:string}>
     */
    public static function toCustomer($key, array $person, $creneau, array $extra_vars = array(), $channels = array('email', 'sms'), array $mail = array())
    {
        $recipient = array_merge(array('first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''), $person, array(
            'type' => 'participant',
            'creneau_id' => $creneau ? (int) $creneau['id'] : null,
            'atelier_id' => $creneau ? (int) $creneau['atelier_id'] : 0,
        ));

        if (!self::dispatch($key, $recipient, array_merge(self::vars($creneau, $recipient), $extra_vars), '', $channels, $mail)) {
            return array();
        }

        return self::flush();
    }

    public static function toIntervenant($key, $intervenant_id, array $creneau, array $extra = array(), $dedup = '', $channels = null)
    {
        $intervenants = Lookup::intervenants();
        $intervenant = isset($intervenants[(int) $intervenant_id]) ? $intervenants[(int) $intervenant_id] : null;

        if (!$intervenant) {
            return 0;
        }

        // Coordonnées lues sur le compte au moment de l'envoi : jamais une copie périmée.
        $identity = !empty($intervenant['user_id']) ? Accounts::identity($intervenant['user_id']) : null;

        if ($identity) {
            $intervenant = array_merge($intervenant, $identity);
        }

        $recipient = array(
            'type' => 'intervenant',
            'first_name' => $intervenant['first_name'],
            'last_name' => $intervenant['last_name'],
            'email' => $intervenant['email'],
            'phone' => $intervenant['phone'],
            'intervenant_id' => (int) $intervenant['id'],
            'creneau_id' => (int) $creneau['id'],
            'atelier_id' => (int) $creneau['atelier_id'],
        );

        return self::dispatch($key, $recipient, self::vars($creneau, $recipient, $extra), $dedup, $channels);
    }

    public static function toWaitlist($key, array $attente, array $creneau, array $extra = array())
    {
        $recipient = array(
            'type' => 'attente',
            'first_name' => $attente['first_name'],
            'last_name' => $attente['last_name'],
            'email' => $attente['email'],
            'phone' => $attente['phone'],
            'attente_id' => (int) $attente['id'],
            'creneau_id' => (int) $creneau['id'],
            'atelier_id' => (int) $creneau['atelier_id'],
        );

        return self::dispatch($key, $recipient, self::vars($creneau, $recipient, $extra));
    }

    /**
     * Lien pour réserver une date : la fiche produit en ligne avec la date présélectionnée ; à défaut,
     * le lien des réglages, puis la page d'accueil.
     *
     * @param array<string,mixed>|null $creneau
     * @param array<string,mixed>|null $atelier
     */
    public static function bookingUrl($creneau, $atelier)
    {
        if ($atelier && \Smbb\Tomo\WooCommerce\Module::active()) {
            $product = \Smbb\Tomo\WooCommerce\Products::linked($atelier);

            if ($product && $product->get_status() === 'publish') {
                $url = (string) get_permalink($product->get_id());

                return $creneau ? add_query_arg('tomo_creneau', (int) $creneau['id'], $url) : $url;
            }
        }

        $fallback = trim((string) Settings::get('waitlist_booking_url'));

        return $fallback !== '' ? $fallback : home_url('/');
    }

    // ------------------------------------------------------------------
    // Préparation et envoi
    // ------------------------------------------------------------------

    /**
     * Programme un message sur chaque canal actif. Renvoie le nombre de messages programmés.
     */
    private static function dispatch($key, array $recipient, array $vars, $dedup = '', $channels = null, array $mail = array())
    {
        if (self::$muted > 0 || !Templates::message($key)) {
            return 0;
        }

        $count = 0;

        foreach (array('email', 'sms') as $channel) {
            $wanted = $channels === null ? Settings::channelOn($key, $channel) : in_array($channel, (array) $channels, true);

            if (!$wanted) {
                continue;
            }

            $address = $channel === 'email' ? (string) $recipient['email'] : (string) $recipient['phone'];

            if ($address === '') {
                continue; // Pas d'adresse sur ce canal : rien à envoyer, rien à journaliser.
            }

            $channel_dedup = $dedup !== '' ? $dedup . ':' . $channel : '';

            if ($channel_dedup !== '' && self::alreadySent($channel_dedup)) {
                continue;
            }

            self::$queue[] = array(
                'event' => $key,
                'channel' => $channel,
                'recipient' => $recipient,
                'address' => $address,
                'subject' => $channel === 'email' ? Templates::render(Settings::template($key, 'subject'), $vars) : '',
                'body' => Templates::render(Settings::template($key, $channel === 'email' ? 'body' : 'text'), $vars),
                'dedup' => $channel_dedup,
                'mail' => $channel === 'email' ? $mail : array(), // pièces jointes, copies (factures)
            );
            $count++;
        }

        if ($count && !self::$shutdown) {
            self::$shutdown = true;
            // Après le vidage des tampons de WordPress (priorité 1), pour ne rien perdre de la page.
            add_action('shutdown', array(__CLASS__, 'flushAfterResponse'), 100);
        }

        return $count;
    }

    /**
     * Envoie la file. Appelé à la fin de la requête, ou directement par la tâche planifiée.
     */
    public static function flush()
    {
        $queue = self::$queue;
        self::$queue = array();
        $results = array();

        foreach ($queue as $message) {
            $results[] = self::deliver($message);
        }

        return $results;
    }

    /**
     * Message d'essai depuis les réglages, envoyé tout de suite.
     *
     * @return array<int,array{channel:string,to:string,status:string,error:string}>
     */
    public static function test($email, $phone)
    {
        $user = wp_get_current_user();
        $recipient = array(
            'type' => 'test',
            'first_name' => $user && $user->exists() ? (string) $user->first_name : '',
            'last_name' => $user && $user->exists() ? (string) $user->last_name : '',
        );
        $sender = (string) Settings::get('sender_name');

        foreach (array('email' => $email, 'sms' => $phone) as $channel => $address) {
            if ((string) $address === '') {
                continue;
            }

            self::$queue[] = array(
                'event' => 'test',
                'channel' => $channel,
                'recipient' => $recipient,
                'address' => (string) $address,
                'subject' => sprintf(__('Test des envois %s', 'smbb-tomo'), $sender),
                'body' => $channel === 'email'
                    ? sprintf(__("Bonjour,\n\nCe message de test confirme que les e-mails de %s partent bien.\n\n%s", 'smbb-tomo'), $sender, implode("\n", array_filter(array($sender, Settings::get('contact_email'), Settings::get('contact_phone')))))
                    : sprintf(__('%s : ce SMS de test confirme que les SMS partent bien.', 'smbb-tomo'), $sender),
                'dedup' => '',
            );
        }

        return self::flush();
    }

    public static function flushAfterResponse()
    {
        if (!self::$queue) {
            return;
        }

        if (function_exists('fastcgi_finish_request') && !wp_doing_cron()) {
            fastcgi_finish_request();
        }

        self::flush();
    }

    private static function deliver(array $message)
    {
        $test = (int) Settings::get('test_mode') === 1;
        $to = $message['address'];
        $status = 'sent';
        $error = '';
        $provider_id = '';

        if ($test && $message['event'] !== 'test') {
            $to = $message['channel'] === 'email' ? (string) Settings::get('test_email') : (string) Settings::get('test_phone');
        }

        if ($to === '') {
            $status = 'skipped';
            $error = __('Mode test : aucune adresse de test pour ce canal.', 'smbb-tomo');
        } elseif ($message['channel'] === 'email') {
            self::$mail_error = '';
            add_action('wp_mail_failed', array(__CLASS__, 'captureMailError'));
            $headers = self::mailHeaders();
            $mail = isset($message['mail']) ? (array) $message['mail'] : array();

            // Copies : jamais en mode test (tout part à l'adresse de test).
            if (!$test) {
                foreach (array('cc' => 'Cc', 'bcc' => 'Bcc') as $field => $header) {
                    foreach (isset($mail[$field]) ? (array) $mail[$field] : array() as $copy) {
                        if (is_email($copy)) {
                            $headers[] = $header . ': ' . $copy;
                        }
                    }
                }
            }

            $attachments = array();

            // Clé texte = nom du fichier joint (WordPress 6.2+), sinon le nom du fichier sur le disque.
            foreach (isset($mail['attachments']) ? (array) $mail['attachments'] : array() as $name => $path) {
                if (is_readable($path)) {
                    $attachments[is_string($name) ? $name : count($attachments)] = $path;
                }
            }
            $ok = wp_mail($to, $message['subject'], self::html($message['body']), $headers, $attachments);
            remove_action('wp_mail_failed', array(__CLASS__, 'captureMailError'));

            if (!$ok) {
                $status = 'failed';
                $error = self::$mail_error !== '' ? self::$mail_error : __("wp_mail n'a pas pu envoyer le message.", 'smbb-tomo');
            }
        } elseif (!Settings::smsReady()) {
            $status = 'skipped';
            $error = __('Twilio n\'est pas encore configuré (Réglages > Envoi).', 'smbb-tomo');
        } elseif (!class_exists(SmbbTwilioSmsClient::class)) {
            $status = 'failed';
            $error = __('Le connecteur Twilio de CodeTool est introuvable.', 'smbb-tomo');
        } else {
            try {
                $client = SmbbTwilioSmsClient::fromSettings(array(
                    'twilio_service' => Settings::get('twilio_service'),
                    'twilio_sid' => Settings::get('twilio_sid'),
                    'twilio_token' => Settings::twilioToken(),
                ));
                $result = $client->send($to, $message['body']);
                $provider_id = isset($result['sid']) ? (string) $result['sid'] : '';
            } catch (\Throwable $exception) {
                $status = 'failed';
                $error = $exception->getMessage();
            }
        }

        if ($test && $status === 'sent' && $message['event'] !== 'test') {
            $status = 'test';
        }

        self::log($message, $to, $status, $error, $provider_id);

        return array('channel' => $message['channel'], 'to' => $to, 'status' => $status, 'error' => $error);
    }

    public static function captureMailError($error)
    {
        if ($error instanceof \WP_Error) {
            self::$mail_error = $error->get_error_message();
        }
    }

    private static function log(array $message, $to, $status, $error, $provider_id)
    {
        global $wpdb;

        $recipient = $message['recipient'];
        $shown = $to;

        if ($to !== $message['address']) {
            $shown = sprintf(__('%1$s (pour %2$s)', 'smbb-tomo'), $to !== '' ? $to : '—', $message['address']);
        }

        $wpdb->insert(Lookup::table('tomo_envoi'), array(
            'event' => $message['event'],
            'channel' => $message['channel'],
            'recipient_type' => $recipient['type'],
            'recipient' => substr($shown, 0, 190),
            'recipient_name' => substr(Lookup::personName($recipient), 0, 190),
            'inscription_id' => !empty($recipient['inscription_id']) ? (int) $recipient['inscription_id'] : null,
            'creneau_id' => !empty($recipient['creneau_id']) ? (int) $recipient['creneau_id'] : null,
            'intervenant_id' => !empty($recipient['intervenant_id']) ? (int) $recipient['intervenant_id'] : null,
            'attente_id' => !empty($recipient['attente_id']) ? (int) $recipient['attente_id'] : null,
            'subject' => substr((string) $message['subject'], 0, 255),
            'body' => (string) $message['body'],
            'status' => $status,
            'error' => $error !== '' ? $error : null,
            'provider_id' => substr((string) $provider_id, 0, 100),
            'dedup_key' => substr((string) $message['dedup'], 0, 190),
            'sent_at' => current_time('mysql'),
            'user_id' => get_current_user_id() ?: null,
            'deleted_flag' => 0,
        ));
    }

    /**
     * Un rappel n'est jamais renvoyé : envoyé, ignoré, envoyé en test, ou trois échecs.
     */
    private static function alreadySent($dedup)
    {
        global $wpdb;

        foreach (self::$queue as $queued) {
            if ($queued['dedup'] === $dedup) {
                return true;
            }
        }

        $rows = $wpdb->get_col($wpdb->prepare('SELECT status FROM ' . Lookup::table('tomo_envoi') . ' WHERE dedup_key = %s', $dedup));
        $failures = 0;

        foreach ((array) $rows as $status) {
            if ($status !== 'failed') {
                return true;
            }

            $failures++;
        }

        return $failures >= 3;
    }

    private static function mailHeaders()
    {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $name = trim(str_replace(array("\r", "\n", '"'), '', (string) Settings::get('sender_name')));
        $from = (string) Settings::get('sender_email');
        $reply = (string) Settings::get('contact_email');

        if ($from !== '' && is_email($from)) {
            $headers[] = 'From: ' . ($name !== '' ? '"' . $name . '" ' : '') . '<' . $from . '>';
        }

        if ($reply !== '' && is_email($reply)) {
            $headers[] = 'Reply-To: ' . ($name !== '' ? '"' . $name . '" ' : '') . '<' . $reply . '>';
        }

        return $headers;
    }

    /**
     * Mise en page sobre : le texte du modèle, liens cliquables.
     */
    private static function html($body)
    {
        $content = make_clickable(nl2br(esc_html((string) $body)));

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:24px 12px;background:#f6f3ef;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px 32px;font-size:15px;line-height:1.6;">'
            . $content
            . '</div></body></html>';
    }

    // ------------------------------------------------------------------
    // Variables des modèles
    // ------------------------------------------------------------------

    /**
     * @param array|null $creneau
     * @return array<string,string>
     */
    public static function vars($creneau, array $recipient, array $extra = array())
    {
        $old = isset($extra['old']) && is_array($extra['old']) ? $extra['old'] : null;
        $atelier_id = $creneau ? $creneau['atelier_id'] : ($old ? $old['atelier_id'] : (isset($recipient['atelier_id']) ? $recipient['atelier_id'] : 0));
        $atelier = Lookup::atelier($atelier_id);
        $sender = (string) Settings::get('sender_name');
        $contact_email = (string) Settings::get('contact_email');
        $contact_phone = (string) Settings::get('contact_phone');

        $vars = array(
            'prenom' => (string) $recipient['first_name'],
            'nom' => (string) $recipient['last_name'],
            'atelier' => $atelier ? (string) $atelier['name'] : '',
            'lieu' => $atelier ? (string) $atelier['location'] : '',
            'infos_pratiques' => $atelier && !empty($atelier['practical_info']) ? trim((string) $atelier['practical_info']) : '',
            'adresse' => $atelier && !empty($atelier['address']) ? str_replace(array("\r\n", "\r", "\n"), ', ', trim((string) $atelier['address'])) : '',
            'date' => '',
            'date_courte' => '',
            'heure' => '',
            'heure_fin' => '',
            'intervenants' => '',
            'places' => '',
            'nb_inscrits' => '',
            'participants' => '',
            'ancienne_date' => $old ? self::longDate($old) . self::at($old) : '',
            'ancienne_date_courte' => $old ? self::shortDate($old) : '',
            'motif' => isset($extra['motif']) ? (string) $extra['motif'] : '',
            'delai' => isset($extra['delai']) ? (string) $extra['delai'] : '',
            'lien_reservation' => self::bookingUrl($creneau, $atelier),
            'lien_compte' => function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : home_url('/'),
            'lien_espace' => admin_url('admin.php?page=smbb-codetool-tomo_espace'),
            'lien_dispos' => !empty($extra['lien_dispos']) ? (string) $extra['lien_dispos'] : admin_url('admin.php?page=smbb-codetool-tomo_espace&dispos=1'),
            'message_demande' => isset($extra['message_demande']) ? (string) $extra['message_demande'] : '',
            'lien_dispos_admin' => $creneau ? admin_url('admin.php?page=smbb-codetool-tomo_creneau&view=details&id=' . (int) $creneau['id']) : '',
            'dates' => isset($extra['dates']) ? (string) $extra['dates'] : '',
            'intervenant_nom' => isset($extra['intervenant_nom']) ? (string) $extra['intervenant_nom'] : '',
            'reponse' => isset($extra['reponse']) ? (string) $extra['reponse'] : '',
            'lien_billet' => '',
            'lien_agenda' => '',
            'lien_documents' => '',
            'contact_email' => $contact_email,
            'contact_telephone' => $contact_phone,
            'expediteur' => $sender,
            'signature' => implode("\n", array_filter(array($sender, $contact_email, $contact_phone))),
        );

        if ($creneau) {
            $vars['date'] = self::longDate($creneau);
            $vars['date_courte'] = self::shortDate($creneau);
            $vars['heure'] = Lookup::slotHours($creneau, false);
            $vars['heure_fin'] = Lookup::allDay($creneau) ? '' : Lookup::hours($creneau['end_time']);
            $vars['intervenants'] = Lookup::intervenantNames($creneau['intervenant_ids']);
            $vars['places'] = (string) (int) $creneau['capacity'];
            $vars['nb_inscrits'] = (string) (int) (isset($creneau['booked']) ? $creneau['booked'] : Capacity::booked($creneau['id']));

            if ($recipient['type'] === 'intervenant') {
                $vars['participants'] = self::participantsText($creneau['id']);
            }
        }

        // Liens personnels du participant : billet (s'il est placé sur une date) et documents encore à remplir.
        if (!empty($recipient['inscription_id']) && $recipient['type'] === 'participant') {
            $inscription = \Smbb\Tomo\Repository\Inscriptions::find($recipient['inscription_id']);

            if ($inscription) {
                $vars['lien_billet'] = Tickets::available($inscription) ? Tickets::url($inscription, 'pdf') : '';
                $vars['lien_agenda'] = Tickets::available($inscription) ? Tickets::url($inscription, 'ics') : '';
                $vars['lien_documents'] = Documents::pendingUrl($inscription);
            }
        }

        return $vars;
    }

    private static function longDate(array $creneau)
    {
        return empty($creneau['start_date']) ? '' : date_i18n('l j F Y', strtotime($creneau['start_date'] . ' 12:00:00'));
    }

    private static function shortDate(array $creneau)
    {
        return empty($creneau['start_date']) ? '' : date_i18n('D j M', strtotime($creneau['start_date'] . ' 12:00:00'));
    }

    private static function at(array $creneau)
    {
        $hour = Lookup::slotHours($creneau, false);

        return $hour !== '' ? ' ' . sprintf(__('à %s', 'smbb-tomo'), $hour) : '';
    }

    /**
     * Liste texte des inscrits, pour l'intervenant.
     */
    private static function participantsText($creneau_id)
    {
        $lines = array();

        foreach (Inscriptions::forCreneau($creneau_id) as $p) {
            $infos = array();

            if (!empty($p['allergies'])) {
                $infos[] = sprintf(__('allergies : %s', 'smbb-tomo'), $p['allergies']);
            }

            if (!empty($p['level'])) {
                $infos[] = Choices::label('levels', $p['level']);
            }

            if (!empty($p['age'])) {
                $infos[] = sprintf(__('%d ans', 'smbb-tomo'), (int) $p['age']);
            }

            if (!empty($p['notes'])) {
                $infos[] = $p['notes'];
            }

            $line = '- ' . Lookup::personName($p) . ($infos ? ' (' . implode(' · ', $infos) . ')' : '');

            if ($p['status'] === 'pending') {
                $line .= ' — ' . __('à confirmer', 'smbb-tomo');
            }

            $lines[] = $line;
        }

        return $lines ? implode("\n", $lines) : __('Personne pour le moment.', 'smbb-tomo');
    }
}
