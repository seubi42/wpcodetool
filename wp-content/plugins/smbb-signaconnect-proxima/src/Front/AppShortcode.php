<?php

namespace Smbb\SignConnectProxima\Front;

use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Memberships;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Service\Pdf;
use Smbb\SignConnectProxima\Service\TemplateService;
use Smbb\SignConnectProxima\Support\Avatar;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Inbox;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\Schema;

defined('ABSPATH') || exit;

/**
 * [signaconnect_app] : l'espace de l'utilisateur. Nouvel envoi, historique, consommation et profil ;
 * plus, selon le rôle, le périmètre des superviseurs, les utilisateurs et la proforma de l'admin d'organisation.
 */
final class AppShortcode
{
    const TAG = 'signaconnect_app';

    /** Ancien nom (avant 0.9.1), toujours reconnu dans les pages existantes. */
    const LEGACY_TAG = 'signconnect_app';

    public static function render()
    {
        if (!is_user_logged_in()) {
            $settings = get_option('smbb_scp_settings', array());

            // Lien du client Windows expiré : on revient sur le brouillon après connexion.
            $redirect = !empty($_GET['scp_draft']) ? add_query_arg('scp_draft', absint($_GET['scp_draft']), get_permalink()) : get_permalink();

            if (isset($settings['style_login']) && !(int) $settings['style_login']) {
                return wp_login_form(array('echo' => false, 'redirect' => $redirect));
            }

            wp_enqueue_style('scp-front', SMBB_SCP_URL . 'assets/front.css', array(), SMBB_SCP_VERSION);

            return Assets::wrap(
                '<div class="scp-login"><div class="scp-login-mark" aria-hidden="true"></div>'
                . '<h2>' . esc_html__('Connexion', 'smbb-signaconnect-proxima') . '</h2>'
                . '<p class="scp-login-lead">' . esc_html__('Accédez à votre espace de signature.', 'smbb-signaconnect-proxima') . '</p>'
                . wp_login_form(array('echo' => false, 'redirect' => $redirect, 'label_log_in' => __('Se connecter', 'smbb-signaconnect-proxima')))
                . '<a class="scp-login-lost" href="' . esc_url(wp_lostpassword_url(get_permalink())) . '">' . esc_html__('Mot de passe oublié ?', 'smbb-signaconnect-proxima') . '</a></div>',
                'scp-app'
            );
        }

        $user_id = get_current_user_id();
        Schema::ensure();
        $memberships = Memberships::forUser($user_id);
        $teams = ClientApi::teams($user_id);

        if (!$memberships) {
            return Assets::wrap(Assets::message(
                __('Votre compte n\'est rattaché à aucune organisation', 'smbb-signaconnect-proxima'),
                __('Demandez à votre administrateur de vous ajouter à une équipe.', 'smbb-signaconnect-proxima')
            ), 'scp-app');
        }

        $scope = Memberships::scope($user_id);
        $supervisor = Memberships::isSupervisor($scope);
        $tabs = array(
            'new' => __('Nouvel envoi', 'smbb-signaconnect-proxima'),
            'documents' => __('Transactions', 'smbb-signaconnect-proxima'),
            'usage' => __('Consommation', 'smbb-signaconnect-proxima'),
        );

        if ($scope['admin_org_ids']) {
            $tabs['members'] = __('Utilisateurs', 'smbb-signaconnect-proxima');
            $tabs['billing'] = __('Facturation', 'smbb-signaconnect-proxima');
        }

        if (TemplateService::organizations($user_id)) {
            $tabs['templates'] = __('Modèles', 'smbb-signaconnect-proxima');
        }

        if ($scope['admin_org_ids']) {
            $tabs['stamps'] = __('Tampons', 'smbb-signaconnect-proxima');
        }

        $tabs['profile'] = __('Mon profil', 'smbb-signaconnect-proxima');
        $tabs['connector'] = __('Connecteur Windows', 'smbb-signaconnect-proxima');
        $view = isset($_GET['scp_view']) ? sanitize_key(wp_unslash($_GET['scp_view'])) : 'new';
        $view = isset($tabs[$view]) ? $view : 'new';
        $base = remove_query_arg(array_merge(array('scp_view', 'status', 'my_month', 'org', 'template', 'scp_draft'), TransactionViews::PARAMS));
        $nav = self::topbar($user_id, $tabs, $view, $base);

        Assets::enqueue('app', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Ajax::APP_NONCE),
            'teams' => $teams,
            'fieldTypes' => Pdf::fieldTypeLabels(),
            'documentsUrl' => add_query_arg('scp_view', 'documents', $base),
            'resumeDraft' => $view === 'new' && !empty($_GET['scp_draft']) ? absint($_GET['scp_draft']) : 0,
            'i18n' => array(
                'uploading' => __('Envoi du PDF…', 'smbb-signaconnect-proxima'),
                'opening' => __('Ouverture du document…', 'smbb-signaconnect-proxima'),
                'sending' => __('Envoi en cours…', 'smbb-signaconnect-proxima'),
                'confirmCancel' => __('Annuler cet envoi ? Le lien du signataire ne fonctionnera plus.', 'smbb-signaconnect-proxima'),
                'reminded' => __('Relance envoyée.', 'smbb-signaconnect-proxima'),
                'error' => __('Une erreur est survenue. Réessayez.', 'smbb-signaconnect-proxima'),
                'placeHint' => __('Choisissez un type de zone, puis cliquez sur la page pour la poser ou cliquez-glissez pour la dessiner.', 'smbb-signaconnect-proxima'),
                'remove' => __('Retirer', 'smbb-signaconnect-proxima'),
                'noTemplate' => __('Aucun modèle', 'smbb-signaconnect-proxima'),
                'recognized' => __('Modèle reconnu : %s. Vérifiez les zones puis continuez.', 'smbb-signaconnect-proxima'),
                'templatesOnly' => __("Document inconnu : votre équipe n'envoie que des documents reconnus par un modèle. Contactez le gestionnaire de votre organisation pour revoir le paramétrage.", 'smbb-signaconnect-proxima'),
                'customRequired' => __('Renseignez « %s ».', 'smbb-signaconnect-proxima'),
                'recognizing' => __('Reconnaissance du document en cours, patientez un instant…', 'smbb-signaconnect-proxima'),
                'skipped' => __('Modèle « %s » reconnu : les zones sont placées.', 'smbb-signaconnect-proxima'),
                'page' => __('Page', 'smbb-signaconnect-proxima'),
                'levelEnforced' => __('Niveau minimum imposé par le modèle « %s ».', 'smbb-signaconnect-proxima'),
                'levelSuggested' => __('Niveau proposé par le modèle « %s ».', 'smbb-signaconnect-proxima'),
                'universignNote' => __("Universign enverra l'invitation au signataire par email, puis les relances.", 'smbb-signaconnect-proxima'),
                'qualifiedNote' => __("Signature qualifiée : le signataire vérifie son identité chez Universign avant de signer (pièce d'identité, quelques minutes).", 'smbb-signaconnect-proxima'),
                'captured' => __('Prérempli depuis le document', 'smbb-signaconnect-proxima'),
                'ocrBusy' => __('Ce PDF ne contient pas de texte : lecture par OCR en cours…', 'smbb-signaconnect-proxima'),
                'validity' => __('Valable', 'smbb-signaconnect-proxima'),
                'journey' => array(
                    'send' => __('Envoi du document', 'smbb-signaconnect-proxima'),
                    'link' => __('Lien par %s', 'smbb-signaconnect-proxima'),
                    'invite' => __('Invitation par %s', 'smbb-signaconnect-proxima'),
                    'emailAndSms' => __('email et SMS', 'smbb-signaconnect-proxima'),
                    'missingEmail' => __('Email manquant', 'smbb-signaconnect-proxima'),
                    'missingPhone' => __('Mobile manquant', 'smbb-signaconnect-proxima'),
                    'missingName' => __('Nom du signataire manquant', 'smbb-signaconnect-proxima'),
                    'missingBoth' => __('Email et mobile manquants', 'smbb-signaconnect-proxima'),
                    'noChannel' => __('Aucun canal choisi', 'smbb-signaconnect-proxima'),
                    'otp' => __('Code par %s', 'smbb-signaconnect-proxima'),
                    'identity' => __("Vérification d'identité", 'smbb-signaconnect-proxima'),
                    'signInternal' => __('Signature', 'smbb-signaconnect-proxima'),
                    'signVerified' => __('Signature certifiée', 'smbb-signaconnect-proxima'),
                    'signQualified' => __('Signature qualifiée', 'smbb-signaconnect-proxima'),
                    'done' => __('PDF signé', 'smbb-signaconnect-proxima'),
                    'doneProof' => __('PDF signé et preuve', 'smbb-signaconnect-proxima'),
                    'editHint' => __('Cliquez sur une étape en pointillés pour la modifier', 'smbb-signaconnect-proxima'),
                    'authBy' => __('Code envoyé par', 'smbb-signaconnect-proxima'),
                    'change' => __('Modifier', 'smbb-signaconnect-proxima'),
                ),
                'linkBy' => __('Envoyer le lien par', 'smbb-signaconnect-proxima'),
                'inviteBy' => __("Envoyer l'invitation par", 'smbb-signaconnect-proxima'),
                'emailUniversign' => __('Email (obligatoire)', 'smbb-signaconnect-proxima'),
                'levelInfo' => __('En savoir plus', 'smbb-signaconnect-proxima'),
            ),
        ));

        if ($view === 'documents') {
            wp_enqueue_script('scp-transactions', SMBB_SCP_URL . 'assets/transactions.js', array('scp-app'), SMBB_SCP_VERSION, true);
            wp_localize_script('scp-transactions', 'ScpTx', array(
                'i18n' => array(
                    'selected' => array(__('%d sélectionnée', 'smbb-signaconnect-proxima'), __('%d sélectionnées', 'smbb-signaconnect-proxima')),
                    'archived' => array(__('Transaction archivée.', 'smbb-signaconnect-proxima'), __('%d transactions archivées.', 'smbb-signaconnect-proxima')),
                    'unarchived' => array(__('Transaction remise dans la liste.', 'smbb-signaconnect-proxima'), __('%d transactions remises dans la liste.', 'smbb-signaconnect-proxima')),
                    'undo' => __('Annuler', 'smbb-signaconnect-proxima'),
                    'labelSearch' => __('Rechercher ou créer un libellé', 'smbb-signaconnect-proxima'),
                    'labelCreate' => __('Créer « %s »', 'smbb-signaconnect-proxima'),
                    'labelNone' => __('Aucun libellé pour l\'instant : tapez un nom pour en créer un.', 'smbb-signaconnect-proxima'),
                    'labelsTitle' => __('Libellés', 'smbb-signaconnect-proxima'),
                    'manageTitle' => __('Gérer mes libellés', 'smbb-signaconnect-proxima'),
                    'manageHint' => __('Vos libellés sont personnels : vos collègues ne les voient pas.', 'smbb-signaconnect-proxima'),
                    'newLabel' => __('Nouveau libellé', 'smbb-signaconnect-proxima'),
                    'labelName' => __('Nom du libellé', 'smbb-signaconnect-proxima'),
                    'create' => __('Créer', 'smbb-signaconnect-proxima'),
                    'close' => __('Fermer', 'smbb-signaconnect-proxima'),
                    'delete' => __('Supprimer', 'smbb-signaconnect-proxima'),
                    'confirmDelete' => __('Supprimer le libellé « %s » ? Les documents ne sont pas supprimés.', 'smbb-signaconnect-proxima'),
                    'saved' => __('Enregistré.', 'smbb-signaconnect-proxima'),
                    'error' => __('Une erreur est survenue. Réessayez.', 'smbb-signaconnect-proxima'),
                ),
            ));
        }

        if ($view === 'profile' || $view === 'stamps') {
            wp_enqueue_script('scp-profile', SMBB_SCP_URL . 'assets/profile.js', array(), SMBB_SCP_VERSION, true);
        }

        try {
            switch ($view) {
                case 'documents':
                    $content = TransactionViews::render($user_id, $scope);
                    break;
                case 'usage':
                    $content = AppViews::usage($user_id, $scope);
                    break;
                case 'members':
                    $content = AppViews::members($user_id, $scope);
                    break;
                case 'billing':
                    $content = AppViews::billing($user_id, $scope);
                    break;
                case 'profile':
                    $content = AppViews::profile($user_id);
                    break;
                case 'templates':
                    $content = TemplateViews::render($user_id);
                    break;
                case 'stamps':
                    $content = AppViews::stamps($user_id, $scope);
                    break;
                case 'connector':
                    $content = AppViews::connector();
                    break;
                default:
                    $content = AppViews::monthStrip($memberships) . self::newSend($teams);
            }
        } catch (\Throwable $exception) {
            $content = Assets::message(__('Écran indisponible', 'smbb-signaconnect-proxima'), $exception->getMessage(), 'warning');
        }

        return Assets::wrap($nav . AppActions::notice() . $content, 'scp-app' . ($view === 'documents' ? ' scp-app--wide' : ''));
    }

    /**
     * Cloche des notifications : pastille du nombre de non lues, liste au clic.
     */
    /**
     * Barre du haut à 2 niveaux : 3 onglets principaux, puis à droite le menu Organisation
     * (admins), la cloche et le menu du compte. Sur mobile, les onglets deviennent une barre façon iOS.
     */
    private static function topbar($user_id, array $tabs, $view, $base)
    {
        $url = function ($key) use ($base) {
            return esc_url($key === 'new' ? $base : add_query_arg('scp_view', $key, $base));
        };
        $short = array(
            'new' => __('Envoyer', 'smbb-signaconnect-proxima'),
            'documents' => __('Transactions', 'smbb-signaconnect-proxima'),
            'usage' => __('Conso', 'smbb-signaconnect-proxima'),
        );
        $primary = '';

        foreach (array('new', 'documents', 'usage') as $key) {
            $primary .= '<a class="scp-tab' . ($view === $key ? ' is-active' : '') . '" href="' . $url($key) . '"' . ($view === $key ? ' aria-current="page"' : '') . '>'
                . self::tabIcon($key) . '<span class="scp-tab-label">' . esc_html($tabs[$key]) . '</span><span class="scp-tab-short" aria-hidden="true">' . esc_html($short[$key]) . '</span></a>';
        }

        $menu_item = function ($key) use ($tabs, $view, $url) {
            return '<a class="scp-menu-item' . ($view === $key ? ' is-active' : '') . '" role="menuitem" href="' . $url($key) . '"' . ($view === $key ? ' aria-current="page"' : '') . '>' . self::tabIcon($key) . '<span>' . esc_html($tabs[$key]) . '</span></a>';
        };
        $organization = '';
        $org_keys = array_values(array_intersect(array('members', 'billing', 'templates', 'stamps'), array_keys($tabs)));

        if ($org_keys) {
            $items = '';

            foreach ($org_keys as $key) {
                $items .= $menu_item($key);
            }

            $active = in_array($view, $org_keys, true);
            $organization = '<div class="scp-menu" data-scp-menu>'
                . '<button type="button" class="scp-menu-button scp-menu-button--org' . ($active ? ' is-active' : '') . '" aria-haspopup="true" aria-expanded="false" data-scp-menu-button>'
                . '<svg class="scp-tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16"/><path d="M15 9h3a2 2 0 0 1 2 2v10M3 21h18M8 7h3M8 11h3M8 15h3"/></svg>'
                . '<span class="scp-menu-button-label">' . esc_html($active ? $tabs[$view] : __('Organisation', 'smbb-signaconnect-proxima')) . '</span>'
                . '<svg class="scp-menu-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>'
                . '<div class="scp-menu-panel" role="menu" data-scp-menu-panel hidden><div class="scp-menu-head">' . esc_html__('Organisation', 'smbb-signaconnect-proxima') . '</div>' . $items . '</div></div>';
        }

        $user = get_userdata($user_id);
        $name = $user ? ($user->display_name !== '' ? $user->display_name : $user->user_login) : '';
        $account = '<div class="scp-menu scp-menu--account" data-scp-menu>'
            . '<button type="button" class="scp-avatar' . ($view === 'profile' ? ' is-active' : '') . '" aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr__('Mon compte', 'smbb-signaconnect-proxima') . '" data-scp-menu-button>' . Avatar::html($user_id, $name, 'scp-face--fill') . '</button>'
            . '<div class="scp-menu-panel" role="menu" data-scp-menu-panel hidden>'
            . '<a class="scp-menu-user" href="' . $url('profile') . '">' . Avatar::html($user_id, $name, 'scp-face--lg') . '<span><strong>' . esc_html($name) . '</strong>' . ($user ? '<small>' . esc_html($user->user_email) . '</small>' : '') . '</span></a>'
            . $menu_item('profile')
            . $menu_item('connector')
            . '<a class="scp-menu-item" role="menuitem" href="' . esc_url(wp_logout_url($base)) . '"><svg class="scp-tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg><span>' . esc_html__('Se déconnecter', 'smbb-signaconnect-proxima') . '</span></a>'
            . '</div></div>';

        return '<div class="scp-topbar">'
            . '<nav class="scp-tabs" aria-label="SignaConnect">' . $primary . '</nav>'
            . '<div class="scp-topbar-side">' . $organization . self::bell($user_id, add_query_arg('scp_view', 'documents', $base)) . $account . '</div>'
            . '</div>';
    }

    /**
     * Petites icônes monochromes des onglets (trait, couleur du texte).
     */
    private static function tabIcon($key)
    {
        $paths = array(
            'new' => '<path d="M21 3 10 14"/><path d="M21 3 14.5 21l-4.5-7-7-4.5z"/>',
            'documents' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
            'usage' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
            'members' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
            'billing' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
            'templates' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
            'stamps' => '<path d="M9.5 3h5l-1 8h-3z"/><path d="M4 15a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3H4z"/><path d="M5 21h14"/>',
            'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'connector' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/><path d="M12 7.5v5.5M9.5 10.5 12 13l2.5-2.5"/>',
        );

        return isset($paths[$key]) ? '<svg class="scp-tab-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[$key] . '</svg>' : '';
    }

    private static function bell($user_id, $documents_url)
    {
        $items = Inbox::items($user_id);
        $unread = Inbox::unread($user_id);
        $list = '';

        foreach (array_slice($items, 0, 12) as $item) {
            $time = strtotime((string) $item['time']);
            $ago = $time ? sprintf(__('il y a %s', 'smbb-signaconnect-proxima'), human_time_diff($time, current_time('timestamp'))) : '';
            $list .= '<li class="scp-bell-item scp-bell-item--' . esc_attr($item['event']) . (empty($item['read']) ? ' is-unread' : '') . '">'
                . '<a href="' . esc_url($documents_url) . '"><i aria-hidden="true"></i><span>' . esc_html($item['text']) . '<small>' . esc_html($ago) . '</small></span></a></li>';
        }

        return '<div class="scp-bell" data-scp-bell>'
            . '<button type="button" class="scp-bell-button" aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr__('Notifications', 'smbb-signaconnect-proxima') . '" data-scp-bell-button>'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6.5 2 6.5H4S6 14 6 9"/><path d="M10 19.5a2 2 0 0 0 4 0"/></svg>'
            . ($unread ? '<b class="scp-bell-count" data-scp-bell-count>' . ($unread > 9 ? '9+' : (int) $unread) . '</b>' : '')
            . '</button>'
            . '<div class="scp-bell-panel" data-scp-bell-panel hidden><div class="scp-bell-head">' . esc_html__('Notifications', 'smbb-signaconnect-proxima') . '</div>'
            . ($list !== '' ? '<ul>' . $list . '</ul>' : '<p class="scp-bell-empty">' . esc_html__('Rien de nouveau. Vous serez prévenu ici dès qu\'un document est signé, refusé ou expiré.', 'smbb-signaconnect-proxima') . '</p>')
            . '</div></div>';
    }

    private static function newSend(array $teams)
    {
        if (!$teams) {
            return Assets::message(
                __('Aucun envoi possible pour le moment', 'smbb-signaconnect-proxima'),
                __("Vos organisations n'acceptent pas de nouvel envoi pour le moment. Contactez votre administrateur.", 'smbb-signaconnect-proxima'),
                'warning'
            );
        }

        $options = '';

        foreach ($teams as $team) {
            $options .= '<option value="' . esc_attr($team['id']) . '">' . esc_html($team['label']) . '</option>';
        }

        $html = '<div class="scp-flow" data-scp-flow>';

        // Étape 1 : équipe + PDF.
        $html .= '<section class="scp-card scp-step" data-scp-step="upload">';
        $html .= '<h2>' . esc_html__('Quel document faire signer ?', 'smbb-signaconnect-proxima') . '</h2>';
        $html .= '<label class="scp-field' . (count($teams) === 1 ? ' is-hidden' : '') . '"><span>' . esc_html__('Pour le compte de', 'smbb-signaconnect-proxima') . '</span><select data-scp-team>' . $options . '</select></label>';
        $html .= '<label class="scp-drop" data-scp-drop><input type="file" accept="application/pdf,.pdf" data-scp-file hidden>'
            . '<span class="scp-drop-icon" aria-hidden="true"></span>'
            . '<strong>' . esc_html__('Déposez un PDF ici', 'smbb-signaconnect-proxima') . '</strong>'
            . '<span>' . esc_html__('ou cliquez pour le choisir (20 Mo max.)', 'smbb-signaconnect-proxima') . '</span></label>';
        $html .= '<div class="scp-alert" data-scp-message hidden></div>';
        $html .= '</section>';

        // Étape 2 : zones.
        $html .= '<section class="scp-card scp-step" data-scp-step="zones" hidden>';
        $html .= '<div class="scp-step-head"><h2>' . esc_html__('Où signer ?', 'smbb-signaconnect-proxima') . '</h2><span class="scp-muted" data-scp-filename></span></div>';
        $html .= '<div class="scp-toolbar" data-scp-toolbar>';
        $html .= '<div class="scp-segmented" data-scp-types></div>';
        $html .= '<label class="scp-inline"><span>' . esc_html__('Modèle', 'smbb-signaconnect-proxima') . '</span><select data-scp-template></select></label>';
        $html .= '</div>';
        $html .= '<p class="scp-hint" data-scp-hint></p>';
        $html .= '<div class="scp-viewer" data-scp-viewer></div>';
        $html .= '<div class="scp-actions"><button type="button" class="scp-button" data-scp-next>' . esc_html__('Continuer', 'smbb-signaconnect-proxima') . '</button></div>';
        $html .= '</section>';

        // Étape 3 : signataire.
        $html .= '<section class="scp-card scp-step" data-scp-step="recipient" hidden>';
        $html .= '<h2>' . esc_html__('Qui doit signer ?', 'smbb-signaconnect-proxima') . '</h2>';
        $html .= '<p class="scp-skipped" data-scp-skipped hidden><span></span> <button type="button" class="scp-link">' . esc_html__('Voir le document', 'smbb-signaconnect-proxima') . '</button></p>';
        $html .= '<form data-scp-send-form novalidate>';
        $html .= '<div class="scp-grid">';
        $html .= '<label class="scp-field scp-field--wide"><span>' . esc_html__('Nom complet', 'smbb-signaconnect-proxima') . '</span><input name="recipient_name" autocomplete="off" required placeholder="' . esc_attr__('Ex. Marie Dupont', 'smbb-signaconnect-proxima') . '"></label>';
        $html .= '<label class="scp-field"><span>' . esc_html__('Email', 'smbb-signaconnect-proxima') . '</span><input type="email" name="recipient_email" autocomplete="off"></label>';
        $html .= '<label class="scp-field"><span>' . esc_html__('Mobile', 'smbb-signaconnect-proxima') . '</span><input type="tel" name="recipient_phone" autocomplete="off" placeholder="06 12 34 56 78"></label>';
        $html .= '</div>';
        // Champs perso du modèle (N° de commande…), remplis par app.js selon le modèle reconnu.
        $html .= '<div class="scp-grid scp-custom-fields" data-scp-custom hidden></div>';
        $html .= '<div class="scp-field"><span>' . esc_html__('Niveau de signature', 'smbb-signaconnect-proxima') . '</span><div class="scp-levels" data-scp-levels role="radiogroup"></div><p class="scp-hint" data-scp-level-hint></p></div>';
        // Canaux et moyen du code : choisis depuis la frise (fenêtre au clic sur l'étape).
        $html .= '<div class="scp-journey" data-scp-journey hidden><div class="scp-journey-head"><span>' . esc_html__('Parcours du signataire', 'smbb-signaconnect-proxima') . '</span><small data-scp-journey-hint></small></div>'
            . '<ol class="scp-journey-track" data-scp-journey-track></ol>'
            . '<div class="scp-journey-pop" data-scp-journey-pop role="dialog" hidden><div class="scp-journey-pop-title" data-scp-pop-title></div>'
            . '<div class="scp-chips" data-scp-channels></div><div class="scp-chips" data-scp-auth></div>'
            . '<button type="button" class="scp-button scp-journey-pop-ok" data-scp-pop-close>' . esc_html__('OK', 'smbb-signaconnect-proxima') . '</button></div></div>';
        $html .= '<label class="scp-field"><span>' . esc_html__('Message (optionnel)', 'smbb-signaconnect-proxima') . '</span><textarea name="send_message" rows="3"></textarea></label>';
        $html .= '<div class="scp-level"><span class="scp-muted" data-scp-validity></span></div>';
        $html .= '<div class="scp-alert" data-scp-message hidden></div>';
        $html .= '<div class="scp-actions"><button type="button" class="scp-button scp-button--ghost" data-scp-back>' . esc_html__('Retour', 'smbb-signaconnect-proxima') . '</button>'
            . '<button type="submit" class="scp-button">' . esc_html__('Envoyer pour signature', 'smbb-signaconnect-proxima') . '</button></div>';
        $html .= '</form></section>';

        // Fin.
        $html .= '<section class="scp-card scp-step" data-scp-step="done" hidden>';
        $html .= Assets::message(__('C\'est parti !', 'smbb-signaconnect-proxima'), __('Le signataire a reçu son invitation. Vous serez prévenu dès qu\'il aura signé.', 'smbb-signaconnect-proxima'), 'success');
        $html .= '<div class="scp-actions scp-actions--center"><a class="scp-button scp-button--ghost" href="' . esc_url(remove_query_arg('scp_view')) . '">' . esc_html__('Nouvel envoi', 'smbb-signaconnect-proxima') . '</a>'
            . '<a class="scp-button" href="' . esc_url(add_query_arg('scp_view', 'documents')) . '">' . esc_html__('Voir mes transactions', 'smbb-signaconnect-proxima') . '</a></div>';
        $html .= '</section>';

        return $html . '</div>';
    }
}
