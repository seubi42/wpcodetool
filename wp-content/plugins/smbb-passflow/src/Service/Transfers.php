<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Support\Input;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Transfert d'un billet à une autre personne (depuis la page du billet, « Mon compte » ou la fiche
 * d'une inscription).
 *
 * La réservation reste la même (place, commande, facture, pass), seuls la personne et le billet changent :
 * - le numéro de version du billet (colonne ticket_rev) augmente : jeton, lien, PDF, QR code, numéro de
 *   série Apple et identifiant Google changent, l'ancien billet ne s'ouvre plus et ne se pointe plus ;
 * - les anciens billets Wallet sont barrés (Apple « voided », Google désactivé), avec le nom de l'ancien
 *   participant (copie gardée dans la colonne transfers) ;
 * - le nouveau participant reçoit son billet (e-mail avec le PDF, SMS, boutons Wallet), l'acheteur de la
 *   commande et l'ancien participant une confirmation ; tout passe par le journal des messages ;
 * - la ligne de commande WooCommerce prend le nouveau nom (« Mon compte » de l'acheteur à jour) et une
 *   note privée est ajoutée ; l'historique de l'inscription garde la trace.
 */
final class Transfers
{
    const ACTION = 'passflow_ticket_transfer';

    /** Confirmation du numéro du nouveau participant par code SMS. */
    const PHONE = 'passflow_ticket_phone';

    /** Garde-fou : nombre de transferts d'un même billet. */
    const MAX = 10;

    public static function hooks()
    {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'submit'));
        add_action('admin_post_nopriv_' . self::ACTION, array(__CLASS__, 'submit'));
        add_action('admin_post_' . self::PHONE, array(__CLASS__, 'phoneSubmit'));
        add_action('admin_post_nopriv_' . self::PHONE, array(__CLASS__, 'phoneSubmit'));
    }

    /**
     * Version du billet (0 = d'origine).
     */
    public static function rev(array $booking)
    {
        return isset($booking['ticket_rev']) ? (int) $booking['ticket_rev'] : 0;
    }

    /**
     * Anciens titulaires, du plus ancien au plus récent.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function log(array $booking)
    {
        $log = isset($booking['transfers']) ? json_decode((string) $booking['transfers'], true) : null;

        return is_array($log) ? array_values(array_filter($log, 'is_array')) : array();
    }

    /**
     * Le billet tel qu'il était avant un transfert (version $rev) : nom de l'ancien titulaire, marqué
     * transféré. Sert à barrer l'ancien billet dans les Wallet et à reconnaître un ancien lien.
     *
     * @return array<string,mixed>|null
     */
    public static function previous(array $booking, $rev)
    {
        foreach (self::log($booking) as $entry) {
            if ((int) $entry['rev'] === (int) $rev) {
                return array_merge($booking, array(
                    'first_name' => (string) $entry['first_name'],
                    'last_name' => (string) $entry['last_name'],
                    'email' => (string) $entry['email'],
                    'phone' => (string) $entry['phone'],
                    'ticket_rev' => (int) $entry['rev'],
                    '_transferred' => $entry,
                ));
            }
        }

        return null;
    }

    /**
     * Le jeton reçu est celui d'un billet transféré depuis ? Renvoie l'ancien billet, sinon null.
     *
     * @return array<string,mixed>|null
     */
    public static function fromToken(array $booking, $token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        foreach (self::log($booking) as $entry) {
            $old = self::previous($booking, (int) $entry['rev']);

            if ($old && hash_equals(Tickets::token($old), $token)) {
                return $old;
            }
        }

        return null;
    }

    /**
     * Le transfert est-il possible ? '' si oui, sinon la raison (affichée).
     *
     * @param bool $staff l'équipe : pas besoin de l'option ni du délai
     */
    public static function blocked(array $booking, $staff = false)
    {
        $d = 'smbb-passflow';
        $booking = Passes::root($booking);

        if (!$staff && !(int) Settings::get('ticket_transfer')) {
            return __("Le transfert des billets n'est pas proposé.", $d);
        }

        if ($booking['status'] === 'present') {
            return __('Ce billet a déjà servi : il ne peut plus être transféré.', $d);
        }

        if (!Tickets::available($booking)) {
            return __("Ce billet ne peut pas être transféré : la réservation n'est pas payée, annulée ou sans date.", $d);
        }

        $slot = Slots::find($booking['slot_id']);

        if (!$slot || $slot['status'] === 'cancelled' || Slots::isPast($slot)) {
            return __('Cette date est passée ou annulée : le billet ne peut plus être transféré.', $d);
        }

        if (Passes::isPass($slot)) {
            foreach (Passes::children((int) $booking['id']) as $child) {
                if ($child['status'] === 'present') {
                    return __('Ce pass a déjà servi un jour : il ne peut plus être transféré.', $d);
                }
            }
        }

        if (count(self::log($booking)) >= self::MAX) {
            return __('Ce billet a déjà été transféré trop de fois. Contactez-nous.', $d);
        }

        if (!$staff) {
            $first = $slot;

            if (Passes::isPass($slot)) {
                $days = Passes::days($slot);
                $first = $days ? reset($days) : $slot;
            }

            $start = strtotime($first['start_date'] . ' ' . (!empty($first['start_time']) ? $first['start_time'] : '00:00:00'));
            $hours = max(0, (int) Settings::get('ticket_transfer_hours'));

            if ($start && current_time('timestamp') > $start - $hours * HOUR_IN_SECONDS) {
                return $hours
                    ? sprintf(_n("Le transfert n'est plus possible moins de %d heure avant le début. Contactez-nous.", "Le transfert n'est plus possible moins de %d heures avant le début. Contactez-nous.", $hours, $d), $hours)
                    : __("L'atelier a commencé : le billet ne peut plus être transféré.", $d);
            }
        }

        return '';
    }

    /**
     * Transfère le billet à une autre personne.
     *
     * @param array{first_name:string,last_name:string,email:string,phone:string} $person
     * @param string $by participant|staff
     * @return array{success:bool,message:string}
     */
    public static function transfer($booking_id, array $person, $by = 'participant')
    {
        global $wpdb;

        $d = 'smbb-passflow';
        $booking = Bookings::find($booking_id);

        if (!$booking) {
            return array('success' => false, 'message' => __('Billet introuvable.', $d));
        }

        $booking = Passes::root($booking);
        $blocked = self::blocked($booking, $by === 'staff');

        if ($blocked !== '') {
            return array('success' => false, 'message' => $blocked);
        }

        $first = trim(sanitize_text_field((string) $person['first_name']));
        $last = trim(sanitize_text_field((string) $person['last_name']));
        $email = strtolower(trim(sanitize_email((string) $person['email'])));
        $phone = Input::phone((string) $person['phone']);

        if ($first === '' || $last === '') {
            return array('success' => false, 'message' => __('Indiquez le prénom et le nom de la personne.', $d));
        }

        if (!is_email($email)) {
            return array('success' => false, 'message' => __("Indiquez une adresse e-mail valide : c'est là que la personne reçoit son billet.", $d));
        }

        $same = strtolower($email) === strtolower((string) $booking['email'])
            && remove_accents(strtolower($first . ' ' . $last)) === remove_accents(strtolower(Lookup::personName($booking)));

        if ($same) {
            return array('success' => false, 'message' => __('Ce billet est déjà au nom de cette personne.', $d));
        }

        $rev = self::rev($booking);
        $now = current_time('mysql');
        $log = self::log($booking);
        $log[] = array(
            'rev' => $rev,
            'first_name' => (string) $booking['first_name'],
            'last_name' => (string) $booking['last_name'],
            'email' => (string) $booking['email'],
            'phone' => (string) $booking['phone'],
            'to' => trim($first . ' ' . $last),
            // Numéro donné par l'ancien participant : pas enregistré tant que la personne ne l'a pas
            // confirmé par code SMS depuis son billet (pas de SMS à un numéro faux ou à quelqu'un d'autre).
            'phone_proposed' => $by === 'staff' ? '' : $phone,
            'at' => $now,
            'by' => $by === 'staff' ? 'staff' : 'participant',
            'user_id' => get_current_user_id(),
        );

        $identity = array(
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $by === 'staff' ? $phone : '', // l'équipe saisit un numéro qu'elle a vérifié
            // Propres à la personne : la nouvelle les donne elle-même (documents, accueil).
            'allergies' => null,
            'level' => '',
            'age' => null,
            'lastupdate_date' => $now,
        );

        $done = $wpdb->update(Lookup::table('passflow_booking'), $identity + array(
            'ticket_rev' => $rev + 1,
            'transfers' => wp_json_encode($log, JSON_UNESCAPED_UNICODE),
        ), array('id' => (int) $booking['id']));

        if ($done === false) {
            return array('success' => false, 'message' => __("Le transfert n'a pas pu être enregistré. Réessayez.", $d));
        }

        // Jours d'un pass : même personne sur chaque jour.
        $wpdb->update(Lookup::table('passflow_booking'), $identity, array('pass_id' => (int) $booking['id']));

        // Documents signés par l'ancien participant : gardés (supprimés logiquement), le nouveau signe les siens.
        $wpdb->update(Lookup::table('passflow_document'), array('deleted_flag' => 1), array('booking_id' => (int) $booking['id'], 'deleted_flag' => 0));

        $old = self::previous(Bookings::find($booking['id']), $rev);
        $new = Bookings::find($booking['id']);
        $old_name = Lookup::personName($old);
        $new_name = Lookup::personName($new);

        History::record((int) $new['id'], 'transferred', sprintf(
            $by === 'staff' ? __('Billet transféré par l\'équipe : %1$s (%2$s) → %3$s (%4$s). Ancien billet désactivé.', $d) : __('Billet transféré par le participant : %1$s (%2$s) → %3$s (%4$s). Ancien billet désactivé.', $d),
            $old_name,
            $old['email'],
            $new_name,
            $new['email']
        ), (int) $new['slot_id']);

        WalletSync::voidPrevious($old);
        $order = self::updateOrder($new, $old_name . ' (' . $old['email'] . ')', $new_name . ' (' . $new['email'] . ')', $by);
        self::notify($new, $old, $order);

        return array('success' => true, 'message' => sprintf(__('Billet transféré à %s : son nouveau billet lui est envoyé, l\'ancien n\'est plus valable.', $d), $new_name));
    }

    /**
     * Ligne de commande : nom des participants à jour (« Mon compte », e-mails WooCommerce) et note privée.
     *
     * @return \WC_Order|null
     */
    private static function updateOrder(array $booking, $old_name, $new_name, $by)
    {
        if (empty($booking['order_id']) || !function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order((int) $booking['order_id']);

        if (!$order) {
            return null;
        }

        $d = 'smbb-passflow';
        $item = !empty($booking['order_item_id']) ? $order->get_item((int) $booking['order_item_id']) : null;

        if ($item) {
            $ids = array_map('intval', (array) $item->get_meta(\Smbb\Passflow\WooCommerce\Orders::META_BOOKINGS));
            $index = array_search((int) $booking['id'], $ids, true);
            $participants = $item->get_meta(\Smbb\Passflow\WooCommerce\Orders::META_PARTICIPANTS);
            $participants = is_array($participants) ? array_values($participants) : array();

            if ($index !== false) {
                $row = isset($participants[$index]) && is_array($participants[$index]) ? $participants[$index] : array();
                $participants[$index] = array_merge($row, array(
                    'first_name' => (string) $booking['first_name'],
                    'last_name' => (string) $booking['last_name'],
                    'email' => (string) $booking['email'],
                    'phone' => (string) $booking['phone'],
                    'allergies' => '',
                    'level' => '',
                    'age' => '',
                ));
                $item->update_meta_data(\Smbb\Passflow\WooCommerce\Orders::META_PARTICIPANTS, $participants);
            }

            // Libellé visible « Participant(s) : … » : refait à partir des réservations de la ligne.
            $names = array();

            foreach ($ids as $id) {
                $row = Bookings::find($id);

                if ($row) {
                    $names[] = Lookup::personName($row);
                }
            }

            foreach ($item->get_meta_data() as $meta) {
                $key = (string) $meta->key;

                if ($names && in_array($key, array('Participant', 'Participants', __('Participant', $d), __('Participants', $d)), true)) {
                    $item->update_meta_data($key, implode(', ', $names), $meta->id);
                }
            }

            $item->save();
        }

        $ref = Tickets::data($booking)['reference'];
        $order->add_order_note(sprintf(
            $by === 'staff' ? __('Passflow : billet %1$s transféré par l\'équipe de %2$s à %3$s. L\'ancien billet (PDF, QR code, Wallet) est désactivé.', $d) : __('Passflow : billet %1$s transféré par le participant de %2$s à %3$s. L\'ancien billet (PDF, QR code, Wallet) est désactivé.', $d),
            $ref,
            $old_name,
            $new_name
        ));

        return $order;
    }

    /**
     * Nouveau participant : son billet. Acheteur : une confirmation. Ancien participant : une confirmation
     * (sauf s'il est l'acheteur, déjà prévenu). Les messages passent dans le journal et la note de commande.
     *
     * @param \WC_Order|null $order
     */
    private static function notify(array $new, array $old, $order)
    {
        $slot = Slots::find($new['slot_id']);

        if (!$slot) {
            return;
        }

        $old_name = Lookup::personName($old);
        $new_name = Lookup::personName($new);
        $vars = array('ancien_participant' => $old_name, 'nouveau_participant' => $new_name);
        $new_email = strtolower((string) $new['email']);
        $old_email = strtolower((string) $old['email']);

        Notifier::forOrder($order ? $order->get_id() : 0);
        Notifier::toParticipant('transfer_received', $new, $slot, $vars);

        $buyer_email = $order ? strtolower(trim((string) $order->get_billing_email())) : '';

        if ($order && is_email($buyer_email) && $buyer_email !== $new_email) {
            Notifier::toPerson('transfer_buyer', array(
                'first_name' => (string) $order->get_billing_first_name(),
                'last_name' => (string) $order->get_billing_last_name(),
                'email' => (string) $order->get_billing_email(),
                'phone' => Input::phone((string) $order->get_billing_phone()),
            ), $new, $slot, $vars + array(
                'commande' => (string) $order->get_order_number(),
                'lien_compte' => function_exists('wc_get_account_endpoint_url') ? (string) wc_get_account_endpoint_url('orders') : home_url('/'),
                'lien_nouveau_billet' => Tickets::url($new, 'page'),
            ));
        }

        if ($old_email !== '' && $old_email !== $buyer_email && $old_email !== $new_email) {
            Notifier::toPerson('transfer_previous', array(
                'first_name' => (string) $old['first_name'],
                'last_name' => (string) $old['last_name'],
                'email' => (string) $old['email'],
                'phone' => (string) $old['phone'],
            ), $new, $slot, $vars);
        }

        Notifier::forOrder(0);
    }

    // ------------------------------------------------------------------
    // Page du billet
    // ------------------------------------------------------------------

    /**
     * Formulaire de transfert, replié sous le billet (page du billet). '' si le transfert n'est pas possible.
     */
    public static function form(array $booking, $error = '')
    {
        $d = 'smbb-passflow';

        if (self::blocked($booking) !== '') {
            return '';
        }

        $field = function ($label, $name, $type, $required, $autocomplete) {
            return '<label class="pf-field"><span>' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" autocomplete="' . esc_attr($autocomplete) . '"' . ($required ? ' required' : '') . '></label>';
        };

        return '<details class="pf-transfer" id="transfert"' . ($error !== '' ? ' open' : '') . '><summary>' . esc_html__('Transférer ce billet à une autre personne', $d) . '</summary>'
            . '<p class="pf-small">' . esc_html__("Vous ne pouvez pas venir ? Donnez votre place. La personne reçoit aussitôt un nouveau billet à son nom (e-mail, SMS, Wallet) et celui-ci ne sera plus valable, y compris dans votre Wallet. L'acheteur de la commande est prévenu.", $d) . '</p>'
            . ($error !== '' ? '<p class="pf-error" role="alert">' . esc_html($error) . '</p>' : '')
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="id" value="' . esc_attr((string) (int) $booking['id']) . '">'
            . '<input type="hidden" name="k" value="' . esc_attr(Tickets::token($booking)) . '">'
            . wp_nonce_field(self::ACTION . '_' . (int) $booking['id'], '_wpnonce', false, false)
            . '<div class="pf-two">' . $field(__('Prénom', $d), 'first_name', 'text', true, 'off') . $field(__('Nom', $d), 'last_name', 'text', true, 'off') . '</div>'
            . $field(__('E-mail', $d), 'email', 'email', true, 'off')
            . $field(__('Téléphone (pour le SMS, facultatif)', $d), 'phone', 'tel', false, 'off')
            . '<label class="pf-check"><input type="checkbox" name="accept" value="1" required> ' . esc_html__("Je comprends que mon billet ne sera plus valable après le transfert.", $d) . '</label>'
            . '<button type="submit" class="pf-btn">' . esc_html__('Transférer le billet', $d) . '</button>'
            . '</form></details>';
    }

    /**
     * Envoi du formulaire de la page du billet (sans connexion : le lien signé du billet suffit).
     */
    public static function submit()
    {
        $d = 'smbb-passflow';
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $token = isset($_POST['k']) ? sanitize_text_field(wp_unslash($_POST['k'])) : '';
        $booking = $id ? Bookings::find($id) : null;

        if (!$booking || !Tickets::valid($booking, $token)) {
            wp_die(esc_html__("Ce billet n'est plus valable : il a peut-être déjà été transféré.", $d), '', array('response' => 403));
        }

        $back = Tickets::url($booking, 'page');

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::ACTION . '_' . $id)) {
            wp_safe_redirect(add_query_arg('transfert', 'expire', $back) . '#transfert');
            exit;
        }

        if (empty($_POST['accept'])) {
            wp_safe_redirect(add_query_arg('transfert', 'accept', $back) . '#transfert');
            exit;
        }

        $result = self::transfer($id, array(
            'first_name' => isset($_POST['first_name']) ? wp_unslash($_POST['first_name']) : '',
            'last_name' => isset($_POST['last_name']) ? wp_unslash($_POST['last_name']) : '',
            'email' => isset($_POST['email']) ? wp_unslash($_POST['email']) : '',
            'phone' => isset($_POST['phone']) ? wp_unslash($_POST['phone']) : '',
        ), 'participant');

        if (!$result['success']) {
            set_transient('smbb_passflow_transfer_error_' . $id, $result['message'], 120);
            wp_safe_redirect(add_query_arg('transfert', 'erreur', $back) . '#transfert');
            exit;
        }

        // L'ancien lien (jeton d'avant) ouvre maintenant la page « billet transféré ».
        wp_safe_redirect(add_query_arg('transfert', 'ok', $back));
        exit;
    }

    /**
     * Message d'erreur à afficher dans le formulaire après une redirection.
     */
    public static function error(array $booking)
    {
        $d = 'smbb-passflow';
        $code = isset($_GET['transfert']) ? sanitize_key(wp_unslash($_GET['transfert'])) : '';

        if ($code === 'expire') {
            return __('La page a expiré : vérifiez et envoyez à nouveau.', $d);
        }

        if ($code === 'accept') {
            return __('Cochez la case pour confirmer le transfert.', $d);
        }

        if ($code === 'erreur') {
            $message = get_transient('smbb_passflow_transfer_error_' . (int) $booking['id']);
            delete_transient('smbb_passflow_transfer_error_' . (int) $booking['id']);

            return is_string($message) && $message !== '' ? $message : __("Le transfert n'a pas pu être fait.", $d);
        }

        return '';
    }

    /**
     * Clé de vérification du numéro : propre au billet et à sa version.
     */
    private static function phoneKey(array $booking)
    {
        return 'passflow-ticket-' . (int) $booking['id'] . '-r' . self::rev($booking);
    }

    /**
     * Vérification par SMS disponible (brique CodeTool + SMS configurés) ?
     */
    private static function phoneCheckReady()
    {
        return class_exists('Smbb\\WpCodeTool\\Sms\\PhoneVerification') && class_exists('Smbb\\WpCodeTool\\Sms\\Sms')
            && \Smbb\WpCodeTool\Sms\Sms::ready() === true;
    }

    /**
     * Page du billet reçu par transfert, sans numéro confirmé : la personne confirme le sien par code SMS
     * (prérempli avec celui donné par l'ancien participant). Ensuite elle reçoit le SMS du billet et les rappels.
     */
    public static function phoneForm(array $booking)
    {
        $d = 'smbb-passflow';

        if (self::rev($booking) === 0 || trim((string) $booking['phone']) !== '' || !Tickets::available($booking) || !self::phoneCheckReady()) {
            return '';
        }

        $log = self::log($booking);
        $last = $log ? end($log) : array();
        $key = self::phoneKey($booking);
        $pending = \Smbb\WpCodeTool\Sms\PhoneVerification::pending($key);
        $proposed = $pending !== '' ? $pending : (isset($last['phone_proposed']) ? (string) $last['phone_proposed'] : '');
        $code = isset($_GET['tel']) ? sanitize_key(wp_unslash($_GET['tel'])) : '';
        $message = get_transient('smbb_passflow_phone_msg_' . (int) $booking['id']);
        delete_transient('smbb_passflow_phone_msg_' . (int) $booking['id']);
        $notice = is_string($message) && $message !== '' ? '<p class="' . ($code === 'erreur' ? 'pf-error' : 'pf-okline') . '" role="status">' . esc_html($message) . '</p>' : '';
        $hidden = '<input type="hidden" name="action" value="' . esc_attr(self::PHONE) . '">'
            . '<input type="hidden" name="id" value="' . esc_attr((string) (int) $booking['id']) . '">'
            . '<input type="hidden" name="k" value="' . esc_attr(Tickets::token($booking)) . '">'
            . wp_nonce_field(self::PHONE . '_' . (int) $booking['id'], '_wpnonce', false, false);
        $action = esc_url(admin_url('admin-post.php'));

        $html = '<section class="pf-phone" id="telephone"><h2>' . esc_html__('Recevoir aussi vos SMS', $d) . '</h2>'
            . '<p class="pf-small">' . esc_html__('Confirmez votre numéro de téléphone pour recevoir votre billet et les rappels par SMS. Nous vous envoyons un code.', $d) . '</p>' . $notice;

        if ($pending !== '') {
            $html .= '<form method="post" action="' . $action . '">' . $hidden . '<input type="hidden" name="step" value="verify">'
                . '<label class="pf-field"><span>' . esc_html(sprintf(__('Code reçu au %s', $d), $pending)) . '</span><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required></label>'
                . '<button type="submit" class="pf-btn">' . esc_html__('Confirmer mon numéro', $d) . '</button></form>'
                . '<form method="post" action="' . $action . '" class="pf-inline">' . $hidden . '<input type="hidden" name="step" value="send"><input type="hidden" name="phone" value="' . esc_attr($pending) . '">'
                . '<button type="submit" class="pf-link">' . esc_html__('Renvoyer le code', $d) . '</button></form>';
        } else {
            $html .= '<form method="post" action="' . $action . '">' . $hidden . '<input type="hidden" name="step" value="send">'
                . '<label class="pf-field"><span>' . esc_html__('Votre téléphone', $d) . '</span><input type="tel" name="phone" value="' . esc_attr($proposed) . '" autocomplete="tel" required></label>'
                . '<button type="submit" class="pf-btn pf-btn--ghost">' . esc_html__('Recevoir un code par SMS', $d) . '</button></form>';
        }

        return $html . '</section>';
    }

    /**
     * Envoi du code, puis vérification : le numéro confirmé est enregistré et le SMS du billet part.
     */
    public static function phoneSubmit()
    {
        $d = 'smbb-passflow';
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $token = isset($_POST['k']) ? sanitize_text_field(wp_unslash($_POST['k'])) : '';
        $booking = $id ? Bookings::find($id) : null;

        if (!$booking || !Tickets::valid($booking, $token)) {
            wp_die(esc_html__("Ce billet n'est plus valable : il a peut-être été transféré.", $d), '', array('response' => 403));
        }

        $back = Tickets::url($booking, 'page');
        $say = function ($state, $message) use ($id, $back) {
            set_transient('smbb_passflow_phone_msg_' . $id, (string) $message, 120);
            wp_safe_redirect(add_query_arg('tel', $state, $back) . '#telephone');
            exit;
        };

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::PHONE . '_' . $id)) {
            $say('erreur', __('La page a expiré : réessayez.', $d));
        }

        if (!self::phoneCheckReady() || trim((string) $booking['phone']) !== '' || self::rev($booking) === 0) {
            wp_safe_redirect($back);
            exit;
        }

        $key = self::phoneKey($booking);
        $step = isset($_POST['step']) ? sanitize_key(wp_unslash($_POST['step'])) : 'send';

        if ($step === 'send') {
            $phone = Input::phone(isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '');
            $sent = \Smbb\WpCodeTool\Sms\PhoneVerification::start($key, $phone, array(
                'label' => sprintf(__('billet %s', $d), Tickets::data($booking)['atelier']),
                'plugin' => 'passflow',
                'source' => 'passflow-phone',
            ));

            if (is_wp_error($sent)) {
                $say('erreur', $sent->get_error_message());
            }

            $say('envoye', sprintf(__('Code envoyé au %s.', $d), $phone));
        }

        $phone = \Smbb\WpCodeTool\Sms\PhoneVerification::check($key, isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '');

        if (is_wp_error($phone)) {
            $say('erreur', $phone->get_error_message());
        }

        global $wpdb;
        $wpdb->update(Lookup::table('passflow_booking'), array('phone' => $phone, 'lastupdate_date' => current_time('mysql')), array('id' => $id));
        $wpdb->update(Lookup::table('passflow_booking'), array('phone' => $phone), array('pass_id' => $id));
        History::record($id, 'updated', sprintf(__('Téléphone %s confirmé par code SMS par le participant.', $d), $phone), (int) $booking['slot_id']);

        // Le SMS du billet, maintenant que le numéro est sûr.
        $booking = Bookings::find($id);
        $log = self::log($booking);
        $last = $log ? end($log) : array();
        $slot = Slots::find($booking['slot_id']);

        if ($slot) {
            Notifier::forOrder(!empty($booking['order_id']) ? (int) $booking['order_id'] : 0);
            Notifier::toParticipant('transfer_received', $booking, $slot, array(
                'ancien_participant' => $last ? trim($last['first_name'] . ' ' . $last['last_name']) : '',
                'nouveau_participant' => Lookup::personName($booking),
            ), '', array('sms'));
            Notifier::forOrder(0);
        }

        $say('ok', __('Numéro confirmé : votre billet vous est aussi envoyé par SMS.', $d));
    }

    /**
     * Ancien lien d'un billet transféré : page qui l'explique (pas de QR code, pas de PDF).
     */
    public static function transferredPage(array $old)
    {
        $d = 'smbb-passflow';
        $entry = $old['_transferred'];
        $t = Tickets::data($old);
        $to = (string) $entry['to'];
        $parts = preg_split('/\s+/', trim($to));
        $short = count($parts) > 1 ? $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.' : $to; // discrétion : « Marie D. »
        $just = isset($_GET['transfert']) && sanitize_key(wp_unslash($_GET['transfert'])) === 'ok';
        $current = Bookings::find($old['id']);
        $order = $current && !empty($current['order_id']) && function_exists('wc_get_order') ? wc_get_order((int) $current['order_id']) : null;
        $user = get_current_user_id();
        $buyer = $user && ($order ? (int) $order->get_customer_id() === $user : (int) $current['customer_id'] === $user);
        $next = '';

        // Le nouveau billet n'est montré qu'à l'acheteur connecté et à l'équipe : l'ancien lien circule
        // aussi chez l'ancien participant, qui ne doit pas pouvoir récupérer le nouveau QR code.
        if ($current && Tickets::available($current) && ($buyer || \Smbb\Passflow\Support\Roles::canManage())) {
            $next = '<p><a class="pf-btn" href="' . esc_url(Tickets::url($current, 'page')) . '">' . esc_html(sprintf(__('Voir le nouveau billet (%s)', $d), Lookup::personName($current))) . '</a></p>';
        } elseif ($current && $order && !$user) {
            $account = function_exists('wc_get_account_endpoint_url') ? (string) wc_get_account_endpoint_url('orders') : wp_login_url();
            $next = '<p class="pf-small">' . esc_html__('Vous avez passé la commande ? Le nouveau billet est dans votre compte.', $d)
                . '</p><p><a class="pf-btn pf-btn--ghost" href="' . esc_url($account) . '">' . esc_html__('Me connecter à mon compte', $d) . '</a></p>';
        }

        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');

        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>' . esc_html__('Billet transféré', $d) . '</title><style>'
            . 'body{margin:0;background:#f4f1ee;color:#2b211c;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
            . '.pf-t{max-width:420px;margin:24px auto;padding:0 16px}.pf-card{background:#fff;border-radius:18px;padding:24px 22px;box-shadow:0 10px 30px rgba(40,25,15,.12);text-align:center}'
            . '.pf-icon{font-size:40px;line-height:1}.pf-card h1{font-size:21px;margin:10px 0 6px}.pf-card p{margin:8px 0;color:#5a4d45}.pf-ok{background:#e8f4ec;color:#1f5f35;border-radius:10px;padding:10px 12px}'
            . '.pf-btn{display:block;text-align:center;padding:12px 16px;border-radius:12px;text-decoration:none;font-weight:600;background:#2b211c;color:#fff;margin-top:14px}.pf-btn--ghost{background:#fff;color:#2b211c;border:1px solid #ddd3cc}.pf-small{font-size:14px}'
            . '</style></head><body><main class="pf-t"><div class="pf-card">'
            . '<div class="pf-icon" aria-hidden="true">' . ($just ? '&#x2705;' : '&#x1F501;') . '</div>'
            . '<h1>' . esc_html($just ? __('Billet transféré', $d) : __('Ce billet a été transféré', $d)) . '</h1>'
            . ($just ? '<p class="pf-ok">' . esc_html(sprintf(__('C\'est fait : %s reçoit son billet par e-mail (si vous avez donné son numéro, elle le confirme par un code SMS depuis son billet).', $d), $short)) . '</p>' : '')
            . '<p><strong>' . esc_html($t['atelier']) . '</strong><br>' . esc_html(trim($t['date'] . ($t['hours'] !== '' ? ' · ' . $t['hours'] : ''))) . '</p>'
            . '<p>' . esc_html(sprintf(__('Transféré le %1$s à %2$s. Ce billet n\'est plus valable : son QR code est désactivé, y compris dans les Wallet.', $d), mysql2date('d/m/Y à H:i', (string) $entry['at']), $short)) . '</p>'
            . $next
            . '</div></main></body></html>';
        exit;
    }
}
