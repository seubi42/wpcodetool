<?php

namespace Smbb\WpCodeTool\Forms;

defined('ABSPATH') || exit;

/**
 * Module « Formulaires » de CodeTool : formulaires de contact, devis, inscription…
 *
 * - Construction dans l'admin avec le repeater de CodeTool en mode aperçu (palette, grille,
 *   panneau de réglages) : menu Formulaires (Admin\Pages\FormsPage).
 * - Placement dans une page par le bloc « Formulaire » ou le shortcode [smbb_form id="12"].
 * - Entrées gardées dans l'admin (lu / non lu, étoile, export CSV, purge RGPD).
 * - Notifications par e-mail (Mailer CodeTool : SMTP, journal, renvoi) et par SMS (Sms CodeTool).
 * - Anti-spam : champ piège, jeton de temps, Turnstile du module Sécurité si configuré.
 * - Reprise des formulaires et entrées de WPForms, et des anciens [wpforms id] / blocs WPForms.
 *
 * Tables : {prefix}smbb_forms et {prefix}smbb_form_entries, créées à la première page d'admin.
 * Le module se coupe avec add_filter('smbb_wpcodetool_forms_enabled', '__return_false').
 *
 * Point d'extension pour les plugins : do_action('smbb_wpcodetool_form_submitted', $entry, $form)
 * après chaque envoi accepté (Passflow peut par exemple créer une demande à partir d'une entrée).
 *
 * Formulaire intégré dans le flux d'un plugin (ex. documents Passflow) : Frontend::fieldsHtml() rend
 * les seuls champs dans le <form> du plugin, Submission::validate() puis Submission::store() les
 * traitent, l'entrée porte une origine (source + source_ref) et EntryView::html() l'affiche.
 */
final class Forms
{
    const DB_VERSION = '2';
    const DB_OPTION = 'smbb_codetool_forms_db';
    const DIR_OPTION = 'smbb_codetool_forms_dir';
    const CRON = 'smbb_codetool_forms_purge';

    public static function hooks()
    {
        if (!self::enabled()) {
            return;
        }

        add_action('admin_init', array(__CLASS__, 'install'));
        // Mise à jour du schéma (colonnes source…) aussi côté site : une entrée peut arriver avant toute page d'admin.
        add_action('init', array(__CLASS__, 'install'), 5);
        add_action('init', array(Frontend::class, 'register'), 20);
        add_action('wp_loaded', array(Submission::class, 'maybeHandle'));
        add_action('admin_post_smbb_form_file', array(Uploads::class, 'download'));
        add_action('admin_post_nopriv_smbb_form_file', array(Uploads::class, 'downloadLoggedOut'));
        add_filter('smbb_wpcodetool_storage_usage', array(__CLASS__, 'storageUsage'), 10, 2);
        add_action('smbb_wpcodetool_register_cron_tasks', array(__CLASS__, 'registerCron'));
        add_filter('smbb_wpcodetool_activity_categories', array(__CLASS__, 'activityCategory'));
        FormsWidget::hooks();
    }

    public static function enabled()
    {
        return (bool) apply_filters('smbb_wpcodetool_forms_enabled', true);
    }

    /**
     * Droit pour créer les formulaires et lire les entrées (filtre smbb_wpcodetool_forms_capability).
     */
    public static function capability()
    {
        return (string) apply_filters('smbb_wpcodetool_forms_capability', 'manage_options');
    }

    public static function formsTable()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_forms';
    }

    public static function entriesTable()
    {
        global $wpdb;

        return $wpdb->prefix . 'smbb_form_entries';
    }

    /**
     * Tables créées (quelle que soit leur version).
     */
    public static function ready()
    {
        return (string) get_option(self::DB_OPTION, '') !== '';
    }

    public static function install()
    {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $forms = self::formsTable();
        $entries = self::entriesTable();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$forms} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'publish',
  fields longtext NOT NULL,
  settings longtext NOT NULL,
  legacy_source varchar(20) NOT NULL DEFAULT '',
  legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY legacy (legacy_source, legacy_id)
) {$charset};");

        dbDelta("CREATE TABLE {$entries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'unread',
  starred tinyint(1) unsigned NOT NULL DEFAULT 0,
  fields longtext NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  page_url varchar(255) NOT NULL DEFAULT '',
  legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(40) NOT NULL DEFAULT '',
  source_ref varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY form_status (form_id, status),
  KEY created_at (created_at),
  KEY legacy_id (legacy_id),
  KEY source (source, source_ref)
) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, true);
    }

    public static function registerCron($registry)
    {
        $registry->register(self::CRON, array(
            'label' => __('Purge des entrées de formulaires', 'smbb-wpcodetool'),
            'description' => __('Efface les entrées plus anciennes que la durée de conservation réglée sur chaque formulaire (RGPD).', 'smbb-wpcodetool'),
            'schedule' => 'daily',
            'callback' => array(__CLASS__, 'purge'),
        ));
    }

    /**
     * Efface les entrées trop anciennes, formulaire par formulaire (réglage « Conservation »).
     *
     * @return int Nombre d'entrées effacées.
     */
    public static function purge()
    {
        if (!self::ready()) {
            return 0;
        }

        $count = 0;

        foreach (FormRepository::all() as $form) {
            $days = (int) $form['settings']['retention'];

            if ($days > 0) {
                $count += EntryRepository::purgeOlderThan((int) $form['id'], $days);
            }
        }

        return $count;
    }

    /**
     * CodeTool › Stockage : une unité choisie par un formulaire ne se supprime pas.
     */
    public static function storageUsage($usage, $unit)
    {
        if (!self::ready() || $unit === 'wp') {
            return $usage;
        }

        foreach (FormRepository::all() as $form) {
            if (($form['settings']['storage'] ?? 'wp') === $unit) {
                $usage[] = sprintf(__('Formulaire « %s »', 'smbb-wpcodetool'), $form['title']);
            }
        }

        return $usage;
    }

    public static function activityCategory($categories)
    {
        $categories['forms'] = array(__('Formulaires', 'smbb-wpcodetool'), 'dashicons-feedback');

        return $categories;
    }

    /**
     * Note une action d'admin dans le journal d'activité.
     */
    public static function log($event, $message, array $form = array(), array $context = array(), $level = 'info')
    {
        do_action('smbb_wpcodetool_activity', 'forms.' . $event, array(
            'category' => 'forms',
            'level' => $level,
            'message' => $message,
            'object_type' => $form ? 'smbb_form' : '',
            'object_id' => $form ? (int) $form['id'] : 0,
            'object_label' => $form ? (string) $form['title'] : '',
            'context' => $context,
            'source' => 'smbb-wpcodetool',
        ));
    }
}
