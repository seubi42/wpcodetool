<?php

namespace Smbb\SignConnect\Service;

defined('ABSPATH') || exit;

final class DocumentAiPromptBuilder
{
    public function build($filename, $page_count, $include_signature_zone, $document_text = '', array $signature_page = array(), array $page_image = array())
    {
        $page_count = max(1, (int) $page_count);
        $selected_page = isset($signature_page['page_number']) ? max(1, (int) $signature_page['page_number']) : $page_count;
        $prompt = "Tu aides a preparer un document PDF a signer.\n"
            . "Nom du fichier : \"" . (string) $filename . "\"\n"
            . "Nombre de pages du PDF : " . $page_count . "\n"
            . ($include_signature_zone
                ? "Une image rasterisee de la page " . $selected_page . " est jointe a cette requete. Analyse uniquement cette image pour placer la zone de signature.\n"
                : "Le PDF n'est pas joint a cette requete pour economiser les couts. Base-toi sur le texte extrait ci-dessous et sur le nom du fichier.\n")
            . "Reponds uniquement avec un JSON valide, sans markdown, sans commentaire.\n"
            . ($include_signature_zone
                ? "Schema attendu : {\"message\":\"...\",\"signature_zone\":{\"page_number\":1,\"x\":0.55,\"y\":0.72,\"width\":0.30,\"height\":0.10,\"explicit_signature_found\":true,\"anchor_text\":\"Signature du client\",\"reason\":\"Boite Signature du client visible\"}}\n"
                : "Schema attendu : {\"message\":\"...\"}\n")
            . ($include_signature_zone ? "signature_zone doit etre un objet. N'utilise null que si l'image est illisible, vide, ou ne montre aucune page de document exploitable.\n" : "")
            . "message : deux phrases maximum en francais, pour dire \"Veuillez trouver le lien de signature pour votre [description courte]\".\n"
            . "Base la description sur le contenu du document quand il est lisible, sinon sur le nom du fichier.\n"
            . "Ne mentionne jamais de plateforme, logiciel, outil, service d'archivage, ni le format PDF.\n";

        if (!$include_signature_zone) {
            $document_text = trim((string) $document_text);

            if ($document_text !== '') {
                $prompt .= "Texte extrait du document :\n---\n" . $document_text . "\n---\n";
            }

            return $prompt;
        }

        return $prompt
            . "Tache : renvoyer une seule zone de signature sous forme de ratios 0..1. Tu dois renvoyer page_number=" . $selected_page . ".\n"
            . (!empty($signature_page['text_preview']) ? "Extrait texte de la page choisie : " . (string) $signature_page['text_preview'] . "\n" : "")
            . (!empty($page_image['width']) && !empty($page_image['height']) ? "Dimensions de l'image analysee : " . (int) $page_image['width'] . "x" . (int) $page_image['height'] . " pixels.\n" : "")
            . "Priorite 1 : si tu vois un libelle ou cadre Signature / Signature du client / Bon pour accord, place le rectangle dans le blanc juste associe a ce libelle, sans couvrir le texte.\n"
            . "Priorite 2 : sinon choisis une zone blanche plausible en bas de page, modifiable par l'utilisateur.\n"
            . "Evite de couvrir texte, montants, tableaux, bordures, logos, coordonnees ou conditions.\n"
            . "Renvoie null seulement si l'image est illisible ou ne montre pas une page exploitable.\n"
            . "x, y, width, height sont des ratios entre 0 et 1 depuis le coin haut gauche de l'image, jamais des pixels ni des pourcentages 0-100.\n"
            . "Reponse courte : JSON uniquement.";
    }
}
