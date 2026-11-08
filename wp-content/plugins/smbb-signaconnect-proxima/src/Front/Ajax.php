<?php

namespace Smbb\SignConnectProxima\Front;

use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Memberships;
use Smbb\SignConnectProxima\Repository\Organizations;
use Smbb\SignConnectProxima\Repository\Transactions;
use Smbb\SignConnectProxima\Service\Billing;
use Smbb\SignConnectProxima\Service\SendService;
use Smbb\SignConnectProxima\Service\SigningService;
use Smbb\SignConnectProxima\Service\StampService;
use Smbb\SignConnectProxima\Service\TemplateService;
use Smbb\SignConnectProxima\Service\Storage;
use Smbb\SignConnectProxima\Service\UniversignFlow;
use Smbb\SignConnectProxima\Service\Usage;
use Smbb\SignConnectProxima\Support\Inbox;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\Schema;
use Smbb\SignConnectProxima\Support\UserPrefs;

defined('ABSPATH') || exit;

/**
 * Points d'entrée AJAX de l'app (connecté) et de la page de signature (public).
 */
final class Ajax
{
    const APP_NONCE = 'scp_app';

    public static function hooks()
    {
        foreach (array('upload', 'draft', 'send', 'remind', 'cancel', 'pdf', 'templatesample', 'templatepdf', 'inbox', 'tx') as $action) {
            add_action('wp_ajax_scp_app_' . $action, array(__CLASS__, 'app' . ucfirst($action)));
        }

        foreach (array('pdf', 'sign', 'refuse') as $action) {
            add_action('wp_ajax_scp_public_' . $action, array(__CLASS__, 'public' . ucfirst($action)));
            add_action('wp_ajax_nopriv_scp_public_' . $action, array(__CLASS__, 'public' . ucfirst($action)));
        }
    }

    public static function appUpload()
    {
        $user_id = self::appUser();

        self::respond(function () use ($user_id) {
            $file = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : array();
            $document = SendService::upload($user_id, isset($_POST['team_id']) ? absint($_POST['team_id']) : 0, $file);

            return self::flowPayload($user_id, $document);
        });
    }

    /** Reprise d'un brouillon (client Windows, ou lien ?scp_draft=ID) : mêmes données qu'après un dépôt. */
    public static function appDraft()
    {
        $user_id = self::appUser();

        self::respond(function () use ($user_id) {
            $document = Documents::find(isset($_POST['document_id']) ? absint($_POST['document_id']) : 0);

            if (!$document || (int) $document['sender_id'] !== (int) $user_id || !empty($document['deleted_flag'])) {
                throw new \RuntimeException(__('Document introuvable.', 'smbb-signaconnect-proxima'));
            }

            if ($document['status'] !== 'draft') {
                throw new \RuntimeException(__('Ce document a déjà été envoyé.', 'smbb-signaconnect-proxima'));
            }

            return self::flowPayload($user_id, $document);
        });
    }

    private static function flowPayload($user_id, array $document)
    {
        $organization = Organizations::find($document['organization_id']);

        return array(
            'document' => self::documentPayload($document),
            'pdfUrl' => self::appPdfUrl($document['id']),
            'templates' => SendService::templates($document['organization_id']),
            'channels' => $organization['internal_channels'],
            'hasTwilio' => Organizations::hasTwilio($organization),
            'hasStamp' => StampService::attachmentId($organization, isset($document['team_id']) ? (int) $document['team_id'] : 0) > 0,
            'defaultMessage' => (string) $organization['default_send_message'],
            'templatesOnly' => SendService::templatesOnly(isset($document['team_id']) ? (int) $document['team_id'] : 0),
            'validityDays' => (int) $organization['send_validity_days'],
            'levels' => self::levels($organization, $document),
            'hasSenderSignature' => UserPrefs::hasSignature($user_id),
            'place' => Organizations::city($organization, isset($document['team_id']) ? (int) $document['team_id'] : 0),
            'defaultLevel' => self::defaultLevel($organization),
            'authMeans' => (string) $organization['simple_auth_means'] === 'email' ? 'email' : 'sms',
        );
    }

    /** PDF d'exemple d'un modèle, importé depuis l'onglet « Modèles ». */
    public static function appTemplatesample()
    {
        $user_id = self::appUser();

        self::respond(function () use ($user_id) {
            $file = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : array();

            return TemplateService::uploadSample($user_id, isset($_POST['organization_id']) ? absint($_POST['organization_id']) : 0, $file);
        });
    }

    /** URL d'un PDF d'exemple, demandée par l'éditeur de zones quand il change. */
    public static function appTemplatepdf()
    {
        $user_id = self::appUser();

        self::respond(function () use ($user_id) {
            $url = TemplateService::sampleUrl($user_id, isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0);

            if ($url === '') {
                throw new \RuntimeException(__("Ce PDF d'exemple n'est pas accessible.", 'smbb-signaconnect-proxima'));
            }

            return array('url' => $url);
        });
    }

    /** Notifications : marquer comme lues. */
    public static function appInbox()
    {
        $user_id = self::appUser();

        self::respond(function () use ($user_id) {
            Inbox::markRead($user_id);

            return array('unread' => 0);
        });
    }

    /**
     * Page Transactions : rangement personnel (étoile, archivage, libellés) et gestion des libellés.
     */
    public static function appTx()
    {
        $user_id = self::appUser();

        self::respond(function () use ($user_id) {
            if (!Schema::ensure()) {
                throw new \RuntimeException(__('Les tables des libellés ne sont pas prêtes : synchronisez le schéma dans CodeTool.', 'smbb-signaconnect-proxima'));
            }

            $input = wp_unslash($_POST);
            $op = isset($input['op']) ? sanitize_key($input['op']) : '';
            $tag_id = isset($input['tag_id']) ? absint($input['tag_id']) : 0;
            $on = !empty($input['on']);

            switch ($op) {
                case 'tag_create':
                    $tag_id = Transactions::createTag($user_id, isset($input['name']) ? (string) $input['name'] : '', isset($input['color']) ? (string) $input['color'] : '');

                    if (!empty($input['ids'])) {
                        Transactions::setTag($user_id, self::txDocuments($user_id, $input), $tag_id, true);
                    }
                    break;
                case 'tag_update':
                    Transactions::updateTag($user_id, $tag_id, isset($input['name']) ? (string) $input['name'] : '', isset($input['color']) ? (string) $input['color'] : '');
                    break;
                case 'tag_delete':
                    Transactions::deleteTag($user_id, $tag_id);
                    break;
                case 'tag':
                    Transactions::setTag($user_id, self::txDocuments($user_id, $input), $tag_id, $on);
                    break;
                case 'archive':
                case 'star':
                    Transactions::setState($user_id, self::txDocuments($user_id, $input), $op === 'archive' ? 'archived' : 'starred', $on);
                    break;
                default:
                    throw new \InvalidArgumentException(__('Action inconnue.', 'smbb-signaconnect-proxima'));
            }

            return array('tags' => array_values(Transactions::tags($user_id)), 'tagId' => $tag_id);
        });
    }

    /**
     * Documents cochés (ids séparés par des virgules), limités à ceux que l'utilisateur peut voir.
     *
     * @return int[]
     */
    private static function txDocuments($user_id, array $input)
    {
        $ids = array_slice(array_unique(array_filter(array_map('absint', explode(',', isset($input['ids']) ? (string) $input['ids'] : '')))), 0, 200);
        $allowed = array();

        foreach ($ids as $id) {
            $document = Documents::find($id);

            if ($document && $document['status'] !== 'draft' && Memberships::canView($user_id, $document)) {
                $allowed[] = (int) $id;
            }
        }

        if (!$allowed) {
            throw new \InvalidArgumentException(__('Aucun document sélectionné.', 'smbb-signaconnect-proxima'));
        }

        return $allowed;
    }

    public static function appSend()
    {
        $user_id = self::appUser();

        self::respond(function () use ($user_id) {
            $input = wp_unslash($_POST);
            $document = SendService::send($user_id, isset($input['document_id']) ? absint($input['document_id']) : 0, $input);

            return array('document' => self::documentPayload($document));
        });
    }

    public static function appRemind()
    {
        $user_id = self::appUser();

        self::respond(function () use ($user_id) {
            return array('document' => self::documentPayload(SendService::remind($user_id, isset($_POST['document_id']) ? absint($_POST['document_id']) : 0)));
        });
    }

    public static function appCancel()
    {
        $user_id = self::appUser();

        self::respond(function () use ($user_id) {
            return array('document' => self::documentPayload(SendService::cancel($user_id, isset($_POST['document_id']) ? absint($_POST['document_id']) : 0)));
        });
    }

    /**
     * PDF pour l'expéditeur : source (aperçu) ou signé (téléchargement).
     */
    public static function appPdf()
    {
        $user_id = self::appUser();

        try {
            $document = SendService::viewable($user_id, isset($_GET['document_id']) ? absint($_GET['document_id']) : 0);
            $what = isset($_GET['signed']) ? ($_GET['signed'] === 'evidence' ? 'evidence' : 'signed') : 'source';
            self::streamPdf($document, $what, !empty($_GET['download']));
        } catch (\Throwable $exception) {
            wp_die(esc_html($exception->getMessage()), '', array('response' => 404));
        }
    }

    public static function publicPdf()
    {
        $resolved = self::publicDocument($_GET);

        if (!in_array($resolved['state'], array('pending', 'signed'), true)) {
            wp_die(esc_html__('Document indisponible.', 'smbb-signaconnect-proxima'), '', array('response' => 404));
        }

        try {
            self::streamPdf($resolved['document'], $resolved['state'] === 'signed' ? 'signed' : 'source', !empty($_GET['download']));
        } catch (\Throwable $exception) {
            wp_die(esc_html__('Document indisponible.', 'smbb-signaconnect-proxima'), '', array('response' => 404));
        }
    }

    public static function publicSign()
    {
        $resolved = self::publicDocument($_POST);

        self::respond(function () use ($resolved) {
            self::assertPending($resolved);
            $input = wp_unslash($_POST);
            SigningService::sign($resolved['document'], $resolved['organization'], array(
                'signature' => isset($input['signature']) ? $input['signature'] : '',
                'signer_name' => isset($input['signer_name']) ? $input['signer_name'] : '',
                'consent' => !empty($input['consent']),
            ));

            return array('downloadUrl' => self::publicPdfUrl($_POST, true));
        });
    }

    public static function publicRefuse()
    {
        $resolved = self::publicDocument($_POST);

        self::respond(function () use ($resolved) {
            self::assertPending($resolved);
            $input = wp_unslash($_POST);
            SigningService::refuse($resolved['document'], $resolved['organization'], array(
                'message' => isset($input['message']) ? $input['message'] : '',
                'signer_name' => isset($input['signer_name']) ? $input['signer_name'] : '',
            ));

            return array();
        });
    }

    /**
     * @param bool|string $signed false = original, true = signé, 'evidence' = attestation de preuve Universign
     */
    public static function appPdfUrl($document_id, $signed = false, $download = false)
    {
        return add_query_arg(array_filter(array(
            'action' => 'scp_app_pdf',
            'document_id' => absint($document_id),
            'signed' => $signed === 'evidence' ? 'evidence' : ($signed ? 1 : null),
            'download' => $download ? 1 : null,
            '_wpnonce' => wp_create_nonce(self::APP_NONCE),
        )), admin_url('admin-ajax.php'));
    }

    /**
     * @param array<string,mixed> $source contient scp_doc et scp_t
     */
    public static function publicPdfUrl(array $source, $download = false)
    {
        return add_query_arg(array_filter(array(
            'action' => 'scp_public_pdf',
            'scp_doc' => isset($source['scp_doc']) ? sanitize_text_field(wp_unslash($source['scp_doc'])) : '',
            'scp_t' => isset($source['scp_t']) ? sanitize_text_field(wp_unslash($source['scp_t'])) : '',
            'download' => $download ? 1 : null,
        )), admin_url('admin-ajax.php'));
    }

    /**
     * Niveaux affichés à l'envoi : tous ceux cochés sur l'organisation, avec leur phrase d'usage.
     */
    private static function levels(array $organization, array $document = array())
    {
        $available = Organizations::availableLevels($organization);
        $levels = array();
        $membership = !empty($document['membership_id']) ? Memberships::find((int) $document['membership_id']) : null;
        $gauges = $membership ? Usage::gauges($membership, Billing::currentMonth()) : array();

        foreach (Levels::all() as $level) {
            if (!in_array($level, (array) $organization['allowed_levels'], true)) {
                continue;
            }

            $levels[] = array(
                'key' => $level,
                'label' => Levels::label($level),
                'usage' => isset($organization['level_usage_' . $level]) ? (string) $organization['level_usage_' . $level] : '',
                'description' => Levels::description($level),
                'norms' => Levels::norm($level),
                'available' => in_array($level, $available, true),
                'rank' => Levels::rank($level),
                'credits' => isset($gauges[$level]) ? Usage::credits($gauges[$level]) : null,
            );
        }

        return $levels;
    }

    private static function defaultLevel(array $organization)
    {
        $available = Organizations::availableLevels($organization);
        $default = isset($organization['default_level']) ? (string) $organization['default_level'] : '';

        return in_array($default, $available, true) ? $default : (string) reset($available);
    }

    public static function documentPayload(array $document)
    {
        return array(
            'id' => (int) $document['id'],
            'filename' => (string) $document['filename'],
            'pageCount' => (int) $document['page_count'],
            'status' => (string) $document['status'],
            'organizationId' => (int) $document['organization_id'],
        );
    }

    private static function publicDocument(array $source)
    {
        return SigningService::resolve(
            isset($source['scp_doc']) ? sanitize_text_field(wp_unslash($source['scp_doc'])) : '',
            isset($source['scp_t']) ? sanitize_text_field(wp_unslash($source['scp_t'])) : ''
        );
    }

    private static function assertPending(array $resolved)
    {
        $messages = array(
            'invalid' => __('Lien de signature invalide.', 'smbb-signaconnect-proxima'),
            'blocked' => __('Ce document est temporairement indisponible.', 'smbb-signaconnect-proxima'),
            'expired' => __('Ce lien a expiré.', 'smbb-signaconnect-proxima'),
        );

        if ($resolved['state'] !== 'pending') {
            throw new \RuntimeException(isset($messages[$resolved['state']]) ? $messages[$resolved['state']] : __('Ce document a déjà été traité.', 'smbb-signaconnect-proxima'));
        }
    }

    private static function appUser()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Session expirée : reconnectez-vous.', 'smbb-signaconnect-proxima')), 401);
        }

        if (!check_ajax_referer(self::APP_NONCE, '_wpnonce', false)) {
            wp_send_json_error(array('message' => __('Session expirée : rechargez la page.', 'smbb-signaconnect-proxima')), 403);
        }

        return get_current_user_id();
    }

    private static function respond(callable $callback)
    {
        try {
            wp_send_json_success($callback());
        } catch (\InvalidArgumentException $exception) {
            wp_send_json_error(array('message' => $exception->getMessage()), 422);
        } catch (\Throwable $exception) {
            wp_send_json_error(array('message' => $exception->getMessage()), 400);
        }
    }

    /**
     * Le signé est disponible pour un document signé ; l'attestation pour un envoi Universign terminé.
     */
    public static function hasFile(array $document, $what)
    {
        $universign = !empty($document['universign_transaction_id']) && $document['signature_level'] !== Levels::INTERNAL;

        if ($what === 'signed') {
            return $document['status'] === 'signed' && (!empty($document['signed_storage_path']) || $universign);
        }

        if ($what === 'evidence') {
            return in_array($document['status'], array('signed', 'refused', 'expired', 'cancelled'), true) && (!empty($document['evidence_storage_path']) || $universign);
        }

        return !empty($document['source_storage_path']);
    }

    /**
     * @param string $what source|signed|evidence
     */
    public static function streamPdf(array $document, $what, $download)
    {
        $organization = Organizations::find($document['organization_id']);
        $columns = array('source' => 'source_storage_path', 'signed' => 'signed_storage_path', 'evidence' => 'evidence_storage_path');
        $what = isset($columns[$what]) ? $what : 'source';
        $key = $document[$columns[$what]];

        if (!$organization || !self::hasFile($document, $what)) {
            throw new \RuntimeException(__('Document indisponible.', 'smbb-signaconnect-proxima'));
        }

        // Sans copie sur S3, le PDF signé et l'attestation sont lus chez Universign.
        $path = $key ? Storage::forDocument($document, $organization)->download($key) : UniversignFlow::download($document, $what);
        $name = Documents::downloadName($document, $what);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        // filename* : le nom exact (espaces, accents) pour les navigateurs, filename : repli ASCII.
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', sanitize_file_name(remove_accents($name))) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($path);
        @unlink($path);
        exit;
    }
}
