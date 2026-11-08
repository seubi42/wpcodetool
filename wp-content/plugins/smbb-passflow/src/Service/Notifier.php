<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Settings;
use Smbb\Passflow\Support\Templates;
use Smbb\WpCodeTool\Connector\SmbbTwilioSmsClient;

defined('ABSPATH') || exit;

/**
 * Envoi des e-mails (wp_mail) et SMS (Twilio) en réponse aux événements Passflow, avec journal.
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

    /** @var array{id:int,resend:bool} commande WooCommerce des messages programmés (note de commande) */
    private static $order_context = array('id' => 0, 'resend' => false);

    /**
     * Les messages programmés ensuite appartiennent à cette commande : une fois partis, ils sont
     * listés dans une note privée de la commande. forOrder(0) pour arrêter.
     */
    public static function forOrder($order_id, $resend = false)
    {
        self::$order_context = array('id' => (int) $order_id, 'resend' => (bool) $resend);
    }

    /** @var array<string,string> repère placé dans le texte => bloc HTML (boutons Wallet) */
    private static $html_slots = array();

    /**
     * Variable de modèle qui porte du HTML : le texte reçoit un repère, remplacé par le HTML une fois
     * le texte mis en forme (le texte des modèles, lui, est toujours échappé).
     */
    private static function htmlSlot($html)
    {
        if ((string) $html === '') {
            return '';
        }

        $marker = '[[pf-html-' . count(self::$html_slots) . ']]';
        self::$html_slots[$marker] = (string) $html;

        return $marker;
    }

    public static function hooks()
    {
        add_action('smbb_passflow_booking_confirmed', array(__CLASS__, 'onConfirmed'), 10, 1);
        add_action('smbb_passflow_booking_moved', array(__CLASS__, 'onMoved'), 10, 4);
        add_action('smbb_passflow_booking_cancelled', array(__CLASS__, 'onCancelled'), 10, 4);
        add_action('smbb_passflow_slot_cancelled', array(__CLASS__, 'onSlotCancelled'), 10, 3);
        add_action('smbb_passflow_waitlist_booked', array(__CLASS__, 'onWaitlistBooked'), 10, 2);
        add_action('smbb_passflow_hosts_assigned', array(__CLASS__, 'onAssigned'), 10, 2);
        add_action('smbb_passflow_seat_freed', array(Waitlist::class, 'notifyNext'), 10, 1);
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

    /**
     * @param array<int,string>|null $channels canaux retenus (commande : l'e-mail est parfois déjà couvert
     *                                         par celui de l'acheteur), sinon ceux des réglages
     */
    public static function onConfirmed($booking_id, $channels = null)
    {
        $booking = Bookings::find($booking_id);
        $slot = $booking && $booking['slot_id'] ? Slots::find($booking['slot_id']) : null;

        if ($slot) {
            self::toParticipant('confirmed', $booking, $slot, array(), '', $channels);
        }
    }

    /**
     * Commande payée : un e-mail à l'acheteur avec tous les tickets en pièces jointes, pour qu'il puisse
     * les imprimer ou les transmettre lui-même. Chaque participant reçoit en plus son ticket personnel.
     *
     * @param array{first_name:string,last_name:string,email:string,phone:string} $buyer
     * @param array<int,int> $booking_ids réservations confirmées de la commande
     * @param array<string,string> $extra_vars commande…
     */
    public static function toBuyer($key, array $buyer, array $booking_ids, array $extra_vars = array())
    {
        $tickets = array();
        $lines = array();

        foreach ($booking_ids as $id) {
            $booking = Bookings::find($id);

            if (!$booking || !Tickets::available($booking)) {
                continue;
            }

            $root = Passes::root($booking);

            if (isset($tickets[(int) $root['id']])) {
                continue; // pass : un seul ticket pour tous les jours
            }

            $tickets[(int) $root['id']] = (int) $root['id'];
            $slot = Slots::find($booking['slot_id']);
            $lines[] = '• ' . Lookup::personName($booking)
                . ($slot ? ' — ' . self::shortDate($slot) . self::at($slot) : '')
                . ' : ' . Tickets::url($booking, 'page');
        }

        if (!$tickets) {
            return 0;
        }

        $first = Bookings::find(reset($tickets));
        $slot = $first ? Slots::find($first['slot_id']) : null;
        $recipient = array_merge(array('first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''), $buyer, array(
            'type' => 'participant',
            'booking_id' => (int) reset($tickets),
            'slot_id' => $slot ? (int) $slot['id'] : null,
            'activity_id' => $first ? (int) $first['activity_id'] : 0,
        ));
        $count = count($tickets);
        $vars = array_merge(self::vars($slot, array_merge($recipient, array('booking_id' => 0))), array(
            'liens_billets' => implode("\n", $lines),
            'nb_billets' => sprintf(_n('%d pièce jointe', '%d pièces jointes', $count, 'smbb-passflow'), $count),
        ), $extra_vars);

        return self::dispatch($key, $recipient, $vars, '', null, array('tickets' => array_values($tickets)));
    }

    public static function onMoved($booking_id, $from_id = 0, $to_id = 0, $confirmed = true)
    {
        unset($confirmed);
        $booking = Bookings::find($booking_id);
        $slot = $to_id ? Slots::find($to_id) : null;

        if ($booking && $slot) {
            self::toParticipant('moved', $booking, $slot, array('old' => $from_id ? Slots::find($from_id) : null));
        }
    }

    public static function onCancelled($booking_id, $status = 'cancelled', $old_slot_id = 0, $reason = '')
    {
        $booking = Bookings::find($booking_id);

        if (!$booking) {
            return;
        }

        $old = $old_slot_id ? Slots::find($old_slot_id) : null;
        self::toParticipant($status === 'pending' ? 'postponed' : 'cancelled', $booking, null, array('old' => $old, 'motif' => $reason));
    }

    /**
     * @param array<int,int> $booking_ids réservations retirées du créneau
     */
    public static function onSlotCancelled($slot_id, $reason = '', $booking_ids = array())
    {
        $slot = Slots::find($slot_id);

        if (!$slot) {
            return;
        }

        foreach ((array) $booking_ids as $id) {
            $booking = Bookings::find($id);

            if ($booking) {
                self::toParticipant('slot_cancelled', $booking, null, array('old' => $slot, 'motif' => $reason));
            }
        }

        foreach (Lookup::ids($slot['host_ids']) as $host_id) {
            self::toHost('slot_cancelled_host', $host_id, $slot, array('motif' => $reason));
        }
    }

    public static function onWaitlistBooked($waitlist_id, $booking_id)
    {
        unset($waitlist_id);
        self::onConfirmed($booking_id);
    }

    /**
     * @param array<int,int> $host_ids
     */
    public static function onAssigned($slot_id, $host_ids)
    {
        $slot = Slots::find($slot_id);

        if (!$slot || $slot['status'] === 'cancelled' || Slots::isPast($slot)) {
            return;
        }

        foreach ((array) $host_ids as $host_id) {
            self::toHost('assigned', $host_id, $slot);
        }
    }

    // ------------------------------------------------------------------
    // Destinataires
    // ------------------------------------------------------------------

    /**
     * @param array|null $slot créneau de la réservation (null si elle n'en a plus)
     * @param array<string,mixed> $extra old (créneau d'avant), motif
     * @param string $dedup clé anti-doublon (rappels)
     * @param array<int,string>|null $channels canaux forcés (renvoi manuel), sinon ceux des réglages
     * @return int nombre de messages programmés
     */
    public static function toParticipant($key, array $booking, $slot, array $extra = array(), $dedup = '', $channels = null)
    {
        $recipient = array(
            'type' => 'participant',
            'first_name' => $booking['first_name'],
            'last_name' => $booking['last_name'],
            'email' => $booking['email'],
            'phone' => $booking['phone'],
            'booking_id' => (int) $booking['id'],
            'slot_id' => $slot ? (int) $slot['id'] : (!empty($extra['old']['id']) ? (int) $extra['old']['id'] : null),
            'activity_id' => (int) $booking['activity_id'],
        );

        // Place confirmée : le ticket personnel part en pièce jointe (produit au moment de l'envoi).
        // Idem pour le billet reçu par transfert.
        $mail = in_array($key, array('confirmed', 'transfer_received'), true) && Tickets::available($booking) ? array('tickets' => array((int) Passes::root($booking)['id'])) : array();
        $vars = self::vars($slot, $recipient, $extra);

        foreach (array('ancien_participant', 'nouveau_participant') as $var) {
            if (isset($extra[$var])) {
                $vars[$var] = (string) $extra[$var];
            }
        }

        return self::dispatch($key, $recipient, $vars, $dedup, $channels, $mail);
    }

    /**
     * Message à une autre personne que le participant, au sujet de sa réservation (acheteur, ancien
     * participant après un transfert…). Mis en file comme les autres, journalisé sur la réservation.
     *
     * @param array{first_name:string,last_name:string,email:string,phone:string} $person
     * @param array<string,string> $extra_vars variables propres au message
     */
    public static function toPerson($key, array $person, array $booking, $slot, array $extra_vars = array())
    {
        $recipient = array_merge(array('first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''), $person, array(
            'type' => 'participant',
            'booking_id' => (int) $booking['id'],
            'slot_id' => $slot ? (int) $slot['id'] : null,
            'activity_id' => (int) $booking['activity_id'],
        ));
        // Pas les liens personnels du participant (billet, documents) : ils ne sont pas pour cette personne.
        $vars = array_merge(self::vars($slot, array_merge($recipient, array('booking_id' => 0))), $extra_vars);

        return self::dispatch($key, $recipient, $vars);
    }

    /**
     * Message à une personne hors inscription (client d'une vente sur place…), envoyé tout de suite.
     *
     * @param array{first_name:string,last_name:string,email:string,phone:string} $person
     * @param array<string,string> $extra_vars variables propres au message
     * @return array<int,array{channel:string,to:string,status:string,error:string}>
     */
    public static function toCustomer($key, array $person, $slot, array $extra_vars = array(), $channels = array('email', 'sms'), array $mail = array())
    {
        $recipient = array_merge(array('first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''), $person, array(
            'type' => 'participant',
            'slot_id' => $slot ? (int) $slot['id'] : null,
            'activity_id' => $slot ? (int) $slot['activity_id'] : 0,
        ));

        if (!self::dispatch($key, $recipient, array_merge(self::vars($slot, $recipient), $extra_vars), '', $channels, $mail)) {
            return array();
        }

        return self::flush();
    }

    public static function toHost($key, $host_id, array $slot, array $extra = array(), $dedup = '', $channels = null)
    {
        $hosts = Lookup::intervenants();
        $host = isset($hosts[(int) $host_id]) ? $hosts[(int) $host_id] : null;

        if (!$host) {
            return 0;
        }

        // Coordonnées lues sur le compte au moment de l'envoi : jamais une copie périmée.
        $identity = !empty($host['user_id']) ? Accounts::identity($host['user_id']) : null;

        if ($identity) {
            $host = array_merge($host, $identity);
        }

        $recipient = array(
            'type' => 'intervenant',
            'first_name' => $host['first_name'],
            'last_name' => $host['last_name'],
            'email' => $host['email'],
            'phone' => $host['phone'],
            'host_id' => (int) $host['id'],
            'slot_id' => (int) $slot['id'],
            'activity_id' => (int) $slot['activity_id'],
        );

        return self::dispatch($key, $recipient, self::vars($slot, $recipient, $extra), $dedup, $channels);
    }

    public static function toWaitlist($key, array $waitlist, array $slot, array $extra = array())
    {
        $recipient = array(
            'type' => 'attente',
            'first_name' => $waitlist['first_name'],
            'last_name' => $waitlist['last_name'],
            'email' => $waitlist['email'],
            'phone' => $waitlist['phone'],
            'waitlist_id' => (int) $waitlist['id'],
            'slot_id' => (int) $slot['id'],
            'activity_id' => (int) $slot['activity_id'],
        );

        return self::dispatch($key, $recipient, self::vars($slot, $recipient, $extra));
    }

    /**
     * Lien pour réserver une date : la fiche produit en ligne avec la date présélectionnée ; à défaut,
     * le lien des réglages, puis la page d'accueil.
     *
     * @param array<string,mixed>|null $slot
     * @param array<string,mixed>|null $activity
     */
    public static function bookingUrl($slot, $activity)
    {
        if ($activity && \Smbb\Passflow\WooCommerce\Module::active()) {
            $product = \Smbb\Passflow\WooCommerce\Products::linked($activity);

            if ($product && $product->get_status() === 'publish') {
                $url = (string) get_permalink($product->get_id());

                return $slot ? add_query_arg('passflow_slot', (int) $slot['id'], $url) : $url;
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

            $body = Templates::render(Settings::template($key, $channel === 'email' ? 'body' : 'text'), $vars);

            // Texte personnalisé d'avant les boutons Wallet : ils se placent sous le lien du billet.
            if ($channel === 'email' && !empty($vars['boutons_wallet']) && !empty($vars['lien_billet'])
                && strpos(Settings::template($key, 'body'), '{boutons_wallet}') === false && strpos($body, $vars['boutons_wallet']) === false) {
                $lines = preg_split('/\r\n|\r|\n/', $body);

                foreach ($lines as $index => $line) {
                    if (strpos($line, $vars['lien_billet']) !== false) {
                        array_splice($lines, $index + 1, 0, array($vars['boutons_wallet']));
                        $body = implode("\n", $lines);
                        break;
                    }
                }
            }

            self::$queue[] = array(
                'event' => $key,
                'channel' => $channel,
                'recipient' => $recipient,
                'address' => $address,
                'subject' => $channel === 'email' ? Templates::render(Settings::template($key, 'subject'), $vars) : '',
                'body' => $body,
                'order' => self::$order_context,
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

        $orders = array();

        foreach ($queue as $message) {
            $result = self::deliver($message);
            $results[] = $result;

            if (!empty($message['order']['id'])) {
                $orders[(int) $message['order']['id']]['resend'] = !empty($message['order']['resend']);
                $orders[(int) $message['order']['id']]['lines'][] = self::orderNoteLine($message, $result);
            }
        }

        foreach ($orders as $order_id => $sent) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;

            if (!$order) {
                continue;
            }

            $count = count($sent['lines']);
            $title = $sent['resend']
                ? sprintf(_n('Passflow : renvoi de %d message', 'Passflow : renvoi de %d messages', $count, 'smbb-passflow'), $count)
                : sprintf(_n('Passflow : envoi de %d message', 'Passflow : envoi de %d messages', $count, 'smbb-passflow'), $count);
            $lines = array();

            foreach ($sent['lines'] as $index => $line) {
                $lines[] = ($index + 1) . ' - ' . $line;
            }

            $order->add_order_note($title . "\n" . implode("\n", $lines)); // note privée
        }

        return $results;
    }

    /**
     * « Acheteur (tous les billets) - a@b.fr », « Billet Jean Michel - c@d.fr », « SMS Jean Michel - +33… »,
     * suivi de l'issue quand le message n'est pas parti normalement.
     *
     * @param array<string,mixed>                                       $message
     * @param array{channel:string,to:string,status:string,error:string} $result
     */
    private static function orderNoteLine(array $message, array $result)
    {
        $d = 'smbb-passflow';
        $name = Lookup::personName($message['recipient']);

        if ($message['event'] === 'order_tickets') {
            $label = __('Acheteur (tous les billets)', $d);
        } elseif ($message['event'] === 'transfer_buyer') {
            $label = sprintf(__('Acheteur, billet transféré (%s)', $d), $message['channel'] === 'sms' ? 'SMS' : __('e-mail', $d));
        } elseif ($message['event'] === 'transfer_previous') {
            $label = sprintf(__('Ancien participant %s, billet transféré', $d), $name);
        } elseif ($message['event'] === 'transfer_received' && $message['channel'] !== 'sms') {
            $label = sprintf(__('Billet transféré à %s', $d), $name);
        } elseif ($message['channel'] === 'sms') {
            $label = sprintf(__('SMS %s', $d), $name);
        } else {
            $label = sprintf(__('Billet %s', $d), $name);
        }

        $line = $label . ' - ' . $message['address'];

        if ($result['status'] === 'test') {
            $line .= ' ' . sprintf(__('(mode test : envoyé à %s)', $d), $result['to']);
        } elseif ($result['status'] === 'failed') {
            $line .= ' ' . sprintf(__('(échec : %s)', $d), $result['error']);
        } elseif ($result['status'] === 'skipped') {
            $line .= ' ' . sprintf(__('(non envoyé : %s)', $d), $result['error']);
        }

        return $line;
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
                'subject' => sprintf(__('Test des envois %s', 'smbb-passflow'), $sender),
                'body' => $channel === 'email'
                    ? sprintf(__("Bonjour,\n\nCe message de test confirme que les e-mails de %s partent bien.\n\n%s", 'smbb-passflow'), $sender, implode("\n", array_filter(array($sender, Settings::get('contact_email'), Settings::get('contact_phone')))))
                    : sprintf(__('%s : ce SMS de test confirme que les SMS partent bien.', 'smbb-passflow'), $sender),
                'dedup' => '',
            );
        }

        return self::flush();
    }

    /**
     * Envoie un message déjà rédigé (communication à une journée), tout de suite, avec journal.
     * Respecte le mode test et la configuration SMS de CodeTool, comme les autres envois.
     *
     * @param array<string,mixed> $recipient type, first_name, last_name, booking_id, slot_id…
     * @return array{channel:string,to:string,status:string,error:string}
     */
    public static function sendOne($event, array $recipient, $channel, $address, $subject, $body)
    {
        return self::deliver(array(
            'event' => (string) $event,
            'channel' => $channel === 'sms' ? 'sms' : 'email',
            'recipient' => $recipient,
            'address' => (string) $address,
            'subject' => (string) $subject,
            'body' => (string) $body,
            'dedup' => '',
            'mail' => array(),
        ));
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

        // Un bloc HTML (boutons Wallet) n'a pas de sens dans un SMS : son repère disparaît.
        if ($message['channel'] === 'sms') {
            $message['body'] = trim(preg_replace('/[ \t]*\[\[pf-html-\d+\]\][ \t]*(\r?\n)?/', '', (string) $message['body']));
        }

        if ($to === '') {
            $status = 'skipped';
            $error = __('Mode test : aucune adresse de test pour ce canal.', 'smbb-passflow');
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

            // Tickets PDF joints : produits juste avant l'envoi, supprimés juste après.
            $temporary = array();

            foreach (isset($mail['tickets']) ? (array) $mail['tickets'] : array() as $ticket_id) {
                $ticket = Bookings::find((int) $ticket_id);
                $path = $ticket && Tickets::available($ticket) ? Tickets::pdfFile($ticket) : '';

                if ($path !== '') {
                    $temporary[] = $path;
                    $attachments[basename($path)] = $path;
                }
            }

            // Blocs HTML des modèles (boutons Wallet) : posés après la mise en forme du texte.
            $html = strtr(self::html($message['body'], $message['subject']), self::$html_slots);

            // Badges Wallet PNG intégrés au message (cid:) plutôt que chargés depuis le site.
            $embedded = array();

            foreach (array('apple', 'google') as $wallet) {
                $badge = strpos($html, 'cid:passflow-wallet-' . $wallet) !== false ? Tickets::emailBadge($wallet) : null;

                if ($badge) {
                    $embedded[] = $badge;
                }
            }

            $embed = function ($phpmailer) use ($embedded) {
                foreach ($embedded as $badge) {
                    $phpmailer->addEmbeddedImage($badge['path'], $badge['cid'], basename($badge['path']), 'base64', 'image/png');
                }
            };

            if ($embedded) {
                add_action('phpmailer_init', $embed);
            }

            // Pas d'adresse d'expéditeur dans Passflow : on garde celle du site, mais avec le bon nom.
            $sender_name = trim(str_replace(array("\r", "\n", '"'), '', (string) Settings::get('sender_name')));
            $sender_name = $sender_name !== '' ? $sender_name : Settings::businessName();
            $from_name = function ($name) use ($sender_name) {
                return $sender_name !== '' && in_array((string) $name, array('', 'WordPress'), true) ? $sender_name : $name;
            };
            add_filter('wp_mail_from_name', $from_name, 20);

            $ok = wp_mail($to, $message['subject'], $html, $headers, $attachments);
            remove_action('wp_mail_failed', array(__CLASS__, 'captureMailError'));
            remove_action('phpmailer_init', $embed);
            remove_filter('wp_mail_from_name', $from_name, 20);

            foreach ($temporary as $path) {
                Tickets::deleteFile($path);
            }

            if (!$ok) {
                $status = 'failed';
                $error = self::$mail_error !== '' ? self::$mail_error : __("wp_mail n'a pas pu envoyer le message.", 'smbb-passflow');
            }
        } elseif (class_exists('Smbb\\WpCodeTool\\Sms\\Sms') && \Smbb\WpCodeTool\Sms\Sms::ready() !== true) {
            // CodeTool gère les SMS mais n'est pas encore configuré : on ne passe plus par l'ancien compte Twilio de Passflow.
            $status = 'skipped';
            $error = sprintf(__('SMS non configurés dans CodeTool › Communication › SMS : %s', 'smbb-passflow'), \Smbb\WpCodeTool\Sms\Sms::ready());
        } elseif (class_exists('Smbb\\WpCodeTool\\Sms\\Sms')) {
            // SMS envoyé par CodeTool › Communication › SMS (journal, coûts, alertes, widget communs).
            // L'ancien compte Twilio de Passflow ne sert plus qu'avec un CodeTool trop ancien (sans module SMS).
            $result = \Smbb\WpCodeTool\Sms\Sms::send($to, $message['body'], array('plugin' => 'passflow', 'event' => $message['event']));

            if (is_wp_error($result)) {
                $status = 'failed';
                $error = $result->get_error_message();
            } else {
                $provider_id = (string) $result['id'];
            }
        } elseif (!Settings::smsReady()) {
            $status = 'skipped';
            $error = __('Aucun envoi SMS configuré (CodeTool › Communication › SMS, ou Réglages > Envoi de Passflow).', 'smbb-passflow');
        } elseif (!class_exists(SmbbTwilioSmsClient::class)) {
            $status = 'failed';
            $error = __('Le connecteur Twilio de CodeTool est introuvable.', 'smbb-passflow');
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
            $shown = sprintf(__('%1$s (pour %2$s)', 'smbb-passflow'), $to !== '' ? $to : '—', $message['address']);
        }

        $wpdb->insert(Lookup::table('passflow_delivery'), array(
            'event' => $message['event'],
            'channel' => $message['channel'],
            'recipient_type' => $recipient['type'],
            'recipient' => substr($shown, 0, 190),
            'recipient_name' => substr(Lookup::personName($recipient), 0, 190),
            'booking_id' => !empty($recipient['booking_id']) ? (int) $recipient['booking_id'] : null,
            'slot_id' => !empty($recipient['slot_id']) ? (int) $recipient['slot_id'] : null,
            'host_id' => !empty($recipient['host_id']) ? (int) $recipient['host_id'] : null,
            'waitlist_id' => !empty($recipient['waitlist_id']) ? (int) $recipient['waitlist_id'] : null,
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

        $rows = $wpdb->get_col($wpdb->prepare('SELECT status FROM ' . Lookup::table('passflow_delivery') . ' WHERE dedup_key = %s', $dedup));
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
     * Mise en page aux couleurs du site (Réglages › Envoi › Habillage des e-mails).
     */
    private static function html($body, $subject = '')
    {
        return EmailBrand::wrap(EmailBrand::bodyToHtml($body), (string) $subject);
    }

    // ------------------------------------------------------------------
    // Variables des modèles
    // ------------------------------------------------------------------

    /**
     * @param array|null $slot
     * @return array<string,string>
     */
    public static function vars($slot, array $recipient, array $extra = array())
    {
        $old = isset($extra['old']) && is_array($extra['old']) ? $extra['old'] : null;
        $activity_id = $slot ? $slot['activity_id'] : ($old ? $old['activity_id'] : (isset($recipient['activity_id']) ? $recipient['activity_id'] : 0));
        $activity = Lookup::atelier($activity_id);
        $sender = (string) Settings::get('sender_name');
        $contact_email = (string) Settings::get('contact_email');
        $contact_phone = (string) Settings::get('contact_phone');

        $vars = array(
            'prenom' => (string) $recipient['first_name'],
            'nom' => (string) $recipient['last_name'],
            'atelier' => $activity ? (string) $activity['name'] : '',
            'lieu' => $activity ? (string) $activity['location'] : '',
            'infos_pratiques' => $activity && !empty($activity['practical_info']) ? trim((string) $activity['practical_info']) : '',
            'adresse' => $activity && !empty($activity['address']) ? str_replace(array("\r\n", "\r", "\n"), ', ', trim((string) $activity['address'])) : '',
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
            'lien_reservation' => self::bookingUrl($slot, $activity),
            'lien_compte' => function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : home_url('/'),
            'lien_espace' => admin_url('admin.php?page=smbb-codetool-passflow_portal'),
            'lien_dispos' => !empty($extra['lien_dispos']) ? (string) $extra['lien_dispos'] : admin_url('admin.php?page=smbb-codetool-passflow_portal&dispos=1'),
            'message_demande' => isset($extra['message_demande']) ? (string) $extra['message_demande'] : '',
            'lien_dispos_admin' => $slot ? admin_url('admin.php?page=smbb-codetool-passflow_slot&view=details&id=' . (int) $slot['id']) : '',
            'dates' => isset($extra['dates']) ? (string) $extra['dates'] : '',
            'intervenant_nom' => isset($extra['intervenant_nom']) ? (string) $extra['intervenant_nom'] : '',
            'reponse' => isset($extra['reponse']) ? (string) $extra['reponse'] : '',
            'lien_billet' => '',
            'lien_billet_pdf' => '',
            'boutons_wallet' => '',
            'liens_billets' => '',
            'nb_billets' => '',
            'lien_agenda' => '',
            'lien_documents' => '',
            'lien_avis' => '',
            'contact_email' => $contact_email,
            'contact_telephone' => $contact_phone,
            'expediteur' => $sender,
            'signature' => implode("\n", array_filter(array($sender, $contact_email, $contact_phone))),
        );

        if ($slot) {
            $vars['date'] = self::longDate($slot);
            $vars['date_courte'] = self::shortDate($slot);
            $vars['heure'] = Lookup::slotHours($slot, false);
            $vars['heure_fin'] = Lookup::allDay($slot) ? '' : Lookup::hours($slot['end_time']);
            $vars['intervenants'] = Lookup::hostNames($slot['host_ids']);
            $vars['places'] = (string) (int) $slot['capacity'];
            $vars['nb_inscrits'] = (string) (int) (isset($slot['booked']) ? $slot['booked'] : Capacity::booked($slot['id']));

            if ($recipient['type'] === 'intervenant') {
                $vars['participants'] = self::participantsText($slot['id']);
            }
        }

        // Liens personnels du participant : billet (s'il est placé sur une date) et documents encore à remplir.
        if (!empty($recipient['booking_id']) && $recipient['type'] === 'participant') {
            $booking = \Smbb\Passflow\Repository\Bookings::find($recipient['booking_id']);

            if ($booking) {
                $ticket = Tickets::available($booking);
                // Page du ticket : PDF, Apple Wallet, Google Wallet et agenda au même endroit.
                $vars['lien_billet'] = $ticket ? Tickets::url($booking, 'page') : '';
                $vars['lien_billet_pdf'] = $ticket ? Tickets::url($booking, 'pdf') : '';
                $vars['boutons_wallet'] = $ticket ? self::htmlSlot(Tickets::emailButtons($booking)) : '';
                $vars['lien_agenda'] = $ticket ? Tickets::url($booking, 'ics') : '';
                $vars['lien_documents'] = Documents::pendingUrl($booking);
                $vars['lien_avis'] = Reviews::url($booking);
            }
        }

        return $vars;
    }

    private static function longDate(array $slot)
    {
        return empty($slot['start_date']) ? '' : date_i18n('l j F Y', strtotime($slot['start_date'] . ' 12:00:00'));
    }

    private static function shortDate(array $slot)
    {
        return empty($slot['start_date']) ? '' : date_i18n('D j M', strtotime($slot['start_date'] . ' 12:00:00'));
    }

    private static function at(array $slot)
    {
        $hour = Lookup::slotHours($slot, false);

        return $hour !== '' ? ' ' . sprintf(__('à %s', 'smbb-passflow'), $hour) : '';
    }

    /**
     * Liste texte des inscrits, pour l'intervenant.
     */
    private static function participantsText($slot_id)
    {
        $lines = array();

        foreach (Bookings::forSlot($slot_id) as $p) {
            $infos = array();

            if (!empty($p['allergies'])) {
                $infos[] = sprintf(__('allergies : %s', 'smbb-passflow'), $p['allergies']);
            }

            if (!empty($p['level'])) {
                $infos[] = Choices::label('levels', $p['level']);
            }

            if (!empty($p['age'])) {
                $infos[] = sprintf(__('%d ans', 'smbb-passflow'), (int) $p['age']);
            }

            if (!empty($p['notes'])) {
                $infos[] = $p['notes'];
            }

            $line = '- ' . Lookup::personName($p) . ($infos ? ' (' . implode(' · ', $infos) . ')' : '');

            if ($p['status'] === 'pending') {
                $line .= ' — ' . __('à confirmer', 'smbb-passflow');
            }

            $lines[] = $line;
        }

        return $lines ? implode("\n", $lines) : __('Personne pour le moment.', 'smbb-passflow');
    }
}
