<?php

namespace Smbb\Passflow\Admin;

use Smbb\Passflow\Service\Demo;
use Smbb\Passflow\Service\Onboarding as Service;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

/**
 * Côté admin de l'assistant de démarrage : bloc de Réglages › Configuration (relancer l'assistant,
 * changer de profil, données de démo) et actions admin-post (supprimer la démo, les factures démo).
 */
final class Onboarding
{
    const DEMO_CREATE = 'passflow_demo_create';
    const DEMO_DELETE = 'passflow_demo_delete';
    const PURGE_INVOICES = 'passflow_invoice_demo_purge';

    public static function hooks()
    {
        add_action('admin_post_' . self::DEMO_CREATE, array(__CLASS__, 'demoCreate'));
        add_action('admin_post_' . self::DEMO_DELETE, array(__CLASS__, 'demoDelete'));
        add_action('admin_post_' . self::PURGE_INVOICES, array(__CLASS__, 'purgeInvoices'));
    }

    public static function available()
    {
        return class_exists('\\Smbb\\WpCodeTool\\Wizard\\WizardManager');
    }

    public static function wizardUrl($step = '')
    {
        return self::available() ? \Smbb\WpCodeTool\Wizard\WizardManager::url(Service::WIZARD, $step) : '';
    }

    /**
     * Bloc « Assistant de démarrage » en tête de Réglages › Configuration.
     */
    public static function tabHtml()
    {
        $d = 'smbb-passflow';

        if (!self::available()) {
            return '';
        }

        $progress = \Smbb\WpCodeTool\Wizard\WizardManager::progress(Service::WIZARD);
        $profile = (string) Service::memory('profile', '');
        $html = '<p>' . esc_html($progress['finished']
            ? __('Configuration terminée. Vous pouvez relancer l\'assistant à tout moment : il repart des réglages actuels.', $d)
            : sprintf(__('Configuration en cours : %1$d étape(s) sur %2$d.', $d), $progress['done'], $progress['total'])) . '</p>';
        $html .= '<p><a class="button button-primary" href="' . esc_url($progress['finished'] ? \Smbb\WpCodeTool\Wizard\WizardManager::reopenUrl(Service::WIZARD) : self::wizardUrl($progress['next'])) . '">'
            . '<span class="dashicons dashicons-flag" aria-hidden="true"></span> ' . esc_html($progress['finished'] ? __('Relancer l\'assistant', $d) : __('Reprendre l\'assistant', $d)) . '</a> '
            . '<a class="button" href="' . esc_url(self::wizardUrl('profile')) . '"><span class="dashicons dashicons-category" aria-hidden="true"></span> '
            . esc_html($profile !== '' ? sprintf(__('Profil d\'activité : %s (changer)', $d), self::profileTitle($profile)) : __('Choisir un profil d\'activité', $d)) . '</a> '
            . '<a class="button" href="' . esc_url(self::wizardUrl('company')) . '"><span class="dashicons dashicons-building" aria-hidden="true"></span> ' . esc_html__('Entreprise et TVA', $d) . '</a></p>';

        if (Demo::exists()) {
            $html .= '<p>' . esc_html__('Des données de démo sont présentes : le mode test reste actif et les factures vont dans la série DEMO-.', $d) . ' '
                . '<a class="button" href="' . esc_url(View::actionUrl(self::DEMO_DELETE)) . '" onclick="return confirm(' . esc_attr(wp_json_encode(__('Supprimer les données de démo (comptes, dates, réservations, commandes, produits et factures de démo) ? Le reste n\'est pas touché.', $d))) . ');">'
                . '<span class="dashicons dashicons-trash" aria-hidden="true"></span> ' . esc_html__('Supprimer les données de démo', $d) . '</a></p>';
        } else {
            $html .= '<p><a class="button" href="' . esc_url(View::actionUrl(self::DEMO_CREATE)) . '"><span class="dashicons dashicons-database-add" aria-hidden="true"></span> ' . esc_html__('Créer des données de démo', $d) . '</a></p>';
        }

        return View::note(__('Assistant de démarrage', $d), $html, 'dashicons-flag');
    }

    private static function profileTitle($key)
    {
        $file = SMBB_PASSFLOW_PATH . 'profiles/' . sanitize_key($key) . '/profile.json';
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) && isset($data['profile']['title']) ? (string) $data['profile']['title'] : $key;
    }

    public static function demoCreate()
    {
        self::guard(self::DEMO_CREATE);
        $result = Demo::create();
        self::done($result === true, $result === true ? __('Données de démo créées. Le mode test est actif : les messages partent à votre adresse.', 'smbb-passflow') : $result);
    }

    public static function demoDelete()
    {
        self::guard(self::DEMO_DELETE);
        self::done(true, Demo::delete());
    }

    public static function purgeInvoices()
    {
        self::guard(self::PURGE_INVOICES);
        $count = \Smbb\Passflow\WooCommerce\Invoices::purgeDemo();
        self::done(true, sprintf(_n('%d facture de démo supprimée.', '%d factures de démo supprimées.', $count, 'smbb-passflow'), $count));
    }

    private static function guard($action)
    {
        check_admin_referer($action . '_0');

        if (!Roles::canManage()) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-passflow'), '', array('response' => 403));
        }
    }

    private static function done($success, $message)
    {
        View::flash(array('success' => (bool) $success, 'message' => (string) $message));
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=smbb-codetool-passflow_settings'));
        exit;
    }
}
