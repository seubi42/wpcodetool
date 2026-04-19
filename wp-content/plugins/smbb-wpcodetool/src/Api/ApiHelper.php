<?php

namespace Smbb\WpCodeTool\Api;

use Smbb\WpCodeTool\Support\ValidationErrors;

// Protection standard : ce fichier est une brique interne du plugin,
// il ne doit pas produire de sortie si on l'appelle directement.
defined('ABSPATH') || exit;

/**
 * Petits helpers communs pour les endpoints REST personnalisés.
 *
 * L'idée importante : les classes API des plugins consommateurs doivent rester lisibles
 * et concentrées sur le métier. Tout ce qui est répétitif autour de WP_REST_Request,
 * WP_Error, limites de pagination ou récupération du store passe ici.
 */
final class ApiHelper
{
    /**
     * Lit un paramètre dans une requête REST WordPress ou dans un tableau de test.
     *
     * On accepte volontairement les deux formes :
     * - WP_REST_Request en vrai runtime WordPress ;
     * - array pendant nos tests unitaires ou nos exemples hors WordPress.
     *
     * @param mixed  $request Requête REST ou tableau.
     * @param string $key     Nom du paramètre à récupérer.
     * @param mixed  $default Valeur retournée si le paramètre n'existe pas.
     * @return mixed
     */
    public function param($request, $key, $default = null)
    {
        if (is_object($request) && method_exists($request, 'get_param')) {
            $value = $request->get_param($key);
            return $value === null ? $default : $value;
        }

        if (is_array($request) && array_key_exists($key, $request)) {
            return $request[$key];
        }

        return $default;
    }

    /**
     * Variante pratique de param() pour les identifiants numériques.
     *
     * Exemple typique : récupérer /tests/{id} ou ?page=2.
     */
    public function intParam($request, $key, $default = 0)
    {
        return (int) $this->param($request, $key, $default);
    }

    /**
     * Récupère une limite de pagination encadrée.
     *
     * Cela évite que chaque endpoint refasse min/max à la main et protège l'API contre
     * des requêtes accidentellement énormes.
     */
    public function limitParam($request, $key = 'limit', $default = 10, $min = 1, $max = 100)
    {
        return min((int) $max, max((int) $min, $this->intParam($request, $key, $default)));
    }

    /**
     * Récupère le store injecté par le moteur CodeTool.
     *
     * Le store est l'objet qui sait lire/écrire les données de la ressource :
     * table SQL, option WordPress, ou autre stockage plus tard. On vérifie aussi,
     * si demandé, qu'il expose les méthodes nécessaires à l'endpoint custom.
     *
     * @param array $context          Contexte injecté par le moteur.
     * @param array $required_methods Méthodes attendues, par exemple array('find').
     * @return object|null
     */
    public function store(array $context, array $required_methods = array())
    {
        $store = isset($context['store']) ? $context['store'] : null;

        if (!is_object($store)) {
            return null;
        }

        foreach ($required_methods as $method) {
            if (!method_exists($store, $method)) {
                return null;
            }
        }

        return $store;
    }

    /**
     * Crée une erreur REST compatible WordPress.
     *
     * En vrai WordPress on retourne WP_Error. Le fallback tableau permet de garder les
     * exemples plus faciles à tester dans un contexte minimal.
     */
    public function error($code, $message, $status = 500)
    {
        if (class_exists('\WP_Error')) {
            return new \WP_Error($code, $message, array('status' => $status));
        }

        return array(
            'error' => $code,
            'message' => $message,
            'status' => $status,
        );
    }

    /**
     * Raccourci pour le cas très courant d'une ressource introuvable.
     */
    public function notFound($message = 'Resource not found.')
    {
        return $this->error('not_found', $message, 404);
    }

    /**
     * Normalise des erreurs de validation pour les reponses JSON.
     *
     * Cela permet de garder des hooks validate() simples (field => message),
     * tout en exposant une structure API plus propre :
     * - path      : notation pointee, exemple api.endpoint ;
     * - html_name : notation HTML, exemple api[endpoint] ;
     * - message   : texte lisible.
     */
    public function validationErrors(array $errors)
    {
        return ValidationErrors::listing($errors);
    }

    /**
     * Reponse REST standard pour une validation en echec.
     *
     * On choisit 422 par defaut : la requete est bien formee, mais les donnees
     * metier ne passent pas la validation.
     */
    public function validationError(array $errors, $message = 'Validation failed.', $status = 422)
    {
        $payload = array(
            'status' => (int) $status,
            'fields' => $this->validationErrors($errors),
        );

        if (class_exists('\WP_Error')) {
            return new \WP_Error('validation_failed', $message, $payload);
        }

        return array(
            'error' => 'validation_failed',
            'message' => $message,
            'status' => (int) $status,
            'fields' => $payload['fields'],
        );
    }
}
