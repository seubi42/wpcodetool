<?php

namespace Smbb\WpCodeTool\Activity;

defined('ABSPATH') || exit;

/**
 * Journal d'activité de l'administration : qui a fait quoi, quand, d'où.
 *
 * Table {prefix}smbb_activity_log, créée à la première page d'admin (dbDelta).
 * Les événements du cœur (connexions, contenus, extensions, comptes et rôles, réglages,
 * commandes WooCommerce) sont branchés par Activity\Watchers. Les autres plugins écrivent
 * avec l'action publique :
 *
 *     do_action('smbb_wpcodetool_activity', 'passflow.pass_cancelled', array(
 *         'category'     => 'passflow',          // défaut : le préfixe avant le point
 *         'level'        => 'notice',            // info | notice | warning | critical
 *         'message'      => 'Pass annulé',       // phrase lisible, sans HTML
 *         'object_type'  => 'passflow_pass',     // optionnel : ce qui a été touché
 *         'object_id'    => 42,
 *         'object_label' => 'Pass 10 cours de Jeanne',
 *         'context'      => array('changes' => array('status' => array('active', 'cancelled'))),
 *         'user_id'      => 0,                   // défaut : utilisateur connecté
 *         'source'       => 'smbb-passflow',
 *     ));
 *
 * L'action ne fait rien si CodeTool est absent : pas besoin de tester class_exists.
 * Catégorie propre à un plugin : filtre smbb_wpcodetool_activity_categories.
 * Lien vers l'objet : filtre smbb_wpcodetool_activity_object_link.
 * Purge : tâche cron quotidienne smbb_codetool_activity_purge (durée réglable).
 */
final class ActivityLog
{
    const ACTION = 'smbb_wpcodetool_activity';
    const DB_VERSION = '1';
    const DB_OPTION = 'smbb_codetool_activity_db';
    const OPTION = 'smbb_codetool_activity';
    const CRON = 'smbb_codetool_activity_purge';
    const MAX_CONTEXT = 65535;

    /** @var array<string,bool> Événements déjà notés pendant cette requête (anti-doublon). */
    private static $seen = array();

    public static function hooks()
    {
        add_action(self::ACTION, array(__CLASS__, 'onAction'), 10, 2);
        add_action('admin_init', array(__CLASS__, 'install'));
        add_action('smbb_wpcodetool_register_cron_tasks', array(__CLASS__, 'registerCron'));

        Watchers::hooks();
    }

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_activity_log';
    }

    /**
     * Droit pour lire le journal et le régler (filtre smbb_wpcodetool_activity_capability).
     */
    public static function capability()
    {
        return (string) apply_filters('smbb_wpcodetool_activity_capability', 'manage_options');
    }

    public static function ready()
    {
        return get_option(self::DB_OPTION) === self::DB_VERSION;
    }

    public static function install()
    {
        if (self::ready()) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  last_at datetime NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 1,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  user_name varchar(250) NOT NULL DEFAULT '',
  user_role varchar(190) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  via varchar(10) NOT NULL DEFAULT '',
  category varchar(40) NOT NULL DEFAULT '',
  event varchar(64) NOT NULL DEFAULT '',
  level varchar(10) NOT NULL DEFAULT 'info',
  object_type varchar(40) NOT NULL DEFAULT '',
  object_id varchar(64) NOT NULL DEFAULT '',
  object_label varchar(255) NOT NULL DEFAULT '',
  message text NOT NULL,
  context longtext NOT NULL,
  source varchar(60) NOT NULL DEFAULT '',
  url varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY category (category),
  KEY user_id (user_id),
  KEY level (level),
  KEY object (object_type, object_id)
) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, true);
    }

    // ------------------------------------------------------------------ Réglages

    public static function defaults()
    {
        $categories = array();

        foreach (array_keys(self::categories()) as $key) {
            $categories[$key] = 1;
        }

        return array(
            'days' => 90,
            'categories' => $categories,
            'failed_logins' => 1,
            'orders_all' => 0,
            'customers' => 0,
            'ip' => 'full',
        );
    }

    public static function settings()
    {
        // Pas de cache : une catégorie peut être ajoutée par filtre après un premier appel
        // (get_option est déjà mis en cache par WordPress).
        $stored = get_option(self::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $defaults = self::defaults();
        $settings = array_merge($defaults, $stored);
        // Catégorie ajoutée par un plugin après coup : notée par défaut.
        $settings['categories'] = array_merge($defaults['categories'], isset($stored['categories']) && is_array($stored['categories']) ? $stored['categories'] : array());

        return $settings;
    }

    public static function saveSettings(array $settings)
    {
        $clean = self::defaults();
        $clean['days'] = array_key_exists((int) ($settings['days'] ?? 90), self::durations()) ? (int) $settings['days'] : 90;
        $clean['ip'] = in_array($settings['ip'] ?? 'full', array('full', 'masked', 'none'), true) ? $settings['ip'] : 'full';

        foreach (array('failed_logins', 'orders_all', 'customers') as $key) {
            $clean[$key] = empty($settings[$key]) ? 0 : 1;
        }

        foreach (array_keys($clean['categories']) as $key) {
            $clean['categories'][$key] = empty($settings['categories'][$key]) ? 0 : 1;
        }

        update_option(self::OPTION, $clean, false);

        return $clean;
    }

    /**
     * Durées de conservation proposées (jours => libellé). 0 = pas de limite de durée.
     */
    public static function durations()
    {
        return array(
            30 => __('30 jours', 'smbb-wpcodetool'),
            90 => __('90 jours', 'smbb-wpcodetool'),
            180 => __('6 mois', 'smbb-wpcodetool'),
            365 => __('1 an', 'smbb-wpcodetool'),
            730 => __('2 ans', 'smbb-wpcodetool'),
            0 => __('Sans limite de durée', 'smbb-wpcodetool'),
        );
    }

    /**
     * Durée de conservation en jours (0 = sans limite ; filtre smbb_wpcodetool_activity_days).
     */
    public static function days()
    {
        $settings = self::settings();

        return max(0, (int) apply_filters('smbb_wpcodetool_activity_days', (int) $settings['days']));
    }

    /**
     * Plafond de lignes, même sans limite de durée (filtre smbb_wpcodetool_activity_max_rows).
     */
    public static function maxRows()
    {
        return max(1000, (int) apply_filters('smbb_wpcodetool_activity_max_rows', 200000));
    }

    /**
     * Catégories : clé => [libellé, dashicon]. Filtre smbb_wpcodetool_activity_categories.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function categories()
    {
        $core = array(
            'login' => array(__('Connexions', 'smbb-wpcodetool'), 'dashicons-admin-network'),
            'security' => array(__('Sécurité', 'smbb-wpcodetool'), 'dashicons-shield'),
            'content' => array(__('Contenus', 'smbb-wpcodetool'), 'dashicons-admin-page'),
            'plugins' => array(__('Extensions et thèmes', 'smbb-wpcodetool'), 'dashicons-admin-plugins'),
            'users' => array(__('Comptes et rôles', 'smbb-wpcodetool'), 'dashicons-admin-users'),
            'settings' => array(__('Réglages', 'smbb-wpcodetool'), 'dashicons-admin-settings'),
            'woocommerce' => array(__('Commandes', 'smbb-wpcodetool'), 'dashicons-cart'),
            'communication' => array(__('Communication', 'smbb-wpcodetool'), 'dashicons-megaphone'),
            'activity' => array(__('Journal', 'smbb-wpcodetool'), 'dashicons-list-view'),
        );

        $all = apply_filters('smbb_wpcodetool_activity_categories', $core);
        $out = array();

        foreach ((array) $all as $key => $def) {
            $key = sanitize_key((string) $key);
            $def = is_array($def) ? array_values($def) : array((string) $def);

            if ($key !== '') {
                $out[$key] = array((string) ($def[0] ?? $key), (string) ($def[1] ?? 'dashicons-marker'));
            }
        }

        return $out;
    }

    public static function category($key)
    {
        $all = self::categories();

        return isset($all[$key]) ? $all[$key] : array($key !== '' ? ucfirst($key) : __('Autre', 'smbb-wpcodetool'), 'dashicons-marker');
    }

    /**
     * Niveaux : clé => libellé, du plus léger au plus grave.
     */
    public static function levels()
    {
        return array(
            'info' => __('Info', 'smbb-wpcodetool'),
            'notice' => __('À noter', 'smbb-wpcodetool'),
            'warning' => __('Attention', 'smbb-wpcodetool'),
            'critical' => __('Critique', 'smbb-wpcodetool'),
        );
    }

    public static function categoryEnabled($category)
    {
        $settings = self::settings();

        // Le journal note toujours ses propres opérations (purge, effacement, réglages).
        if ($category === 'activity') {
            return true;
        }

        return !isset($settings['categories'][$category]) || !empty($settings['categories'][$category]);
    }

    // ------------------------------------------------------------------ Écriture

    /**
     * Action publique smbb_wpcodetool_activity.
     */
    public static function onAction($event, $args = array())
    {
        self::log((string) $event, is_array($args) ? $args : array('message' => (string) $args));
    }

    /**
     * Note un événement. Retourne l'identifiant de la ligne (0 si rien n'a été écrit).
     *
     * Arguments : category, level, message, object_type, object_id, object_label, context (array),
     * user_id (défaut : utilisateur connecté), user_login (ex. identifiant tapé lors d'un échec),
     * source (slug du plugin), group (secondes : un même événement répété depuis la même IP
     * pendant ce délai augmente un compteur au lieu d'ajouter une ligne), repeat (true = pas d'anti-doublon).
     */
    public static function log($event, array $args = array())
    {
        if (!self::ready()) {
            return 0;
        }

        $event = substr(preg_replace('/[^a-z0-9_.\-]/', '', strtolower((string) $event)), 0, 64);

        if ($event === '') {
            return 0;
        }

        $dot = strpos($event, '.');
        $category = sanitize_key((string) ($args['category'] ?? ($dot ? substr($event, 0, $dot) : 'other')));
        $category = substr($category, 0, 40);

        if (!self::categoryEnabled($category)) {
            return 0;
        }

        $level = isset($args['level']) && array_key_exists($args['level'], self::levels()) ? $args['level'] : 'info';
        $user_id = array_key_exists('user_id', $args) ? (int) $args['user_id'] : get_current_user_id();
        $user = $user_id > 0 ? get_userdata($user_id) : false;
        $context = isset($args['context']) && is_array($args['context']) ? self::mask($args['context']) : array();
        $json = $context ? (string) wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        if (strlen($json) > self::MAX_CONTEXT) {
            $json = (string) wp_json_encode(array('_truncated' => true), JSON_UNESCAPED_UNICODE);
        }

        $now = current_time('mysql', true);
        $entry = array(
            'created_at' => $now,
            'last_at' => $now,
            'hits' => 1,
            'user_id' => $user ? (int) $user->ID : 0,
            'user_login' => self::cut($user ? $user->user_login : (string) ($args['user_login'] ?? ''), 60),
            'user_name' => self::cut($user ? $user->display_name : '', 250),
            'user_role' => self::cut($user ? implode(',', (array) $user->roles) : '', 190),
            'ip' => self::ip(),
            'user_agent' => self::cut(isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '', 255),
            'via' => self::via(),
            'category' => $category,
            'event' => $event,
            'level' => $level,
            'object_type' => self::cut(sanitize_key((string) ($args['object_type'] ?? '')), 40),
            'object_id' => self::cut((string) ($args['object_id'] ?? ''), 64),
            'object_label' => self::cut(wp_strip_all_tags((string) ($args['object_label'] ?? '')), 255),
            'message' => self::cut(wp_strip_all_tags((string) ($args['message'] ?? $event)), 2000),
            'context' => $json,
            'source' => self::cut(sanitize_key((string) ($args['source'] ?? 'core')), 60),
            'url' => self::cut(self::requestUri(), 255),
        );

        /**
         * Dernière retouche avant écriture. Retourner false annule l'écriture.
         */
        $entry = apply_filters('smbb_wpcodetool_activity_entry', $entry, $args);

        if (!is_array($entry)) {
            return 0;
        }

        $key = md5($entry['event'] . '|' . $entry['object_type'] . '|' . $entry['object_id'] . '|' . $entry['message'] . '|' . $entry['user_id']);

        if (empty($args['repeat']) && isset(self::$seen[$key])) {
            return 0;
        }

        self::$seen[$key] = true;

        global $wpdb;
        $group = isset($args['group']) ? (int) $args['group'] : 0;

        // Rafales (ex. échecs de connexion) : une ligne avec un compteur plutôt que mille lignes.
        if ($group > 0) {
            $transient = 'smbb_act_' . md5($entry['event'] . '|' . $entry['ip'] . '|' . $entry['user_login'] . '|' . $entry['object_id']);
            $existing = (int) get_transient($transient);

            if ($existing > 0) {
                $updated = $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET hits = hits + 1, last_at = %s WHERE id = %d', $now, $existing)); // phpcs:ignore

                if ($updated) {
                    set_transient($transient, $existing, $group);

                    return $existing;
                }
            }
        }

        if (!$wpdb->insert(self::table(), $entry)) {
            return 0;
        }

        $id = (int) $wpdb->insert_id;

        if ($group > 0) {
            set_transient($transient, $id, $group);
        }

        do_action('smbb_wpcodetool_activity_logged', $id, $entry);

        return $id;
    }

    /**
     * Masque les valeurs dont la clé ressemble à un secret (mot de passe, jeton, clé privée...).
     */
    public static function mask(array $data, $depth = 0)
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecretKey($key) && !is_array($value) && (string) $value !== '') {
                $data[$key] = '••••••';
            } elseif (is_array($value) && $depth < 6) {
                // Paire [avant, après] d'un champ secret : on garde seulement « modifié ».
                if (is_string($key) && self::isSecretKey($key)) {
                    $data[$key] = array('••••••', '••••••');
                } else {
                    $data[$key] = self::mask($value, $depth + 1);
                }
            } elseif (is_object($value)) {
                $data[$key] = '(' . get_class($value) . ')';
            }
        }

        return $data;
    }

    public static function isSecretKey($key)
    {
        return (bool) preg_match('/(\bpass(word|wd)?\b|_pass(word|wd)?\b|_pass$|secret|token|private|api_?key|apikey|licen[cs]e_?key|salt|credential|signature|service_account)/i', (string) $key);
    }

    private static function cut($value, $length)
    {
        $value = (string) $value;

        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    /**
     * Adresse IP du visiteur (REMOTE_ADDR ; derrière un proxy : filtre smbb_wpcodetool_activity_ip).
     */
    public static function ip()
    {
        $settings = self::settings();

        if ($settings['ip'] === 'none') {
            return '';
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ip = (string) apply_filters('smbb_wpcodetool_activity_ip', $ip);
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';

        if ($ip !== '' && $settings['ip'] === 'masked' && function_exists('wp_privacy_anonymize_ip')) {
            $ip = wp_privacy_anonymize_ip($ip);
        }

        return substr($ip, 0, 45);
    }

    /**
     * Par où l'action est passée : admin, web, rest, ajax, cron, cli, xmlrpc.
     */
    public static function via()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return 'cli';
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 'cron';
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return 'xmlrpc';
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return 'ajax';
        }

        return is_admin() ? 'admin' : 'web';
    }

    private static function requestUri()
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return '';
        }

        $uri = (string) wp_unslash($_SERVER['REQUEST_URI']); // phpcs:ignore
        // Pas de jetons dans le journal (liens de réinitialisation, nonces...).
        $uri = preg_replace('/([?&](key|_wpnonce|nonce|token|pass[a-z0-9_]*|pwd|rp_key)=)[^&#]*/i', '$1…', $uri);

        return esc_url_raw($uri) !== '' ? $uri : '';
    }

    // ------------------------------------------------------------------ Lecture

    /**
     * Filtres acceptés : category, level, user (id), from, to (Y-m-d, heure du site), s, object_type, object_id.
     */
    public static function normalizeFilters(array $raw)
    {
        $date = static function ($value) {
            $value = (string) $value;

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        };

        return array(
            'category' => sanitize_key((string) ($raw['category'] ?? '')),
            'level' => array_key_exists((string) ($raw['level'] ?? ''), self::levels()) ? (string) $raw['level'] : '',
            'user' => isset($raw['user']) && $raw['user'] !== '' ? (string) (int) $raw['user'] : '',
            'from' => $date($raw['from'] ?? ''),
            'to' => $date($raw['to'] ?? ''),
            's' => trim(sanitize_text_field((string) ($raw['s'] ?? ''))),
            'object_type' => sanitize_key((string) ($raw['object_type'] ?? '')),
            'object_id' => sanitize_text_field((string) ($raw['object_id'] ?? '')),
        );
    }

    /**
     * @return array{0:string,1:array} Clause WHERE et ses arguments.
     */
    private static function where(array $filters, $skip = '')
    {
        global $wpdb;
        $where = array('1=1');
        $args = array();

        if ($filters['category'] !== '' && $skip !== 'category') {
            $where[] = 'category = %s';
            $args[] = $filters['category'];
        }

        if ($filters['level'] !== '') {
            // Un niveau = ce niveau et les plus graves.
            $levels = array_keys(self::levels());
            $keep = array_slice($levels, (int) array_search($filters['level'], $levels, true));
            $where[] = 'level IN (' . implode(',', array_fill(0, count($keep), '%s')) . ')';
            $args = array_merge($args, $keep);
        }

        if ($filters['user'] !== '') {
            $where[] = 'user_id = %d';
            $args[] = (int) $filters['user'];
        }

        if ($filters['from'] !== '') {
            $where[] = 'created_at >= %s';
            $args[] = get_gmt_from_date($filters['from'] . ' 00:00:00');
        }

        if ($filters['to'] !== '') {
            $where[] = 'created_at <= %s';
            $args[] = get_gmt_from_date($filters['to'] . ' 23:59:59');
        }

        if ($filters['object_type'] !== '') {
            $where[] = 'object_type = %s';
            $args[] = $filters['object_type'];

            if ($filters['object_id'] !== '') {
                $where[] = 'object_id = %s';
                $args[] = $filters['object_id'];
            }
        }

        if ($filters['s'] !== '') {
            $like = '%' . $wpdb->esc_like($filters['s']) . '%';
            $where[] = '(message LIKE %s OR object_label LIKE %s OR user_login LIKE %s OR user_name LIKE %s OR ip LIKE %s OR event LIKE %s OR context LIKE %s)';
            $args = array_merge($args, array_fill(0, 7, $like));
        }

        return array(implode(' AND ', $where), $args);
    }

    private static function prepare($sql, array $args)
    {
        global $wpdb;

        return $args ? $wpdb->prepare($sql, $args) : $sql; // phpcs:ignore
    }

    /**
     * Une page du journal : ['rows' => [...], 'total' => n].
     */
    public static function query(array $filters, $page = 1, $per_page = 50)
    {
        if (!self::ready()) {
            return array('rows' => array(), 'total' => 0);
        }

        global $wpdb;
        list($where, $args) = self::where($filters);
        $table = self::table();
        $total = (int) $wpdb->get_var(self::prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", $args)); // phpcs:ignore
        $offset = max(0, ((int) $page - 1) * (int) $per_page);
        $rows = $wpdb->get_results(self::prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge($args, array((int) $per_page, $offset))), ARRAY_A); // phpcs:ignore

        return array('rows' => (array) $rows, 'total' => $total);
    }

    /**
     * Nombre de lignes par catégorie, avec les autres filtres appliqués (pour les pastilles).
     *
     * @return array<string,int>
     */
    public static function countsByCategory(array $filters)
    {
        if (!self::ready()) {
            return array();
        }

        global $wpdb;
        list($where, $args) = self::where($filters, 'category');
        $rows = $wpdb->get_results(self::prepare('SELECT category, COUNT(*) AS n FROM ' . self::table() . " WHERE {$where} GROUP BY category", $args), ARRAY_A); // phpcs:ignore
        $out = array();

        foreach ((array) $rows as $row) {
            $out[(string) $row['category']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Personnes présentes dans le journal (pour le filtre « Qui »).
     *
     * @return array<int,string> id => nom
     */
    public static function people()
    {
        if (!self::ready()) {
            return array();
        }

        global $wpdb;
        $rows = $wpdb->get_results('SELECT user_id, MAX(user_name) AS name, MAX(user_login) AS login FROM ' . self::table() . ' WHERE user_id > 0 GROUP BY user_id ORDER BY name LIMIT 300', ARRAY_A); // phpcs:ignore
        $out = array();

        foreach ((array) $rows as $row) {
            $out[(int) $row['user_id']] = $row['name'] !== '' ? $row['name'] . ' (' . $row['login'] . ')' : (string) $row['login'];
        }

        return $out;
    }

    /**
     * Chiffres pour la page de réglages : lignes, plus ancienne, taille.
     */
    public static function stats()
    {
        $out = array('rows' => 0, 'oldest' => '', 'bytes' => 0);

        if (!self::ready()) {
            return $out;
        }

        global $wpdb;
        $out['rows'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table()); // phpcs:ignore
        $out['oldest'] = (string) $wpdb->get_var('SELECT MIN(created_at) FROM ' . self::table()); // phpcs:ignore
        $size = $wpdb->get_row($wpdb->prepare('SELECT data_length + index_length AS bytes FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s', DB_NAME, self::table()), ARRAY_A); // phpcs:ignore
        $out['bytes'] = is_array($size) ? (int) $size['bytes'] : 0;

        return $out;
    }

    /**
     * Lignes pour l'export CSV (mêmes filtres que la liste), par paquets.
     *
     * @return \Generator<array>
     */
    public static function iterate(array $filters, $limit = 50000)
    {
        if (!self::ready()) {
            return;
        }

        global $wpdb;
        list($where, $args) = self::where($filters);
        $table = self::table();
        $done = 0;
        $last = PHP_INT_MAX;

        while ($done < $limit) {
            $rows = $wpdb->get_results(self::prepare("SELECT * FROM {$table} WHERE {$where} AND id < %d ORDER BY id DESC LIMIT 1000", array_merge($args, array($last))), ARRAY_A); // phpcs:ignore

            if (!$rows) {
                return;
            }

            foreach ($rows as $row) {
                $last = (int) $row['id'];
                $done++;
                yield $row;

                if ($done >= $limit) {
                    return;
                }
            }
        }
    }

    // ------------------------------------------------------------------ Entretien

    public static function registerCron($registry)
    {
        $days = self::days();
        $registry->register(self::CRON, array(
            'label' => __('Purge du journal d\'activité', 'smbb-wpcodetool'),
            'description' => $days > 0
                ? sprintf(__('Efface les événements notés depuis plus de %d jours.', 'smbb-wpcodetool'), $days)
                : sprintf(__('Sans limite de durée : garde au plus %s événements.', 'smbb-wpcodetool'), number_format_i18n(self::maxRows())),
            'schedule' => 'daily',
            'callback' => array(__CLASS__, 'purge'),
        ));
    }

    /**
     * Applique la durée de conservation et le plafond de lignes. Retourne le nombre de lignes effacées.
     */
    public static function purge()
    {
        if (!self::ready()) {
            return 0;
        }

        global $wpdb;
        $table = self::table();
        $deleted = 0;
        $days = self::days();

        if ($days > 0) {
            $limit = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
            $deleted += (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $limit)); // phpcs:ignore
        }

        $max = self::maxRows();
        $cut = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max)); // phpcs:ignore

        if ($cut > 0) {
            $deleted += (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d", $cut)); // phpcs:ignore
        }

        return $deleted;
    }

    /**
     * Efface tout le journal. L'effacement lui-même est noté (première ligne du nouveau journal).
     */
    public static function clear()
    {
        if (!self::ready()) {
            return 0;
        }

        global $wpdb;
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table()); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . self::table()); // phpcs:ignore

        self::log('activity.cleared', array(
            'level' => 'critical',
            'message' => sprintf(__('Journal d\'activité entièrement effacé (%d événements).', 'smbb-wpcodetool'), $count),
            'context' => array('rows' => $count),
        ));

        return $count;
    }
}
