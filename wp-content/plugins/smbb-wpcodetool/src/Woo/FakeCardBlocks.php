<?php

namespace Smbb\WpCodeTool\Woo;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * La carte fictive dans le bloc Commande de WooCommerce (assets/fake-card-blocks.js).
 *
 * Le bloc envoie les champs dans paymentMethodData ; WooCommerce les remet dans $_POST
 * avant d'appeler FakeCardGateway::process_payment(), comme pour le tunnel classique.
 */
final class FakeCardBlocks extends AbstractPaymentMethodType
{
    protected $name = FakeCard::GATEWAY_ID;

    public function initialize()
    {
        $settings = get_option(FakeCard::GATEWAY_OPTION, array());
        $this->settings = is_array($settings) ? $settings : array();
    }

    public function is_active()
    {
        return FakeCard::enabled()
            && FakeCard::visibleForCurrentUser()
            && $this->get_setting('enabled', 'yes') === 'yes';
    }

    public function get_payment_method_script_handles()
    {
        FakeCard::registerAssets();
        wp_enqueue_style('smbb-fake-card');

        return array('smbb-fake-card-blocks');
    }

    public function get_payment_method_data()
    {
        $title = (string) $this->get_setting('title', __('Carte bancaire (TEST)', 'smbb-wpcodetool'));

        if (stripos($title, 'test') === false) {
            $title = trim($title . ' (TEST)');
        }

        return array(
            'title' => $title,
            'description' => (string) $this->get_setting('description', ''),
            'supports' => array('products'),
        );
    }
}
