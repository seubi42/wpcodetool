<?php

namespace Smbb\Passflow\CodeTool;

use Smbb\Passflow\Service\Demo;
use Smbb\Passflow\Service\Onboarding;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Assistant de démarrage de Passflow (codetool/wizards/passflow.json) : ce que fait chaque étape
 * en plus du formulaire (réglages WooCommerce, TVA, profil, première offre, démo, mise en production).
 */
final class WizardHooks
{
    public function stepDone($key, array $context)
    {
        switch ($key) {
            case 'profile':
                return Onboarding::memory('profile', '') !== '' ? true : null;
            case 'offer':
                return Onboarding::offer() ? true : null;
            case 'demo':
                return Demo::exists() ? true : null;
        }

        return null;
    }

    public function stepContext($key, array $context)
    {
        switch ($key) {
            case 'shop':
                return array('checklist' => Onboarding::wooChecklist(), 'fake_card' => Onboarding::fakeCardChoice());
            case 'company':
                return array('vat_rate' => Onboarding::currentRate() ?: '20');
            case 'offer':
                $offer = Onboarding::offer();
                $defaults = Onboarding::offerDefaults();

                return array('offer' => $offer ? array_merge($defaults, array_intersect_key($offer, $defaults), array('image_id' => (int) $offer['image_id'])) : $defaults, 'offer_exists' => (bool) $offer);
            case 'demo':
                return array('summary' => Demo::summary(), 'demo_exists' => Demo::exists());
            case 'end':
                return array('demo_exists' => Demo::exists(), 'offer' => Onboarding::offer());
        }

        return array();
    }

    public function applyProfile($key, array $profile, array $context)
    {
        return Onboarding::applyProfile($profile);
    }

    public function saveStep($key, array $data, array $context)
    {
        $d = 'smbb-passflow';

        switch ($key) {
            case 'shop':
                Onboarding::wooDefaults(isset($data['wizard_fake_card']) ? (string) $data['wizard_fake_card'] : '');

                return true;

            case 'company':
                Onboarding::syncTaxes(isset($data['wizard_vat_rate']) ? (string) $data['wizard_vat_rate'] : '');
                Onboarding::syncStoreAddress();

                return true;

            case 'offer':
                return Onboarding::saveOffer($data);

            case 'brand':
                return $this->brand();

            case 'demo':
                if (empty($data['wizard_demo']) || Demo::exists()) {
                    return true;
                }

                $created = Demo::create();

                return $created === true ? array('message' => __('Données de démo créées. Le mode test est actif : les messages partent à votre adresse.', $d)) : $created;

            case 'end':
                return $this->goLive($data);
        }

        return true;
    }

    /**
     * Couleur et logo de marque repris par CodeTool : accent du back-office Passflow, logo de connexion.
     */
    private function brand()
    {
        $color = (string) Settings::get('ticket_color');

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) && Settings::get('ticket_color_mode') === 'fixed') {
            Onboarding::saveSettings(array('admin_accent' => $color));
        }

        $logo = (int) Settings::get('ticket_logo_id');

        if ($logo && class_exists('\\Smbb\\WpCodeTool\\Admin\\Skin')) {
            $look = \Smbb\WpCodeTool\Admin\Skin::settings();

            if (empty($look['login_logo'])) {
                \Smbb\WpCodeTool\Admin\Skin::saveSettings($look['theme'], $look['accent'], array('login_logo' => $logo));
            }
        }

        return true;
    }

    /**
     * Dernière étape : passer en production si demandé (mode test et factures démo coupés).
     */
    private function goLive(array $data)
    {
        if (!empty($data['wizard_delete_demo']) && Demo::exists()) {
            Demo::delete();
        }

        if (!empty($data['wizard_go_live'])) {
            if (Demo::exists()) {
                return __('Supprimez d\'abord les données de démo (case ci-dessus) : tant qu\'elles existent, le mode test reste actif.', 'smbb-passflow');
            }

            Onboarding::saveSettings(array('test_mode' => 0, 'invoice_demo' => 0));
            Onboarding::fakeCard('none');
        }

        return true;
    }

    public function finish(array $context)
    {
        return admin_url('admin.php?page=smbb-codetool-passflow');
    }
}
