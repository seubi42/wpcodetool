<?php

namespace Smbb\SignConnectProxima;

defined('ABSPATH') || exit;

/**
 * Point d'entrée du plugin : admin (généré par CodeTool à partir de codetool/models),
 * signature interne (app, page publique, tâche planifiée) et Universign (transactions, webhook).
 * et facturation (proforma mensuelle, clôture, export CSV).
 */
final class Plugin
{
    public function hooks()
    {
        add_action('admin_enqueue_scripts', array($this, 'adminAssets'));
        Support\FrontPages::hooks();
        Support\Schema::hooks();
        Support\Avatar::hooks();
        Support\Favicon::hooks();
        Admin\ConnectionTests::hooks();
        Admin\TemplateEditor::hooks();
        Admin\DocumentActions::hooks();
        Admin\BillingActions::hooks();
        Front\Webhook::hooks();
        Front\ClientApi::hooks();
        Front\Ajax::hooks();
        Front\AppActions::hooks();
        Front\SignShortcode::hooks();
        Service\Lifecycle::hooks();
        Service\StorageMigration::hooks();
        add_action('init', array($this, 'shortcodes'), 5);
    }

    /**
     * Les vrais shortcodes passent avant les messages d'attente de FrontPages.
     */
    public function shortcodes()
    {
        add_shortcode(Front\AppShortcode::TAG, array(Front\AppShortcode::class, 'render'));
        add_shortcode(Front\SignShortcode::TAG, array(Front\SignShortcode::class, 'render'));
        add_shortcode(Front\AppShortcode::LEGACY_TAG, array(Front\AppShortcode::class, 'render'));
        add_shortcode(Front\SignShortcode::LEGACY_TAG, array(Front\SignShortcode::class, 'render'));
    }

    public function adminAssets($hook_suffix)
    {
        if (strpos((string) $hook_suffix, 'smbb-codetool-scp') === false) {
            return;
        }

        wp_enqueue_style('smbb-scp-admin', SMBB_SCP_URL . 'assets/admin.css', array(), SMBB_SCP_VERSION);
    }
}
