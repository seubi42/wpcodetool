<?php

namespace Smbb\Passflow\WooCommerce;

use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Input;
use Smbb\Passflow\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Participants favoris d'un client : les personnes qu'il inscrit souvent (enfants, conjoint, amis).
 *
 * Gardés en méta utilisateur (une liste, une entrée par prénom + nom). Alimentés par la case
 * « Mémoriser ce participant » de la fiche produit (enregistrés à la commande, donc aussi pour un
 * compte créé au paiement) et gérés dans Mon compte › Participants favoris.
 */
final class Favorites
{
    const META = 'smbb_passflow_favorites';
    const ENDPOINT = 'participants-favoris';
    const AJAX_DELETE = 'passflow_favorite_delete';
    const FORM_ACTION = 'passflow_favorite_save';
    const MAX = 50;

    const FIELDS = array('first_name', 'last_name', 'email', 'phone', 'level', 'age', 'allergies');

    public static function hooks()
    {
        add_action('init', array(__CLASS__, 'endpoint'), 5); // avant le flush de Account::endpoint()
        add_filter('woocommerce_get_query_vars', array(__CLASS__, 'queryVars'));
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'menu'), 20);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', array(__CLASS__, 'title'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array(__CLASS__, 'render'));
        add_action('template_redirect', array(__CLASS__, 'handlePost'));
        add_action('wp_ajax_' . self::AJAX_DELETE, array(__CLASS__, 'ajaxDelete'));
    }

    // ------------------------------------------------------------------
    // Données
    // ------------------------------------------------------------------

    /**
     * @return array<int,array<string,string>>
     */
    public static function all($user_id)
    {
        $list = $user_id ? get_user_meta((int) $user_id, self::META, true) : array();
        $out = array();

        foreach (is_array($list) ? $list : array() as $row) {
            $row = self::clean(is_array($row) ? $row : array());

            if ($row['first_name'] !== '' && $row['last_name'] !== '') {
                $out[] = $row;
            }
        }

        usort($out, function ($a, $b) {
            return strcasecmp($a['first_name'] . ' ' . $a['last_name'], $b['first_name'] . ' ' . $b['last_name']);
        });

        return $out;
    }

    /**
     * Clé d'un favori : prénom + nom, sans casse ni accents.
     */
    public static function key(array $row)
    {
        $name = trim((isset($row['first_name']) ? $row['first_name'] : '') . ' ' . (isset($row['last_name']) ? $row['last_name'] : ''));

        return strtolower(remove_accents(preg_replace('/\s+/', ' ', $name)));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    public static function clean(array $row)
    {
        $email = sanitize_email(isset($row['email']) ? (string) $row['email'] : '');
        $age = isset($row['age']) ? (int) $row['age'] : 0;

        return array(
            'first_name' => sanitize_text_field(isset($row['first_name']) ? (string) $row['first_name'] : ''),
            'last_name' => sanitize_text_field(isset($row['last_name']) ? (string) $row['last_name'] : ''),
            'email' => $email !== '' && is_email($email) ? strtolower($email) : '',
            'phone' => Input::phone(isset($row['phone']) ? (string) $row['phone'] : ''),
            'level' => isset($row['level']) && array_key_exists((string) $row['level'], Choices::levels()) ? (string) $row['level'] : '',
            'age' => $age > 0 && $age <= 120 ? (string) $age : '',
            'allergies' => sanitize_text_field(isset($row['allergies']) ? (string) $row['allergies'] : ''),
        );
    }

    /**
     * Ajoute ou met à jour des favoris (même prénom + nom = mise à jour).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return int nombre de nouveaux favoris
     */
    public static function remember($user_id, array $rows)
    {
        if (!$user_id) {
            return 0;
        }

        $list = self::all($user_id);
        $index = array();

        foreach ($list as $i => $row) {
            $index[self::key($row)] = $i;
        }

        $added = 0;

        foreach ($rows as $row) {
            $row = self::clean($row);
            $key = self::key($row);

            if ($row['first_name'] === '' || $row['last_name'] === '') {
                continue;
            }

            if (isset($index[$key])) {
                // On complète sans effacer ce qui était déjà connu.
                foreach ($row as $field => $value) {
                    if ($value !== '' && !in_array($field, array('first_name', 'last_name'), true)) {
                        $list[$index[$key]][$field] = $value;
                    }
                }
            } elseif (count($list) < self::MAX) {
                $index[$key] = count($list);
                $list[] = $row;
                $added++;
            }
        }

        update_user_meta((int) $user_id, self::META, array_values($list));

        return $added;
    }

    public static function forget($user_id, $key)
    {
        $list = array_values(array_filter(self::all($user_id), function ($row) use ($key) {
            return self::key($row) !== (string) $key;
        }));

        update_user_meta((int) $user_id, self::META, $list);
    }

    /**
     * Remplace un favori (modification depuis Mon compte).
     */
    public static function replace($user_id, $old_key, array $row)
    {
        $row = self::clean($row);
        $list = array();
        $done = false;

        foreach (self::all($user_id) as $current) {
            $key = self::key($current);

            if ($key === (string) $old_key) {
                $list[] = $row;
                $done = true;
            } elseif ($key !== self::key($row)) {
                $list[] = $current;
            }
        }

        if (!$done) {
            $list[] = $row;
        }

        update_user_meta((int) $user_id, self::META, array_slice($list, 0, self::MAX));
    }

    /**
     * Mémorisation possible : client connecté, ou création de compte proposée au paiement.
     */
    public static function canRemember()
    {
        return is_user_logged_in() || get_option('woocommerce_enable_signup_and_login_from_checkout') === 'yes' || get_option('woocommerce_enable_guest_checkout') === 'no';
    }

    // ------------------------------------------------------------------
    // Mon compte › Participants favoris
    // ------------------------------------------------------------------

    public static function endpoint()
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public static function queryVars($vars)
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    public static function menu($items)
    {
        $result = array();

        foreach ((array) $items as $key => $label) {
            $result[$key] = $label;

            if ($key === Account::ENDPOINT) {
                $result[self::ENDPOINT] = __('Participants favoris', 'smbb-passflow');
            }
        }

        if (!isset($result[self::ENDPOINT])) {
            $result[self::ENDPOINT] = __('Participants favoris', 'smbb-passflow');
        }

        return $result;
    }

    public static function title()
    {
        return __('Participants favoris', 'smbb-passflow');
    }

    private static function pageUrl($args = array())
    {
        $url = wc_get_account_endpoint_url(self::ENDPOINT);

        return $args ? add_query_arg($args, $url) : $url;
    }

    /**
     * Ajout, modification et suppression depuis Mon compte (formulaires classiques).
     */
    public static function handlePost()
    {
        if (empty($_POST['passflow_favorite_action']) || !is_user_logged_in()) {
            return;
        }

        $d = 'smbb-passflow';
        $user_id = get_current_user_id();
        $action = sanitize_key(wp_unslash($_POST['passflow_favorite_action']));

        if (!isset($_POST['_passflow_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_passflow_nonce'])), self::FORM_ACTION . '_' . $user_id)) {
            wc_add_notice(__('La page a expiré : réessayez.', $d), 'error');
            wp_safe_redirect(self::pageUrl());
            exit;
        }

        $old = isset($_POST['passflow_favorite_key']) ? sanitize_text_field(wp_unslash($_POST['passflow_favorite_key'])) : '';

        if ($action === 'delete') {
            self::forget($user_id, $old);
            wc_add_notice(__('Participant retiré de vos favoris.', $d));
            wp_safe_redirect(self::pageUrl());
            exit;
        }

        $row = self::clean(isset($_POST['passflow_favorite']) && is_array($_POST['passflow_favorite']) ? wp_unslash($_POST['passflow_favorite']) : array());

        if ($row['first_name'] === '' || $row['last_name'] === '') {
            wc_add_notice(__('Indiquez le prénom et le nom.', $d), 'error');
            wp_safe_redirect(self::pageUrl($old !== '' ? array('modifier' => rawurlencode($old)) : array('ajouter' => 1)));
            exit;
        }

        if ($old !== '') {
            self::replace($user_id, $old, $row);
            wc_add_notice(__('Participant modifié.', $d));
        } else {
            self::remember($user_id, array($row));
            wc_add_notice(__('Participant ajouté à vos favoris.', $d));
        }

        wp_safe_redirect(self::pageUrl());
        exit;
    }

    public static function render()
    {
        $d = 'smbb-passflow';
        $user_id = get_current_user_id();
        $list = self::all($user_id);
        $edit_key = isset($_GET['modifier']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['modifier']))) : '';
        $editing = null;

        foreach ($list as $row) {
            if ($edit_key !== '' && self::key($row) === $edit_key) {
                $editing = $row;
            }
        }

        echo '<p class="passflow-account-help">' . esc_html__('Les personnes que vous inscrivez souvent. Lors d\'une réservation, « Ajouter mes participants favoris » remplit leurs coordonnées en un clic.', $d) . '</p>';

        if ($list) {
            echo '<ul class="passflow-fav-list">';

            foreach ($list as $row) {
                $key = self::key($row);
                $meta = array_filter(array($row['email'], $row['phone']));
                echo '<li class="passflow-fav-item">'
                    . '<span class="passflow-fav-avatar" aria-hidden="true">' . esc_html(self::initials($row)) . '</span>'
                    . '<span class="passflow-fav-id"><strong>' . esc_html($row['first_name'] . ' ' . $row['last_name']) . '</strong>'
                    . ($meta ? '<small>' . esc_html(implode(' · ', $meta)) . '</small>' : '')
                    . ($row['allergies'] !== '' ? '<small>' . esc_html(sprintf(__('Allergies : %s', $d), $row['allergies'])) . '</small>' : '')
                    . '</span>'
                    . '<span class="passflow-fav-actions">'
                    . '<a class="button" href="' . esc_url(self::pageUrl(array('modifier' => rawurlencode($key)))) . '">' . esc_html__('Modifier', $d) . '</a>'
                    . '<form method="post" onsubmit="return confirm(\'' . esc_js(sprintf(__('Retirer %s de vos favoris ?', $d), $row['first_name'] . ' ' . $row['last_name'])) . '\');">'
                    . self::hiddenFields('delete', $key)
                    . '<button type="submit" class="button passflow-fav-delete">' . esc_html__('Supprimer', $d) . '</button></form>'
                    . '</span></li>';
            }

            echo '</ul>';
        } else {
            echo '<p>' . esc_html__('Aucun participant favori pour le moment. Cochez « Mémoriser ce participant » lors de votre prochaine réservation, ou ajoutez-en un ici.', $d) . '</p>';
        }

        $open = $editing || isset($_GET['ajouter']) || !$list;
        echo '<details class="passflow-fav-form"' . ($open ? ' open' : '') . '><summary>' . esc_html($editing ? sprintf(__('Modifier %s', $d), $editing['first_name'] . ' ' . $editing['last_name']) : __('Ajouter un participant', $d)) . '</summary>';
        echo '<form method="post" class="passflow-participant">' . self::hiddenFields('save', $editing ? self::key($editing) : '');
        echo self::fields('passflow_favorite', $editing ?: array());
        echo '<p><button type="submit" class="button">' . esc_html($editing ? __('Enregistrer', $d) : __('Ajouter', $d)) . '</button>';

        if ($editing) {
            echo ' <a href="' . esc_url(self::pageUrl()) . '">' . esc_html__('Annuler', $d) . '</a>';
        }

        echo '</p></form></details>';
    }

    private static function hiddenFields($action, $key)
    {
        return '<input type="hidden" name="passflow_favorite_action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="passflow_favorite_key" value="' . esc_attr($key) . '">'
            . '<input type="hidden" name="_passflow_nonce" value="' . esc_attr(wp_create_nonce(self::FORM_ACTION . '_' . get_current_user_id())) . '">';
    }

    public static function initials(array $row)
    {
        $first = function_exists('mb_substr') ? mb_substr($row['first_name'], 0, 1) : substr($row['first_name'], 0, 1);
        $last = function_exists('mb_substr') ? mb_substr($row['last_name'], 0, 1) : substr($row['last_name'], 0, 1);

        return function_exists('mb_strtoupper') ? mb_strtoupper($first . $last) : strtoupper($first . $last);
    }

    /**
     * Champs d'un participant (mêmes réglages que la fiche produit), sous le nom $prefix[champ].
     */
    public static function fields($prefix, array $values, $readonly = false)
    {
        $d = 'smbb-passflow';
        $name = function ($field) use ($prefix) {
            return $prefix . '[' . $field . ']';
        };
        $value = function ($field) use ($values) {
            return isset($values[$field]) ? (string) $values[$field] : '';
        };
        $ro = $readonly ? ' readonly' : '';
        $html = '<div class="passflow-row">'
            . '<label>' . esc_html__('Prénom', $d) . ' <span class="required">*</span><input type="text" data-field="first_name" name="' . esc_attr($name('first_name')) . '" value="' . esc_attr($value('first_name')) . '" required autocomplete="given-name"' . $ro . '></label>'
            . '<label>' . esc_html__('Nom', $d) . ' <span class="required">*</span><input type="text" data-field="last_name" name="' . esc_attr($name('last_name')) . '" value="' . esc_attr($value('last_name')) . '" required autocomplete="family-name"' . $ro . '></label>'
            . '</div>';
        $contact = Front::askContact();

        if ($contact !== 'off') {
            $star = $contact === 'required' ? ' <span class="required">*</span>' : '';
            $req = $contact === 'required' ? ' required' : '';
            $html .= '<div class="passflow-row">'
                . '<label>' . esc_html__('E-mail', $d) . $star . '<input type="email" data-field="email" name="' . esc_attr($name('email')) . '" value="' . esc_attr($value('email')) . '"' . $req . ' autocomplete="email"' . $ro . '></label>'
                . '<label>' . esc_html__('Téléphone mobile', $d) . $star . '<input type="tel" data-field="phone" name="' . esc_attr($name('phone')) . '" value="' . esc_attr($value('phone')) . '"' . $req . ' autocomplete="tel"' . $ro . '></label>'
                . '</div>';
        }

        $extra = '';

        if ((int) Settings::get('ask_level')) {
            $extra .= '<label>' . esc_html__('Niveau', $d) . '<select data-field="level" name="' . esc_attr($name('level')) . '">';

            foreach (Choices::levels() as $key => $label) {
                $extra .= '<option value="' . esc_attr($key) . '"' . selected($value('level'), $key, false) . '>' . esc_html($label) . '</option>';
            }

            $extra .= '</select></label>';
        }

        if ((int) Settings::get('ask_age')) {
            $extra .= '<label>' . esc_html__('Âge', $d) . '<input type="number" min="1" max="120" data-field="age" name="' . esc_attr($name('age')) . '" value="' . esc_attr($value('age')) . '"></label>';
        }

        if ($extra !== '') {
            $html .= '<div class="passflow-row">' . $extra . '</div>';
        }

        if ((int) Settings::get('ask_allergies')) {
            $html .= '<label class="passflow-full">' . esc_html__('Allergies ou intolérances', $d) . '<input type="text" data-field="allergies" name="' . esc_attr($name('allergies')) . '" value="' . esc_attr($value('allergies')) . '" placeholder="' . esc_attr__('Aucune', $d) . '"></label>';
        }

        return $html;
    }

    // ------------------------------------------------------------------
    // Ajax : suppression depuis la fenêtre de la fiche produit
    // ------------------------------------------------------------------

    public static function nonce()
    {
        return wp_create_nonce(self::AJAX_DELETE . '_' . get_current_user_id());
    }

    public static function ajaxDelete()
    {
        check_ajax_referer(self::AJAX_DELETE . '_' . get_current_user_id(), 'nonce');
        self::forget(get_current_user_id(), isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '');
        wp_send_json_success(array('favorites' => self::forScript(get_current_user_id())));
    }

    /**
     * Favoris pour le script de la fiche produit.
     *
     * @return array<int,array<string,string>>
     */
    public static function forScript($user_id)
    {
        return array_map(function ($row) {
            return $row + array('key' => self::key($row), 'initials' => self::initials($row));
        }, self::all($user_id));
    }
}
