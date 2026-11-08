<?php

namespace Smbb\WpCodeTool\Forms;

use Smbb\WpCodeTool\Security\Security;
use Smbb\WpCodeTool\Security\Turnstile;

defined('ABSPATH') || exit;

/**
 * Affichage des formulaires sur le site : shortcode [smbb_form id="12"], bloc « Formulaire »,
 * et reprise des anciens [wpforms id="…"] / blocs WPForms quand WPForms n'est plus actif.
 */
final class Frontend
{
    const BLOCK = 'smbb/form';

    /** @var bool */
    private static $assets = false;

    public static function register()
    {
        add_shortcode('smbb_form', array(__CLASS__, 'shortcode'));

        wp_register_style('smbb-forms', SMBB_WPCODETOOL_URL . 'assets/forms.css', array(), self::version('forms.css'));
        wp_register_script('smbb-forms', SMBB_WPCODETOOL_URL . 'assets/forms.js', array(), self::version('forms.js'), true);

        if (function_exists('register_block_type')) {
            wp_register_script(
                'smbb-forms-block',
                SMBB_WPCODETOOL_URL . 'assets/forms-block.js',
                array('wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n'),
                self::version('forms-block.js'),
                true
            );

            register_block_type(self::BLOCK, array(
                'api_version' => 2,
                'title' => __('Formulaire', 'smbb-wpcodetool'),
                'category' => 'widgets',
                'icon' => 'feedback',
                'attributes' => array(
                    'formId' => array('type' => 'number', 'default' => 0),
                    'className' => array('type' => 'string', 'default' => ''),
                ),
                'editor_script' => 'smbb-forms-block',
                'editor_style' => 'smbb-forms',
                'render_callback' => array(__CLASS__, 'renderBlock'),
            ));

            add_action('enqueue_block_editor_assets', array(__CLASS__, 'editorData'));
        }

        // WPForms désactivé après la reprise : ses shortcodes et blocs continuent d'afficher le formulaire repris.
        if (!function_exists('wpforms') && !class_exists('WPForms\\WPForms', false)) {
            if (!shortcode_exists('wpforms')) {
                add_shortcode('wpforms', array(__CLASS__, 'legacyShortcode'));
            }

            if (function_exists('register_block_type') && !\WP_Block_Type_Registry::get_instance()->is_registered('wpforms/form-selector')) {
                register_block_type('wpforms/form-selector', array(
                    'api_version' => 2,
                    'attributes' => array(
                        'formId' => array('type' => 'string', 'default' => ''),
                        'displayTitle' => array('type' => 'boolean', 'default' => false),
                        'displayDesc' => array('type' => 'boolean', 'default' => false),
                        'className' => array('type' => 'string', 'default' => ''),
                    ),
                    'render_callback' => array(__CLASS__, 'renderLegacyBlock'),
                ));
            }
        }
    }

    private static function version($file)
    {
        $path = SMBB_WPCODETOOL_PATH . 'assets/' . $file;

        return is_readable($path) ? SMBB_WPCODETOOL_VERSION . '.' . filemtime($path) : SMBB_WPCODETOOL_VERSION;
    }

    /**
     * Liste des formulaires pour le bloc de l'éditeur.
     */
    public static function editorData()
    {
        $forms = array();

        foreach (FormRepository::choices() as $id => $title) {
            $forms[] = array('value' => $id, 'label' => $title);
        }

        wp_localize_script('smbb-forms-block', 'SmbbFormsBlock', array(
            'forms' => $forms,
            'newUrl' => admin_url('admin.php?page=' . \Smbb\WpCodeTool\Admin\Pages\FormsPage::SLUG),
            'editUrl' => admin_url('admin.php?page=' . \Smbb\WpCodeTool\Admin\Pages\FormsPage::SLUG . '&form='),
        ));
    }

    public static function shortcode($atts)
    {
        $atts = shortcode_atts(array('id' => 0, 'class' => ''), (array) $atts, 'smbb_form');

        return self::render((int) $atts['id'], array('class' => (string) $atts['class']));
    }

    public static function renderBlock($attributes)
    {
        $id = isset($attributes['formId']) ? (int) $attributes['formId'] : 0;

        if ($id <= 0) {
            return defined('REST_REQUEST') && REST_REQUEST ? '<p>' . esc_html__('Choisissez un formulaire dans la colonne de droite.', 'smbb-wpcodetool') . '</p>' : '';
        }

        return self::render($id, array('class' => isset($attributes['className']) ? (string) $attributes['className'] : ''));
    }

    public static function legacyShortcode($atts)
    {
        $atts = shortcode_atts(array('id' => 0, 'title' => false, 'description' => false), (array) $atts, 'wpforms');

        return self::renderLegacy((int) $atts['id'], array('show_title' => filter_var($atts['title'], FILTER_VALIDATE_BOOLEAN)));
    }

    public static function renderLegacyBlock($attributes)
    {
        return self::renderLegacy(isset($attributes['formId']) ? (int) $attributes['formId'] : 0, array(
            'show_title' => !empty($attributes['displayTitle']),
            'class' => isset($attributes['className']) ? (string) $attributes['className'] : '',
        ));
    }

    private static function renderLegacy($wpforms_id, array $args)
    {
        $form = $wpforms_id > 0 ? FormRepository::findByLegacy('wpforms', $wpforms_id) : null;

        if (!$form) {
            return current_user_can(Forms::capability())
                ? '<p class="smbb-form-missing">' . esc_html(sprintf(__('Formulaire WPForms n° %d pas encore repris dans CodeTool › Formulaires › Importer.', 'smbb-wpcodetool'), $wpforms_id)) . '</p>'
                : '';
        }

        return self::render($form['id'], $args);
    }

    public static function enqueue()
    {
        if (self::$assets) {
            return;
        }

        self::$assets = true;
        wp_enqueue_style('smbb-forms');
        wp_enqueue_script('smbb-forms');
    }

    /**
     * HTML d'un formulaire. Après un envoi refusé, les valeurs et erreurs sont reprises.
     */
    public static function render($form_id, array $args = array())
    {
        $form = FormRepository::find($form_id);

        if (!$form || $form['status'] === 'trash') {
            return current_user_can(Forms::capability()) ? '<p class="smbb-form-missing">' . esc_html__('Ce formulaire n\'existe plus.', 'smbb-wpcodetool') . '</p>' : '';
        }

        self::enqueue();
        $settings = $form['settings'];
        $state = Submission::state($form['id']);
        $sent = Submission::sentFor($form['id']);
        $dom_id = 'smbb-form-' . $form['id'];
        $classes = array('smbb-form', 'smbb-form--' . sanitize_html_class($settings['style']));

        foreach (array($settings['css_class'], $args['class'] ?? '') as $extra) {
            foreach (explode(' ', (string) $extra) as $class) {
                if (sanitize_html_class($class) !== '') {
                    $classes[] = sanitize_html_class($class);
                }
            }
        }

        $show_title = !empty($args['show_title']) || $settings['show_title'] === '1';
        $html = '<div class="' . esc_attr(implode(' ', $classes)) . '" id="' . esc_attr($dom_id) . '">';

        if ($show_title) {
            $html .= '<h2 class="smbb-form-title">' . esc_html($form['title']) . '</h2>';
        }

        if ($sent) {
            $message = SmartTags::replace($settings['confirmation']['message'], Submission::sentContext($form), true);

            return $html . '<div class="smbb-form-confirmation" role="status" tabindex="-1">' . wp_kses_post(wpautop($message)) . '</div></div>';
        }

        if (trim((string) $settings['description']) !== '') {
            $html .= '<div class="smbb-form-description">' . wp_kses_post(wpautop($settings['description'])) . '</div>';
        }

        if (!empty($state['error'])) {
            $html .= '<div class="smbb-form-alert" role="alert" tabindex="-1">' . wp_kses($state['error'], array('strong' => array(), 'br' => array())) . '</div>';
        }

        $has_file = false;

        foreach ($form['fields'] as $field) {
            if ($field['type'] === 'file') {
                $has_file = true;
                break;
            }
        }

        $action = self::currentUrl();
        $html .= '<form method="post" action="' . esc_url($action . '#' . $dom_id) . '" class="smbb-form-body" novalidate' . ($has_file ? ' enctype="multipart/form-data"' : '') . ' data-smbb-form>';
        $html .= '<input type="hidden" name="smbb_form_id" value="' . esc_attr($form['id']) . '">';
        $html .= '<input type="hidden" name="smbb_form_t" value="' . esc_attr(Submission::token($form['id'])) . '">';
        $html .= '<input type="hidden" name="smbb_form_page" value="' . esc_attr($action) . '">';

        // Champ piège : invisible pour les personnes, rempli par les robots.
        $html .= '<div class="smbb-form-hp" aria-hidden="true"><label>' . esc_html__('Ne pas remplir', 'smbb-wpcodetool') . '<input type="text" name="smbb_form_website" value="" tabindex="-1" autocomplete="off"></label></div>';

        $html .= '<div class="smbb-form-grid">';

        // Logique conditionnelle : état de départ calculé ici (pas de saut à l'affichage), puis forms.js suit les réponses.
        $start = array();

        foreach ($form['fields'] as $field) {
            $start[$field['key']] = isset($state['values']) && array_key_exists($field['key'], $state['values']) ? $state['values'][$field['key']] : '';
        }

        $visible = Logic::visibility($form['fields'], $start);

        foreach ($form['fields'] as $field) {
            $html .= self::fieldHtml($form, $field, $state, empty($visible[$field['key']]));
        }

        $html .= '</div>';

        if ($settings['turnstile'] === '1' && class_exists(Turnstile::class) && Turnstile::active()) {
            Turnstile::enqueue();
            $ts = Security::part('turnstile');
            $html .= '<div class="smbb-form-turnstile"><div class="cf-turnstile" data-sitekey="' . esc_attr(trim((string) $ts['site_key'])) . '" data-theme="' . esc_attr(in_array($ts['theme'], array('light', 'dark'), true) ? $ts['theme'] : 'auto') . '" data-size="flexible" data-language="' . esc_attr(substr(determine_locale(), 0, 2)) . '"></div></div>';
        }

        $html .= '<div class="smbb-form-actions"><button type="submit" class="smbb-form-submit wp-element-button">' . esc_html($settings['submit_label'] !== '' ? $settings['submit_label'] : __('Envoyer', 'smbb-wpcodetool')) . '</button></div>';
        $html .= '</form></div>';

        return $html;
    }

    /**
     * Champs seuls d'un formulaire, à poser dans le <form> d'un plugin (documents Passflow…) :
     * ni balise <form>, ni champ piège, ni Turnstile, ni bouton (le plugin protège déjà sa page).
     * Le plugin traite l'envoi avec Submission::validate() puis Submission::store().
     *
     * $args : name_prefix (ex. 'doc[decharge][form]'), file_prefix (ex. 'pf_doc_decharge_'),
     * id_prefix (ids HTML uniques), values / errors (réaffichage après un envoi refusé), title (bool).
     * Le <form> du plugin doit avoir enctype="multipart/form-data" si self::hasFile($form).
     */
    public static function fieldsHtml(array $form, array $args = array())
    {
        self::enqueue();

        $state = array(
            'name_prefix' => isset($args['name_prefix']) ? (string) $args['name_prefix'] : 'smbb_fields',
            'file_prefix' => isset($args['file_prefix']) ? (string) $args['file_prefix'] : 'smbb_file_',
            'id_prefix' => isset($args['id_prefix']) ? (string) $args['id_prefix'] : 'smbb-form-' . $form['id'],
            'values' => isset($args['values']) && is_array($args['values']) ? $args['values'] : array(),
            'errors' => isset($args['errors']) && is_array($args['errors']) ? $args['errors'] : array(),
        );
        $start = array();

        foreach ($form['fields'] as $field) {
            $start[$field['key']] = array_key_exists($field['key'], $state['values']) ? $state['values'][$field['key']] : '';
        }

        $visible = Logic::visibility($form['fields'], $start);
        $html = '<div class="smbb-form smbb-form--embedded smbb-form--' . sanitize_html_class($form['settings']['style']) . '" data-smbb-form>';

        if (!empty($args['title'])) {
            $html .= '<h3 class="smbb-form-title">' . esc_html($form['title']) . '</h3>';
        }

        if (trim((string) $form['settings']['description']) !== '') {
            $html .= '<div class="smbb-form-description">' . wp_kses_post(wpautop($form['settings']['description'])) . '</div>';
        }

        $html .= '<div class="smbb-form-grid">';

        foreach ($form['fields'] as $field) {
            $html .= self::fieldHtml($form, $field, $state, empty($visible[$field['key']]));
        }

        return $html . '</div></div>';
    }

    public static function hasFile(array $form)
    {
        foreach ($form['fields'] as $field) {
            if ($field['type'] === 'file') {
                return true;
            }
        }

        return false;
    }

    /**
     * Rendu d'un champ (mêmes classes que l'aperçu du builder).
     * $state : values, errors, et pour un formulaire intégré name_prefix / file_prefix / id_prefix.
     */
    public static function fieldHtml(array $form, array $field, array $state = array(), $hidden = false)
    {
        $logic = Logic::attributes($field) . ($hidden ? ' hidden' : '');
        $type = $field['type'];
        $key = $field['key'];
        // Noms des champs : ceux du formulaire public, ou ceux du <form> d'un plugin (fieldsHtml).
        $id = (isset($state['id_prefix']) ? (string) $state['id_prefix'] : 'smbb-form-' . $form['id']) . '-' . $key;
        $name = (isset($state['name_prefix']) ? (string) $state['name_prefix'] : 'smbb_fields') . '[' . $key . ']';
        $file_prefix = isset($state['file_prefix']) ? (string) $state['file_prefix'] : 'smbb_file_';
        $values = isset($state['values']) ? $state['values'] : array();
        $errors = isset($state['errors']) ? $state['errors'] : array();
        $value = array_key_exists($key, $values) ? $values[$key] : '';
        $error = isset($errors[$key]) ? (string) $errors[$key] : '';
        $required = $field['required'] === '1';
        $width = in_array($field['width'], FieldTypes::WIDTHS, true) ? $field['width'] : 'full';
        $describe = array();
        $help = '';
        $error_html = '';

        if ($field['help'] !== '' && !in_array($type, array('section', 'html'), true)) {
            $help = '<p class="smbb-form-help" id="' . esc_attr($id . '-help') . '">' . esc_html($field['help']) . '</p>';
            $describe[] = $id . '-help';
        }

        if ($error !== '') {
            $error_html = '<p class="smbb-form-error" id="' . esc_attr($id . '-error') . '">' . esc_html($error) . '</p>';
            $describe[] = $id . '-error';
        }

        $aria = ($describe ? ' aria-describedby="' . esc_attr(implode(' ', $describe)) . '"' : '') . ($error !== '' ? ' aria-invalid="true"' : '') . ($required ? ' required aria-required="true"' : '');
        $label = '<label class="smbb-form-label" for="' . esc_attr($id) . '">' . esc_html($field['label']) . ($required ? '<span class="smbb-form-required" aria-hidden="true">*</span>' : '') . '</label>';
        $placeholder = $field['placeholder'] !== '' ? ' placeholder="' . esc_attr($field['placeholder']) . '"' : '';
        $control = '';

        switch ($type) {
            case 'section':
                return '<div class="smbb-form-field smbb-form-field--section" data-width="full"' . $logic . '><h3 class="smbb-form-section-title">' . esc_html($field['label']) . '</h3>'
                    . ($field['help'] !== '' ? '<p class="smbb-form-section-text">' . esc_html($field['help']) . '</p>' : '') . '</div>';

            case 'html':
                return '<div class="smbb-form-field smbb-form-field--html" data-width="' . esc_attr($width) . '"' . $logic . '>' . wp_kses_post(wpautop($field['content'])) . '</div>';

            case 'hidden':
                return '';

            case 'textarea':
                $control = $label . '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="5"' . $placeholder . $aria . '>' . esc_textarea(is_scalar($value) ? (string) $value : '') . '</textarea>';
                break;

            case 'select':
                $control = $label . '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $aria . '><option value="">' . esc_html($field['placeholder'] !== '' ? $field['placeholder'] : __('Choisir…', 'smbb-wpcodetool')) . '</option>';

                foreach (FieldTypes::choices($field) as $choice) {
                    $control .= '<option' . selected((string) $value, $choice, false) . '>' . esc_html($choice) . '</option>';
                }

                $control .= '</select>';
                break;

            case 'radio':
            case 'checkbox':
                $checked = $type === 'checkbox' ? (array) $value : array((string) $value);
                $control = '<fieldset class="smbb-form-choices"' . ($describe ? ' aria-describedby="' . esc_attr(implode(' ', $describe)) . '"' : '') . '><legend class="smbb-form-label">' . esc_html($field['label']) . ($required ? '<span class="smbb-form-required" aria-hidden="true">*</span>' : '') . '</legend>';

                foreach (FieldTypes::choices($field) as $i => $choice) {
                    $control .= '<label class="smbb-form-choice"><input type="' . $type . '" name="' . esc_attr($name . ($type === 'checkbox' ? '[]' : '')) . '" value="' . esc_attr($choice) . '"'
                        . (in_array($choice, $checked, true) ? ' checked' : '') . ($type === 'radio' && $required && $i === 0 ? ' required' : '') . '> <span>' . esc_html($choice) . '</span></label>';
                }

                $control .= '</fieldset>';
                break;

            case 'rating':
                // De 5 à 1 dans le HTML (rangée inversée en CSS) : « coché ~ étoiles suivantes » colore les plus petites.
                $control = '<fieldset class="smbb-form-choices"' . ($describe ? ' aria-describedby="' . esc_attr(implode(' ', $describe)) . '"' : '') . '><legend class="smbb-form-label">' . esc_html($field['label']) . ($required ? '<span class="smbb-form-required" aria-hidden="true">*</span>' : '') . '</legend><span class="smbb-form-rating">';

                for ($i = FieldTypes::RATING_MAX; $i >= 1; $i--) {
                    $star_id = $id . '-' . $i;
                    $control .= '<input type="radio" id="' . esc_attr($star_id) . '" name="' . esc_attr($name) . '" value="' . $i . '"' . checked((string) $value, (string) $i, false) . ($required && $i === 1 ? ' required' : '') . '>'
                        . '<label for="' . esc_attr($star_id) . '" title="' . esc_attr(sprintf(_n('%d étoile', '%d étoiles', $i, 'smbb-wpcodetool'), $i)) . '"><span aria-hidden="true">★</span><span class="smbb-form-sr">' . esc_html(sprintf(_n('%d étoile', '%d étoiles', $i, 'smbb-wpcodetool'), $i)) . '</span></label>';
                }

                $control .= '</span></fieldset>';
                break;

            case 'gdpr':
                $control = '<label class="smbb-form-choice smbb-form-consent" for="' . esc_attr($id) . '"><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"' . ($value ? ' checked' : '') . $aria . '> <span>' . esc_html($field['label']) . ($required ? '<span class="smbb-form-required" aria-hidden="true">*</span>' : '') . '</span></label>';
                break;

            case 'file':
                $accept = implode(',', array_map(static function ($ext) {
                    return '.' . $ext;
                }, FieldTypes::fileTypes($field)));
                $control = $label . '<span class="smbb-form-file"><input type="file" id="' . esc_attr($id) . '" name="' . esc_attr($file_prefix . $key) . '[]" accept="' . esc_attr($accept) . '" multiple' . $aria . '>'
                    . '<span class="smbb-form-file-hint">' . esc_html(sprintf(__('Formats : %1$s · %2$d Mo max.', 'smbb-wpcodetool'), implode(', ', FieldTypes::fileTypes($field)), (int) $field['file_max'])) . '</span></span>';
                break;

            default:
                $input = array('email' => 'email', 'phone' => 'tel', 'number' => 'number', 'date' => 'date', 'url' => 'url')[$type] ?? 'text';
                $auto = array('email' => ' autocomplete="email"', 'phone' => ' autocomplete="tel"', 'url' => ' autocomplete="url"')[$type] ?? '';
                $control = $label . '<input type="' . $input . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr(is_scalar($value) ? (string) $value : '') . '"' . $placeholder . $auto . ($type === 'number' ? ' step="any" inputmode="decimal"' : '') . $aria . '>';
                break;
        }

        $html = (string) apply_filters('smbb_wpcodetool_form_field_html', $control . $help . $error_html, $field, $form, $state);

        return '<div class="smbb-form-field smbb-form-field--' . esc_attr($type) . ($error !== '' ? ' has-error' : '') . '" data-width="' . esc_attr($width) . '" data-key="' . esc_attr($key) . '"' . $logic . '>' . $html . '</div>';
    }

    /**
     * Adresse de la page affichée, sans les paramètres d'un envoi précédent.
     */
    public static function currentUrl()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/'; // phpcs:ignore

        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return get_permalink() ?: home_url('/');
        }

        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return remove_query_arg(array('smbb_form_sent', 'smbb_form_ref'), set_url_scheme('http://' . $host . $uri));
    }
}
