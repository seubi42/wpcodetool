<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Bons cadeaux : une façade sur les codes promo WooCommerce, sans autre stockage.
 *
 * - créer : un code + un montant → code promo « remise fixe sur le panier », utilisable une seule fois ;
 * - suivre : tous les codes WooCommerce, utilisés ou non ;
 * - imprimer : un PDF avec un QR code qui ouvre la boutique avec le bon prêt à s'appliquer ;
 * - utiliser en boutique : on retrouve le bon (code ou QR), on confirme, il est consommé et on prévient
 *   le bénéficiaire et l'adresse des nouvelles commandes WooCommerce.
 */
final class GiftCards
{
    const META = '_passflow_gift_card';
    const SESSION = 'passflow_gift_code';
    const QUERY = 'passflow_voucher';
    const META_EMAIL = '_passflow_gift_email';
    const META_REDEEMED = '_passflow_redeemed';

    public static function hooks()
    {
        add_action('template_redirect', array(__CLASS__, 'fromLink'));
        add_action('woocommerce_add_to_cart', array(__CLASS__, 'applyPending'), 20);
        add_action('woocommerce_before_cart', array(__CLASS__, 'applyPending'));
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'applyPending'));

        // Juste après la commande, WooCommerce revérifie le panier : le bon (usage unique) vient d'être compté,
        // il le juge épuisé et affiche « code retiré » une fraction de seconde avant la page de remerciement.
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'rememberUsed'), 10, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'rememberUsed'), 10, 1);
        add_filter('woocommerce_coupon_error', array(__CLASS__, 'hideJustUsed'), 10, 3);

        // Page « Payer la commande » (lien de paiement des ventes sur place) : WooCommerce n'y propose
        // pas de code promo. On ajoute le champ, avant le formulaire de paiement.
        add_action('before_woocommerce_pay', array(__CLASS__, 'payForm'));
        add_action('wp_loaded', array(__CLASS__, 'applyOnPay'), 5);
    }

    /**
     * Commande en attente affichée sur la page de paiement.
     *
     * @return \WC_Order|null
     */
    private static function payOrder()
    {
        global $wp;

        $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        $order = $order_id ? wc_get_order($order_id) : null;
        $key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        return $order && hash_equals($order->get_order_key(), (string) $key) && $order->needs_payment() ? $order : null;
    }

    public static function payForm()
    {
        $order = self::payOrder();

        if (!$order || !wc_coupons_enabled()) {
            return;
        }

        $d = 'smbb-passflow';
        $pending = function_exists('WC') && WC()->session ? (string) WC()->session->get(self::SESSION) : '';
        $applied = $order->get_coupon_codes();

        echo '<form method="post" class="passflow-pay-coupon checkout_coupon woocommerce-form-coupon" style="margin:0 0 1.5em">';
        echo '<p style="margin:0 0 .5em">' . esc_html__("Vous avez un bon d'achat ou un code promo ?", $d) . '</p>';
        echo '<p class="form-row form-row-first" style="margin:0"><label class="screen-reader-text" for="passflow_pay_coupon">' . esc_html__('Code', $d) . '</label>'
            . '<input type="text" name="passflow_pay_coupon" id="passflow_pay_coupon" class="input-text" value="' . esc_attr($pending) . '" placeholder="' . esc_attr__('Code du bon', $d) . '" autocomplete="off" style="text-transform:uppercase"></p>';
        echo '<p class="form-row form-row-last" style="margin:0"><button type="submit" class="button' . esc_attr(function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : '') . '" name="passflow_pay_coupon_apply" value="1">' . esc_html__('Appliquer', $d) . '</button></p>';
        echo '<input type="hidden" name="passflow_pay_order" value="' . (int) $order->get_id() . '"><input type="hidden" name="passflow_pay_key" value="' . esc_attr($order->get_order_key()) . '">';
        wp_nonce_field('passflow_pay_coupon_' . $order->get_id(), 'passflow_pay_coupon_nonce');
        echo '<div class="clear"></div>';

        if ($applied) {
            echo '<p style="margin:.5em 0 0;font-size:.9em">' . esc_html(sprintf(__('Déjà appliqué : %s', $d), strtoupper(implode(', ', $applied)))) . '</p>';
        }

        echo '</form>';
    }

    /**
     * Applique le code à la commande. Si le bon couvre tout, la commande est réglée.
     */
    public static function applyOnPay()
    {
        if (empty($_POST['passflow_pay_coupon_apply']) || empty($_POST['passflow_pay_order']) || !function_exists('wc_get_order')) {
            return;
        }

        $d = 'smbb-passflow';
        $order_id = absint($_POST['passflow_pay_order']);
        $order = wc_get_order($order_id);
        $key = isset($_POST['passflow_pay_key']) ? wc_clean(wp_unslash($_POST['passflow_pay_key'])) : '';

        if (!$order || !isset($_POST['passflow_pay_coupon_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['passflow_pay_coupon_nonce'])), 'passflow_pay_coupon_' . $order_id)
            || !hash_equals($order->get_order_key(), (string) $key) || !$order->needs_payment()) {
            return;
        }

        // Commande d'un compte client : seulement ce compte (comme le paiement lui-même).
        if ($order->get_customer_id() && get_current_user_id() !== (int) $order->get_customer_id()) {
            wc_add_notice(__('Connectez-vous avec le compte de la commande pour utiliser un bon.', $d), 'error');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        $code = self::normalize(isset($_POST['passflow_pay_coupon']) ? wp_unslash($_POST['passflow_pay_coupon']) : '');

        if ($code === '') {
            wc_add_notice(__('Saisissez le code du bon.', $d), 'error');
        } else {
            $result = $order->apply_coupon(wc_format_coupon_code($code));

            if (is_wp_error($result)) {
                wc_add_notice(wp_strip_all_tags($result->get_error_message()), 'error');
            } else {
                $order->add_order_note(sprintf(__('Code %s appliqué par le client sur la page de paiement.', $d), $code));
                $order->save();

                if (function_exists('WC') && WC()->session) {
                    WC()->session->set(self::SESSION, null);
                }

                // Bon qui couvre toute la commande : rien à payer, la commande est réglée.
                if ((float) $order->get_total() <= 0) {
                    $order->payment_complete();
                    wc_add_notice(__('Votre bon couvre toute la commande : elle est réglée, merci !', $d), 'success');
                    wp_safe_redirect($order->get_checkout_order_received_url());
                    exit;
                }

                wc_add_notice(sprintf(__('Bon appliqué : il reste %s à régler.', $d), html_entity_decode(wp_strip_all_tags(wc_price($order->get_total(), array('currency' => $order->get_currency()))), ENT_QUOTES, 'UTF-8')), 'success');
            }
        }

        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /**
     * Retient (2 minutes, dans la session) les bons cadeaux de la commande qui vient d'être passée.
     *
     * @param int|\WC_Order $order_or_id
     */
    public static function rememberUsed($order_or_id, $posted = null, $order = null)
    {
        $order = $order instanceof \WC_Order ? $order : ($order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order($order_or_id));

        if (!$order || !function_exists('WC') || !WC()->session) {
            return;
        }

        $codes = array();

        foreach ($order->get_coupon_codes() as $code) {
            $coupon_id = wc_get_coupon_id_by_code($code);

            if ($coupon_id && get_post_meta($coupon_id, self::META, true)) {
                $codes[] = wc_format_coupon_code($code);
            }
        }

        if ($codes) {
            WC()->session->set('passflow_gift_just_used', array('codes' => $codes, 'until' => time() + 120));
        }
    }

    /**
     * Masque l'erreur « bon épuisé / retiré » d'un bon cadeau qu'on vient d'utiliser dans cette commande.
     * Les autres erreurs de code promo restent affichées.
     *
     * @param string     $message
     * @param int        $code
     * @param \WC_Coupon $coupon
     */
    public static function hideJustUsed($message, $code, $coupon)
    {
        if (!$coupon instanceof \WC_Coupon || !function_exists('WC') || !WC()->session) {
            return $message;
        }

        $used = WC()->session->get('passflow_gift_just_used');
        $reasons = array(\WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED, \WC_Coupon::E_WC_COUPON_INVALID_REMOVED);

        if (is_array($used) && !empty($used['until']) && $used['until'] >= time() && in_array((int) $code, $reasons, true)
            && in_array(wc_format_coupon_code($coupon->get_code()), (array) $used['codes'], true)) {
            return '';
        }

        return $message;
    }

    // ------------------------------------------------------------------
    // Création et lecture
    // ------------------------------------------------------------------

    /**
     * @param array{code?:string,amount?:mixed,recipient?:string,email?:string,expires?:string} $data
     * @return array{success:bool,message:string,id?:int}
     */
    public static function create(array $data)
    {
        $d = 'smbb-passflow';
        $code = isset($data['code']) ? self::normalize($data['code']) : '';
        $amount = isset($data['amount']) ? (float) str_replace(',', '.', (string) $data['amount']) : 0;
        $expires = isset($data['expires']) ? trim((string) $data['expires']) : '';

        if ($amount <= 0) {
            return array('success' => false, 'message' => __('Indiquez un montant.', $d));
        }

        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : '';

        if ($email !== '' && !is_email($email)) {
            return array('success' => false, 'message' => __('Adresse e-mail invalide.', $d));
        }

        if ($expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
            return array('success' => false, 'message' => __("Date d'expiration invalide.", $d));
        }

        if ($code === '') {
            $code = self::generateCode();
        } elseif (wc_get_coupon_id_by_code($code)) {
            return array('success' => false, 'message' => sprintf(__('Le code %s existe déjà.', $d), $code));
        }

        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount(wc_format_decimal($amount));
        $coupon->set_usage_limit(1);
        $coupon->set_individual_use(false);
        $coupon->set_description(isset($data['recipient']) ? sanitize_text_field($data['recipient']) : '');

        if ($expires !== '') {
            $coupon->set_date_expires($expires . ' 23:59:59');
        }

        $coupon->update_meta_data(self::META, 1);

        if ($email !== '') {
            $coupon->update_meta_data(self::META_EMAIL, $email);
        }

        $id = (int) $coupon->save();

        if ($id < 1) {
            return array('success' => false, 'message' => __("Le bon cadeau n'a pas pu être créé.", $d));
        }

        // Solde et historique : le bon peut servir en plusieurs fois.
        VoucherBalance::created(new \WC_Coupon($id), isset($data['recipient']) ? sanitize_text_field($data['recipient']) : '');

        return array('success' => true, 'id' => $id, 'message' => sprintf(__('Bon cadeau %1$s de %2$s créé.', $d), $code, self::amountText($coupon)));
    }

    /**
     * Tous les codes WooCommerce, du plus récent au plus ancien, avec leur état.
     *
     * @param string $filter all|unused|used|expired
     * @return array<int,array<string,mixed>>
     */
    public static function rows($filter = 'all', $search = '')
    {
        $args = array(
            'post_type' => 'shop_coupon',
            'post_status' => array('publish', 'future', 'draft', 'pending', 'private'),
            'numberposts' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        if ($search !== '') {
            $args['s'] = $search;
        }

        $posts = get_posts($args);
        $rows = array();

        foreach ($posts as $post) {
            $coupon = new \WC_Coupon($post->ID);
            $row = self::row($coupon, $post);

            if ($filter === 'all' || $row['state'] === $filter) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string,int>
     */
    public static function counts()
    {
        $counts = array('all' => 0, 'unused' => 0, 'used' => 0, 'expired' => 0);

        foreach (self::rows() as $row) {
            $counts['all']++;
            $counts[$row['state']]++;
        }

        return $counts;
    }

    /**
     * @param \WC_Coupon $coupon
     */
    public static function row($coupon, $post = null)
    {
        $limit = (int) $coupon->get_usage_limit();
        $used = (int) $coupon->get_usage_count();
        $expires = $coupon->get_date_expires();
        $expired = $expires && $expires->getTimestamp() < time();

        if ($limit > 0 && $used >= $limit) {
            $state = 'used';
        } elseif ($expired || ($post && $post->post_status !== 'publish')) {
            $state = 'expired';
        } else {
            $state = 'unused';
        }

        return array(
            'id' => (int) $coupon->get_id(),
            'code' => strtoupper((string) $coupon->get_code()),
            'amount' => self::amountText($coupon),
            'recipient' => (string) $coupon->get_description(),
            'limit' => $limit,
            'used' => $used,
            'used_by' => array_values(array_filter(array_map(array(__CLASS__, 'customerLabel'), (array) $coupon->get_used_by()))),
            'expires' => $expires ? $expires->date_i18n('j M Y') : '',
            'created' => $coupon->get_date_created() ? $coupon->get_date_created()->date_i18n('j M Y') : '',
            'state' => $state,
            'passflow' => (bool) $coupon->get_meta(self::META),
            'gift' => $coupon->get_discount_type() === 'fixed_cart' && $limit === 1,
            'edit_url' => (string) get_edit_post_link($coupon->get_id(), 'raw'),
            'email' => self::beneficiaryEmail($coupon),
            'redeemed' => self::redeemedText($coupon),
        );
    }

    // ------------------------------------------------------------------
    // Utilisation en boutique
    // ------------------------------------------------------------------

    /**
     * Retrouve un bon à partir de ce qui a été tapé ou scanné : le code, ou le lien du QR code (?passflow_voucher=CODE).
     *
     * @return \WC_Coupon|null
     */
    public static function find($input)
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $input)) {
            $query = (string) wp_parse_url($input, PHP_URL_QUERY);
            parse_str($query, $args);
            $input = isset($args[self::QUERY]) ? (string) $args[self::QUERY] : '';
        }

        $code = self::normalize(rawurldecode($input));
        $id = $code !== '' ? (int) wc_get_coupon_id_by_code($code) : 0;

        return $id ? new \WC_Coupon($id) : null;
    }

    /**
     * Consomme un bon en boutique : une utilisation comptée, la date et la personne notées, e-mails envoyés.
     *
     * @return array{success:bool,message:string}
     */
    public static function redeem($coupon_id, $note = '')
    {
        $d = 'smbb-passflow';
        $coupon = new \WC_Coupon((int) $coupon_id);

        if (!$coupon->get_id()) {
            return array('success' => false, 'message' => __('Bon introuvable.', $d));
        }

        $row = self::row($coupon, get_post($coupon->get_id()));

        if ($row['state'] !== 'unused') {
            return array('success' => false, 'message' => sprintf(__('Le bon %1$s ne peut pas être utilisé : %2$s.', $d), $row['code'], mb_strtolower(self::states()[$row['state']])));
        }

        $user = wp_get_current_user();
        $coupon->increase_usage_count(__('En boutique', $d));
        $coupon = new \WC_Coupon($coupon->get_id());
        $coupon->update_meta_data(self::META_REDEEMED, array(
            'at' => current_time('mysql'),
            'by' => (int) $user->ID,
            'by_name' => (string) $user->display_name,
            'note' => sanitize_text_field((string) $note),
        ));
        $coupon->save();
        VoucherBalance::redeemedInStore(new \WC_Coupon($coupon->get_id()), sanitize_text_field((string) $note));

        $sent = self::notifyRedeemed($coupon);

        return array(
            'success' => true,
            'message' => sprintf(__('Bon %1$s de %2$s utilisé.', $d), $row['code'], $row['amount'])
                . ($sent ? ' ' . sprintf(_n('Un e-mail de confirmation est parti à %s.', 'Des e-mails de confirmation sont partis à %s.', count($sent), $d), implode(', ', $sent)) : ''),
        );
    }

    /**
     * E-mail « bon utilisé » au bénéficiaire (e-mail noté sur le bon ou restriction d'e-mail WooCommerce)
     * et à l'adresse qui reçoit les nouvelles commandes WooCommerce.
     *
     * @return string[] adresses prévenues
     */
    private static function notifyRedeemed($coupon)
    {
        $d = 'smbb-passflow';
        $row = self::row($coupon);
        $data = (array) $coupon->get_meta(self::META_REDEEMED);
        $when = !empty($data['at']) ? mysql2date('j F Y à H\hi', $data['at']) : '';
        $shop = (string) get_bloginfo('name');
        $subject = sprintf(__('Bon cadeau %1$s utilisé le %2$s', $d), $row['code'], $when);
        $body = '<p>' . esc_html(sprintf(__('Le bon cadeau %1$s d\'une valeur de %2$s a été utilisé en boutique le %3$s.', $d), $row['code'], $row['amount'], $when)) . '</p>'
            . ($row['recipient'] !== '' ? '<p>' . esc_html(sprintf(__('Bénéficiaire : %s', $d), $row['recipient'])) . '</p>' : '')
            . (!empty($data['note']) ? '<p>' . esc_html(sprintf(__('Note : %s', $d), $data['note'])) . '</p>' : '')
            . '<p>' . esc_html(sprintf(__('Il n\'est plus valable. Merci de votre visite, %s.', $d), $shop)) . '</p>';
        $staff_body = $body . (!empty($data['by_name']) ? '<p>' . esc_html(sprintf(__('Enregistré par %s.', $d), $data['by_name'])) . '</p>' : '');

        $recipients = array();

        if ($row['email'] !== '') {
            $recipients[$row['email']] = $body;
        }

        foreach (self::shopRecipients() as $email) {
            $recipients[$email] = $staff_body;
        }

        $test = (int) Settings::get('test_mode') === 1 ? trim((string) Settings::get('test_email')) : '';
        $sent = array();

        foreach ($recipients as $email => $html) {
            $to = $test !== '' ? $test : $email;
            $message = function_exists('WC') && WC()->mailer() ? WC()->mailer()->wrap_message(__('Bon cadeau utilisé', $d), $html) : $html;
            $ok = function_exists('WC') && WC()->mailer()
                ? WC()->mailer()->send($to, $subject, $message)
                : wp_mail($to, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));

            if ($ok) {
                $sent[] = $email . ($test !== '' ? ' (' . sprintf(__('mode test : %s', $d), $test) . ')' : '');
            }
        }

        return $sent;
    }

    /**
     * Destinataires de l'e-mail « Nouvelle commande » de WooCommerce (à défaut, l'e-mail du site).
     *
     * @return string[]
     */
    private static function shopRecipients()
    {
        $settings = get_option('woocommerce_new_order_settings', array());
        $raw = is_array($settings) && !empty($settings['recipient']) ? (string) $settings['recipient'] : (string) get_option('admin_email');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'is_email'));
    }

    private static function beneficiaryEmail($coupon)
    {
        $email = (string) $coupon->get_meta(self::META_EMAIL);

        if ($email === '') {
            $restrictions = array_values(array_filter((array) $coupon->get_email_restrictions(), 'is_email'));
            $email = $restrictions ? (string) $restrictions[0] : '';
        }

        return strtolower($email);
    }

    private static function redeemedText($coupon)
    {
        $data = $coupon->get_meta(self::META_REDEEMED);

        if (!is_array($data) || empty($data['at'])) {
            return '';
        }

        return sprintf(__('En boutique le %s', 'smbb-passflow'), mysql2date('j M Y, H\hi', $data['at']))
            . (!empty($data['by_name']) ? ' (' . $data['by_name'] . ')' : '')
            . (!empty($data['note']) ? ' · ' . $data['note'] : '');
    }

    public static function states()
    {
        return array(
            'unused' => __('Non utilisé', 'smbb-passflow'),
            'used' => __('Utilisé', 'smbb-passflow'),
            'expired' => __('Expiré ou désactivé', 'smbb-passflow'),
        );
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------

    /**
     * Envoie le PDF d'un bon (A5 paysage) et termine la requête.
     */
    public static function pdf($coupon_id)
    {
        $coupon = new \WC_Coupon((int) $coupon_id);

        if (!$coupon->get_id() || !class_exists('\\TCPDF')) {
            wp_die(esc_html__('PDF indisponible : bon introuvable ou bibliothèque TCPDF absente (fournie par SMBB WP CodeTool).', 'smbb-passflow'));
        }

        $d = 'smbb-passflow';
        $row = self::row($coupon);
        $shop = (string) Settings::get('sender_name') ?: get_bloginfo('name');
        $link = self::link($row['code']);

        $pdf = new \TCPDF('L', 'mm', 'A5', true, 'UTF-8', false);
        $pdf->SetCreator($shop);
        $pdf->SetAuthor($shop);
        $pdf->SetTitle(sprintf(__('Bon cadeau %s', $d), $row['code']));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(14, 14, 14);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        // Cadre.
        $pdf->SetDrawColor(190, 160, 120);
        $pdf->SetLineWidth(0.6);
        $pdf->RoundedRect(8, 8, 194, 132, 4);

        $pdf->SetTextColor(60, 45, 35);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->SetXY(16, 18);
        $pdf->Cell(110, 6, $shop, 0, 1);

        $pdf->SetFont('dejavusans', 'B', 28);
        $pdf->SetXY(16, 28);
        $pdf->Cell(110, 14, __('Bon cadeau', $d), 0, 1);

        $pdf->SetFont('dejavusans', 'B', 34);
        $pdf->SetTextColor(150, 70, 60);
        $pdf->SetXY(16, 48);
        $pdf->Cell(110, 18, $row['amount'], 0, 1);

        $pdf->SetTextColor(60, 45, 35);
        $y = 72;

        if ($row['recipient'] !== '') {
            $pdf->SetFont('dejavusans', '', 12);
            $pdf->SetXY(16, $y);
            $pdf->MultiCell(110, 6, sprintf(__('Pour %s', $d), $row['recipient']), 0, 'L');
            $y = $pdf->GetY() + 3;
        }

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetXY(16, $y);
        $pdf->MultiCell(110, 5, implode("\n", array_filter(array(
            __('À utiliser en une seule fois sur notre boutique en ligne, pour réserver un atelier.', $d),
            $row['expires'] !== '' ? sprintf(__("Valable jusqu'au %s.", $d), $row['expires']) : '',
            __('Scannez le QR code, ou saisissez le code au moment de payer.', $d),
        ))), 0, 'L');

        // Code.
        $pdf->SetFont('dejavusansmono', 'B', 16);
        $pdf->SetFillColor(247, 241, 232);
        $pdf->SetXY(16, 112);
        $pdf->Cell(110, 12, $row['code'], 0, 1, 'C', true);

        // QR code.
        $pdf->write2DBarcode($link, 'QRCODE,M', 140, 30, 52, 52, array(
            'border' => false,
            'padding' => 0,
            'fgcolor' => array(60, 45, 35),
            'bgcolor' => false,
        ), 'N');
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY(136, 84);
        $pdf->MultiCell(60, 4, preg_replace('#^https?://#', '', home_url()), 0, 'C');

        nocache_headers();
        $pdf->Output('bon-cadeau-' . sanitize_file_name(strtolower($row['code'])) . '.pdf', 'D');
        exit;
    }

    // ------------------------------------------------------------------
    // QR code → boutique avec le bon
    // ------------------------------------------------------------------

    /**
     * Lien encodé dans le QR code : la boutique, avec le code à appliquer.
     */
    public static function link($code)
    {
        $base = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');

        return add_query_arg(self::QUERY, rawurlencode($code), $base ?: home_url('/'));
    }

    /**
     * ?passflow_voucher=CODE : on garde le code en session, il s'appliquera dès qu'il y aura un panier.
     */
    public static function fromLink()
    {
        if (empty($_GET[self::QUERY]) || !function_exists('WC') || !WC()->session) {
            return;
        }

        $code = self::normalize(wp_unslash($_GET[self::QUERY]));
        $coupon = new \WC_Coupon($code);
        $row = $coupon->get_id() ? self::row($coupon) : null;

        if (!$row || $row['state'] !== 'unused') {
            wc_add_notice(__("Ce bon cadeau n'est plus valable.", 'smbb-passflow'), 'error');
        } else {
            WC()->session->set_customer_session_cookie(true);
            WC()->session->set(self::SESSION, $code);
            wc_add_notice(sprintf(__('Votre bon cadeau de %s sera déduit de votre réservation.', 'smbb-passflow'), $row['amount']), 'success');
            self::applyPending();
        }

        wp_safe_redirect(remove_query_arg(self::QUERY));
        exit;
    }

    public static function applyPending()
    {
        if (!function_exists('WC') || !WC()->session || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $code = (string) WC()->session->get(self::SESSION);

        if ($code === '') {
            return;
        }

        if (!WC()->cart->has_discount(wc_format_coupon_code($code))) {
            WC()->cart->apply_coupon($code);
        }

        WC()->session->set(self::SESSION, null);
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    /**
     * Préfixe des codes (Réglages › Général › Bons d'achat) : lettres et chiffres, vide = pas de préfixe.
     */
    public static function prefix()
    {
        return \Smbb\Passflow\Support\Settings::companyCode();
    }

    /**
     * Code lisible, sans caractères ambigus (0/O, 1/I) : ABC-7KQ4-M2XP (préfixe = sigle de l'entreprise).
     */
    public static function generateCode()
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $prefix = self::prefix();

        do {
            $code = $prefix;

            for ($block = 0; $block < 2; $block++) {
                $code .= $code === '' ? '' : '-';

                for ($i = 0; $i < 4; $i++) {
                    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
            }
        } while (wc_get_coupon_id_by_code($code));

        return $code;
    }

    private static function normalize($code)
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $code));
    }

    /**
     * @param \WC_Coupon $coupon
     */
    private static function amountText($coupon)
    {
        if ($coupon->get_discount_type() === 'percent') {
            return wc_format_localized_decimal($coupon->get_amount()) . ' %';
        }

        return html_entity_decode(wp_strip_all_tags(wc_price($coupon->get_amount())), ENT_QUOTES, 'UTF-8');
    }

    private static function customerLabel($value)
    {
        if (is_numeric($value)) {
            $user = get_userdata((int) $value);

            return $user ? $user->user_email : '';
        }

        return (string) $value;
    }
}
