<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Repository\DocumentRepository;
use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

/**
 * Genere une proposition courte de message d\'envoi avec OpenAI.
 *
 * Le service reste strictement optionnel : si l\'IA est désactivée ou mal
 * configurée, le parcours classique continue sans suggestion\'automatique.
 */
final class DocumentSendMessageSuggestionService
{
    private $documents;

    public function __construct(DocumentRepository $documents = null)
    {
        $this->documents = $documents ?: new DocumentRepository();
    }

    public function suggest($document_id, $user_id)
    {
        if (!SignConnectSettings::openAiConfigured()) {
            return $this->error(__('AI is disabled or not configured.', 'smbb-signconnect'), 400);
        }

        $document = $this->documents->findOwnedByUser($document_id, $user_id);

        if (!$document) {
            return $this->error(__('Document not found or inaccessible.', 'smbb-signconnect'), 404);
        }

        if (!class_exists('\Smbb\WpCodeTool\Connector\SmbbOpenAiTextClient')) {
            return $this->error(__('The SMBB WP CodeTool OpenAI connector is unavailable.', 'smbb-signconnect'), 500);
        }

        $filename = isset($document['filename']) ? (string) $document['filename'] : __('document', 'smbb-signconnect');
        $prompt = $this->prompt($filename);

        try {
            $client = \Smbb\WpCodeTool\Connector\SmbbOpenAiTextClient::fromSettings(SignConnectSettings::all());
            $message = $client->ask($prompt, array(
                'reasoning_effort' => 'minimal',
                'max_output_tokens' => 180,
                'timeout' => 30,
            ));
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage(), 500);
        }

        $message = trim(wp_strip_all_tags((string) $message));

        if ($message === '') {
            return $this->error(__('AI did not return a usable message.', 'smbb-signconnect'), 500);
        }

        return array(
            'success' => true,
            'message' => function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500),
        );
    }

    private function prompt($filename)
    {
        return "Tu aides a rediger un message de demande de signature electronique.\n"
            . "A partir du nom de fichier suivant : \"" . (string) $filename . "\"\n"
            . "Genere uniquement un message en francais, deux phrases maximum, sans guillemets, sans objet d'email.\n"
            . "Le message doit dire : Veuillez trouver le lien de signature pour votre [mini description du document en 20 mots max].\n"
            . "La mini description doit etre naturelle et utile, sans inventer d'informations non presentes dans le nom du fichier.\n"
            . "Ignore les noms de plateformes, logiciels, outils ou exports presents dans le fichier, par exemple Zeendoc, SignConnect, DocuSign, Adobe, PDF, scan, export.\n"
            . "Ne mentionne jamais une plateforme, un logiciel, un outil, un service d'archivage ou le format du fichier.";
    }

    private function error($message, $status)
    {
        return array(
            'success' => false,
            'message' => (string) $message,
            'status_code' => (int) $status,
        );
    }
}
