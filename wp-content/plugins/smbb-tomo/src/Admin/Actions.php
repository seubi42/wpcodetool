<?php

namespace Smbb\Tomo\Admin;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Inscriptions;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Booking;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Roles;
use Smbb\Tomo\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Actions métier déclenchées depuis les fiches (admin-post.php) : annuler, déplacer, pointer,
 * faire l'appel d'un créneau, annuler ou dupliquer un créneau, inscrire depuis la liste d'attente, exporter,
 * créer un compte intervenant.
 */
final class Actions
{
    const CANCEL = 'tomo_inscription_cancel';
    const MOVE = 'tomo_inscription_move';
    const PRESENCE = 'tomo_presence';
    const CRENEAU_CANCEL = 'tomo_creneau_cancel';
    const CRENEAU_DUPLICATE = 'tomo_creneau_duplicate';
    const WAITLIST_BOOK = 'tomo_waitlist_book';
    const EXPORT = 'tomo_export_csv';
    const ACCOUNT = 'tomo_intervenant_account';
    const REMIND = 'tomo_remind';
    const TEST_SEND = 'tomo_test_send';
    const PRODUCT_CREATE = 'tomo_product_create';
    const GIFT_CREATE = 'tomo_gift_create';
    const GIFT_PDF = 'tomo_gift_pdf';
    const GIFT_REDEEM = 'tomo_gift_redeem';
    const ROLL_CALL = 'tomo_roll_call';
    const ONSITE_SALE = 'tomo_onsite_sale';
    const ONSITE_RESEND = 'tomo_onsite_resend';
    const TEMPLATES_RESET = 'tomo_templates_reset';
    const DOCUMENTS_SEND = 'tomo_documents_send';
    const DISPO_REQUEST = 'tomo_dispo_request';
    const DISPO_SET = 'tomo_dispo_set';
    const WALKIN = 'tomo_walkin';
    const ONSITE_REFUND = 'tomo_onsite_refund';
    const ONSITE_CANCEL = 'tomo_onsite_cancel';

    public static function hooks()
    {
        foreach (array(
            self::CANCEL => 'cancel',
            self::MOVE => 'move',
            self::PRESENCE => 'presence',
            self::CRENEAU_CANCEL => 'cancelCreneau',
            self::CRENEAU_DUPLICATE => 'duplicateCreneau',
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
            self::DISPO_REQUEST => 'requestDispos',
            self::DISPO_SET => 'setDispo',
            self::WALKIN => 'walkin',
            self::ONSITE_REFUND => 'onsiteRefund',
            self::ONSITE_CANCEL => 'onsiteCancel',
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

    public static function move()
    {
        $id = self::guard(self::MOVE);
        $target = isset($_POST['creneau_id']) ? absint($_POST['creneau_id']) : 0;
        $force = !empty($_POST['force']);
        $confirm = !isset($_POST['confirm_present']) || !empty($_POST['confirm']);

        if (!$target) {
            self::done(array('success' => false, 'message' => __('Choisissez le créneau de destination.', 'smbb-tomo')));
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
        $inscription = Inscriptions::find($id);

        if (!$inscription || !self::canSeeCreneau($inscription['creneau_id'])) {
            self::deny();
        }

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';

        // Confirmer une réservation reste l'affaire de la pâtisserie.
        if ($inscription['status'] === 'pending' && $status === 'confirmed' && !Roles::canManage()) {
            self::deny();
        }

        self::done(Booking::presence($id, $status));
    }

    public static function cancelCreneau()
    {
        $id = self::guard(self::CRENEAU_CANCEL);
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

        self::done(Booking::cancelCreneau($id, $reason));
    }

    public static function duplicateCreneau()
    {
        $id = self::guard(self::CRENEAU_DUPLICATE);
        $raw = isset($_POST['dates']) ? (array) wp_unslash($_POST['dates']) : array();
        $dates = array_values(array_filter(array_map('sanitize_text_field', $raw)));

        self::done(Booking::duplicateCreneau($id, $dates));
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

        $creneau = Creneaux::find($id);

        if (!$creneau) {
            self::done(array('success' => false, 'message' => __('Créneau introuvable.', 'smbb-tomo')));
        }

        $target = isset($_GET['target']) && $_GET['target'] === 'intervenants' ? 'intervenants' : 'participants';
        $count = \Smbb\Tomo\Service\Reminders::sendNow($creneau, $target);

        self::done($count > 0
            ? array('success' => true, 'message' => sprintf(_n('%d message envoyé. Le résultat est dans l\'onglet Messages.', '%d messages envoyés. Le résultat est dans l\'onglet Messages.', $count, 'smbb-tomo'), $count))
            : array('success' => false, 'message' => __('Aucun message à envoyer : personne avec une adresse sur ce canal.', 'smbb-tomo')));
    }

    /**
     * Bon cadeau : code + montant → code promo WooCommerce à usage unique.
     */
    public static function createGift()
    {
        self::guard(self::GIFT_CREATE);

        if (!\Smbb\Tomo\WooCommerce\Module::active()) {
            self::done(array('success' => false, 'message' => __('WooCommerce doit être activé.', 'smbb-tomo')));
        }

        $result = \Smbb\Tomo\WooCommerce\GiftCards::create(array(
            'code' => isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '',
            'amount' => isset($_POST['amount']) ? sanitize_text_field(wp_unslash($_POST['amount'])) : '',
            'recipient' => isset($_POST['recipient']) ? sanitize_text_field(wp_unslash($_POST['recipient'])) : '',
            'email' => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
            'expires' => isset($_POST['expires']) ? sanitize_text_field(wp_unslash($_POST['expires'])) : '',
        ));

        View::flash($result);
        wp_safe_redirect(View::url('tomo_bons', !empty($result['id']) ? array('created' => (int) $result['id']) : array()));
        exit;
    }

    /**
     * Bon utilisé en boutique : il est consommé et les e-mails partent.
     */
    public static function redeemGift()
    {
        $id = self::guard(self::GIFT_REDEEM);

        if (!\Smbb\Tomo\WooCommerce\Module::active()) {
            self::done(array('success' => false, 'message' => __('WooCommerce doit être activé.', 'smbb-tomo')));
        }

        if (!\Smbb\Tomo\Support\Settings::giftInStore()) {
            self::done(array('success' => false, 'message' => __("L'utilisation des bons en boutique est désactivée dans les réglages.", 'smbb-tomo')));
        }

        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
        View::flash(\Smbb\Tomo\WooCommerce\GiftCards::redeem($id, $note));
        wp_safe_redirect(View::url('tomo_bons', array('etat' => 'used')));
        exit;
    }

    public static function giftPdf()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::GIFT_PDF . '_' . $id);

        if (!Roles::canManage() || !\Smbb\Tomo\WooCommerce\Module::active()) {
            self::deny();
        }

        \Smbb\Tomo\WooCommerce\GiftCards::pdf($id);
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

        if (!\Smbb\Tomo\WooCommerce\Module::active()) {
            self::done(array('success' => false, 'message' => __('WooCommerce doit être activé.', 'smbb-tomo')));
        }

        self::done(\Smbb\Tomo\WooCommerce\Products::createFromAdmin($id));
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
            self::done(array('success' => false, 'message' => __("Renseignez et enregistrez d'abord un e-mail ou un téléphone de test.", 'smbb-tomo')));
        }

        $lines = array();
        $ok = true;

        foreach (\Smbb\Tomo\Service\Notifier::test($email, $phone) as $result) {
            $label = $result['channel'] === 'email' ? __('E-mail', 'smbb-tomo') : __('SMS', 'smbb-tomo');
            $ok = $ok && $result['status'] === 'sent';
            $lines[] = $result['status'] === 'sent'
                ? sprintf(__('%1$s envoyé à %2$s.', 'smbb-tomo'), $label, $result['to'])
                : sprintf(__('%1$s non envoyé : %2$s', 'smbb-tomo'), $label, $result['error']);
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
        $creneau = Creneaux::find($id);

        if (!$creneau || !self::canSeeCreneau($id)) {
            self::deny();
        }

        $filename = sanitize_file_name('tomo-' . sanitize_title(Lookup::atelierName($creneau['atelier_id'])) . '-' . $creneau['start_date'] . '.csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(array(
            __('Nom', 'smbb-tomo'), __('Prénom', 'smbb-tomo'), __('E-mail', 'smbb-tomo'), __('Téléphone', 'smbb-tomo'),
            __('Allergies', 'smbb-tomo'), __('Niveau', 'smbb-tomo'), __('Âge', 'smbb-tomo'), __('Remarques', 'smbb-tomo'),
            __('Source', 'smbb-tomo'), __('Statut', 'smbb-tomo'), __("Note de l'intervenant", 'smbb-tomo'),
        ), \Smbb\Tomo\Service\Documents::titles()), ';');

        $rows = Inscriptions::forCreneau($id);
        $answers = \Smbb\Tomo\Service\Documents::answers(array_column($rows, 'id'));

        foreach ($rows as $row) {
            fputcsv($out, array_merge(array(
                $row['last_name'], $row['first_name'], $row['email'], $row['phone'],
                $row['allergies'], $row['level'] !== '' ? Choices::label('levels', $row['level']) : '', $row['age'], $row['notes'],
                Choices::label('sources', $row['source']), Choices::label('inscriptionStatuses', $row['status']),
                (string) $row['intervenant_note'],
            ), \Smbb\Tomo\Service\Documents::csvValues(isset($answers[(int) $row['id']]) ? $answers[(int) $row['id']] : array())), ';');
        }

        fclose($out);
        exit;
    }

    /**
     * Crée le compte WordPress « Intervenant Tomo » d'un intervenant et lui envoie le lien pour choisir son mot de passe.
     */
    public static function createAccount()
    {
        global $wpdb;

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::ACCOUNT . '_' . $id);

        if (!Roles::canManage() || !current_user_can('create_users')) {
            self::done(array('success' => false, 'message' => __("Seul un administrateur peut créer des comptes WordPress.", 'smbb-tomo')));
        }

        $intervenant = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('tomo_intervenant') . ' WHERE id = %d AND deleted_flag = 0', $id), ARRAY_A);

        if (!$intervenant || !is_email($intervenant['email'])) {
            self::done(array('success' => false, 'message' => __("Renseignez d'abord l'e-mail de l'intervenant.", 'smbb-tomo')));
        }

        if (!empty($intervenant['user_id']) && get_userdata((int) $intervenant['user_id'])) {
            self::done(array('success' => false, 'message' => __('Cet intervenant a déjà un compte.', 'smbb-tomo')));
        }

        $user_id = email_exists($intervenant['email']);

        if ($user_id) {
            $user = get_userdata($user_id);
            $user->add_role(Roles::ROLE_INTERVENANT);
            $message = __('Un compte existait déjà avec cet e-mail : il est rattaché et reçoit le rôle Intervenant Tomo.', 'smbb-tomo');
        } else {
            $login = sanitize_user(strstr($intervenant['email'], '@', true), true) ?: 'intervenant';
            $base = $login;
            $n = 1;

            while (username_exists($login)) {
                $login = $base . (++$n);
            }

            $user_id = wp_insert_user(array(
                'user_login' => $login,
                'user_email' => $intervenant['email'],
                'user_pass' => wp_generate_password(24),
                'first_name' => $intervenant['first_name'],
                'last_name' => $intervenant['last_name'],
                'display_name' => Lookup::personName($intervenant),
                'role' => Roles::ROLE_INTERVENANT,
            ));

            if (is_wp_error($user_id)) {
                self::done(array('success' => false, 'message' => $user_id->get_error_message()));
            }

            wp_new_user_notification($user_id, null, 'user');
            $message = sprintf(__('Compte créé : %s a reçu un e-mail pour choisir son mot de passe.', 'smbb-tomo'), $intervenant['email']);
        }

        $wpdb->update(Lookup::table('tomo_intervenant'), array('user_id' => (int) $user_id), array('id' => $id));
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

        $d = 'smbb-tomo';
        $creneau_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::ROLL_CALL . '_' . $creneau_id);
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau || !self::canSeeCreneau($creneau_id)) {
            self::deny();
        }

        $presence = isset($_POST['presence']) && is_array($_POST['presence']) ? wp_unslash($_POST['presence']) : array();
        $notes = isset($_POST['note']) && is_array($_POST['note']) ? wp_unslash($_POST['note']) : array();
        $can_point = self::canPoint($creneau, self::forced());
        $pointed = 0;
        $noted = 0;
        $errors = array();

        foreach (Inscriptions::forCreneau($creneau_id) as $inscription) {
            $id = (int) $inscription['id'];

            if ($can_point && isset($presence[$id])) {
                $wanted = sanitize_key((string) $presence[$id]);
                $target = '';

                if (in_array($wanted, array('present', 'absent'), true) && $inscription['status'] !== $wanted) {
                    $target = $wanted;
                } elseif ($wanted === 'none' && in_array($inscription['status'], array('present', 'absent'), true)) {
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

                if ($note !== trim((string) $inscription['intervenant_note'])) {
                    $wpdb->update(Lookup::table('tomo_inscription'), array(
                        'intervenant_note' => $note !== '' ? $note : null,
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
        $creneau_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::ONSITE_SALE . '_' . $creneau_id);
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau || !self::canSeeCreneau($creneau_id) || !\Smbb\Tomo\WooCommerce\Module::active()) {
            self::deny();
        }

        if (!self::canPoint($creneau, self::forced())) {
            self::done(array('success' => false, 'message' => __("Ce n'est pas le jour de l'atelier : la caisse est fermée. Activez « Accès forcé » pour l'ouvrir.", 'smbb-tomo')));
        }

        self::done(self::saleFromRequest($creneau));
    }

    /**
     * Vente sur place lue dans la requête (formulaire de la caisse), en admin-post comme en Ajax.
     *
     * @return array{success:bool,message:string}
     */
    public static function saleFromRequest(array $creneau)
    {
        $lines = isset($_POST['qty']) && is_array($_POST['qty']) ? array_map('absint', wp_unslash($_POST['qty'])) : array(); // phpcs:ignore WordPress.Security.NonceVerification
        $customer = array();

        foreach (array('first_name', 'last_name', 'email', 'phone') as $key) {
            $customer[$key] = isset($_POST['customer'][$key]) ? sanitize_text_field(wp_unslash($_POST['customer'][$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        }

        $mode = isset($_POST['mode']) && $_POST['mode'] === 'link' ? 'link' : 'paid'; // phpcs:ignore WordPress.Security.NonceVerification
        $method = isset($_POST['method']) ? sanitize_key(wp_unslash($_POST['method'])) : 'card'; // phpcs:ignore WordPress.Security.NonceVerification

        // Vente à un participant : son compte client, si la réservation en a un.
        $inscription_id = isset($_POST['inscription']) ? absint($_POST['inscription']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $inscription = $inscription_id ? Inscriptions::find($inscription_id) : null;
        $inscription = $inscription && (int) $inscription['creneau_id'] === (int) $creneau['id'] ? $inscription : null;

        return \Smbb\Tomo\WooCommerce\OnsiteSales::create($creneau, $lines, $customer, $mode, $method, Caisse::context(), $inscription ? (int) $inscription['customer_id'] : 0, $inscription ? (int) $inscription['id'] : 0);
    }

    public static function onsiteCancel()
    {
        $order_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::ONSITE_CANCEL . '_' . $order_id);
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $creneau_id = $order ? (int) $order->get_meta(\Smbb\Tomo\WooCommerce\OnsiteSales::META_CRENEAU) : 0;

        if (!$order || !$creneau_id || !self::canSeeCreneau($creneau_id)) {
            self::deny();
        }

        self::done(Caisse::cancel($order_id, $creneau_id));
    }

    public static function onsiteResend()
    {
        $order_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::ONSITE_RESEND . '_' . $order_id);
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $creneau_id = $order ? (int) $order->get_meta(\Smbb\Tomo\WooCommerce\OnsiteSales::META_CRENEAU) : 0;

        if (!$order || !$creneau_id || !self::canSeeCreneau($creneau_id)) {
            self::deny();
        }

        if ($order->is_paid()) {
            self::done(array('success' => false, 'message' => __('Cette commande est déjà payée.', 'smbb-tomo')));
        }

        self::done(array('success' => true, 'message' => \Smbb\Tomo\WooCommerce\OnsiteSales::sendLink($order, Creneaux::find($creneau_id))));
    }

    /**
     * Caisse : rembourse une ligne d'une vente en bon d'achat (retour produit).
     */
    public static function onsiteRefund()
    {
        $order_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::ONSITE_REFUND . '_' . $order_id);
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $creneau_id = $order ? (int) $order->get_meta(\Smbb\Tomo\WooCommerce\OnsiteSales::META_CRENEAU) : 0;

        if (!$order || !$creneau_id || !self::canSeeCreneau($creneau_id)) {
            self::deny();
        }

        $creneau = Creneaux::find($creneau_id);

        if (!$creneau || !self::canPoint($creneau, self::forced())) {
            self::done(array('success' => false, 'message' => __("Ce n'est pas le jour de l'atelier : la caisse est fermée. Activez « Accès forcé » pour l'ouvrir.", 'smbb-tomo')));
        }

        self::done(self::refundFromRequest($order, $creneau));
    }

    /**
     * Retour en bon d'achat lu dans la requête (cases cochées de la vente), en admin-post comme en Ajax.
     *
     * @param \WC_Order $order
     * @return array{success:bool,message:string}
     */
    public static function refundFromRequest($order, $creneau)
    {
        $lines = array();

        foreach (isset($_POST['lines']) && is_array($_POST['lines']) ? wp_unslash($_POST['lines']) : array() as $item_id => $qty) { // phpcs:ignore WordPress.Security.NonceVerification
            $lines[absint($item_id)] = absint($qty);
        }

        return \Smbb\Tomo\WooCommerce\Refunds::toVoucher($order, $lines, !empty($_POST['send']), $creneau); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Jour de l'atelier : pointage et scan libres. Les autres jours, il faut d'abord activer
     * « Pointer quand même » sur la feuille d'appel (envoyé en hors_date=1).
     */
    public static function isRollCallDay(array $creneau)
    {
        return $creneau['status'] !== 'cancelled' && !empty($creneau['start_date']) && $creneau['start_date'] === current_time('Y-m-d');
    }

    public static function canPoint(array $creneau, $forced = false)
    {
        return $creneau['status'] !== 'cancelled' && !empty($creneau['start_date']) && (self::isRollCallDay($creneau) || $forced);
    }

    public static function forced()
    {
        return !empty($_POST['hors_date']);
    }

    /**
     * À partir du jour de l'atelier (tableau de bord, vente sur place), sauf créneau annulé.
     */
    public static function rollCallOpen(array $creneau)
    {
        return $creneau['status'] !== 'cancelled' && !empty($creneau['start_date']) && $creneau['start_date'] <= current_time('Y-m-d');
    }

    /**
     * Gestionnaire, ou intervenant affecté au créneau.
     */
    public static function canSeeCreneau($creneau_id)
    {
        if (Roles::canManage()) {
            return true;
        }

        if (!current_user_can(Roles::CAP_INTERVENANT)) {
            return false;
        }

        $intervenant = Lookup::intervenantForUser(get_current_user_id());
        $creneau = Creneaux::find($creneau_id);

        return $intervenant && $creneau && in_array((int) $intervenant['id'], Lookup::ids($creneau['intervenant_ids']), true);
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

        if (!in_array($scope, array('participant', 'intervenant'), true) && !\Smbb\Tomo\Support\Templates::message($scope)) {
            self::done(array('success' => false, 'message' => __('Message inconnu.', 'smbb-tomo')));
        }

        $count = \Smbb\Tomo\Support\Templates::reset($scope);

        self::done(array('success' => true, 'message' => $count
            ? sprintf(_n('%d message remis au texte d\'origine.', '%d messages remis au texte d\'origine.', $count, 'smbb-tomo'), $count)
            : __('Les textes étaient déjà ceux d\'origine.', 'smbb-tomo')));
    }

    /**
     * Fiche d'une inscription : envoie tout de suite le lien des documents encore à remplir.
     */
    public static function sendDocuments()
    {
        $d = 'smbb-tomo';
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::DOCUMENTS_SEND . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        $inscription = \Smbb\Tomo\Repository\Inscriptions::find($id);
        $inscription = $inscription ? \Smbb\Tomo\Service\Passes::root($inscription) : null;

        if (!$inscription) {
            self::done(array('success' => false, 'message' => __('Inscription introuvable.', $d)));
        }

        if (\Smbb\Tomo\Service\Documents::pendingUrl($inscription) === '') {
            self::done(array('success' => false, 'message' => __('Rien à demander : tous les documents sont remplis, ou la réservation n\'est plus active.', $d)));
        }

        $creneau = $inscription['creneau_id'] ? Creneaux::find($inscription['creneau_id']) : null;
        $count = \Smbb\Tomo\Service\Notifier::toParticipant('documents_request', $inscription, $creneau);

        self::done($count > 0
            ? array('success' => true, 'message' => sprintf(_n('%d message envoyé. Il apparaît dans « Demandes envoyées ».', '%d messages envoyés. Ils apparaissent dans « Demandes envoyées ».', $count, $d), $count))
            : array('success' => false, 'message' => __('Aucun message envoyé : pas d\'adresse e-mail ni de téléphone, ou les deux canaux de « Demande de documents » sont coupés dans Réglages › Messages.', $d)));
    }

    /**
     * « Demander les dispos » : une date (id) ou toutes les dates à venir (id = 0).
     */
    public static function requestDispos()
    {
        $d = 'smbb-tomo';
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::DISPO_REQUEST . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        $result = \Smbb\Tomo\Service\Availability::request($id ? array($id) : null);

        self::done($result['people'] > 0
            ? array('success' => true, 'message' => sprintf(_n('Demande envoyée à %1$d intervenant (%2$d date sans réponse).', 'Demande envoyée à %1$d intervenants (%2$d dates sans réponse).', $result['people'], $d), $result['people'], $result['dates']))
            : array('success' => false, 'message' => __('Personne à relancer : tous les intervenants prévus ont déjà répondu.', $d)));
    }

    /**
     * Réponse saisie par l'équipe à la place de l'intervenant (fiche de la date).
     */
    public static function setDispo()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::DISPO_SET . '_' . $id);

        if (!Roles::canManage()) {
            self::deny();
        }

        $intervenant_id = isset($_GET['intervenant']) ? absint($_GET['intervenant']) : 0;
        $answer = isset($_GET['answer']) ? sanitize_key(wp_unslash($_GET['answer'])) : '';
        $creneau = Creneaux::find($id);

        if (!$creneau || !\Smbb\Tomo\Service\Availability::concerns($creneau, $intervenant_id)) {
            self::done(array('success' => false, 'message' => __("Cet intervenant n'est pas prévu sur cette date.", 'smbb-tomo')));
        }

        \Smbb\Tomo\Service\Availability::set($id, $intervenant_id, $answer, 'admin');
        self::done(array('success' => true, 'message' => __('Disponibilité enregistrée.', 'smbb-tomo')));
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
        $d = 'smbb-tomo';
        $creneau_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::WALKIN . '_' . $creneau_id);
        $creneau = Creneaux::find($creneau_id);

        if (!$creneau || !self::canSeeCreneau($creneau_id)) {
            self::deny();
        }

        if (!self::canPoint($creneau, self::forced())) {
            self::done(array('success' => false, 'message' => __("Ce n'est pas le jour de l'atelier : activez « Pointer quand même » d'abord.", $d)));
        }

        $row = isset($_POST['walkin']) && is_array($_POST['walkin']) ? wp_unslash($_POST['walkin']) : array();
        $first = sanitize_text_field(isset($row['first_name']) ? (string) $row['first_name'] : '');
        $last = sanitize_text_field(isset($row['last_name']) ? (string) $row['last_name'] : '');
        $email_raw = trim(isset($row['email']) ? (string) $row['email'] : '');
        $email = strtolower(sanitize_email($email_raw));
        $phone = \Smbb\Tomo\Support\Input::phone(isset($row['phone']) ? (string) $row['phone'] : '');

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
            $created = \Smbb\Tomo\Service\Accounts::customer($email, $first, $last, $phone, $creneau);

            if (is_wp_error($created)) {
                self::done(array('success' => false, 'message' => $created->get_error_message()));
            }

            $customer_id = (int) $created['id'];
            $account = $created['created'] ? __('compte client créé, lien pour choisir le mot de passe envoyé', $d) : __('compte client existant rattaché', $d);
        }


        // Paiement par SMS avant ajout : commande en attente, la personne arrive sur la liste au paiement.
        if ($mode === 'sms') {
            if (!class_exists('\\Smbb\\Tomo\\WooCommerce\\Walkins') || !\Smbb\Tomo\WooCommerce\Module::active()) {
                self::done(array('success' => false, 'message' => __("WooCommerce n'est pas actif : pas de paiement en ligne possible.", $d)));
            }

            $result = \Smbb\Tomo\WooCommerce\Walkins::createOrder($creneau, array('first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone), $customer_id);

            if (!empty($result['success']) && $account !== '') {
                $result['message'] .= ' ' . ucfirst($account) . '.';
            }

            self::done($result);
        }

        $now = current_time('mysql');
        $id = Booking::insertInscription(array(
            'creneau_id' => (int) $creneau_id,
            'atelier_id' => (int) $creneau['atelier_id'],
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
        \Smbb\Tomo\Service\History::record($id, 'walkin', sprintf(__('Participant imprévu ajouté sur place par %s, pointé présent.', $d), $user && $user->exists() ? $user->display_name : '—') . ($account !== '' ? ' (' . $account . ')' : ''), (int) $creneau_id);
        \Smbb\Tomo\Service\Capacity::refresh($creneau_id);
        do_action('smbb_tomo_walkin_added', (int) $id, (int) $creneau_id);

        $message = sprintf(__('%s ajouté(e) à la liste, pointé(e) présent(e).', $d), trim($first . ' ' . $last));
        $message .= $account !== '' ? ' ' . ucfirst($account) . '.' : ' ' . __("Pas d'e-mail : pas de compte client.", $d);

        View::flash(array('success' => true, 'message' => $message));
        $back = wp_get_referer() ?: View::url('tomo_espace', array('creneau' => (int) $creneau_id));
        wp_safe_redirect(remove_query_arg('imprevu', $back) . '#tomo-row-' . (int) $id);
        exit;
    }

    private static function deny()
    {
        wp_die(esc_html__('Action non autorisée.', 'smbb-tomo'), '', array('response' => 403));
    }

    private static function done(array $result)
    {
        View::flash($result);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=smbb-codetool-tomo'));
        exit;
    }
}
