<?php

namespace Smbb\Sample\CodeTool;

use Smbb\WpCodeTool\Api\ApiHelper;

// Cette classe est un exemple de callback API custom pour une ressource CodeTool.
// Elle n'est pas appelée directement : le moteur la chargera quand une route custom
// déclarée dans test.json sera exécutée.
defined('ABSPATH') || exit;

/**
 * Endpoints REST personnalisés de la ressource "test".
 *
 * Le CRUD standard doit rester généré par le moteur CodeTool. Cette classe sert seulement
 * aux cas métier qui sortent du standard : recherche spéciale, action d'impression, calcul,
 * synchronisation externe, etc.
 */
final class TestApi
{
    // Helper partagé fourni par smbb-wpcodetool.
    // Il évite de recopier param(), error(), limitParam(), etc. dans chaque classe API.
    private $api;

    /**
     * Le moteur pourra injecter son ApiHelper.
     *
     * Le fallback "new ApiHelper()" garde l'exemple autonome et plus facile à lire/tester.
     */
    public function __construct(ApiHelper $api = null)
    {
        $this->api = $api ?: new ApiHelper();
    }

    /**
     * Exemple de route custom : chercher des lignes par nom.
     *
     * Le store est l'accès aux données de la ressource. Ici on demande explicitement
     * qu'il possède une méthode search(), car cet endpoint en dépend.
     */
    public function searchByName($request, array $context = array())
    {
        $store = $this->api->store($context, array('search'));

        if (!$store) {
            return $this->api->error('missing_store', 'The test store is not available.');
        }

        // Le helper lit les paramètres de requête et borne la limite à 50 résultats.
        // Le détail SQL reste dans le store, pas dans cette classe API.
        return $store->search(array(
            'name' => $this->api->param($request, 'name', ''),
            'limit' => $this->api->limitParam($request, 'limit', 10, 1, 50),
        ));
    }

    /**
     * Exemple d'action custom sur une ligne : produire un résumé imprimable.
     *
     * La route déclarée dans le JSON ressemble à /tests/{id}/print.
     */
    public function printSummary($request, array $context = array())
    {
        $store = $this->api->store($context, array('find'));

        if (!$store) {
            return $this->api->error('missing_store', 'The test store is not available.');
        }

        // On récupère l'id avec intParam() pour éviter que chaque endpoint fasse son cast.
        $row = $store->find($this->api->intParam($request, 'id'));

        if (!$row) {
            return $this->api->notFound('Test item not found.');
        }

        // On retourne un tableau simple : WordPress REST le convertira en JSON.
        return array(
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'title' => isset($row['name']) ? (string) $row['name'] : '',
            'summary' => sprintf('Test #%d: %s', (int) $row['id'], (string) $row['name']),
        );
    }
}
