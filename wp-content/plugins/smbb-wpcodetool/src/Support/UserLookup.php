<?php

namespace Smbb\WpCodeTool\Support;

defined('ABSPATH') || exit;

/**
 * Recherche de comptes WordPress pour le champ relationnel search() :
 *
 *     $form->search('Compte', array('resource' => 'wp_user'))->setName('user_id')
 *
 * On cherche dans l'identifiant, l'e-mail, le nom affiché, le prénom, le nom et le téléphone
 * (méta *phone*, comparée sans espaces ni points). Droit requis : list_users, ou le filtre
 * smbb_wpcodetool_user_lookup_allowed.
 *
 * Filtres :
 * - smbb_wpcodetool_user_lookup_allowed (bool $allowed) ;
 * - smbb_wpcodetool_user_lookup_meta_keys (string[] $keys) : métas cherchées en texte ;
 * - smbb_wpcodetool_user_lookup_item (array $item, \WP_User $user) : value, label, meta.
 */
final class UserLookup
{
    const RESOURCE = 'wp_user';

    public static function allowed()
    {
        return (bool) apply_filters('smbb_wpcodetool_user_lookup_allowed', current_user_can('list_users'));
    }

    /**
     * @return array<int,array{value:string,label:string,meta:string}>
     */
    public static function items($search, $limit = 12, $exclude = '')
    {
        global $wpdb;

        $search = trim((string) $search);
        $limit = max(1, min(50, (int) $limit));

        if ($search === '') {
            return array();
        }

        $ids = array();
        $exact = ltrim($search, '#');

        if (ctype_digit($exact) && strlen($exact) < 7 && get_userdata((int) $exact)) {
            $ids[] = (int) $exact;
        }

        $query = new \WP_User_Query(array(
            'search' => '*' . $search . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name', 'user_nicename'),
            'fields' => 'ID',
            'number' => $limit,
        ));
        $ids = array_merge($ids, array_map('intval', (array) $query->get_results()));

        $keys = (array) apply_filters('smbb_wpcodetool_user_lookup_meta_keys', array('first_name', 'last_name', 'nickname', 'billing_first_name', 'billing_last_name', 'billing_company'));
        $keys = array_values(array_filter(array_map('sanitize_key', $keys)));
        $like = '%' . $wpdb->esc_like($search) . '%';

        if ($keys) {
            $in = implode(',', array_fill(0, count($keys), '%s'));
            $ids = array_merge($ids, array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ({$in}) AND meta_value LIKE %s LIMIT %d",
                array_merge($keys, array($like, $limit))
            ))));
        }

        // « Prénom Nom » ou « Nom Prénom ».
        if (strpos($search, ' ') !== false) {
            $ids = array_merge($ids, array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT f.user_id FROM {$wpdb->usermeta} f INNER JOIN {$wpdb->usermeta} l ON l.user_id = f.user_id AND l.meta_key = 'last_name'"
                . " WHERE f.meta_key = 'first_name' AND (CONCAT(f.meta_value, ' ', l.meta_value) LIKE %s OR CONCAT(l.meta_value, ' ', f.meta_value) LIKE %s) LIMIT %d",
                $like,
                $like,
                $limit
            ))));
        }

        // Téléphone : on compare les chiffres, 0612… comme +33 6 12….
        $digits = self::digits($search);

        if (strlen($digits) >= 4 && preg_match('/^[\d\s.\-+()]+$/', $search)) {
            $clean = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(meta_value, ' ', ''), '.', ''), '-', ''), '(', ''), ')', '')";
            $ids = array_merge($ids, array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s AND (REPLACE({$clean}, '+33', '0') LIKE %s OR {$clean} LIKE %s) LIMIT %d",
                '%phone%',
                '%' . $wpdb->esc_like($digits) . '%',
                '%' . $wpdb->esc_like($digits) . '%',
                $limit
            ))));
        }

        $items = array();

        foreach (array_unique($ids) as $id) {
            if ($exclude !== '' && (string) $id === (string) $exclude) {
                continue;
            }

            $item = self::item($id);

            if ($item) {
                $items[] = $item;
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array{value:string,label:string,meta:string}|null
     */
    public static function item($user_id)
    {
        $user = get_userdata((int) $user_id);

        if (!$user) {
            return null;
        }

        $name = trim($user->first_name . ' ' . $user->last_name);
        $phone = self::phone($user->ID);
        $meta = array_filter(array($user->user_email, $phone));

        $item = array(
            'value' => (string) $user->ID,
            'label' => $name !== '' ? $name : $user->display_name,
            'meta' => implode(' · ', $meta),
        );

        return (array) apply_filters('smbb_wpcodetool_user_lookup_item', $item, $user);
    }

    /**
     * Premier téléphone connu du compte (WooCommerce, puis méta « phone »).
     */
    public static function phone($user_id)
    {
        foreach ((array) apply_filters('smbb_wpcodetool_user_phone_keys', array('billing_phone', 'phone', 'mobile')) as $key) {
            $value = trim((string) get_user_meta((int) $user_id, $key, true));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public static function digits($value)
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if (strpos($digits, '33') === 0 && strlen($digits) >= 11) {
            $digits = '0' . substr($digits, 2);
        }

        return $digits;
    }
}
