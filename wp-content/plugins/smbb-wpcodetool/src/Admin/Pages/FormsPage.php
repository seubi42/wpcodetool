<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Admin\AdminManager;
use Smbb\WpCodeTool\Admin\AppBar;
use Smbb\WpCodeTool\Admin\Form;
use Smbb\WpCodeTool\Forms\EntryRepository;
use Smbb\WpCodeTool\Forms\EntryView;
use Smbb\WpCodeTool\Forms\FieldTypes;
use Smbb\WpCodeTool\Forms\Logic;
use Smbb\WpCodeTool\Forms\FormRepository;
use Smbb\WpCodeTool\Forms\Forms;
use Smbb\WpCodeTool\Forms\SmartTags;
use Smbb\WpCodeTool\Forms\Uploads;
use Smbb\WpCodeTool\Forms\WpFormsImport;
use Smbb\WpCodeTool\Security\Turnstile;
use Smbb\WpCodeTool\Sms\Sms;

defined('ABSPATH') || exit;

/**
 * App « Formulaires » : liste des formulaires, builder, entrées, reprise WPForms.
 *
 * Menu de premier niveau (une app avec sa barre). Pages sœurs du groupe « Formulaires » :
 * Tous les formulaires, Entrées, Importer. Pendant la modification d'un formulaire, un second
 * groupe à son nom apparaît dans la barre d'app : Champs, Réglages, Notifications, Confirmations,
 * Entrées (2e niveau de la barre, pas d'onglets maison).
 */
final class FormsPage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-forms';
    const ENTRIES_SLUG = 'smbb-wpcodetool-forms-entries';
    const IMPORT_SLUG = 'smbb-wpcodetool-forms-import';
    const NONCE = 'smbb_codetool_forms';
    const PER_PAGE = 30;

    /** @var array|null Formulaire ouvert (pour le menu contextuel). */
    private static $context = null;

    /**
     * @return string[]
     */
    public static function slugs()
    {
        return array(self::SLUG, self::ENTRIES_SLUG, self::IMPORT_SLUG);
    }

    public static function sections()
    {
        return array(
            'fields' => array(__('Champs', 'smbb-wpcodetool'), 'dashicons-forms'),
            'settings' => array(__('Réglages', 'smbb-wpcodetool'), 'dashicons-admin-generic'),
            'notifications' => array(__('Notifications', 'smbb-wpcodetool'), 'dashicons-email-alt'),
            'confirmations' => array(__('Confirmation', 'smbb-wpcodetool'), 'dashicons-yes-alt'),
        );
    }

    public static function url($view = '', array $args = array())
    {
        $slug = $view === 'entries' ? self::ENTRIES_SLUG : ($view === 'import' ? self::IMPORT_SLUG : self::SLUG);

        return add_query_arg($args, admin_url('admin.php?page=' . $slug));
    }

    public static function formUrl($id, $section = 'fields')
    {
        return self::url('', array('form' => (int) $id, 'section' => $section));
    }

    /** Slug de menu d'une section (une adresse : WordPress l'affiche telle quelle). */
    private static function sectionSlug($id, $section)
    {
        return $section === 'entries'
            ? 'admin.php?page=' . self::ENTRIES_SLUG . '&form=' . (int) $id
            : 'admin.php?page=' . self::SLUG . '&form=' . (int) $id . '&section=' . $section;
    }

    // ------------------------------------------------------------------ Menus

    public static function hooks()
    {
        add_action('admin_menu', array(__CLASS__, 'contextMenu'), 998);
        add_filter('smbb_wpcodetool_menu_groups', array(__CLASS__, 'menuGroups'));
        add_filter('smbb_wpcodetool_apps', array(__CLASS__, 'apps'));
        add_filter('submenu_file', array(__CLASS__, 'submenuFile'), 20);
    }

    public static function registerMenus(AdminManager $manager)
    {
        $cap = Forms::capability();
        $unread = EntryRepository::unreadCount();
        $bubble = $unread > 0 ? ' <span class="awaiting-mod count-' . $unread . '"><span class="pending-count">' . number_format_i18n($unread) . '</span></span>' : '';
        $render = static function ($view) use ($manager) {
            return static function () use ($manager, $view) {
                (new self($manager))->render($view);
            };
        };

        // Même fonction pour le menu et son premier sous-menu : WordPress l'accroche une seule fois.
        $main = $render('');
        add_menu_page(__('Formulaires', 'smbb-wpcodetool'), __('Formulaires', 'smbb-wpcodetool') . $bubble, $cap, self::SLUG, $main, 'dashicons-feedback', 26);
        add_submenu_page(self::SLUG, __('Formulaires', 'smbb-wpcodetool'), __('Tous les formulaires', 'smbb-wpcodetool'), $cap, self::SLUG, $main);
        add_submenu_page(self::SLUG, __('Entrées', 'smbb-wpcodetool'), __('Entrées', 'smbb-wpcodetool') . $bubble, $cap, self::ENTRIES_SLUG, $render('entries'));
        add_submenu_page(self::SLUG, __('Importer', 'smbb-wpcodetool'), __('Importer', 'smbb-wpcodetool'), $cap, self::IMPORT_SLUG, $render('import'));

        AppBar::addIcons(array(
            self::SLUG => 'dashicons-feedback',
            self::ENTRIES_SLUG => 'dashicons-email-alt2',
            self::IMPORT_SLUG => 'dashicons-database-import',
        ));
    }

    /**
     * Formulaire ouvert (builder ou entrées filtrées sur un formulaire).
     */
    private static function openForm()
    {
        if (self::$context !== null) {
            return self::$context ?: null;
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $id = isset($_GET['form']) ? absint($_GET['form']) : 0;

        if (!$id && $page === self::ENTRIES_SLUG && isset($_GET['entry'])) {
            $entry = EntryRepository::find(absint($_GET['entry']));
            $id = $entry ? (int) $entry['form_id'] : 0;
        }
        // phpcs:enable

        $form = in_array($page, array(self::SLUG, self::ENTRIES_SLUG), true) && $id ? FormRepository::find($id) : null;
        self::$context = $form ?: false;

        return $form;
    }

    /**
     * Pendant la modification d'un formulaire : ses sections en 2e niveau de la barre d'app.
     */
    public static function contextMenu()
    {
        global $submenu;
        $form = self::openForm();

        if (!$form || !current_user_can(Forms::capability())) {
            return;
        }

        foreach (self::sections() + array('entries' => array(__('Entrées', 'smbb-wpcodetool'), 'dashicons-email-alt2')) as $key => $def) {
            $slug = self::sectionSlug($form['id'], $key);
            $submenu[self::SLUG][] = array($def[0], Forms::capability(), $slug, $def[0]);
            AppBar::addIcons(array($slug => $def[1]));
        }
    }

    public static function menuGroups($groups)
    {
        $main = array(__('Formulaires', 'smbb-wpcodetool'), 'dashicons-feedback');

        foreach (self::slugs() as $slug) {
            $groups[$slug] = $main;
        }

        $form = self::openForm();

        if ($form) {
            $group = array(wp_html_excerpt($form['title'], 32, '…'), 'dashicons-edit');

            foreach (array_keys(self::sections() + array('entries' => 1)) as $key) {
                $groups[self::sectionSlug($form['id'], $key)] = $group;
            }
        }

        return $groups;
    }

    public static function apps($slugs)
    {
        $slugs[] = self::SLUG;

        return $slugs;
    }

    /**
     * Entrée de menu surlignée : la section ouverte du formulaire.
     */
    public static function submenuFile($file)
    {
        $form = self::openForm();

        if (!$form) {
            return $file;
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : 'fields';
        // phpcs:enable

        if ($page === self::ENTRIES_SLUG) {
            return self::sectionSlug($form['id'], 'entries');
        }

        return self::sectionSlug($form['id'], array_key_exists($section, self::sections()) ? $section : 'fields');
    }

    // ------------------------------------------------------------------ Actions

    public static function handle()
    {
        if (!current_user_can(Forms::capability())) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($page === self::ENTRIES_SLUG && isset($_GET['export']) && $_GET['export'] === 'csv') {
            self::export();
        }

        if ($page === self::ENTRIES_SLUG && isset($_GET['entry'])) {
            // Ouvrir une entrée la marque comme lue.
            $entry = EntryRepository::find(absint($_GET['entry']));

            if ($entry && $entry['status'] === 'unread') {
                EntryRepository::setStatus(array($entry['id']), 'read');
            }
        }

        if (empty($_POST['smbb_forms_do'])) {
            return;
        }

        $do = sanitize_key(wp_unslash($_POST['smbb_forms_do']));
        // phpcs:enable

        if ($do === 'save') {
            $id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
            check_admin_referer('smbb_forms_save_' . $id);
            self::saveSection($id);

            return;
        }

        check_admin_referer(self::NONCE, 'smbb_forms_nonce');

        switch ($do) {
            case 'create':
                self::create();
                break;
            case 'duplicate':
            case 'delete':
                self::formAction($do, isset($_POST['form_id']) ? absint($_POST['form_id']) : 0);
                break;
            case 'entries':
                self::entriesAction();
                break;
            case 'import':
                self::import();
                break;
        }
    }

    private static function create()
    {
        // phpcs:disable WordPress.Security.NonceVerification -- vérifié dans handle().
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $template = isset($_POST['template']) ? sanitize_key(wp_unslash($_POST['template'])) : 'contact';
        // phpcs:enable
        $templates = FormRepository::templates();
        $template = isset($templates[$template]) ? $template : 'contact';
        $fields = $templates[$template]['fields'];
        $settings = FormRepository::defaults();
        $settings['turnstile'] = FormRepository::turnstileByDefault() ? '1' : '0';

        // Visiteur : premier champ e-mail du modèle, écrit en clair dans les deux notifications
        // (« Répondre à » de l'équipe, destinataire de l'accusé de réception).
        $email = '';

        // Identifiants lisibles d'office (« Votre message » → votre_message) : balises {field:…} parlantes.
        $used = array();

        foreach ($fields as $i => $field) {
            $key = $field['type'] === 'gdpr' ? 'consentement' : ($field['type'] === 'email' ? 'email' : FieldTypes::normalizeKey((string) ($field['label'] ?? '')));
            $key = $key !== '' ? substr($key, 0, 30) : 'f' . ($i + 1);
            $base = $key;
            $n = 2;

            while (isset($used[$key])) {
                $key = $base . '_' . $n++;
            }

            $used[$key] = true;
            $fields[$i]['key'] = $key;

            if ($field['type'] === 'email' && $email === '') {
                $email = '{field:' . $key . '}';
            }
        }

        if ($email !== '') {
            foreach ($settings['notifications'] as $n => $notification) {
                foreach (array('to', 'reply_to') as $key) {
                    if ($notification[$key] === '{visitor_email}') {
                        $settings['notifications'][$n][$key] = $email;
                    }
                }
            }
        }

        $id = FormRepository::save(array(
            'title' => $title !== '' ? $title : $templates[$template]['label'],
            'fields' => $fields,
            'settings' => $settings,
        ));

        if (!$id) {
            self::flash('error', __('Le formulaire n\'a pas pu être créé (table absente ?). Rechargez la page et réessayez.', 'smbb-wpcodetool'));
            wp_safe_redirect(self::url());
            exit;
        }

        $form = FormRepository::find($id);
        Forms::log('form_created', sprintf(__('Formulaire créé : %s', 'smbb-wpcodetool'), $form['title']), $form);
        self::flash('success', __('Formulaire créé. Ajoutez ou réglez les champs, puis enregistrez.', 'smbb-wpcodetool'));
        wp_safe_redirect(self::formUrl($id));
        exit;
    }

    private static function formAction($do, $id)
    {
        $form = FormRepository::find($id);

        if (!$form) {
            wp_safe_redirect(self::url());
            exit;
        }

        if ($do === 'duplicate') {
            $copy = FormRepository::duplicate($id);
            Forms::log('form_duplicated', sprintf(__('Formulaire dupliqué : %s', 'smbb-wpcodetool'), $form['title']), $form);
            self::flash('success', __('Copie créée.', 'smbb-wpcodetool'));
            wp_safe_redirect($copy ? self::formUrl($copy) : self::url());
            exit;
        }

        $counts = EntryRepository::statusCounts($id);
        FormRepository::delete($id);
        Forms::log('form_deleted', sprintf(__('Formulaire supprimé : %s', 'smbb-wpcodetool'), $form['title']), $form, array('entries' => $counts['all'] + $counts['spam']), 'warning');
        self::flash('success', sprintf(__('« %s » supprimé, avec ses entrées.', 'smbb-wpcodetool'), $form['title']));
        wp_safe_redirect(self::url());
        exit;
    }

    private static function saveSection($id)
    {
        $form = FormRepository::find($id);

        if (!$form) {
            wp_safe_redirect(self::url());
            exit;
        }

        // phpcs:disable WordPress.Security.NonceVerification -- vérifié dans handle().
        $section = isset($_POST['section']) ? sanitize_key(wp_unslash($_POST['section'])) : 'fields';
        $posted = wp_unslash($_POST); // phpcs:ignore -- nettoyé par FieldTypes / FormRepository.
        // phpcs:enable

        $renamed = array();

        if ($section === 'fields') {
            $form['title'] = isset($posted['title']) ? (string) $posted['title'] : $form['title'];
            $form['fields'] = isset($posted['fields']) && is_array($posted['fields']) ? $posted['fields'] : array();
            // Ordre des lignes = leur index (fields[0], fields[1]…), pas l'ordre d'arrivée : le panneau de réglages
            // du champ choisi est placé après la liste, ses valeurs arrivent en dernier.
            ksort($form['fields'], SORT_NUMERIC);
            $form['fields'] = array_values($form['fields']);
            $renamed = self::applyKeyRenames($form);
        } elseif ($section === 'settings') {
            $form['settings'] = FormRepository::sanitizeSettings(array_intersect_key($posted, array_flip(array('submit_label', 'description', 'show_title', 'style', 'store_entries', 'retention', 'storage', 'turnstile', 'sms', 'sms_to', 'css_class'))), $form['settings']);
        } elseif ($section === 'notifications') {
            $form['settings'] = FormRepository::sanitizeSettings(array('notifications' => isset($posted['notifications']) ? $posted['notifications'] : array()), $form['settings']);
        } elseif ($section === 'confirmations') {
            $form['settings'] = FormRepository::sanitizeSettings(array('confirmation' => isset($posted['confirmation']) ? $posted['confirmation'] : array()), $form['settings']);
        }

        FormRepository::save($form);

        if ($renamed) {
            EntryRepository::renameKeys($form['id'], $renamed);
            Forms::log('form_keys_renamed', sprintf(__('Identifiants de champs changés : %s', 'smbb-wpcodetool'), implode(', ', array_map(static function ($old, $new) {
                return $old . ' → ' . $new;
            }, array_keys($renamed), $renamed))), $form);
        }

        $sections = self::sections();
        Forms::log('form_saved', sprintf(__('Formulaire modifié (%1$s) : %2$s', 'smbb-wpcodetool'), isset($sections[$section]) ? $sections[$section][0] : $section, $form['title']), $form);
        $pending = get_transient('smbb_forms_flash_' . get_current_user_id());

        if (!is_array($pending) || $pending[0] !== 'error') {
            self::flash('success', $renamed
                ? sprintf(__('Enregistré. Identifiants changés partout (notifications, conditions, entrées) : %s.', 'smbb-wpcodetool'), implode(', ', array_map(static function ($old, $new) {
                    return '{field:' . $old . '} → {field:' . $new . '}';
                }, array_keys($renamed), $renamed)))
                : __('Enregistré.', 'smbb-wpcodetool'));
        }

        wp_safe_redirect(self::formUrl($id, $section));
        exit;
    }

    /**
     * Identifiants modifiés dans le builder (champ « Identifiant » = new_key) : la clé du champ change,
     * et ce qui la cite suit (conditions des autres champs, balises {field:…} des notifications, de la
     * confirmation et des réglages). Un identifiant déjà pris par un autre champ est refusé.
     *
     * @return array<string,string> ancienne clé => nouvelle
     */
    private static function applyKeyRenames(array &$form)
    {
        $wanted = array();

        foreach ($form['fields'] as $i => $field) {
            $old = isset($field['key']) ? substr(sanitize_key((string) $field['key']), 0, 40) : '';
            $new = isset($field['new_key']) ? FieldTypes::normalizeKey((string) $field['new_key']) : '';
            unset($form['fields'][$i]['new_key']);
            $wanted[$i] = array($old, $new !== '' ? $new : $old);
        }

        // Clés finales : un identifiant demandé par deux champs (ou déjà celui d'un autre) n'est pas appliqué.
        $count = array();

        foreach ($wanted as $pair) {
            $count[$pair[1]] = ($count[$pair[1]] ?? 0) + 1;
        }

        $map = array();
        $refused = array();

        foreach ($wanted as $i => $pair) {
            list($old, $new) = $pair;

            if ($old === '' || $new === $old) {
                continue;
            }

            if ($count[$new] > 1) {
                $refused[] = $new;
                continue;
            }

            $map[$old] = $new;
            $form['fields'][$i]['key'] = $new;
        }

        if ($refused) {
            self::flash('error', sprintf(__('Identifiant déjà utilisé par un autre champ, non appliqué : %s.', 'smbb-wpcodetool'), implode(', ', array_unique($refused))));
        }

        if (!$map) {
            return array();
        }

        foreach ($form['fields'] as $i => $field) {
            $ref = (string) ($field['logic_field'] ?? '');

            if ($ref !== '' && isset($map[$ref])) {
                $form['fields'][$i]['logic_field'] = $map[$ref];
            }
        }

        $tags = array();

        foreach ($map as $old => $new) {
            $tags['{field:' . $old . '}'] = '{field:' . $new . '}';
        }

        array_walk_recursive($form['settings'], static function (&$value) use ($tags) {
            if (is_string($value) && strpos($value, '{field:') !== false) {
                $value = strtr($value, $tags);
            }
        });

        return $map;
    }

    private static function entriesAction()
    {
        // phpcs:disable WordPress.Security.NonceVerification -- vérifié dans handle().
        $action = isset($_POST['batch']) ? sanitize_key(wp_unslash($_POST['batch'])) : '';
        $ids = isset($_POST['ids']) ? array_filter(array_map('absint', (array) wp_unslash($_POST['ids']))) : array();
        $back = isset($_POST['back']) ? esc_url_raw(wp_unslash($_POST['back'])) : '';
        // phpcs:enable
        $back = wp_validate_redirect($back, self::url('entries'));
        $count = 0;

        switch ($action) {
            case 'read':
            case 'unread':
            case 'spam':
                $count = EntryRepository::setStatus($ids, $action);
                break;
            case 'not_spam':
                $count = EntryRepository::setStatus($ids, 'read');
                break;
            case 'star':
            case 'unstar':
                $count = EntryRepository::setStarred($ids, $action === 'star');
                break;
            case 'delete':
                $count = EntryRepository::delete($ids);
                Forms::log('entries_deleted', sprintf(_n('%d entrée de formulaire supprimée', '%d entrées de formulaires supprimées', $count, 'smbb-wpcodetool'), $count), array(), array('ids' => $ids), 'notice');

                // Après la suppression d'une entrée ouverte : retour à la liste.
                if (strpos($back, 'entry=') !== false) {
                    $back = remove_query_arg('entry', $back);
                }
                break;
            default:
                self::flash('error', __('Choisissez une action.', 'smbb-wpcodetool'));
                wp_safe_redirect($back);
                exit;
        }

        if ($action !== 'delete' || $count) {
            self::flash('success', $action === 'delete'
                ? sprintf(_n('%d entrée supprimée.', '%d entrées supprimées.', $count, 'smbb-wpcodetool'), $count)
                : __('C\'est fait.', 'smbb-wpcodetool'));
        }

        wp_safe_redirect($back);
        exit;
    }

    private static function import()
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- vérifié dans handle().
        $ids = isset($_POST['wpforms']) ? array_filter(array_map('absint', (array) wp_unslash($_POST['wpforms']))) : array();
        // phpcs:ignore WordPress.Security.NonceVerification
        $with_entries = !empty($_POST['with_entries']);

        if (!$ids) {
            self::flash('error', __('Cochez au moins un formulaire à reprendre.', 'smbb-wpcodetool'));
            wp_safe_redirect(self::url('import'));
            exit;
        }

        $report = WpFormsImport::import($ids, $with_entries);
        set_transient('smbb_forms_import_' . get_current_user_id(), $report, 600);
        Forms::log('wpforms_imported', sprintf(__('Reprise WPForms : %1$d formulaire(s), %2$d entrée(s)', 'smbb-wpcodetool'), $report['forms'], $report['entries']), array(), array('report' => $report), 'notice');
        self::flash('success', sprintf(__('Reprise terminée : %1$d formulaire(s), %2$d entrée(s).', 'smbb-wpcodetool'), $report['forms'], $report['entries']));
        wp_safe_redirect(self::url('import'));
        exit;
    }

    /**
     * Export CSV des entrées filtrées. Avec un formulaire choisi : une colonne par champ.
     */
    private static function export()
    {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::NONCE . '_csv')) {
            wp_die(esc_html__('Lien d\'export expiré : rechargez la page des entrées.', 'smbb-wpcodetool'));
        }

        $filters = self::filters();
        $form = $filters['form'] !== '' ? FormRepository::find((int) $filters['form']) : null;
        $columns = array();

        if ($form) {
            foreach ($form['fields'] as $field) {
                if (FieldTypes::isInput($field['type'])) {
                    $columns[$field['key']] = $field['label'];
                }
            }
        }

        Forms::log('entries_exported', $form ? sprintf(__('Export CSV des entrées : %s', 'smbb-wpcodetool'), $form['title']) : __('Export CSV des entrées de formulaires', 'smbb-wpcodetool'), $form ?: array(), array('filters' => array_filter($filters)), 'notice');

        $names = FormRepository::choices();
        $filename = sanitize_file_name(($form ? $form['title'] : 'formulaires') . '-entrees-' . wp_date('Y-m-d-His')) . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $head = array(__('N°', 'smbb-wpcodetool'), __('Date', 'smbb-wpcodetool'), __('Formulaire', 'smbb-wpcodetool'), __('Statut', 'smbb-wpcodetool'), __('Suivie', 'smbb-wpcodetool'));
        $head = $columns ? array_merge($head, array_values($columns)) : array_merge($head, array(__('Réponses', 'smbb-wpcodetool')));
        $head[] = __('Page', 'smbb-wpcodetool');
        $head[] = 'IP';
        fputcsv($out, $head, ';');

        foreach (EntryRepository::iterate($filters) as $entry) {
            $row = array(
                $entry['id'],
                get_date_from_gmt($entry['created_at'], 'Y-m-d H:i:s'),
                $names[$entry['form_id']] ?? '#' . $entry['form_id'],
                self::statusLabel($entry['status']),
                $entry['starred'] ? __('oui', 'smbb-wpcodetool') : '',
            );

            if ($columns) {
                $by_key = array();

                foreach ($entry['fields'] as $field) {
                    $by_key[$field['key'] ?? ''] = SmartTags::valueText($field);
                }

                foreach (array_keys($columns) as $key) {
                    $row[] = self::csvCell($by_key[$key] ?? '');
                }
            } else {
                $row[] = self::csvCell(SmartTags::allFieldsText($entry['fields']));
            }

            $row[] = $entry['page_url'];
            $row[] = $entry['ip'];
            fputcsv($out, $row, ';');
        }

        fclose($out);
        exit;
    }

    /** Pas de formule exécutée par le tableur à l'ouverture. */
    private static function csvCell($value)
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], array('=', '+', '-', '@'), true) ? "'" . $value : $value;
    }

    private static function statusLabel($status)
    {
        $labels = array(
            'unread' => __('Non lue', 'smbb-wpcodetool'),
            'read' => __('Lue', 'smbb-wpcodetool'),
            'spam' => __('Indésirable', 'smbb-wpcodetool'),
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    private static function filters()
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        return EntryRepository::normalizeFilters(array_map(static function ($value) {
            return is_scalar($value) ? (string) wp_unslash($value) : '';
        }, $_GET));
    }

    private static function flash($type, $message)
    {
        set_transient('smbb_forms_flash_' . get_current_user_id(), array($type, $message), 120);
    }

    private function notices()
    {
        $key = 'smbb_forms_flash_' . get_current_user_id();
        $flash = get_transient($key);
        $notices = array();

        if (is_array($flash)) {
            delete_transient($key);
            $notices[] = '<div class="smbb-codetool-notice is-' . esc_attr($flash[0]) . '"><p class="smbb-codetool-notice-message">' . esc_html($flash[1]) . '</p></div>';
        }

        if (!Forms::ready()) {
            $notices[] = '<div class="smbb-codetool-notice is-info"><p class="smbb-codetool-notice-message">' . esc_html__('Les tables des formulaires se créent au prochain chargement d\'une page d\'admin.', 'smbb-wpcodetool') . '</p></div>';
        }

        return $notices;
    }

    // ------------------------------------------------------------------ Rendu

    public function render($view = '')
    {
        if (!current_user_can(Forms::capability())) {
            wp_die(esc_html__('Vous n\'avez pas le droit de gérer les formulaires.', 'smbb-wpcodetool'));
        }

        echo '<div class="wrap smbb-codetool smbb-forms-page">';

        if ($view === 'entries') {
            // phpcs:ignore WordPress.Security.NonceVerification
            $entry = isset($_GET['entry']) ? EntryRepository::find(absint($_GET['entry'])) : null;
            $entry ? $this->renderEntry($entry) : $this->renderEntries();
        } elseif ($view === 'import') {
            $this->renderImport();
        } else {
            $form = self::openForm();
            $form ? $this->renderBuilder($form) : $this->renderList();
        }

        echo '</div>';
    }

    private function nonceField()
    {
        wp_nonce_field(self::NONCE, 'smbb_forms_nonce');
    }

    private function renderList()
    {
        $forms = FormRepository::all();
        $counts = EntryRepository::countsByForm();

        echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
            __('Formulaires', 'smbb-wpcodetool'),
            __('Contact, devis, inscription… Placez un formulaire dans une page avec le bloc « Formulaire ». Les réponses arrivent par e-mail et restent dans Entrées.', 'smbb-wpcodetool'),
            'dashicons-feedback',
            $this->notices()
        );

        // Formulaires WPForms pas encore repris : on propose la reprise tant qu'il en reste.
        $waiting = array_filter(WpFormsImport::detect(), static function ($item) {
            return !$item['imported'];
        });

        if ($waiting) {
            $entries = array_sum(array_map('intval', wp_list_pluck($waiting, 'entries')));
            echo '<div class="smbb-forms-import-hint"><span class="dashicons dashicons-database-import" aria-hidden="true"></span><p><strong>'
                . esc_html(sprintf(_n('%d formulaire WPForms à reprendre', '%d formulaires WPForms à reprendre', count($waiting), 'smbb-wpcodetool'), count($waiting)))
                . '</strong> ' . esc_html(implode(', ', wp_list_pluck($waiting, 'title')))
                . ($entries ? ' · ' . esc_html(sprintf(_n('%d entrée', '%d entrées', $entries, 'smbb-wpcodetool'), $entries)) : '')
                . '</p><a class="button button-primary" href="' . esc_url(self::url('import')) . '">' . esc_html__('Voir et reprendre', 'smbb-wpcodetool') . '</a></div>';
        }
        ?>
        <form method="post" class="smbb-forms-create">
            <?php $this->nonceField(); ?>
            <input type="hidden" name="smbb_forms_do" value="create">
            <label class="smbb-forms-create-title">
                <span><?php esc_html_e('Nouveau formulaire', 'smbb-wpcodetool'); ?></span>
                <input type="text" name="title" placeholder="<?php esc_attr_e('Nom (ex. Contact, Demande de devis)', 'smbb-wpcodetool'); ?>">
            </label>
            <fieldset class="smbb-forms-templates">
                <legend><?php esc_html_e('Partir de', 'smbb-wpcodetool'); ?></legend>
                <?php foreach (FormRepository::templates() as $key => $template) : ?>
                    <label class="smbb-forms-template">
                        <input type="radio" name="template" value="<?php echo esc_attr($key); ?>"<?php checked($key, 'contact'); ?>>
                        <span><?php echo esc_html($template['label']); ?><small><?php echo esc_html(sprintf(_n('%d champ', '%d champs', count($template['fields']), 'smbb-wpcodetool'), count($template['fields']))); ?></small></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <button class="button button-primary"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e('Créer', 'smbb-wpcodetool'); ?></button>
        </form>

        <table class="widefat striped smbb-codetool-table smbb-forms-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Formulaire', 'smbb-wpcodetool'); ?></th>
                    <th scope="col"><?php esc_html_e('À placer dans une page', 'smbb-wpcodetool'); ?></th>
                    <th scope="col"><?php esc_html_e('Entrées', 'smbb-wpcodetool'); ?></th>
                    <th scope="col"><?php esc_html_e('Dernière', 'smbb-wpcodetool'); ?></th>
                    <th scope="col" class="smbb-forms-actions-col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'smbb-wpcodetool'); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$forms) : ?>
                    <tr class="smbb-codetool-empty-row">
                        <td class="smbb-codetool-empty" colspan="5">
                            <span class="smbb-codetool-empty-icon" aria-hidden="true"><span class="dashicons dashicons-feedback"></span></span>
                            <p class="smbb-codetool-empty-title"><?php esc_html_e('Aucun formulaire pour l\'instant', 'smbb-wpcodetool'); ?></p>
                            <p class="smbb-codetool-empty-text">
                                <?php esc_html_e('Créez-en un ci-dessus', 'smbb-wpcodetool'); ?>
                                <?php if (WpFormsImport::available()) : ?>
                                    <?php esc_html_e('ou', 'smbb-wpcodetool'); ?> <a href="<?php echo esc_url(self::url('import')); ?>"><?php esc_html_e('reprenez ceux de WPForms', 'smbb-wpcodetool'); ?></a>
                                <?php endif; ?>.
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($forms as $form) : ?>
                    <?php $c = $counts[$form['id']] ?? array('total' => 0, 'unread' => 0, 'last' => ''); ?>
                    <tr>
                        <td>
                            <a class="smbb-codetool-primary-cell-link" href="<?php echo esc_url(self::formUrl($form['id'])); ?>"><strong><?php echo esc_html($form['title']); ?></strong></a>
                            <div class="smbb-forms-meta">
                                <?php echo esc_html(sprintf(_n('%d champ', '%d champs', count($form['fields']), 'smbb-wpcodetool'), count($form['fields']))); ?>
                                <?php if ($form['legacy_source'] === 'wpforms') : ?> · <?php echo esc_html(sprintf(__('repris de WPForms (n° %d)', 'smbb-wpcodetool'), $form['legacy_id'])); ?><?php endif; ?>
                            </div>
                        </td>
                        <td><code class="smbb-forms-code" title="<?php esc_attr_e('Shortcode, si la page n\'utilise pas l\'éditeur de blocs', 'smbb-wpcodetool'); ?>">[smbb_form id="<?php echo (int) $form['id']; ?>"]</code></td>
                        <td>
                            <a href="<?php echo esc_url(self::url('entries', array('form' => $form['id']))); ?>"><?php echo esc_html(number_format_i18n($c['total'])); ?></a>
                            <?php if ($c['unread']) : ?><span class="smbb-forms-badge"><?php echo esc_html(sprintf(_n('%d nouvelle', '%d nouvelles', $c['unread'], 'smbb-wpcodetool'), $c['unread'])); ?></span><?php endif; ?>
                        </td>
                        <td><?php echo $c['last'] !== '' ? esc_html(human_time_diff(strtotime($c['last'] . ' UTC'))) : '—'; ?></td>
                        <td class="smbb-forms-row-actions">
                            <a class="button button-small" href="<?php echo esc_url(self::formUrl($form['id'])); ?>"><?php esc_html_e('Modifier', 'smbb-wpcodetool'); ?></a>
                            <form method="post">
                                <?php $this->nonceField(); ?>
                                <input type="hidden" name="form_id" value="<?php echo (int) $form['id']; ?>">
                                <button class="smbb-codetool-icon-button" name="smbb_forms_do" value="duplicate" title="<?php esc_attr_e('Dupliquer', 'smbb-wpcodetool'); ?>" aria-label="<?php esc_attr_e('Dupliquer', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>
                                <button class="smbb-codetool-icon-button is-danger submitdelete" name="smbb_forms_do" value="delete" data-smbb-confirm="<?php echo esc_attr(sprintf(_n('Supprimer « %1$s » et sa %2$d entrée ? C\'est définitif.', 'Supprimer « %1$s » et ses %2$d entrées ? C\'est définitif.', $c['total'], 'smbb-wpcodetool'), $form['title'], $c['total'])); ?>" title="<?php esc_attr_e('Supprimer', 'smbb-wpcodetool'); ?>" aria-label="<?php esc_attr_e('Supprimer', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Builder : une section du formulaire à la fois (Champs, Réglages, Notifications, Confirmation).
     */
    private function renderBuilder(array $form)
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : 'fields';
        $sections = self::sections();
        $section = isset($sections[$section]) ? $section : 'fields';
        // « Identifiant » modifiable de chaque champ : prérempli avec sa clé actuelle.
        $item = array('title' => $form['title'], 'fields' => array_map(static function ($field) {
            return $field + array('new_key' => $field['key']);
        }, $form['fields'])) + $form['settings'];

        echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
            $form['title'],
            sprintf(__('%1$s · Bloc « Formulaire » dans l\'éditeur, ou shortcode [smbb_form id="%2$d"]', 'smbb-wpcodetool'), $sections[$section][0], $form['id']),
            $sections[$section][1],
            $this->notices()
        );

        $builder = new Form(array(
            'action_url' => self::formUrl($form['id'], $section),
            'item' => $item,
            'nonce_action' => 'smbb_forms_save_' . $form['id'],
        ));
        $hidden = '<input type="hidden" name="smbb_forms_do" value="save"><input type="hidden" name="form_id" value="' . (int) $form['id'] . '"><input type="hidden" name="section" value="' . esc_attr($section) . '">';

        echo '<div class="smbb-forms-builder smbb-forms-section-' . esc_attr($section) . '" data-smbb-forms-style="' . esc_attr($form['settings']['style']) . '">';

        switch ($section) {
            case 'settings':
                echo $builder->save(__('Enregistrer', 'smbb-wpcodetool'))->setFields($hidden, ...$this->settingsFields($builder, $form)); // phpcs:ignore -- HTML du form builder.
                break;
            case 'notifications':
                echo $builder->save(__('Enregistrer', 'smbb-wpcodetool'))->setFields($hidden, ...$this->notificationFields($builder)); // phpcs:ignore
                break;
            case 'confirmations':
                echo $builder->save(__('Enregistrer', 'smbb-wpcodetool'))->setFields($hidden, ...$this->confirmationFields($builder)); // phpcs:ignore
                break;
            default:
                echo $builder->save(__('Enregistrer le formulaire', 'smbb-wpcodetool'))->setFields($hidden, ...$this->fieldsFields($builder, $form)); // phpcs:ignore
        }

        echo '</div>';
    }

    private function fieldsFields(Form $f, array $form)
    {
        // Champs observables par une condition (forms-admin.js complète la liste avec les champs ajoutés).
        $refs = array(__('Choisir un champ…', 'smbb-wpcodetool') => '');

        foreach ($form['fields'] as $field) {
            if (FieldTypes::isInput($field['type']) && $field['type'] !== 'hidden') {
                $refs[($field['label'] !== '' ? wp_html_excerpt($field['label'], 40, '…') : $field['key']) . ' (' . $field['key'] . ')'] = $field['key'];
            }
        }

        $modes = array_flip(Logic::modes());
        $ops = array_flip(Logic::operators());

        $types = array();

        foreach (FieldTypes::all() as $key => $def) {
            $types[$key] = array('label' => $def['label'], 'icon' => $def['icon'], 'group' => $def['group']);
        }

        $with_placeholder = array_keys(array_filter(FieldTypes::all(), static function ($def) {
            return $def['placeholder'];
        }));
        $with_placeholder[] = 'select';

        return array(
            $f->text(__('Nom du formulaire', 'smbb-wpcodetool'))->setName('title')->required(),
            $f->repeater('')
                ->setName('fields')
                ->types($types)
                ->titleFrom('label')
                ->widthFrom('width')
                ->uid('key', 'f')
                ->mode('preview', array('renderer' => 'smbbFormField', 'empty' => __('Formulaire vide : cliquez sur un champ de la palette, ou glissez-le ici.', 'smbb-wpcodetool')))
                ->setFields(array(
                    $f->text(__('Libellé', 'smbb-wpcodetool'))->setName('label'),
                    $f->toggle(__('Obligatoire', 'smbb-wpcodetool'))->setName('required')->hideIf('type', FieldTypes::noRequired()),
                    $f->textarea(__('Choix, un par ligne', 'smbb-wpcodetool'), array('rows' => 5))->setName('choices')->showIf('type', array('select', 'radio', 'checkbox')),
                    $f->textarea(__('Texte affiché', 'smbb-wpcodetool'), array('rows' => 5))->setName('content')->showIf('type', 'html'),
                    $f->text(__('Valeur envoyée', 'smbb-wpcodetool'))->setName('default')->showIf('type', 'hidden')
                        ->setHelp(__('Invisible pour le visiteur. Balises : {page_url}, {user_email}, {date}…', 'smbb-wpcodetool')),
                    $f->text(__('Texte d\'aide', 'smbb-wpcodetool'))->setName('help')->hideIf('type', array('hidden', 'html'))
                        ->setHelp(__('Affiché sous le champ (sous le titre pour une section).', 'smbb-wpcodetool')),
                    $f->text(__('Texte d\'exemple', 'smbb-wpcodetool'))->setName('placeholder')->showIf('type', $with_placeholder),
                    $f->select(__('Largeur', 'smbb-wpcodetool'))->setName('width')->hideIf('type', array('hidden', 'section'))->setOptions(array(
                        __('Pleine largeur', 'smbb-wpcodetool') => 'full',
                        __('Moitié', 'smbb-wpcodetool') => 'half',
                        __('Un tiers', 'smbb-wpcodetool') => 'third',
                        __('Deux tiers', 'smbb-wpcodetool') => 'two-thirds',
                    )),
                    $f->text(__('Formats acceptés', 'smbb-wpcodetool'), array('placeholder' => 'pdf, jpg, png, docx'))->setName('file_types')->showIf('type', 'file')
                        ->setHelp(__('Vide : documents et images courants.', 'smbb-wpcodetool')),
                    $f->number(__('Taille maximale (Mo)', 'smbb-wpcodetool'), array('min' => 1, 'max' => 64, 'placeholder' => '10'))->setName('file_max')->showIf('type', 'file'),
                    $f->select(__('Affichage', 'smbb-wpcodetool'))->setName('logic')->setOptions($modes)->hideIf('type', 'hidden')
                        ->setHelp(__('Logique conditionnelle : selon la réponse à un autre champ.', 'smbb-wpcodetool')),
                    $f->select(__('Quand le champ', 'smbb-wpcodetool'))->setName('logic_field')->setOptions($refs)->showIf('logic', array('show', 'hide')),
                    $f->select(__('Condition', 'smbb-wpcodetool'))->setName('logic_op')->setOptions($ops)->showIf('logic', array('show', 'hide')),
                    $f->text(__('Valeur', 'smbb-wpcodetool'))->setName('logic_value')->showIf('logic_op', array('is', 'is_not', 'contains'))
                        ->setHelp(__('Un des choix du champ, ou un texte. Majuscules et minuscules indifférentes.', 'smbb-wpcodetool')),
                    $f->text(__('Identifiant', 'smbb-wpcodetool'), array('placeholder' => 'prenom', 'maxlength' => 40, 'spellcheck' => 'false', 'autocomplete' => 'off'))->setName('new_key')->hideIf('type', array('section', 'html'))
                        ->setHelp(__('Nom de la balise dans les e-mails : {field:prenom}. Minuscules, chiffres, _ et -. Le changer met à jour les notifications, la confirmation, les conditions et les entrées déjà reçues.', 'smbb-wpcodetool')),
                    '<p class="description smbb-forms-key" data-smbb-forms-key></p>',
                )),
        );
    }

    private function settingsFields(Form $f, array $form)
    {
        $styles = array_flip(FormRepository::styles());
        $retentions = array_flip(FormRepository::retentions());
        $fields = array(
            $f->section(__('Affichage', 'smbb-wpcodetool'), '', array(
                $f->text(__('Texte du bouton', 'smbb-wpcodetool'))->setName('submit_label'),
                $f->toggle(__('Afficher le nom du formulaire au-dessus', 'smbb-wpcodetool'))->setName('show_title'),
                $f->textarea(__('Texte d\'introduction', 'smbb-wpcodetool'), array('rows' => 3))->setName('description')->setHelp(__('Facultatif, affiché avant les champs.', 'smbb-wpcodetool')),
                $f->select(__('Style des champs', 'smbb-wpcodetool'))->setName('style')->setOptions($styles)->setHelp(__('Couleurs et polices viennent du thème du site.', 'smbb-wpcodetool')),
                $f->text(__('Classe CSS', 'smbb-wpcodetool'))->setName('css_class')->setHelp(__('Pour un style particulier dans le thème. Facultatif.', 'smbb-wpcodetool')),
            ))->setIcon('dashicons-admin-appearance'),
            $f->section(__('Entrées', 'smbb-wpcodetool'), '', array(
                $f->toggle(__('Garder les entrées dans l\'admin', 'smbb-wpcodetool'))->setName('store_entries')
                    ->setHelp(__('Coupé : les réponses partent seulement par e-mail.', 'smbb-wpcodetool')),
                $f->select(__('Conservation', 'smbb-wpcodetool'))->setName('retention')->setOptions($retentions)->showIf('store_entries', '1')
                    ->setHelp(__('Les entrées plus anciennes sont effacées chaque nuit (RGPD).', 'smbb-wpcodetool')),
                $f->select(__('Stockage des pièces jointes', 'smbb-wpcodetool'))->setName('storage')->setOptions(array_flip(\Smbb\WpCodeTool\Storage\Storage::choices()))->showIf('store_entries', '1')
                    ->setHelp(sprintf(__('Où ranger les fichiers envoyés. Ajouter un bucket S3 : %s.', 'smbb-wpcodetool'), 'CodeTool › Réglages › Stockage')),
            ))->setIcon('dashicons-email-alt2'),
        );

        $spam = array('<p class="description">' . esc_html__('Toujours actifs : champ piège invisible et contrôle du temps de saisie.', 'smbb-wpcodetool') . '</p>');

        // Toujours proposée (cochée d'office) : la vérification s'applique dès que Turnstile est configuré dans CodeTool.
        $spam[] = $f->toggle(__('Vérification Turnstile (Cloudflare)', 'smbb-wpcodetool'))->setName('turnstile')
            ->setHelp(Turnstile::active()
                ? __('Active : clés réglées dans CodeTool › Sécurité › Turnstile. À décocher seulement si ce formulaire ne doit pas l\'avoir.', 'smbb-wpcodetool')
                : __('Turnstile n\'est pas encore configuré dans CodeTool : cochée, la vérification s\'activera toute seule dès que les clés y seront réglées.', 'smbb-wpcodetool'));

        if (!Turnstile::active()) {
            $spam[] = '<p class="description"><a href="' . esc_url(SecurityPage::url('turnstile')) . '">' . esc_html__('Configurer Turnstile dans CodeTool › Sécurité › Turnstile', 'smbb-wpcodetool') . '</a></p>';
        }

        $fields[] = $f->section(__('Anti-spam', 'smbb-wpcodetool'), '', $spam)->setIcon('dashicons-shield');

        if (class_exists(Sms::class) && Sms::ready() === true) {
            $fields[] = $f->section(__('SMS', 'smbb-wpcodetool'), '', array(
                $f->toggle(__('Prévenir par SMS à chaque entrée', 'smbb-wpcodetool'))->setName('sms'),
                $f->text(__('Numéros', 'smbb-wpcodetool'), array('placeholder' => '06 12 34 56 78'))->setName('sms_to')->showIf('sms', '1')
                    ->setHelp(__('Séparés par des virgules. Envoyé par le connecteur SMS de CodeTool.', 'smbb-wpcodetool')),
            ))->setIcon('dashicons-smartphone');
        }

        return $fields;
    }

    private function notificationFields(Form $f)
    {
        $tags = array();

        foreach (SmartTags::help() as $tag => $label) {
            $tags[] = '<code>' . esc_html($tag) . '</code> ' . esc_html($label);
        }

        return array(
            '<p class="description smbb-forms-tags">' . esc_html__('Balises utilisables :', 'smbb-wpcodetool') . ' ' . implode(' · ', $tags) . '</p>',
            $f->repeater('')
                ->setName('notifications')
                ->titleFrom('name')
                ->setFields(array(
                    $f->row(
                        $f->text(__('Nom', 'smbb-wpcodetool'))->setName('name'),
                        $f->toggle(__('Active', 'smbb-wpcodetool'), array('default' => '1'))->setName('enabled')
                    ),
                    $f->row(
                        $f->text(__('Envoyer à', 'smbb-wpcodetool'), array('placeholder' => '{admin_email}'))->setName('to')->setHelp(__('Une ou plusieurs adresses, séparées par des virgules.', 'smbb-wpcodetool')),
                        $f->text(__('Répondre à', 'smbb-wpcodetool'), array('placeholder' => '{field:f3}'))->setName('reply_to')->setHelp(__('Souvent l\'e-mail saisi par le visiteur.', 'smbb-wpcodetool'))
                    ),
                    $f->row(
                        $f->text(__('Objet', 'smbb-wpcodetool'))->setName('subject'),
                        $f->text(__('Nom de l\'expéditeur', 'smbb-wpcodetool'))->setName('from_name')->setHelp(__('Vide : celui de CodeTool › E-mails.', 'smbb-wpcodetool'))
                    ),
                    $f->textarea(__('Message', 'smbb-wpcodetool'), array('rows' => 7))->setName('message'),
                    $f->toggle(__('Joindre les fichiers envoyés', 'smbb-wpcodetool'))->setName('attach_files')
                        ->setHelp(sprintf(__('Jusqu\'à %d Mo au total ; au-delà, l\'e-mail garde seulement les liens. Dans {all_fields}, chaque fichier est un lien de téléchargement réservé aux comptes de l\'équipe (pas dans les e-mails envoyés au visiteur).', 'smbb-wpcodetool'), (int) round(\Smbb\WpCodeTool\Forms\Notifier::attachMax() / MB_IN_BYTES))),
                )),
            '<p class="description">' . sprintf(
                esc_html__('Envoyés par %s : SMTP, journal et renvoi compris.', 'smbb-wpcodetool'),
                '<a href="' . esc_url(admin_url('admin.php?page=' . MailPage::SLUG)) . '">' . esc_html__('CodeTool › E-mails', 'smbb-wpcodetool') . '</a>'
            ) . '</p>',
        );
    }

    private function confirmationFields(Form $f)
    {
        $pages = array(__('— Choisir une page —', 'smbb-wpcodetool') => '0');

        foreach (get_pages(array('sort_column' => 'post_title', 'post_status' => 'publish')) as $page) {
            $pages[$page->post_title !== '' ? $page->post_title : '#' . $page->ID] = (string) $page->ID;
        }

        return array(
            $f->section(__('Après l\'envoi', 'smbb-wpcodetool'), '', array(
                $f->select(__('Que voit le visiteur ?', 'smbb-wpcodetool'))->setName('confirmation[type]')->setOptions(array(
                    __('Un message à la place du formulaire', 'smbb-wpcodetool') => 'message',
                    __('Une page du site', 'smbb-wpcodetool') => 'page',
                    __('Une autre adresse', 'smbb-wpcodetool') => 'url',
                )),
                $f->textarea(__('Message', 'smbb-wpcodetool'), array('rows' => 4))->setName('confirmation[message]')->showIf('confirmation[type]', 'message')
                    ->setHelp(__('Balises admises : {form_name}, {site_name}…', 'smbb-wpcodetool')),
                $f->select(__('Page', 'smbb-wpcodetool'))->setName('confirmation[page]')->setOptions($pages)->showIf('confirmation[type]', 'page'),
                $f->text(__('Adresse', 'smbb-wpcodetool'), array('placeholder' => 'https://'))->setName('confirmation[url]')->showIf('confirmation[type]', 'url'),
            ))->setIcon('dashicons-yes-alt'),
        );
    }

    // ------------------------------------------------------------------ Entrées

    private function renderEntries()
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $filters = self::filters();
        $form = $filters['form'] !== '' ? FormRepository::find((int) $filters['form']) : null;
        $data = EntryRepository::query($filters, $paged, self::PER_PAGE);
        $counts = EntryRepository::statusCounts($form ? $form['id'] : 0);
        $names = FormRepository::choices();
        $keep = array_filter($filters, 'strlen');
        $pages = max(1, (int) ceil($data['total'] / self::PER_PAGE));
        $here = self::url('entries', $keep + ($paged > 1 ? array('paged' => $paged) : array()));

        echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
            $form ? sprintf(__('Entrées · %s', 'smbb-wpcodetool'), $form['title']) : __('Entrées', 'smbb-wpcodetool'),
            __('Les réponses envoyées par vos formulaires. Ouvrir une entrée la marque comme lue.', 'smbb-wpcodetool'),
            'dashicons-email-alt2',
            $this->notices()
        );

        $tabs = array(
            '' => array(__('Toutes', 'smbb-wpcodetool'), 'dashicons-screenoptions', $counts['all']),
            'unread' => array(__('Non lues', 'smbb-wpcodetool'), 'dashicons-marker', $counts['unread']),
            'starred' => array(__('Suivies', 'smbb-wpcodetool'), 'dashicons-star-filled', $counts['starred']),
            'spam' => array(__('Indésirables', 'smbb-wpcodetool'), 'dashicons-dismiss', $counts['spam']),
        );
        ?>
        <nav class="smbb-forms-tabs" aria-label="<?php esc_attr_e('Statut', 'smbb-wpcodetool'); ?>">
            <?php foreach ($tabs as $key => $tab) : ?>
                <?php if ($key === 'spam' && !$tab[2] && $filters['status'] !== 'spam') { continue; } ?>
                <a class="smbb-forms-tab<?php echo $filters['status'] === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url(self::url('entries', array_filter(array('status' => $key) + $keep, 'strlen'))); ?>">
                    <span class="dashicons <?php echo esc_attr($tab[1]); ?>" aria-hidden="true"></span><?php echo esc_html($tab[0]); ?>
                    <span class="smbb-forms-tab-count"><?php echo esc_html(number_format_i18n($tab[2])); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <form method="get" class="smbb-forms-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::ENTRIES_SLUG); ?>">
            <?php if ($filters['status'] !== '') : ?><input type="hidden" name="status" value="<?php echo esc_attr($filters['status']); ?>"><?php endif; ?>
            <label class="smbb-forms-search">
                <span class="screen-reader-text"><?php esc_html_e('Rechercher', 'smbb-wpcodetool'); ?></span>
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <input type="search" name="s" value="<?php echo esc_attr($filters['s']); ?>" placeholder="<?php esc_attr_e('Nom, e-mail, message…', 'smbb-wpcodetool'); ?>">
            </label>
            <label>
                <span class="smbb-forms-filter-label"><?php esc_html_e('Formulaire', 'smbb-wpcodetool'); ?></span>
                <select name="form">
                    <option value=""><?php esc_html_e('Tous', 'smbb-wpcodetool'); ?></option>
                    <?php foreach ($names as $id => $title) : ?>
                        <option value="<?php echo (int) $id; ?>"<?php selected($filters['form'], (string) $id); ?>><?php echo esc_html($title); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span class="smbb-forms-filter-label"><?php esc_html_e('Du', 'smbb-wpcodetool'); ?></span><input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>"></label>
            <label><span class="smbb-forms-filter-label"><?php esc_html_e('Au', 'smbb-wpcodetool'); ?></span><input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>"></label>
            <span class="smbb-forms-filter-actions">
                <button class="button button-primary"><?php esc_html_e('Filtrer', 'smbb-wpcodetool'); ?></button>
                <?php if (array_diff_key($keep, array('status' => 1))) : ?>
                    <a class="button" href="<?php echo esc_url(self::url('entries', $filters['status'] !== '' ? array('status' => $filters['status']) : array())); ?>"><?php esc_html_e('Effacer les filtres', 'smbb-wpcodetool'); ?></a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(self::url('entries', $keep + array('export' => 'csv')), self::NONCE . '_csv')); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e('CSV', 'smbb-wpcodetool'); ?></a>
            </span>
        </form>

        <form method="post" class="smbb-codetool-batch-form" data-smbb-batch-form data-confirm-delete="<?php esc_attr_e('Supprimer les entrées sélectionnées et leurs fichiers ? C\'est définitif.', 'smbb-wpcodetool'); ?>">
            <?php $this->nonceField(); ?>
            <input type="hidden" name="smbb_forms_do" value="entries">
            <input type="hidden" name="back" value="<?php echo esc_attr($here); ?>">
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <label class="screen-reader-text" for="smbb-forms-batch"><?php esc_html_e('Action groupée', 'smbb-wpcodetool'); ?></label>
                    <select name="batch" id="smbb-forms-batch">
                        <option value=""><?php esc_html_e('Actions groupées', 'smbb-wpcodetool'); ?></option>
                        <option value="read"><?php esc_html_e('Marquer comme lues', 'smbb-wpcodetool'); ?></option>
                        <option value="unread"><?php esc_html_e('Marquer comme non lues', 'smbb-wpcodetool'); ?></option>
                        <option value="star"><?php esc_html_e('Suivre (étoile)', 'smbb-wpcodetool'); ?></option>
                        <option value="unstar"><?php esc_html_e('Ne plus suivre', 'smbb-wpcodetool'); ?></option>
                        <?php if ($filters['status'] === 'spam') : ?>
                            <option value="not_spam"><?php esc_html_e('Pas indésirable', 'smbb-wpcodetool'); ?></option>
                        <?php else : ?>
                            <option value="spam"><?php esc_html_e('Indésirable', 'smbb-wpcodetool'); ?></option>
                        <?php endif; ?>
                        <option value="delete" data-danger><?php esc_html_e('Supprimer', 'smbb-wpcodetool'); ?></option>
                    </select>
                    <button class="button"><?php esc_html_e('Appliquer', 'smbb-wpcodetool'); ?></button>
                </div>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo esc_html(sprintf(_n('%s entrée', '%s entrées', $data['total'], 'smbb-wpcodetool'), number_format_i18n($data['total']))); ?></span>
                    <?php
                    if ($pages > 1) {
                        echo wp_kses_post(paginate_links(array(
                            'base' => add_query_arg('paged', '%#%', self::url('entries', $keep)),
                            'format' => '',
                            'current' => $paged,
                            'total' => $pages,
                        )));
                    }
                    ?>
                </div>
            </div>

            <table class="widefat striped smbb-codetool-table smbb-forms-entries">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" data-smbb-select-all aria-label="<?php esc_attr_e('Tout sélectionner', 'smbb-wpcodetool'); ?>"></td>
                        <th scope="col" class="smbb-forms-star-col"><span class="screen-reader-text"><?php esc_html_e('Suivie', 'smbb-wpcodetool'); ?></span></th>
                        <th scope="col"><?php esc_html_e('Réponses', 'smbb-wpcodetool'); ?></th>
                        <?php if (!$form) : ?><th scope="col"><?php esc_html_e('Formulaire', 'smbb-wpcodetool'); ?></th><?php endif; ?>
                        <th scope="col"><?php esc_html_e('Reçue', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$data['rows']) : ?>
                        <tr class="smbb-codetool-empty-row">
                            <td class="smbb-codetool-empty" colspan="<?php echo $form ? 4 : 5; ?>">
                                <span class="smbb-codetool-empty-icon" aria-hidden="true"><span class="dashicons dashicons-email-alt2"></span></span>
                                <p class="smbb-codetool-empty-title"><?php echo esc_html($keep ? __('Aucune entrée ne correspond', 'smbb-wpcodetool') : __('Aucune entrée pour l\'instant', 'smbb-wpcodetool')); ?></p>
                                <p class="smbb-codetool-empty-text"><?php esc_html_e('Les réponses des visiteurs apparaîtront ici.', 'smbb-wpcodetool'); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($data['rows'] as $entry) : ?>
                        <?php $link = self::url('entries', array('entry' => $entry['id']) + $keep); ?>
                        <tr class="<?php echo $entry['status'] === 'unread' ? 'is-unread' : ''; ?>">
                            <th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $entry['id']; ?>" data-smbb-row-select></th>
                            <td class="smbb-forms-star-col"><?php echo $entry['starred'] ? '<span class="dashicons dashicons-star-filled smbb-forms-star" aria-label="' . esc_attr__('Suivie', 'smbb-wpcodetool') . '"></span>' : ''; ?></td>
                            <td>
                                <a class="smbb-codetool-primary-cell-link" href="<?php echo esc_url($link); ?>">
                                    <?php if ($entry['status'] === 'unread') : ?><span class="smbb-forms-dot" aria-label="<?php esc_attr_e('Non lue', 'smbb-wpcodetool'); ?>"></span><?php endif; ?>
                                    <?php echo esc_html(EntryRepository::summary($entry) ?: sprintf(__('Entrée n° %d', 'smbb-wpcodetool'), $entry['id'])); ?>
                                    <?php $origin = EntryRepository::source($entry); ?>
                                    <?php if ($origin) : ?><span class="smbb-forms-origin" title="<?php echo esc_attr($origin['label']); ?>"><?php echo esc_html(ucfirst($entry['source'])); ?></span><?php endif; ?>
                                </a>
                            </td>
                            <?php if (!$form) : ?>
                                <td><a href="<?php echo esc_url(self::url('entries', array('form' => $entry['form_id']) + array_diff_key($keep, array('form' => 1)))); ?>"><?php echo esc_html($names[$entry['form_id']] ?? '#' . $entry['form_id']); ?></a></td>
                            <?php endif; ?>
                            <td><span title="<?php echo esc_attr(get_date_from_gmt($entry['created_at'], 'd/m/Y H:i')); ?>"><?php echo esc_html(self::when($entry['created_at'])); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <?php
    }

    private static function when($gmt)
    {
        $time = strtotime($gmt . ' UTC');

        if (time() - $time < DAY_IN_SECONDS) {
            return sprintf(__('il y a %s', 'smbb-wpcodetool'), human_time_diff($time));
        }

        return wp_date(get_option('date_format') . ' · H:i', $time);
    }

    private function renderEntry(array $entry)
    {
        $form = FormRepository::find($entry['form_id']);
        $filters = self::filters();
        $keep = array_diff_key(array_filter($filters, 'strlen'), array('entry' => 1));
        $near = EntryRepository::neighbours($entry, $filters);
        $here = self::url('entries', array('entry' => $entry['id']) + $keep);
        $user = $entry['user_id'] ? get_userdata($entry['user_id']) : null;

        echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
            sprintf(__('Entrée n° %d', 'smbb-wpcodetool'), $entry['id']),
            ($form ? $form['title'] . ' · ' : '') . wp_date(get_option('date_format') . ' · H:i', strtotime($entry['created_at'] . ' UTC')),
            'dashicons-email-alt2',
            $this->notices()
        );
        ?>
        <div class="smbb-forms-entry-nav">
            <a class="button" href="<?php echo esc_url(self::url('entries', $keep)); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e('Toutes les entrées', 'smbb-wpcodetool'); ?></a>
            <span class="smbb-forms-entry-step">
                <?php if ($near['prev']) : ?><a class="button" href="<?php echo esc_url(self::url('entries', array('entry' => $near['prev']) + $keep)); ?>" title="<?php esc_attr_e('Plus récente', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e('Plus récente', 'smbb-wpcodetool'); ?></span></a><?php endif; ?>
                <?php if ($near['next']) : ?><a class="button" href="<?php echo esc_url(self::url('entries', array('entry' => $near['next']) + $keep)); ?>" title="<?php esc_attr_e('Plus ancienne', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e('Plus ancienne', 'smbb-wpcodetool'); ?></span></a><?php endif; ?>
            </span>
            <form method="post" class="smbb-forms-entry-actions">
                <?php $this->nonceField(); ?>
                <input type="hidden" name="smbb_forms_do" value="entries">
                <input type="hidden" name="ids[]" value="<?php echo (int) $entry['id']; ?>">
                <input type="hidden" name="back" value="<?php echo esc_attr($here); ?>">
                <button class="button" name="batch" value="<?php echo $entry['starred'] ? 'unstar' : 'star'; ?>"><span class="dashicons <?php echo $entry['starred'] ? 'dashicons-star-filled' : 'dashicons-star-empty'; ?>" aria-hidden="true"></span><?php echo $entry['starred'] ? esc_html__('Suivie', 'smbb-wpcodetool') : esc_html__('Suivre', 'smbb-wpcodetool'); ?></button>
                <button class="button" name="batch" value="unread"><?php esc_html_e('Marquer non lue', 'smbb-wpcodetool'); ?></button>
                <button class="button" name="batch" value="<?php echo $entry['status'] === 'spam' ? 'not_spam' : 'spam'; ?>"><?php echo $entry['status'] === 'spam' ? esc_html__('Pas indésirable', 'smbb-wpcodetool') : esc_html__('Indésirable', 'smbb-wpcodetool'); ?></button>
                <button class="button smbb-forms-danger submitdelete" name="batch" value="delete" data-smbb-confirm="<?php esc_attr_e('Supprimer cette entrée et ses fichiers ? C\'est définitif.', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e('Supprimer', 'smbb-wpcodetool'); ?></button>
            </form>
        </div>

        <div class="smbb-forms-entry">
            <?php echo EntryView::html($entry); // phpcs:ignore -- HTML échappé par EntryView. ?>

            <aside class="smbb-forms-entry-meta">
                <h3><?php esc_html_e('Détails', 'smbb-wpcodetool'); ?></h3>
                <dl>
                    <dt><?php esc_html_e('Statut', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(self::statusLabel($entry['status'])); ?></dd>
                    <?php if ($form) : ?>
                        <dt><?php esc_html_e('Formulaire', 'smbb-wpcodetool'); ?></dt><dd><a href="<?php echo esc_url(self::formUrl($form['id'])); ?>"><?php echo esc_html($form['title']); ?></a></dd>
                    <?php endif; ?>
                    <?php if ($entry['page_url'] !== '') : ?>
                        <dt><?php esc_html_e('Page', 'smbb-wpcodetool'); ?></dt><dd><a href="<?php echo esc_url($entry['page_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html(wp_parse_url($entry['page_url'], PHP_URL_PATH) ?: $entry['page_url']); ?></a></dd>
                    <?php endif; ?>
                    <?php if ($user) : ?>
                        <dt><?php esc_html_e('Compte', 'smbb-wpcodetool'); ?></dt><dd><a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><?php echo esc_html($user->display_name); ?></a></dd>
                    <?php endif; ?>
                    <?php if ($entry['ip'] !== '') : ?>
                        <dt><?php esc_html_e('Adresse IP', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($entry['ip']); ?></dd>
                    <?php endif; ?>
                    <?php if ($entry['user_agent'] !== '') : ?>
                        <dt><?php esc_html_e('Navigateur', 'smbb-wpcodetool'); ?></dt><dd class="smbb-forms-ua"><?php echo esc_html($entry['user_agent']); ?></dd>
                    <?php endif; ?>
                    <?php $origin = EntryRepository::source($entry); ?>
                    <?php if ($origin) : ?>
                        <dt><?php esc_html_e('Origine', 'smbb-wpcodetool'); ?></dt><dd><?php echo $origin['url'] !== '' ? '<a href="' . esc_url($origin['url']) . '">' . esc_html($origin['label']) . '</a>' : esc_html($origin['label']); ?></dd>
                    <?php endif; ?>
                    <?php if ($entry['legacy_id']) : ?>
                        <dt><?php esc_html_e('Origine', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(sprintf(__('WPForms, entrée n° %d', 'smbb-wpcodetool'), $entry['legacy_id'])); ?></dd>
                    <?php endif; ?>
                </dl>
            </aside>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ Reprise WPForms

    private function renderImport()
    {
        $found = WpFormsImport::detect();
        $key = 'smbb_forms_import_' . get_current_user_id();
        $report = get_transient($key);

        if ($report) {
            delete_transient($key);
        }

        echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
            __('Reprendre les formulaires de WPForms', 'smbb-wpcodetool'),
            __('Les formulaires et leurs entrées sont copiés : WPForms n\'est pas modifié. Une fois WPForms désactivé, ses blocs et shortcodes affichent les formulaires repris, sans retoucher les pages.', 'smbb-wpcodetool'),
            'dashicons-database-import',
            $this->notices()
        );

        if (is_array($report) && !empty($report['notes'])) {
            echo '<div class="smbb-codetool-notice is-info"><p class="smbb-codetool-notice-message">' . esc_html__('Points à vérifier après la reprise :', 'smbb-wpcodetool') . '</p><ul class="smbb-forms-report">';

            foreach ($report['notes'] as $note) {
                echo '<li>' . esc_html($note) . '</li>';
            }

            echo '</ul></div>';
        }

        if (!$found) {
            echo '<div class="smbb-codetool-empty smbb-forms-import-empty"><span class="smbb-codetool-empty-icon" aria-hidden="true"><span class="dashicons dashicons-database-import"></span></span>'
                . '<p class="smbb-codetool-empty-title">' . esc_html__('Aucun formulaire WPForms trouvé', 'smbb-wpcodetool') . '</p>'
                . '<p class="smbb-codetool-empty-text">' . esc_html__('Rien à reprendre sur ce site.', 'smbb-wpcodetool') . '</p></div>';

            return;
        }
        ?>
        <form method="post" class="smbb-forms-import">
            <?php $this->nonceField(); ?>
            <input type="hidden" name="smbb_forms_do" value="import">
            <table class="widefat striped smbb-codetool-table">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" data-smbb-select-all aria-label="<?php esc_attr_e('Tout sélectionner', 'smbb-wpcodetool'); ?>"></td>
                        <th scope="col"><?php esc_html_e('Formulaire WPForms', 'smbb-wpcodetool'); ?></th>
                        <th scope="col"><?php esc_html_e('Champs', 'smbb-wpcodetool'); ?></th>
                        <th scope="col"><?php esc_html_e('Entrées', 'smbb-wpcodetool'); ?></th>
                        <th scope="col"><?php esc_html_e('Déjà repris', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($found as $item) : ?>
                        <tr>
                            <th scope="row" class="check-column"><input type="checkbox" name="wpforms[]" value="<?php echo (int) $item['id']; ?>" data-smbb-row-select<?php checked(!$item['imported']); ?>></th>
                            <td><strong><?php echo esc_html($item['title']); ?></strong> <span class="smbb-forms-meta">n° <?php echo (int) $item['id']; ?></span>
                                <?php if ($item['unsupported']) : ?><div class="smbb-forms-meta"><?php echo esc_html(sprintf(__('Non repris : %s', 'smbb-wpcodetool'), implode(', ', $item['unsupported']))); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo (int) $item['fields']; ?></td>
                            <td><?php echo $item['entries'] === null ? '—' : esc_html(number_format_i18n($item['entries'])); ?></td>
                            <td><?php echo $item['imported'] ? '<a href="' . esc_url(self::formUrl($item['imported'])) . '">' . esc_html__('Oui, voir', 'smbb-wpcodetool') . '</a>' : esc_html__('Non', 'smbb-wpcodetool'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="smbb-forms-import-options">
                <label><input type="checkbox" name="with_entries" value="1" checked> <?php esc_html_e('Reprendre aussi les entrées (sans doublon si on relance)', 'smbb-wpcodetool'); ?></label>
            </p>
            <p class="submit"><button class="button button-primary"><span class="dashicons dashicons-database-import" aria-hidden="true"></span><?php esc_html_e('Reprendre la sélection', 'smbb-wpcodetool'); ?></button></p>
            <p class="description"><?php esc_html_e('Un formulaire déjà repris n\'est pas recréé : seules ses nouvelles entrées sont ajoutées.', 'smbb-wpcodetool'); ?></p>
        </form>
        <?php
    }
}
