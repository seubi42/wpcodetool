<?php

namespace Smbb\WpCodeTool\Woo;

defined('ABSPATH') || exit;

/**
 * Passerelle WooCommerce « Carte bancaire (TEST) » : voir FakeCard.
 *
 * Champs postés (tunnel classique, page « Payer la commande » et bloc Commande) :
 * smbb_fake_card_number, smbb_fake_card_expiry (MM / AA), smbb_fake_card_cvc,
 * smbb_fake_card_scenario, smbb_fake_card_threeds (ok | failed, rempli par la lightbox).
 */
class FakeCardGateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = FakeCard::GATEWAY_ID;
        $this->has_fields = true;
        $this->method_title = __('Carte bancaire FICTIVE (CodeTool)', 'smbb-wpcodetool');
        $this->method_description = __('Outil de développement : formulaire de carte façon Stripe, 3D Secure simulé, commande en cours sans aucun encaissement. Activé depuis CodeTool › WooCommerce.', 'smbb-wpcodetool');
        $this->supports = array('products', 'refunds');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Activer', 'smbb-wpcodetool'),
                'type' => 'checkbox',
                'label' => __('Proposer la carte fictive au paiement', 'smbb-wpcodetool'),
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Titre', 'smbb-wpcodetool'),
                'type' => 'text',
                'description' => __('« TEST » est ajouté s\'il n\'y figure pas.', 'smbb-wpcodetool'),
                'default' => __('Carte bancaire (TEST)', 'smbb-wpcodetool'),
            ),
            'description' => array(
                'title' => __('Description', 'smbb-wpcodetool'),
                'type' => 'textarea',
                'default' => __('Paiement simulé pour les tests : aucune carte n\'est débitée.', 'smbb-wpcodetool'),
            ),
        );
    }

    /**
     * Le libellé dit toujours « TEST », même si quelqu'un l'a retiré du titre.
     */
    public function get_title()
    {
        $title = (string) parent::get_title();

        if (stripos($title, 'test') === false) {
            $title = trim($title . ' (TEST)');
        }

        return $title;
    }

    public function get_icon()
    {
        $icon = '<span class="smbb-fc-icons"><span class="smbb-fc-badge is-test">TEST</span></span>';

        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    public function is_available()
    {
        return FakeCard::enabled() && FakeCard::visibleForCurrentUser() && parent::is_available();
    }

    /**
     * Formulaire embarqué (le JS le remplit avec une carte aléatoire, chiffre par chiffre).
     */
    public function payment_fields()
    {
        $settings = FakeCard::settings();

        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
        ?>
        <div class="smbb-fc" data-smbb-fc="classic" data-amount="<?php echo esc_attr($this->amountLabel()); ?>">
            <div class="smbb-fc-ribbon"><?php esc_html_e('Mode test : aucune carte n\'est débitée', 'smbb-wpcodetool'); ?></div>
            <div class="smbb-fc-field">
                <label for="smbb-fc-number"><?php esc_html_e('Numéro de carte', 'smbb-wpcodetool'); ?></label>
                <div class="smbb-fc-input has-brand">
                    <input id="smbb-fc-number" type="text" name="smbb_fake_card_number" inputmode="numeric" autocomplete="off" placeholder="1234 1234 1234 1234" data-fc="number">
                    <span class="smbb-fc-brand" data-fc="brand" aria-hidden="true"></span>
                </div>
            </div>
            <div class="smbb-fc-row">
                <div class="smbb-fc-field">
                    <label for="smbb-fc-expiry"><?php esc_html_e('Expiration', 'smbb-wpcodetool'); ?></label>
                    <div class="smbb-fc-input"><input id="smbb-fc-expiry" type="text" name="smbb_fake_card_expiry" inputmode="numeric" autocomplete="off" placeholder="MM / AA" data-fc="expiry"></div>
                </div>
                <div class="smbb-fc-field">
                    <label for="smbb-fc-cvc"><?php esc_html_e('CVC', 'smbb-wpcodetool'); ?></label>
                    <div class="smbb-fc-input"><input id="smbb-fc-cvc" type="text" name="smbb_fake_card_cvc" inputmode="numeric" autocomplete="off" placeholder="123" data-fc="cvc"></div>
                </div>
            </div>
            <div class="smbb-fc-tools">
                <button type="button" class="smbb-fc-shuffle" data-fc="shuffle"><span aria-hidden="true">⟳</span> <?php esc_html_e('Autre carte', 'smbb-wpcodetool'); ?></button>
                <label class="smbb-fc-scenario">
                    <span><?php esc_html_e('Scénario', 'smbb-wpcodetool'); ?></span>
                    <select name="smbb_fake_card_scenario" data-fc="scenario">
                        <?php foreach (FakeCard::scenarios() as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['scenario'], $key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <input type="hidden" name="smbb_fake_card_threeds" value="" data-fc="threeds">
        </div>
        <?php
    }

    /**
     * Montant affiché dans la lightbox 3D Secure (panier, ou commande sur « Payer la commande »).
     */
    private function amountLabel()
    {
        $total = 0;

        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            $order = wc_get_order(absint(get_query_var('order-pay')));
            $total = $order ? $order->get_total() : 0;
        } elseif (WC()->cart) {
            $total = WC()->cart->get_total('edit');
        }

        return html_entity_decode(wp_strip_all_tags(wc_price($total)), ENT_QUOTES, 'UTF-8');
    }

    public function validate_fields()
    {
        $error = $this->fieldError($this->posted());

        if ($error !== '') {
            wc_add_notice($error, 'error');

            return false;
        }

        return true;
    }

    /**
     * @return array{number:string,expiry:string,cvc:string,scenario:string,threeds:string}
     */
    private function posted()
    {
        // phpcs:disable WordPress.Security.NonceVerification -- le nonce du tunnel est vérifié par WooCommerce.
        $read = static function ($key) {
            return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        };
        // phpcs:enable

        $scenarios = FakeCard::scenarios();
        $scenario = sanitize_key($read('smbb_fake_card_scenario'));

        return array(
            'number' => preg_replace('/\D+/', '', $read('smbb_fake_card_number')),
            'expiry' => $read('smbb_fake_card_expiry'),
            'cvc' => preg_replace('/\D+/', '', $read('smbb_fake_card_cvc')),
            'scenario' => isset($scenarios[$scenario]) ? $scenario : FakeCard::settings()['scenario'],
            'threeds' => sanitize_key($read('smbb_fake_card_threeds')),
        );
    }

    private function fieldError(array $data)
    {
        $length = strlen($data['number']);

        if ($length < 13 || $length > 19 || !FakeCard::luhn($data['number'])) {
            return __('Le numéro de carte n\'est pas valide.', 'smbb-wpcodetool');
        }

        if (!preg_match('/^\s*(\d{1,2})\s*\/\s*(\d{2}|\d{4})\s*$/', $data['expiry'], $match)) {
            return __('La date d\'expiration n\'est pas valide.', 'smbb-wpcodetool');
        }

        $month = (int) $match[1];
        $year = (int) $match[2];
        $year = $year < 100 ? 2000 + $year : $year;
        $now = (int) gmdate('Y') * 12 + (int) gmdate('n');

        if ($month < 1 || $month > 12 || $year * 12 + $month < $now) {
            return __('La carte est expirée ou sa date n\'est pas valide.', 'smbb-wpcodetool');
        }

        $cvc = FakeCard::brand($data['number']) === 'amex' ? 4 : 3;

        if (strlen($data['cvc']) !== $cvc) {
            return __('Le code de sécurité n\'est pas valide.', 'smbb-wpcodetool');
        }

        return '';
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Commande introuvable.', 'smbb-wpcodetool'), 'error');

            return array('result' => 'failure');
        }

        $data = $this->posted();
        $error = $this->fieldError($data);

        if ($error !== '') {
            wc_add_notice($error, 'error');

            return array('result' => 'failure');
        }

        $brand = FakeCard::brand($data['number']);
        $card = FakeCard::brandLabel($brand) . ' •••• ' . substr($data['number'], -4);
        $prefix = __('[Paiement FICTIF CodeTool]', 'smbb-wpcodetool') . ' ';

        $failures = array(
            'declined' => __('Votre carte a été refusée. Essayez une autre carte.', 'smbb-wpcodetool'),
            'funds' => __('Fonds insuffisants sur cette carte.', 'smbb-wpcodetool'),
        );

        if (isset($failures[$data['scenario']])) {
            return $this->fail($order, $failures[$data['scenario']], $prefix . $card . ' : ' . $failures[$data['scenario']]);
        }

        if (FakeCard::needs3ds($data['scenario'])) {
            if ($data['threeds'] === 'failed' || $data['scenario'] === '3ds_fail') {
                return $this->fail($order, __('L\'authentification 3D Secure a échoué. Le paiement n\'a pas abouti.', 'smbb-wpcodetool'), $prefix . $card . ' : ' . __('3D Secure refusé.', 'smbb-wpcodetool'));
            }

            if ($data['threeds'] !== 'ok') {
                wc_add_notice(__('L\'authentification 3D Secure n\'a pas eu lieu (JavaScript bloqué ?). Réessayez.', 'smbb-wpcodetool'), 'error');

                return array('result' => 'failure');
            }
        }

        $transaction = 'fake_' . strtolower(wp_generate_password(20, false, false));

        $order->update_meta_data('_smbb_fake_payment', array(
            'brand' => $brand,
            'last4' => substr($data['number'], -4),
            'scenario' => $data['scenario'],
            'threeds' => $data['threeds'] === 'ok',
            'at' => time(),
        ));
        $order->add_order_note($prefix . sprintf(
            /* translators: 1: carte, 2: 3D Secure oui/non */
            __('%1$s acceptée%2$s. Aucun encaissement réel.', 'smbb-wpcodetool'),
            $card,
            $data['threeds'] === 'ok' ? ' ' . __('après 3D Secure', 'smbb-wpcodetool') : ''
        ));
        // Comme un vrai paiement : statut « en cours » (ou « terminée » si tout est virtuel/téléchargeable),
        // stock réduit, e-mails WooCommerce envoyés, hooks woocommerce_payment_complete déclenchés.
        $order->payment_complete($transaction);

        if (WC()->cart) {
            WC()->cart->empty_cart();
        }

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

    private function fail(\WC_Order $order, $message, $note)
    {
        $order->update_status('failed', $note);
        wc_add_notice($message, 'error');

        return array('result' => 'failure');
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        if ($order) {
            $order->add_order_note(sprintf(
                /* translators: 1: montant, 2: motif */
                __('[Paiement FICTIF CodeTool] Remboursement simulé de %1$s. %2$s', 'smbb-wpcodetool'),
                wp_strip_all_tags(wc_price((float) $amount, array('currency' => $order->get_currency()))),
                (string) $reason
            ));
        }

        return true;
    }
}
