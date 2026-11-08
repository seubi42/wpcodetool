<?php

namespace Smbb\Passflow\Admin;

use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Service\Booking;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Roles;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Actions métier déclenchées depuis les fiches (admin-post.php) : annuler, déplacer, pointer,
 * faire l'appel d'un créneau, annuler ou dupliquer un créneau, inscrire depuis la liste d'attente, exporter,
 * créer un compte intervenant.
 */
final class Actions
{
    const CANCEL = 'passflow_booking_cancel';
    const MOVE = 'passflow_booking_move';
    const PRESENCE = 'passflow_presence';
    const SLOT_CANCEL = 'passflow_slot_cancel';
    const SLOT_DUPLICATE = 'passflow_slot_duplicate';
    const WAITLIST_BOOK = 'passflow_waitlist_book';
    const EXPORT = 'passflow_export_csv';
    const ACCOUNT = 'passflow_host_account';
    const REMIND = 'passflow_remind';
    const TEST_SEND = 'passflow_test_send';
    const PRODUCT_CREATE = 'passflow_product_create';
    const GIFT_CREATE = 'passflow_gift_create';
    const GIFT_PDF = 'passflow_gift_pdf';
    const GIFT_REDEEM = 'passflow_gift_redeem';
    const ROLL_CALL = 'passflow_roll_call';
    const ONSITE_SALE = 'passflow_onsite_sale';
    const ONSITE_RESEND = 'passflow_onsite_resend';
    const TEMPLATES_RESET = 'passflow_templates_reset';
    const DOCUMENTS_SEND = 'passflow_documents_send';
    const AVAILABILITY_REQUEST = 'passflow_availability_request';
    const AVAILABILITY_SET = 'passflow_availability_set';
    const WALKIN = 'passflow_walkin';
    const ONSITE_REFUND = 'passflow_onsite_refund';
    const ONSITE_CANCEL = 'passflow_onsite_cancel';
    const BROADCAST_SEND = 'passflow_broadcast_send';
    const BROADCAST_STOP = 'passflow_broadcast_stop';
    const REPORT_EXPORT = 'passflow_report_csv';
    const TRANSFER = 'passflow_booking_transfer';
    const COMPARE_EXPORT = 'passflow_compare_csv';
    const REVIEW_SEND = 'passflow_review_send';
    const REVIEW_FORM = 'passflow_review_form';

    public static function hooks()
    {
        foreach (array(
            self::CANCEL => 'cancel',
            self::MOVE => 'move',
            self::PRESENCE => 'presence',
            self::SLOT_CANCEL => 'cancelSlot',
            self::SLOT_DUPLICATE => 'duplicateSlot',
            self::WAITLIST_BOOK => 'bookWaitlist',
            self::EXPORT => 'export',
            self::ACCOUNT => 'createAccount',
            self::REMIND => 'remind',
            self::TEST_SEND => 'testSend',
            self::PRODUCT_CREATE => 'createProduct',
            self::GIFT_CREATE => 'createGift',
            self::GIFT_PDF => 'giftPdf',
            self::GIFT_REDEEM => 'redeemGift',
            self::ROLL_CALL => 'rollCall',
            self::ONSITE_SALE => 'onsiteSale',
            self::ONSITE_RESEND => 'onsiteResend',
            self::TEMPLATES_RESET => 'resetTemplates',
            self::DOCUMENTS_SEND => 'sendDocuments',
            self::AVAILABILITY_REQUEST => 'requestAvailabilities',
            self::AVAILABILITY_SET => 'setAvailability',
            self::WALKIN => 'walkin',
            self::ONSITE_REFUND => 'onsiteRefund',
            self::ONSITE_CANCEL => 'onsiteCancel',
            self::BROADCAST_SEND => 'sendBroadcast',
            self::BROADCAST_STOP => 'stopBroadcast',
            self::REPORT_EXPORT => 'exportReport',
            self::TRANSFER => 'transfer',
            self::COMPARE_EXPORT => 'exportCompare',
            self::REVIEW_SEND => 'sendReview',
            self::REVIEW_FORM => 'createReviewForm',
        ) as $action => $method) {
            add_action('admin_post_' . $action, array(__CLASS__, $method));
        }
    }

    public static function cancel()
    {
        $id = self::guard(self::CANCEL);
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'cancel';
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

        self::done(Booking::cancel($id, $mode === 'replace', $reason));
    }

    /**
     * Fiche d'une inscription : l'équipe transfère le billet à une autre personne.
     */
    public static function transfer()
    {
        $id = self::guard(self::TRANSFER);

        self::done(\Smbb\Passflow\Service\Transfers::transfer($id, array(
            'first_name' => isset($_POST['first_name']) ? wp_unslash($_POST['first_name']) : '',
            'last_name' => isset($_POST['last_name']) ? wp_unslash($_POST['last_name']) : '',
            'email' => isset($_POST['email']) ? wp_unslash($_POST['email']) : '',
            'phone' => isset($_POST['phone']) ? wp_unslash($_POST['phone']) : '',
        ), 'staff'));
    }

    public static function move()
    {
        $id = self::guard(self::MOVE);
        $target = isset($_POST['slot_id']) ? absint($_POST['slot_id']) : 0;
        $force = !empty($_POST['force']);
        $confirm = !isset($_POST['confirm_present']) || !empty($_POST['confirm']);

        if (!$target) {
            self::done(array('success' => false, 'message' => __('Choisissez le créneau de destination.', 'smbb-passflow')));
        }

        self::done(Booking::move($id, $target, $force, $confirm));
    }

    /**
     * Pointage : gestionnaires, ou intervenant du créneau.
     */
    public static function presence()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::PRESENCE . '_' . $id);
        $booking = Bookings::find($id);

        if (!$booking || !self::canSeeSlot($booking['slot_id'])) {
            self::deny();
        }

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';

        // Confirmer une réservation reste l'affaire de la pâtisserie.
        if ($booking['status'] === 'pending' && $status === 'confirmed' && !Roles::canManage()) {
            self::deny();
        }

        self::done(Booking::presence($id, $status));
    }

    public static function cancelSlot()
    {
        $id = self::guard(self::SLOT_CANCEL);
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

        self::done(Booking::cancelSlot($id, $reason));
    }

    public static function duplicateSlot()
    {
        $id = self::guard(self::SLOT_DUPLICATE);
        $raw = isset($_POST['dates']) ? (array) wp_unslash($_POST['dates']) : array();
        $dates = array_values(array_filter(array_map('sanitize_text_field', $raw)));

        self::done(Booking::duplicateSlot($id, $dates));
    }

    public static function bookWaitlist()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::WAITLIST_BOOK . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        self::done(Booking::bookFromWaitlist($id, !empty($_GET['force'])));
    }

    /**
     * Renvoi manuel d'un rappel depuis la fiche d'un créneau.
     */
    public static function remind()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::REMIND . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        $slot = Slots::find($id);

        if (!$slot) {
            self::done(array('success' => false, 'message' => __('Créneau introuvable.', 'smbb-passflow')));
        }

        $target = isset($_GET['target']) && $_GET['target'] === 'intervenants' ? 'intervenants' : 'participants';
        $count = \Smbb\Passflow\Service\Reminders::sendNow($slot, $target);

        self::done($count > 0
            ? array('success' => true, 'message' => sprintf(_n('%d message envoyé. Le résultat est dans l\'onglet Messages.', '%d messages envoyés. Le résultat est dans l\'onglet Messages.', $count, 'smbb-passflow'), $count))
            : array('success' => false, 'message' => __('Aucun message à envoyer : personne avec une adresse sur ce canal.', 'smbb-passflow')));
    }

    /**
     * Bon cadeau : code + montant → code promo WooCommerce à usage unique.
     */
    public static function createGift()
    {
        self::guard(self::GIFT_CREATE);

        if (!\Smbb\Passflow\WooCommerce\Module::active()) {
            self::done(array('success' => false, 'message' => __('WooCommerce doit être activé.', 'smbb-passflow')));
        }

        $result = \Smbb\Passflow\WooCommerce\GiftCards::create(array(
            'code' => isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '',
            'amount' => isset($_POST['amount']) ? sanitize_text_field(wp_unslash($_POST['amount'])) : '',
            'recipient' => isset($_POST['recipient']) ? sanitize_text_field(wp_unslash($_POST['recipient'])) : '',
            'email' => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
            'expires' => isset($_POST['expires']) ? sanitize_text_field(wp_unslash($_POST['expires'])) : '',
        ));

        View::flash($result);
        wp_safe_redirect(View::url('passflow_vouchers', !empty($result['id']) ? array('created' => (int) $result['id']) : array()));
        exit;
    }

    /**
     * Bon utilisé en boutique : il est consommé et les e-mails partent.
     */
    public static function redeemGift()
    {
        $id = self::guard(self::GIFT_REDEEM);

        if (!\Smbb\Passflow\WooCommerce\Module::active()) {
            self::done(array('success' => false, 'message' => __('WooCommerce doit être activé.', 'smbb-passflow')));
        }

        if (!\Smbb\Passflow\Support\Settings::giftInStore()) {
            self::done(array('success' => false, 'message' => __("L'utilisation des bons en boutique est désactivée dans les réglages.", 'smbb-passflow')));
        }

        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
        View::flash(\Smbb\Passflow\WooCommerce\GiftCards::redeem($id, $note));
        wp_safe_redirect(View::url('passflow_vouchers', array('etat' => 'used')));
        exit;
    }

    public static function giftPdf()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::GIFT_PDF . '_' . $id);

        if (!Roles::canManage() || !\Smbb\Passflow\WooCommerce\Module::active()) {
            self::deny();
        }

        \Smbb\Passflow\WooCommerce\GiftCards::pdf($id);
    }

    /**
     * Crée le produit WooCommerce d'un atelier (brouillon) et le lie.
     */
    public static function createProduct()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::PRODUCT_CREATE . '_' . $id);

        if (!Roles::canManage() || !current_user_can('edit_products')) {
            self::deny();
        }

        if (!\Smbb\Passflow\WooCommerce\Module::active()) {
            self::done(array('success' => false, 'message' => __('WooCommerce doit être activé.', 'smbb-passflow')));
        }

        self::done(\Smbb\Passflow\WooCommerce\Products::createFromAdmin($id));
    }

    /**
     * Envoi d'essai depuis les réglages (vers l'e-mail et le téléphone de test, sinon ceux de la pâtisserie).
     */
    public static function testSend()
    {
        check_admin_referer(self::TEST_SEND . '_0');

        if (!Roles::canManage()) {
            self::deny();
        }

        $email = (string) Settings::get('test_email') ?: (string) Settings::get('contact_email');
        $phone = (string) Settings::get('test_phone') ?: (string) Settings::get('contact_phone');

        if ($email === '' && $phone === '') {
            self::done(array('success' => false, 'message' => __("Renseignez et enregistrez d'abord un e-mail ou un téléphone de test.", 'smbb-passflow')));
        }

        $lines = array();
        $ok = true;

        foreach (\Smbb\Passflow\Service\Notifier::test($email, $phone) as $result) {
            $label = $result['channel'] === 'email' ? __('E-mail', 'smbb-passflow') : __('SMS', 'smbb-passflow');
            $ok = $ok && $result['status'] === 'sent';
            $lines[] = $result['status'] === 'sent'
                ? sprintf(__('%1$s envoyé à %2$s.', 'smbb-passflow'), $label, $result['to'])
                : sprintf(__('%1$s non envoyé : %2$s', 'smbb-passflow'), $label, $result['error']);
        }

        self::done(array('success' => $ok, 'message' => implode(' ', $lines)));
    }

    /**
     * Liste d'un créneau en CSV (séparateur « ; » et BOM pour Excel).
     */
    public static function export()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::EXPORT . '_' . $id);
        $slot = Slots::find($id);

        if (!$slot || !self::canSeeSlot($id)) {
            self::deny();
        }

        $filename = sanitize_file_name('passflow-' . sanitize_title(Lookup::activityName($slot['activity_id'])) . '-' . $slot['start_date'] . '.csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(array(
            __('Nom', 'smbb-passflow'), __('Prénom', 'smbb-passflow'), __('E-mail', 'smbb-passflow'), __('Téléphone', 'smbb-passflow'),
            __('Allergies', 'smbb-passflow'), __('Niveau', 'smbb-passflow'), __('Âge', 'smbb-passflow'), __('Remarques', 'smbb-passflow'),
            __('Source', 'smbb-passflow'), __('Statut', 'smbb-passflow'), __("Note de l'intervenant", 'smbb-passflow'),
        ), \Smbb\Passflow\Service\Documents::titles()), ';');

        $rows = Bookings::forSlot($id);
        $answers = \Smbb\Passflow\Service\Documents::answers(array_column($rows, 'id'));

        foreach ($rows as $row) {
            fputcsv($out, array_merge(array(
                $row['last_name'], $row['first_name'], $row['email'], $row['phone'],
                $row['allergies'], $row['level'] !== '' ? Choices::label('levels', $row['level']) : '', $row['age'], $row['notes'],
                Choices::label('sources', $row['source']), Choices::label('bookingStatuses', $row['status']),
                (string) $row['host_note'],
            ), \Smbb\Passflow\Service\Documents::csvValues(isset($answers[(int) $row['id']]) ? $answers[(int) $row['id']] : array())), ';');
        }

        fclose($out);
        exit;
    }

    /**
     * Bilan d'un créneau en CSV (présences, ventes sur place, documents, messages, liste nominative).
     */
    public static function exportReport()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::REPORT_EXPORT . '_' . $id);
        $slot = Slots::find($id);

        if (!$slot || !Roles::canManage()) {
            self::deny();
        }

        \Smbb\Passflow\Service\Report::csv($slot);
    }

    /**
     * Comparaison de dates ou d'ateliers en CSV (mêmes paramètres que la page Comparer).
     */
    public static function exportCompare()
    {
        check_admin_referer(self::COMPARE_EXPORT . '_0');

        if (!Roles::canManage()) {
            self::deny();
        }

        $compare = \Smbb\Passflow\Service\Compare::query($_GET);
        \Smbb\Passflow\Service\Compare::csv($compare['columns'], $compare['title']);
    }

    /**
     * Bilan › Avis : envoie tout de suite la demande d'avis d'une journée (sans attendre le délai).
     */
    public static function sendReview()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::REVIEW_SEND . '_' . $id);
        $slot = Slots::find($id);

        if (!$slot || !Roles::canManage()) {
            self::deny();
        }

        self::done(\Smbb\Passflow\Service\Reviews::sendFor($slot));
    }

    /**
     * Réglages › Avis : crée le questionnaire type dans CodeTool › Formulaires et le choisit.
     */
    public static function createReviewForm()
    {
        check_admin_referer(self::REVIEW_FORM . '_0');

        if (!Roles::canManage()) {
            self::deny();
        }

        $id = \Smbb\Passflow\Service\Reviews::createDefaultForm();

        self::done($id
            ? array('success' => true, 'message' => __('Questionnaire de satisfaction créé et choisi. Ajustez ses questions dans CodeTool › Formulaires si besoin.', 'smbb-passflow'))
            : array('success' => false, 'message' => __("Le questionnaire n'a pas pu être créé : le module Formulaires de CodeTool (0.4.2 ou plus) est-il actif ?", 'smbb-passflow')));
    }

    /**
     * Crée le compte WordPress « Intervenant Passflow » d'un intervenant et lui envoie le lien pour choisir son mot de passe.
     */
    public static function createAccount()
    {
        global $wpdb;

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::ACCOUNT . '_' . $id);

        if (!Roles::canManage() || !current_user_can('create_users')) {
            self::done(array('success' => false, 'message' => __("Seul un administrateur peut créer des comptes WordPress.", 'smbb-passflow')));
        }

        $host = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('passflow_host') . ' WHERE id = %d AND deleted_flag = 0', $id), ARRAY_A);

        if (!$host || !is_email($host['email'])) {
            self::done(array('success' => false, 'message' => __("Renseignez d'abord l'e-mail de l'intervenant.", 'smbb-passflow')));
        }

        if (!empty($host['user_id']) && get_userdata((int) $host['user_id'])) {
            self::done(array('success' => false, 'message' => __('Cet intervenant a déjà un compte.', 'smbb-passflow')));
        }

        $user_id = email_exists($host['email']);

        if ($user_id) {
            $user = get_userdata($user_id);
            $user->add_role(Roles::ROLE_HOST);
            $message = __('Un compte existait déjà avec cet e-mail : il est rattaché et reçoit le rôle Intervenant Passflow.', 'smbb-passflow');
        } else {
            $login = sanitize_user(strstr($host['email'], '@', true), true) ?: 'intervenant';
            $base = $login;
            $n = 1;

            while (username_exists($login)) {
                $login = $base . (++$n);
            }

            $user_id = wp_insert_user(array(
                'user_login' => $login,
                'user_email' => $host['email'],
                'user_pass' => wp_generate_password(24),
                'first_name' => $host['first_name'],
                'last_name' => $host['last_name'],
                'display_name' => Lookup::personName($host),
                'role' => Roles::ROLE_HOST,
            ));

            if (is_wp_error($user_id)) {
                self::done(array('success' => false, 'message' => $user_id->get_error_message()));
            }

            wp_new_user_notification($user_id, null, 'user');
            $message = sprintf(__('Compte créé : %s a reçu un e-mail pour choisir son mot de passe.', 'smbb-passflow'), $host['email']);
        }

        $wpdb->update(Lookup::table('passflow_host'), array('user_id' => (int) $user_id), array('id' => $id));
        Lookup::flush();

        self::done(array('success' => true, 'message' => $message));
    }

    /**
     * Appel d'un créneau depuis l'espace intervenant : présences (à partir du jour de l'atelier) et notes.
     * Gestionnaires, ou intervenant affecté au créneau.
     */
    public static function rollCall()
    {
        global $wpdb;

        $d = 'smbb-passflow';
        $slot_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::ROLL_CALL . '_' . $slot_id);
        $slot = Slots::find($slot_id);

        if (!$slot || !self::canSeeSlot($slot_id)) {
            self::deny();
        }

        $presence = isset($_POST['presence']) && is_array($_POST['presence']) ? wp_unslash($_POST['presence']) : array();
        $notes = isset($_POST['note']) && is_array($_POST['note']) ? wp_unslash($_POST['note']) : array();
        $can_point = self::canPoint($slot, self::forced());
        $pointed = 0;
        $noted = 0;
        $errors = array();

        foreach (Bookings::forSlot($slot_id) as $booking) {
            $id = (int) $booking['id'];

            if ($can_point && isset($presence[$id])) {
                $wanted = sanitize_key((string) $presence[$id]);
                $target = '';

                if (in_array($wanted, array('present', 'absent'), true) && $booking['status'] !== $wanted) {
                    $target = $wanted;
                } elseif ($wanted === 'none' && in_array($booking['status'], array('present', 'absent'), true)) {
                    $target = 'confirmed';
                }

                if ($target !== '') {
                    $result = Booking::presence($id, $target);

                    if (!empty($result['success'])) {
                        $pointed++;
                    } else {
                        $errors[] = $result['message'];
                    }
                }
            }

            if (array_key_exists($id, $notes)) {
                $note = trim(sanitize_textarea_field((string) $notes[$id]));

                if ($note !== trim((string) $booking['host_note'])) {
                    $wpdb->update(Lookup::table('passflow_booking'), array(
                        'host_note' => $note !== '' ? $note : null,
                        'lastupdate_date' => current_time('mysql'),
                        'lastupdate_by' => get_current_user_id() ?: null,
                    ), array('id' => $id));
                    $noted++;
                }
            }
        }

        if ($errors) {
            self::done(array('success' => false, 'message' => implode(' ', array_unique($errors))));
        }

        $parts = array();

        if ($pointed) {
            $parts[] = sprintf(_n('%d présence enregistrée', '%d présences enregistrées', $pointed, $d), $pointed);
        }

        if ($noted) {
            $parts[] = sprintf(_n('%d note enregistrée', '%d notes enregistrées', $noted, $d), $noted);
        }

        self::done(array('success' => true, 'message' => $parts ? implode(', ', $parts) . '.' : __('Appel enregistré, rien n\'a changé.', $d)));
    }

    /**
     * Vente sur place depuis l'écran d'appel : commande WooCommerce payée sur place, ou lien de paiement.
     */
    public static function onsiteSale()
    {
        $slot_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::ONSITE_SALE . '_' . $slot_id);
        $slot = Slots::find($slot_id);

        if (!$slot || !self::canSeeSlot($slot_id) || !\Smbb\Passflow\WooCommerce\Module::active()) {
            self::deny();
        }

        if (!self::canPoint($slot, self::forced())) {
            self::done(array('success' => false, 'message' => __("Ce n'est pas le jour de l'atelier : la caisse est fermée. Activez « Accès forcé » pour l'ouvrir.", 'smbb-passflow')));
        }

        self::done(self::saleFromRequest($slot));
    }

    /**
     * Vente sur place lue dans la requête (formulaire de la caisse), en admin-post comme en Ajax.
     *
     * @return array{success:bool,message:string}
     */
    public static function saleFromRequest(array $slot)
    {
        $lines = isset($_POST['qty']) && is_array($_POST['qty']) ? array_map('absint', wp_unslash($_POST['qty'])) : array(); // phpcs:ignore WordPress.Security.NonceVerification
        $customer = array();

        foreach (array('first_name', 'last_name', 'email', 'phone') as $key) {
            $customer[$key] = isset($_POST['customer'][$key]) ? sanitize_text_field(wp_unslash($_POST['customer'][$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        }

        $mode = isset($_POST['mode']) && $_POST['mode'] === 'link' ? 'link' : 'paid'; // phpcs:ignore WordPress.Security.NonceVerification
        $method = isset($_POST['method']) ? sanitize_key(wp_unslash($_POST['method'])) : 'card'; // phpcs:ignore WordPress.Security.NonceVerification

        // Caisse : « Lien de paiement par SMS » est un choix de la liste des moyens de paiement.
        if ($method === 'link') {
            $mode = 'link';
            $method = 'card';
        }

        // Vente à un participant : son compte client, si la réservation en a un.
        $booking_id = isset($_POST['inscription']) ? absint($_POST['inscription']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $booking = $booking_id ? Bookings::find($booking_id) : null;
        $booking = $booking && (int) $booking['slot_id'] === (int) $slot['id'] ? $booking : null;

        return \Smbb\Passflow\WooCommerce\OnsiteSales::create($slot, $lines, $customer, $mode, $method, Cashdesk::context(), $booking ? (int) $booking['customer_id'] : 0, $booking ? (int) $booking['id'] : 0);
    }

    public static function onsiteCancel()
    {
        $order_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::ONSITE_CANCEL . '_' . $order_id);
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $slot_id = $order ? (int) $order->get_meta(\Smbb\Passflow\WooCommerce\OnsiteSales::META_SLOT) : 0;

        if (!$order || !$slot_id || !self::canSeeSlot($slot_id)) {
            self::deny();
        }

        self::done(Cashdesk::cancel($order_id, $slot_id));
    }

    public static function onsiteResend()
    {
        $order_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::ONSITE_RESEND . '_' . $order_id);
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $slot_id = $order ? (int) $order->get_meta(\Smbb\Passflow\WooCommerce\OnsiteSales::META_SLOT) : 0;

        if (!$order || !$slot_id || !self::canSeeSlot($slot_id)) {
            self::deny();
        }

        if ($order->is_paid()) {
            self::done(array('success' => false, 'message' => __('Cette commande est déjà payée.', 'smbb-passflow')));
        }

        self::done(array('success' => true, 'message' => \Smbb\Passflow\WooCommerce\OnsiteSales::sendLink($order, Slots::find($slot_id))));
    }

    /**
     * Caisse : rembourse une ligne d'une vente en bon d'achat (retour produit).
     */
    public static function onsiteRefund()
    {
        $order_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::ONSITE_REFUND . '_' . $order_id);
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $slot_id = $order ? (int) $order->get_meta(\Smbb\Passflow\WooCommerce\OnsiteSales::META_SLOT) : 0;

        if (!$order || !$slot_id || !self::canSeeSlot($slot_id)) {
            self::deny();
        }

        $slot = Slots::find($slot_id);

        if (!$slot || !self::canPoint($slot, self::forced())) {
            self::done(array('success' => false, 'message' => __("Ce n'est pas le jour de l'atelier : la caisse est fermée. Activez « Accès forcé » pour l'ouvrir.", 'smbb-passflow')));
        }

        self::done(self::refundFromRequest($order, $slot));
    }

    /**
     * Retour en bon d'achat lu dans la requête (cases cochées de la vente), en admin-post comme en Ajax.
     *
     * @param \WC_Order $order
     * @return array{success:bool,message:string}
     */
    public static function refundFromRequest($order, $slot)
    {
        $lines = array();

        foreach (isset($_POST['lines']) && is_array($_POST['lines']) ? wp_unslash($_POST['lines']) : array() as $item_id => $qty) { // phpcs:ignore WordPress.Security.NonceVerification
            $lines[absint($item_id)] = absint($qty);
        }

        return \Smbb\Passflow\WooCommerce\Refunds::toVoucher($order, $lines, !empty($_POST['send']), $slot); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Jour de l'atelier : pointage et scan libres. Les autres jours, il faut d'abord activer
     * « Pointer quand même » sur la feuille d'appel (envoyé en hors_date=1).
     */
    public static function isRollCallDay(array $slot)
    {
        return $slot['status'] !== 'cancelled' && !empty($slot['start_date']) && $slot['start_date'] === current_time('Y-m-d');
    }

    public static function canPoint(array $slot, $forced = false)
    {
        return $slot['status'] !== 'cancelled' && !empty($slot['start_date']) && (self::isRollCallDay($slot) || $forced);
    }

    public static function forced()
    {
        return !empty($_POST['hors_date']);
    }

    /**
     * À partir du jour de l'atelier (tableau de bord, vente sur place), sauf créneau annulé.
     */
    public static function rollCallOpen(array $slot)
    {
        return $slot['status'] !== 'cancelled' && !empty($slot['start_date']) && $slot['start_date'] <= current_time('Y-m-d');
    }

    /**
     * Gestionnaire, ou intervenant affecté au créneau.
     */
    public static function canSeeSlot($slot_id)
    {
        if (Roles::canManage()) {
            return true;
        }

        if (!current_user_can(Roles::CAP_HOST)) {
            return false;
        }

        $host = Lookup::hostForUser(get_current_user_id());
        $slot = Slots::find($slot_id);

        return $host && $slot && in_array((int) $host['id'], Lookup::ids($slot['host_ids']), true);
    }

    /**
     * Vérifie le nonce POST et la capacité de gestion, renvoie l'id.
     */
    /**
     * Réglages › Messages : remettre les textes d'origine (un message, ou tout un onglet).
     */
    public static function resetTemplates()
    {
        check_admin_referer(self::TEMPLATES_RESET . '_0');

        if (!Roles::canManage()) {
            self::deny();
        }

        $scope = isset($_GET['scope']) ? sanitize_key(wp_unslash($_GET['scope'])) : '';

        if (!in_array($scope, array('participant', 'intervenant'), true) && !\Smbb\Passflow\Support\Templates::message($scope)) {
            self::done(array('success' => false, 'message' => __('Message inconnu.', 'smbb-passflow')));
        }

        $count = \Smbb\Passflow\Support\Templates::reset($scope);

        self::done(array('success' => true, 'message' => $count
            ? sprintf(_n('%d message remis au texte d\'origine.', '%d messages remis au texte d\'origine.', $count, 'smbb-passflow'), $count)
            : __('Les textes étaient déjà ceux d\'origine.', 'smbb-passflow')));
    }

    /**
     * Fiche d'une inscription : envoie tout de suite le lien des documents encore à remplir.
     */
    public static function sendDocuments()
    {
        $d = 'smbb-passflow';
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::DOCUMENTS_SEND . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        $booking = \Smbb\Passflow\Repository\Bookings::find($id);
        $booking = $booking ? \Smbb\Passflow\Service\Passes::root($booking) : null;

        if (!$booking) {
            self::done(array('success' => false, 'message' => __('Inscription introuvable.', $d)));
        }

        if (\Smbb\Passflow\Service\Documents::pendingUrl($booking) === '') {
            self::done(array('success' => false, 'message' => __('Rien à demander : tous les documents sont remplis, ou la réservation n\'est plus active.', $d)));
        }

        $slot = $booking['slot_id'] ? Slots::find($booking['slot_id']) : null;
        $count = \Smbb\Passflow\Service\Notifier::toParticipant('documents_request', $booking, $slot);

        self::done($count > 0
            ? array('success' => true, 'message' => sprintf(_n('%d message envoyé. Il apparaît dans « Demandes envoyées ».', '%d messages envoyés. Ils apparaissent dans « Demandes envoyées ».', $count, $d), $count))
            : array('success' => false, 'message' => __('Aucun message envoyé : pas d\'adresse e-mail ni de téléphone, ou les deux canaux de « Demande de documents » sont coupés dans Réglages › Messages.', $d)));
    }

    /**
     * « Demander les dispos » : une date (id) ou toutes les dates à venir (id = 0).
     */
    public static function requestAvailabilities()
    {
        $d = 'smbb-passflow';
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::AVAILABILITY_REQUEST . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        $result = \Smbb\Passflow\Service\Availability::request($id ? array($id) : null);

        self::done($result['people'] > 0
            ? array('success' => true, 'message' => sprintf(_n('Demande envoyée à %1$d intervenant (%2$d date sans réponse).', 'Demande envoyée à %1$d intervenants (%2$d dates sans réponse).', $result['people'], $d), $result['people'], $result['dates']))
            : array('success' => false, 'message' => __('Personne à relancer : tous les intervenants prévus ont déjà répondu.', $d)));
    }

    /**
     * Réponse saisie par l'équipe à la place de l'intervenant (fiche de la date).
     */
    public static function setAvailability()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::AVAILABILITY_SET . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        $host_id = isset($_GET['intervenant']) ? absint($_GET['intervenant']) : 0;
        $answer = isset($_GET['answer']) ? sanitize_key(wp_unslash($_GET['answer'])) : '';
        $slot = Slots::find($id);

        if (!$slot || !\Smbb\Passflow\Service\Availability::concerns($slot, $host_id)) {
            self::done(array('success' => false, 'message' => __("Cet intervenant n'est pas prévu sur cette date.", 'smbb-passflow')));
        }

        \Smbb\Passflow\Service\Availability::set($id, $host_id, $answer, 'admin');
        self::done(array('success' => true, 'message' => __('Disponibilité enregistrée.', 'smbb-passflow')));
    }

    /**
     * Fiche d'une journée › Communication : met une campagne SMS / e-mail en file d'attente.
     */
    public static function sendBroadcast()
    {
        $id = self::guard(self::BROADCAST_SEND);
        $slot = Slots::find($id);

        if (!$slot) {
            self::done(array('success' => false, 'message' => __('Créneau introuvable.', 'smbb-passflow')));
        }

        $channels = isset($_POST['channels']) && is_array($_POST['channels']) ? array_map('sanitize_key', wp_unslash($_POST['channels'])) : array();
        $result = \Smbb\Passflow\Service\Broadcasts::create(
            $slot,
            isset($_POST['target']) ? sanitize_key(wp_unslash($_POST['target'])) : 'all',
            $channels,
            isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '',
            isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '',
            // RGPD : « Avec consentement » sauf choix explicite du cas d'urgence.
            !(isset($_POST['audience']) && $_POST['audience'] === 'urgent')
        );

        // En cas d'erreur, le brouillon est gardé pour ne pas tout retaper.
        if (empty($result['success'])) {
            set_transient('passflow_broadcast_draft_' . get_current_user_id(), array(
                'target' => isset($_POST['target']) ? sanitize_key(wp_unslash($_POST['target'])) : 'all',
                'channels' => $channels,
                'subject' => isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '',
                'message' => isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '',
                'audience' => isset($_POST['audience']) && $_POST['audience'] === 'urgent' ? 'urgent' : 'consent',
            ), 600);
        }

        self::done($result);
    }

    public static function stopBroadcast()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::BROADCAST_STOP . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        $count = \Smbb\Passflow\Service\Broadcasts::stop($id);

        self::done(array('success' => true, 'message' => sprintf(_n('Communication arrêtée : %d message ne partira pas.', 'Communication arrêtée : %d messages ne partiront pas.', $count, 'smbb-passflow'), $count)));
    }

    private static function guard($action)
    {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer($action . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        return $id;
    }

    /**
     * Feuille d'appel : participant imprévu accepté sur place. Ajouté présent, même si la date est
     * complète (places libres négatives). Avec un e-mail, un compte client est créé (ou repris) :
     * documents à signer et suivi comme pour les autres.
     */
    public static function walkin()
    {
        $d = 'smbb-passflow';
        $slot_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::WALKIN . '_' . $slot_id);
        $slot = Slots::find($slot_id);

        if (!$slot || !self::canSeeSlot($slot_id)) {
            self::deny();
        }

        if (!self::canPoint($slot, self::forced())) {
            self::done(array('success' => false, 'message' => __("Ce n'est pas le jour de l'atelier : activez « Pointer quand même » d'abord.", $d)));
        }

        $row = isset($_POST['walkin']) && is_array($_POST['walkin']) ? wp_unslash($_POST['walkin']) : array();
        $first = sanitize_text_field(isset($row['first_name']) ? (string) $row['first_name'] : '');
        $last = sanitize_text_field(isset($row['last_name']) ? (string) $row['last_name'] : '');
        $email_raw = trim(isset($row['email']) ? (string) $row['email'] : '');
        $email = strtolower(sanitize_email($email_raw));
        $phone = \Smbb\Passflow\Support\Input::phone(isset($row['phone']) ? (string) $row['phone'] : '');

        if ($first === '' || $last === '') {
            self::done(array('success' => false, 'message' => __('Indiquez le prénom et le nom du participant.', $d)));
        }

        if ($email_raw !== '' && !is_email($email)) {
            self::done(array('success' => false, 'message' => __("L'adresse e-mail n'est pas valide.", $d)));
        }

        $mode = isset($_POST['mode']) && $_POST['mode'] === 'sms' ? 'sms' : 'paid';

        // Pas de commande sans compte : le paiement par SMS demande un e-mail.
        if ($mode === 'sms' && $email === '') {
            self::done(array('success' => false, 'message' => __("Pour le paiement par SMS, indiquez l'e-mail du participant : son compte client est créé avec.", $d)));
        }

        // Compte client : repris s'il existe déjà avec cet e-mail, sinon créé (lien « choisir mon mot de passe » envoyé).
        $customer_id = 0;
        $account = '';

        if ($email !== '') {
            $created = \Smbb\Passflow\Service\Accounts::customer($email, $first, $last, $phone, $slot);

            if (is_wp_error($created)) {
                self::done(array('success' => false, 'message' => $created->get_error_message()));
            }

            $customer_id = (int) $created['id'];
            $account = $created['created'] ? __('compte client créé, lien pour choisir le mot de passe envoyé', $d) : __('compte client existant rattaché', $d);
        }


        // Paiement par SMS avant ajout : commande en attente, la personne arrive sur la liste au paiement.
        if ($mode === 'sms') {
            if (!class_exists('\\Smbb\\Passflow\\WooCommerce\\Walkins') || !\Smbb\Passflow\WooCommerce\Module::active()) {
                self::done(array('success' => false, 'message' => __("WooCommerce n'est pas actif : pas de paiement en ligne possible.", $d)));
            }

            $result = \Smbb\Passflow\WooCommerce\Walkins::createOrder($slot, array('first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone), $customer_id);

            if (!empty($result['success']) && $account !== '') {
                $result['message'] .= ' ' . ucfirst($account) . '.';
            }

            self::done($result);
        }

        $now = current_time('mysql');
        $id = Booking::insertBooking(array(
            'slot_id' => (int) $slot_id,
            'activity_id' => (int) $slot['activity_id'],
            'customer_id' => $customer_id ?: null,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $phone,
            'source' => 'walkin',
            'status' => 'present',
            'checked_at' => $now,
        ));

        if (!$id) {
            self::done(array('success' => false, 'message' => __("Le participant n'a pas pu être ajouté.", $d)));
        }

        $user = wp_get_current_user();
        \Smbb\Passflow\Service\History::record($id, 'walkin', sprintf(__('Participant imprévu ajouté sur place par %s, pointé présent.', $d), $user && $user->exists() ? $user->display_name : '—') . ($account !== '' ? ' (' . $account . ')' : ''), (int) $slot_id);
        \Smbb\Passflow\Service\Capacity::refresh($slot_id);
        do_action('smbb_passflow_walkin_added', (int) $id, (int) $slot_id);

        $message = sprintf(__('%s ajouté(e) à la liste, pointé(e) présent(e).', $d), trim($first . ' ' . $last));
        $message .= $account !== '' ? ' ' . ucfirst($account) . '.' : ' ' . __("Pas d'e-mail : pas de compte client.", $d);

        View::flash(array('success' => true, 'message' => $message));
        $back = wp_get_referer() ?: View::url('passflow_portal', array('creneau' => (int) $slot_id));
        wp_safe_redirect(remove_query_arg('imprevu', $back) . '#passflow-row-' . (int) $id);
        exit;
    }

    private static function deny()
    {
        wp_die(esc_html__('Action non autorisée.', 'smbb-passflow'), '', array('response' => 403));
    }

    private static function done(array $result)
    {
        View::flash($result);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=smbb-codetool-passflow'));
        exit;
    }
}
