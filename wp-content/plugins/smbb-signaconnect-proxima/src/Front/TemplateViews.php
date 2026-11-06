<?php

namespace Smbb\SignConnectProxima\Front;

use Smbb\SignConnectProxima\Admin\TemplateEditor;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Repository\Templates;
use Smbb\SignConnectProxima\Service\TemplateService;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\Placeholders;

defined('ABSPATH') || exit;

/**
 * Onglet « Modèles » de l'app : liste et édition des modèles de détection,
 * pour les admins d'organisation et les utilisateurs qui ont le droit « Modèles ».
 */
final class TemplateViews
{
    const D = 'smbb-signaconnect-proxima';

    public static function render($user_id)
    {
        $organizations = TemplateService::organizations($user_id);
        $wanted = isset($_GET['template']) ? sanitize_key(wp_unslash($_GET['template'])) : '';

        if ($wanted === 'new' || ctype_digit($wanted)) {
            return self::edit($user_id, $organizations, $wanted === 'new' ? 0 : (int) $wanted);
        }

        return self::index($organizations);
    }

    private static function organizationId(array $organizations)
    {
        $wanted = isset($_GET['org']) ? absint($_GET['org']) : 0;

        return isset($organizations[$wanted]) ? $wanted : (int) key($organizations);
    }

    private static function url(array $args)
    {
        return add_query_arg(array_merge(array('scp_view' => 'templates'), $args), remove_query_arg(array('template', 'org', 'scp_view')));
    }

    private static function index(array $organizations)
    {
        $organization_id = self::organizationId($organizations);
        $rows = '';

        foreach (TemplateService::listFor($organization_id) as $template) {
            $fields = json_decode((string) $template['field_zones'], true);
            $detect = json_decode((string) $template['detection_zones'], true);
            $meta = array(
                sprintf(_n('%d zone', '%d zones', is_array($fields) ? count($fields) : 0, self::D), is_array($fields) ? count($fields) : 0),
                is_array($detect) && $detect ? sprintf(_n('%d règle de reconnaissance', '%d règles de reconnaissance', count($detect), self::D), count($detect)) : __('Pas de reconnaissance automatique', self::D),
            );

            if ((int) $template['match_count'] > 0) {
                $meta[] = sprintf(_n('utilisé %d fois', 'utilisé %d fois', (int) $template['match_count'], self::D), (int) $template['match_count']);
            }

            $rows .= '<a class="scp-tpl-row' . ((int) $template['is_active'] ? '' : ' is-inactive') . '" href="' . esc_url(self::url(array('template' => (int) $template['id'], 'org' => $organization_id))) . '">'
                . '<span class="scp-tpl-row-main"><strong>' . esc_html($template['name']) . '</strong><small>' . esc_html(implode(' · ', $meta)) . '</small></span>'
                . '<span class="scp-tpl-row-tags"><span class="scp-badge scp-badge--level">' . esc_html(Levels::label($template['suggested_level'])) . ((int) $template['level_enforced'] ? ' · ' . esc_html__('imposé', self::D) : '') . '</span>'
                . ((int) $template['is_active'] ? '' : '<span class="scp-badge scp-badge--cancelled">' . esc_html__('Inactif', self::D) . '</span>') . '</span>'
                . '<span class="scp-tpl-row-chevron" aria-hidden="true"></span></a>';
        }

        $switch = '';

        if (count($organizations) > 1) {
            $switch = '<form class="scp-filters" method="get">' . AppViews::keepQuery(array('scp_view' => 'templates')) . AppViews::select('org', array_map('strval', $organizations), (string) $organization_id) . '</form>';
        }

        return '<section class="scp-card"><div class="scp-step-head"><div><h2>' . esc_html__('Modèles de documents', self::D) . '</h2>'
            . '<span class="scp-muted">' . esc_html__('Reconnus à l\'envoi : les zones se placent toutes seules et le niveau de signature est proposé.', self::D) . '</span></div>'
            . '<a class="scp-button" href="' . esc_url(self::url(array('template' => 'new', 'org' => $organization_id))) . '">' . esc_html__('Nouveau modèle', self::D) . '</a></div>'
            . $switch
            . ($rows !== '' ? '<div class="scp-tpl-list">' . $rows . '</div>' : '<p class="scp-empty">' . esc_html__('Aucun modèle pour le moment. Créez-en un à partir d\'un PDF type : devis, contrat, bon de commande…', self::D) . '</p>')
            . '</section>';
    }

    private static function edit($user_id, array $organizations, $template_id)
    {
        $template = $template_id ? TemplateService::find($user_id, $template_id) : array(
            'id' => 0,
            'organization_id' => self::organizationId($organizations),
            'name' => '',
            'description' => '',
            'suggested_level' => '',
            'level_enforced' => 0,
            'is_active' => 1,
            'sample_pdf_attachment_id' => 0,
            'field_zones' => '[]',
            'detection_zones' => '[]',
        );
        $settings = Templates::settings($template);
        $organization_id = (int) $template['organization_id'];
        $organization = Organizations::find($organization_id);
        $levels = $organization ? Organizations::availableLevels($organization) : Levels::all();
        $level_options = '';

        foreach ($levels as $level) {
            $level_options .= '<option value="' . esc_attr($level) . '"' . selected($template['suggested_level'], $level, false) . '>' . esc_html(Levels::label($level)) . '</option>';
        }

        $sample_id = (int) $template['sample_pdf_attachment_id'];
        $sample_url = $sample_id ? TemplateService::sampleUrl($user_id, $sample_id) : '';
        $sample_name = $sample_url !== '' ? wp_basename((string) get_attached_file($sample_id)) : '';
        $back = self::url(array('org' => $organization_id));
        $editor = TemplateEditor::html($template, array(
            'pdfUrl' => $sample_url,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Ajax::APP_NONCE),
            'action' => 'scp_app_templatepdf',
        ));
        wp_enqueue_script('scp-app-templates', SMBB_SCP_URL . 'assets/app-templates.js', array('scp-viewer'), SMBB_SCP_VERSION, true);

        $html = '<form method="post" action="' . AppActions::formAction() . '" class="scp-tpl-form" data-scp-tpl-form'
            . ' data-ajax="' . esc_attr(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce(Ajax::APP_NONCE)) . '" data-confirm="' . esc_attr__('Supprimer ce modèle ? Les documents déjà envoyés ne changent pas.', self::D) . '">'
            . AppActions::fields(AppActions::TEMPLATE, 'templates')
            . '<input type="hidden" name="template_id" value="' . (int) $template['id'] . '">'
            . '<input type="hidden" name="organization_id" value="' . $organization_id . '">'
            . '<input type="hidden" name="sample_pdf_attachment_id" value="' . ($sample_url !== '' ? $sample_id : '') . '">'
            . '<textarea name="field_zones" hidden>' . esc_textarea((string) $template['field_zones']) . '</textarea>'
            . '<textarea name="detection_zones" hidden>' . esc_textarea((string) $template['detection_zones']) . '</textarea>'

            . '<section class="scp-card"><div class="scp-step-head"><div><h2>' . esc_html($template['id'] ? $template['name'] : __('Nouveau modèle', self::D)) . '</h2>'
            . (count($organizations) > 1 ? '<span class="scp-muted">' . esc_html($organizations[$organization_id]) . '</span>' : '') . '</div>'
            . '<a class="scp-link" href="' . esc_url($back) . '">' . esc_html__('← Tous les modèles', self::D) . '</a></div>'
            . '<div class="scp-grid">'
            . '<label class="scp-field scp-field--wide"><span>' . esc_html__('Nom', self::D) . '</span><input name="name" required maxlength="190" value="' . esc_attr($template['name']) . '" placeholder="' . esc_attr__('Ex. Devis travaux', self::D) . '"></label>'
            . '<label class="scp-field scp-field--wide"><span>' . esc_html__('Description (optionnelle)', self::D) . '</span><input name="description" value="' . esc_attr($template['description']) . '"></label>'
            . '<label class="scp-field"><span>' . esc_html__('Niveau de signature proposé', self::D) . '</span><select name="suggested_level">' . $level_options . '</select></label>'
            . '<div class="scp-field"><span>' . esc_html__('Options', self::D) . '</span>'
            . '<label class="scp-check"><input type="checkbox" name="level_enforced" value="1"' . checked((int) $template['level_enforced'], 1, false) . '><span>' . esc_html__('Niveau minimum imposé', self::D) . '<small>' . esc_html__('Impossible de choisir un niveau plus faible à l\'envoi.', self::D) . '</small></span></label>'
            . '<label class="scp-check"><input type="checkbox" name="is_active" value="1"' . checked((int) $template['is_active'], 1, false) . '><span>' . esc_html__('Actif', self::D) . '<small>' . esc_html__('Un modèle inactif n\'est plus proposé à l\'envoi.', self::D) . '</small></span></label>'
            . '</div></div></section>'

            . self::sendingCard($settings)

            . '<section class="scp-card"><div class="scp-step-head"><div><h2>' . esc_html__('Zones', self::D) . '</h2>'
            . '<span class="scp-muted">' . esc_html__('Importez un PDF type, puis placez les champs à remplir et les zones qui permettent de le reconnaître.', self::D) . '</span></div>'
            . '<label class="scp-button scp-button--ghost scp-tpl-upload"><input type="file" accept="application/pdf,.pdf" data-scp-tpl-file hidden>'
            . '<span data-scp-tpl-file-label data-change="' . esc_attr__('Changer le PDF', self::D) . '">' . esc_html($sample_name !== '' ? __('Changer le PDF', self::D) : __('Importer un PDF d\'exemple', self::D)) . '</span></label></div>'
            . '<p class="scp-muted scp-tpl-file" data-scp-tpl-file-name>' . esc_html($sample_name) . '</p>'
            . $editor
            . '</section>'

            . '<div class="scp-actions">'
            . ($template['id'] ? '<button type="submit" name="delete" value="1" class="scp-button scp-button--ghost scp-tpl-delete" data-scp-tpl-delete formnovalidate>' . esc_html__('Supprimer', self::D) . '</button>' : '')
            . '<a class="scp-button scp-button--ghost" href="' . esc_url($back) . '">' . esc_html__('Annuler', self::D) . '</a>'
            . '<button type="submit" class="scp-button">' . esc_html($template['id'] ? __('Enregistrer', self::D) : __('Créer le modèle', self::D)) . '</button></div>'
            . '</form>';

        return $html;
    }

    /**
     * Champs perso (N° de commande…) et stratégie de nommage. La liste est éditée en JavaScript
     * (app-templates.js) et enregistrée en JSON dans custom_fields.
     */
    private static function customFieldsEditor(array $settings)
    {
        $json = wp_json_encode($settings['custom_fields'], JSON_UNESCAPED_UNICODE);

        return '<div class="scp-tpl-custom" data-scp-tpl-custom data-max="' . (int) Templates::MAX_CUSTOM_FIELDS . '"'
            . ' data-label-placeholder="' . esc_attr__('Ex. N° de commande', self::D) . '" data-required="' . esc_attr__('Obligatoire', self::D) . '" data-remove="' . esc_attr__('Retirer', self::D) . '">'
            . '<div class="scp-field"><span>' . esc_html__('Champs à saisir à l\'envoi', self::D) . '</span>'
            . '<small class="scp-hint">' . esc_html__('Ajoutés sous le nom, l\'email et le mobile du signataire. Ils classent la transaction (visibles et cherchables dans Transactions) et ne sont pas écrits sur le PDF. Une zone Destinataire peut aussi les lire sur le document.', self::D) . '</small></div>'
            . '<textarea name="custom_fields" hidden>' . esc_textarea($json) . '</textarea>'
            . '<div class="scp-tpl-custom-list" data-scp-tpl-custom-list></div>'
            . '<button type="button" class="scp-link" data-scp-tpl-custom-add>' . esc_html__('+ Ajouter un champ', self::D) . '</button>'
            . '</div>'
            . '<label class="scp-field scp-tpl-naming"><span>' . esc_html__('Nom du PDF (stratégie de nommage)', self::D) . '</span>'
            . '<input name="naming_pattern" maxlength="255" value="' . esc_attr($settings['naming_pattern']) . '" placeholder="' . esc_attr__('Ex. Devis - [nom] - [N° de commande]', self::D) . '">'
            . '<small class="scp-hint">' . esc_html__('Vide : le nom du fichier déposé est gardé. Sinon le PDF est renommé avant l\'envoi (Universign compris) et le PDF signé garde ce nom. Variables :', self::D)
            . ' <code>' . implode('</code> <code>', array_map('esc_html', Placeholders::help())) . '</code> <span data-scp-tpl-custom-vars></span></small></label>';
    }

    /**
     * Réglages d'envoi : message prérempli, aperçu sauté, email du document signé à l'expéditeur.
     */
    private static function sendingCard(array $settings)
    {
        $variables = '<code>' . implode('</code> <code>', array_map('esc_html', Placeholders::help())) . '</code>';

        return '<section class="scp-card"><div class="scp-step-head"><div><h2>' . esc_html__('À l\'envoi', self::D) . '</h2>'
            . '<span class="scp-muted">' . esc_html__('Ce que l\'expéditeur trouve tout prêt quand ce modèle est reconnu ou choisi.', self::D) . '</span></div></div>'
            . self::customFieldsEditor($settings)
            . '<label class="scp-field"><span>' . esc_html__('Message par défaut au signataire', self::D) . '</span><textarea name="default_message" rows="3" placeholder="' . esc_attr__('Vide : message par défaut de l\'organisation.', self::D) . '">' . esc_textarea($settings['default_message']) . '</textarea></label>'
            . '<label class="scp-check"><input type="checkbox" name="skip_preview" value="1"' . checked($settings['skip_preview'], true, false) . '><span>' . esc_html__('Passer l\'aperçu quand ce modèle est reconnu', self::D) . '<small>' . esc_html__('L\'expéditeur arrive directement sur « Qui doit signer ? », les zones déjà placées. Il peut revenir au document d\'un clic.', self::D) . '</small></span></label>'
            . '<div class="scp-tpl-mail" data-scp-tpl-mail>'
            . '<label class="scp-check"><input type="checkbox" name="signed_mail_enabled" value="1" data-scp-tpl-mail-toggle' . checked($settings['signed_mail_enabled'], true, false) . '><span>' . esc_html__('Envoyer le document signé à l\'expéditeur par email', self::D) . '<small>' . esc_html__('Avec le sujet et le message ci-dessous, même si l\'expéditeur a coupé la notification « signé » dans son profil. Le PDF signé est joint.', self::D) . '</small></span></label>'
            . '<div class="scp-tpl-mail-fields" data-scp-tpl-mail-fields' . ($settings['signed_mail_enabled'] ? '' : ' hidden') . '>'
            . '<label class="scp-field"><span>' . esc_html__('Sujet', self::D) . '</span><input name="signed_mail_subject" maxlength="255" value="' . esc_attr($settings['signed_mail_subject']) . '" placeholder="' . esc_attr__('Ex. Signé : [document] – [nom]', self::D) . '"></label>'
            . '<label class="scp-field"><span>' . esc_html__('Message', self::D) . '</span><textarea name="signed_mail_body" rows="4" placeholder="' . esc_attr__('Ex. Bonjour [expéditeur], [nom] a signé le document [document] le [date].', self::D) . '">' . esc_textarea($settings['signed_mail_body']) . '</textarea></label>'
            . '<label class="scp-field"><span>' . esc_html__('En copie (optionnel)', self::D) . '</span><input name="signed_mail_cc" value="' . esc_attr(implode(', ', $settings['signed_mail_cc'])) . '" placeholder="' . esc_attr__('compta@exemple.fr, direction@exemple.fr', self::D) . '"></label>'
            . '<p class="scp-hint">' . esc_html__('Variables utilisables dans le sujet et le message :', self::D) . ' ' . $variables . ' <span data-scp-tpl-custom-vars></span></p>'
            . '</div></div></section>';
    }
}
