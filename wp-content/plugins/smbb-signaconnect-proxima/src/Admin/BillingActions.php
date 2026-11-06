<?php

namespace Smbb\SignConnectProxima\Admin;

use Smbb\SignConnectProxima\Service\Billing;

defined('ABSPATH') || exit;

/**
 * Actions de l'écran Facturation : export CSV, clôture et réouverture d'un mois.
 */
final class BillingActions
{
    const CSV = 'scp_billing_csv';
    const CLOSE = 'scp_billing_close';
    const REOPEN = 'scp_billing_reopen';
    const PAGE = 'smbb-codetool-scp_billing';

    public static function hooks()
    {
        foreach (array(self::CSV, self::CLOSE, self::REOPEN) as $action) {
            add_action('admin_post_' . $action, array(__CLASS__, 'handle'));
        }
    }

    public static function url($action, $organization_id, $month)
    {
        return wp_nonce_url(
            add_query_arg(array('action' => $action, 'organization_id' => absint($organization_id), 'month' => $month), admin_url('admin-post.php')),
            $action . '_' . absint($organization_id) . '_' . $month
        );
    }

    public static function pageUrl(array $args = array())
    {
        return add_query_arg(array_merge(array('page' => self::PAGE), $args), admin_url('admin.php'));
    }

    public static function handle()
    {
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $organization_id = isset($_GET['organization_id']) ? absint($_GET['organization_id']) : 0;
        $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : '';

        if (!current_user_can('manage_options') || !check_admin_referer($action . '_' . $organization_id . '_' . $month) || !Billing::validMonth($month)) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-signaconnect-proxima'), '', array('response' => 403));
        }

        try {
            if ($action === self::CSV) {
                $invoice = Billing::get($organization_id, $month);
                $name = sanitize_file_name('proforma-' . $invoice['month'] . '-' . sanitize_title($invoice['organization']['name']) . '.csv');
                nocache_headers();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $name . '"');
                echo Billing::csv($invoice); // phpcs:ignore -- fichier CSV.
                exit;
            }

            if ($action === self::CLOSE) {
                $invoice = Billing::close($organization_id, $month);
                $result = array('success' => true, 'message' => sprintf(__('Mois clôturé : %s HT figés.', 'smbb-signaconnect-proxima'), Billing::money($invoice['total'])));
            } else {
                Billing::reopen($organization_id, $month);
                $result = array('success' => true, 'message' => __('Mois rouvert : les montants sont de nouveau recalculés en direct.', 'smbb-signaconnect-proxima'));
            }
        } catch (\Throwable $exception) {
            $result = array('success' => false, 'message' => $exception->getMessage());
        }

        set_transient('scp_billing_' . get_current_user_id(), $result, 300);
        wp_safe_redirect(self::pageUrl(array('organization_id' => $organization_id, 'month' => $month)));
        exit;
    }

    public static function notice()
    {
        $key = 'scp_billing_' . get_current_user_id();
        $result = get_transient($key);

        if (!is_array($result)) {
            return '';
        }

        delete_transient($key);

        return '<div class="notice notice-' . ($result['success'] ? 'success' : 'error') . ' inline"><p>' . esc_html($result['message']) . '</p></div>';
    }
}
