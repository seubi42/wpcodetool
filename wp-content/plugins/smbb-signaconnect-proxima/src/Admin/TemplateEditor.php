<?php

namespace Smbb\SignConnectProxima\Admin;

use Smbb\SignConnectProxima\Service\Pdf;

defined('ABSPATH') || exit;

/**
 * Éditeur visuel des zones d'un modèle : le PDF d'exemple est affiché, on y place
 * les champs à signer et les zones de reconnaissance. Le résultat remplit les champs
 * JSON du formulaire, enregistrés normalement par CodeTool.
 */
final class TemplateEditor
{
    const AJAX_ACTION = 'scp_template_sample';

    public static function hooks()
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, array(__CLASS__, 'ajaxSample'));
    }

    /**
     * URL d'un PDF de la médiathèque, quand on change le PDF d'exemple sans enregistrer.
     */
    public static function ajaxSample()
    {
        if (!current_user_can('manage_options') || !check_ajax_referer(self::AJAX_ACTION, '_wpnonce', false)) {
            wp_send_json_error(array('message' => __('Action non autorisée.', 'smbb-signaconnect-proxima')), 403);
        }

        $url = self::pdfUrl(isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0);

        if ($url === '') {
            wp_send_json_error(array('message' => __("Ce fichier n'est pas un PDF de la médiathèque.", 'smbb-signaconnect-proxima')));
        }

        wp_send_json_success(array('url' => $url));
    }

    public static function pdfUrl($attachment_id)
    {
        $attachment_id = (int) $attachment_id;

        if ($attachment_id <= 0 || get_post_mime_type($attachment_id) !== 'application/pdf') {
            return '';
        }

        $url = wp_get_attachment_url($attachment_id);

        return $url ? (string) $url : '';
    }

    /**
     * HTML de l'éditeur, à placer dans une section du formulaire.
     */
    public static function html(array $item, array $options = array())
    {
        self::enqueue();
        $d = 'smbb-signaconnect-proxima';
        $pdf_url = self::pdfUrl(isset($item['sample_pdf_attachment_id']) ? $item['sample_pdf_attachment_id'] : 0);
        $config = array(
            'pdfUrl' => $pdf_url,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::AJAX_ACTION),
            'action' => self::AJAX_ACTION,
            'fieldTypes' => Pdf::fieldTypeLabels(),
            'i18n' => array(
                'page' => __('Page', $d),
                'detect' => __('Reconnaissance', $d),
                'remove' => __('Supprimer', $d),
                'noText' => __('aucun texte trouvé', $d),
                'reading' => __('lecture en cours…', $d),
                'ocr' => __('lu par OCR', $d),
                'capture' => __('Destinataire', $d),
                'captureTitle' => __('Cette zone préremplit, à l\'envoi :', $d),
                'captureOk' => __('valeur trouvée sur l\'exemple', $d),
                'captureKo' => __('rien trouvé sur l\'exemple', $d),
                'targetName' => __('Nom complet', $d),
                'targetEmail' => __('Email', $d),
                'targetPhone' => __('Mobile', $d),
                'ruleTitle' => __('Règle de reconnaissance', $d),
                'ruleText' => __('Contient le texte', $d),
                'ruleNumber' => __('Montant', $d),
                'textLabel' => __('Texte attendu', $d),
                'valueLabel' => __('Valeur', $d),
                'read' => __('Lu sur le PDF d\'exemple :', $d),
                'amount' => __('montant lu', $d),
                'ok' => __('règle vérifiée sur l\'exemple', $d),
                'ko' => __('règle non vérifiée sur l\'exemple', $d),
                'reread' => __('Reprendre le texte lu', $d),
                'error' => __("Impossible d'afficher le PDF d'exemple.", $d),
                'noPdf' => __("Choisissez un PDF d'exemple ci-dessus pour placer les zones sur le document.", $d),
                'anchorTitle' => __('Page de cette zone dans le document envoyé', $d),
                'anchorHelp' => __('La zone est posée sur la page %1$d du PDF d\'exemple, qui en compte %2$d. Comment retrouver cette page dans les documents envoyés ?', $d),
                'anchorStart' => __('Page %d (en partant du début)', $d),
                'anchorStartHelp' => __('Toujours la même page, quelle que soit la longueur du document.', $d),
                'anchorEnd' => __('Page %d (en partant de la fin)', $d),
                'anchorLastHelp' => __('Toujours la dernière page, même si le document a plus ou moins de pages.', $d),
                'anchorEndHelp' => __('Toujours la %de page en partant de la fin, même si le document a plus ou moins de pages.', $d),
                'anchorShort' => __('Si le document envoyé est trop court pour cette page, le champ est mis sur la page la plus proche et l\'expéditeur peut le déplacer avant l\'envoi.', $d),
                'anchorShortRead' => __('Si le document envoyé est trop court pour cette page, cette zone n\'est pas lue (règle non vérifiée, rien de prérempli).', $d),
                'close' => __('Fermer', $d),
                'missing' => __('Ces zones sont hors du PDF d\'exemple (page inexistante) et seront conservées telles quelles : %d.', $d),
            ),
        );
        // Depuis l'app : URL du PDF et point d'accès AJAX propres à l'utilisateur connecté.
        $config = array_merge($config, $options);

        return '<div class="scp-tpl" data-scp-template-editor data-config="' . esc_attr(wp_json_encode($config)) . '">'
            . '<div class="scp-tpl-toolbar">'
            . '<div class="scp-tpl-segments" data-scp-tpl-types role="group" aria-label="' . esc_attr__('Type de zone à placer', $d) . '"></div>'
            . '</div>'
            . '<p class="scp-tpl-hint" data-scp-tpl-hint>'
            . esc_html__('Choisissez un type, puis cliquez sur la page pour poser une zone ou cliquez-glissez pour la dessiner. La signature garde les proportions demandées par Universign (4 × plus large que haute). Cliquez une zone de reconnaissance pour régler sa règle (texte contenu ou condition sur un montant). Une zone Destinataire lit le nom, l\'email ou le mobile du signataire, ou un champ perso du modèle (N° de commande…), pour préremplir l\'envoi. L\'étiquette P8 / P-3 sous chaque zone indique sa page : cliquez-la pour compter la page depuis le début ou depuis la fin du document envoyé.', $d)
            . '</p>'
            . '<p class="scp-tpl-message" data-scp-tpl-message hidden></p>'
            . '<div class="scp-tpl-rule" data-scp-tpl-rule hidden></div>'
            . '<div class="scp-tpl-viewer" data-scp-tpl-viewer></div>'
            . '</div>';
    }

    private static function enqueue()
    {
        wp_enqueue_script('scp-viewer', SMBB_SCP_URL . 'assets/viewer.js', array(), SMBB_SCP_VERSION, true);
        wp_enqueue_script('scp-zones', SMBB_SCP_URL . 'assets/zones.js', array('scp-viewer'), SMBB_SCP_VERSION, true);
        wp_enqueue_script('scp-template-editor', SMBB_SCP_URL . 'assets/template-editor.js', array('scp-viewer', 'scp-zones'), SMBB_SCP_VERSION, true);
    }
}
