<?php

namespace Smbb\Sample\CodeTool;

// Exemple de classe de hooks pour une ressource CodeTool.
// Elle ne fait pas le rendu admin et ne gère pas les routes REST : elle accompagne
// uniquement le cycle de vie des données.
defined('ABSPATH') || exit;

/**
 * Hooks métier de la ressource "test".
 *
 * Le moteur appellera ces méthodes à des moments précis :
 * - beforeValidate : nettoyage léger avant validation ;
 * - validate       : retourne les erreurs métier ;
 * - beforeSave     : normalisation finale avant écriture ;
 * - afterSave      : notification après sauvegarde ;
 * - beforeDelete   : possibilité de bloquer une suppression.
 */
final class TestHooks
{
    /**
     * Nettoyage avant validation.
     *
     * On garde cette étape séparée de beforeSave pour que la validation travaille déjà
     * sur des valeurs propres, sans pour autant modifier la base de données.
     */
    public function beforeValidate(array $data, array $context = array())
    {
        // Le nom ne doit pas échouer à cause d'espaces accidentels autour de la valeur.
        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }

        // Convention de l'exemple : chaîne vide sur ces champs = valeur absente.
        // Le moteur décidera ensuite si null est autorisé selon la définition du modèle.
        foreach (array('number', 'amount', 'parent_id', 'json_table', 'json_object') as $key) {
            if (array_key_exists($key, $data) && $data[$key] === '') {
                $data[$key] = null;
            }
        }

        // Les champs d'audit ne doivent jamais être fournis par le formulaire ou l'API.
        // Ils appartiennent au moteur : date de création, date de mise à jour, utilisateur.
        foreach (array('creation_date', 'lastupdate_date', 'creation_by', 'lastupdate_by') as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * Validation métier.
     *
     * La convention retenue ici : on retourne un tableau d'erreurs indexé par nom de champ.
     * Un tableau vide signifie que les données peuvent continuer vers beforeSave().
     */
    public function validate(array $data, array $context = array())
    {
        $errors = array();

        if (empty($data['name'])) {
            $errors['name'] = 'Name is required.';
        }

        if (!empty($data['name']) && strlen((string) $data['name']) > 50) {
            $errors['name'] = 'Name must be 50 characters or fewer.';
        }

        // Regle metier d'exemple demandee pour verifier la remontee d'erreurs
        // dans le CRUD admin : on interdit explicitement la valeur "poney".
        if (isset($data['name']) && strtolower((string) $data['name']) === 'poney') {
            $errors['name'] = 'Les poneys sont interdit.';
        }

        if (isset($data['amount']) && $data['amount'] !== null && !is_numeric($data['amount'])) {
            $errors['amount'] = 'Amount must be numeric.';
        }

        if (!empty($data['parent_id']) && !empty($context['id']) && (string) $data['parent_id'] === (string) $context['id']) {
            $errors['parent_id'] = 'A record cannot be its own parent.';
        }

        // Les colonnes JSON peuvent arriver sous forme de tableaux PHP depuis la view,
        // ou sous forme de chaîne JSON depuis une API. Si c'est une chaîne, on la valide.
        foreach (array('gallery_ids', 'json_table', 'json_object') as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                json_decode($data[$key], true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[$key] = 'Value must be valid JSON.';
                }
            }
        }

        return $errors;
    }

    /**
     * Normalisation juste avant sauvegarde.
     *
     * Ici on transforme les types pour se rapprocher du stockage SQL attendu :
     * int, decimal formaté, JSON encodé.
     */
    public function beforeSave(array $data, array $context = array())
    {
        if (array_key_exists('number', $data) && $data['number'] !== null) {
            $data['number'] = (int) $data['number'];
        }

        if (array_key_exists('amount', $data) && $data['amount'] !== null) {
            $data['amount'] = number_format((float) $data['amount'], 2, '.', '');
        }

        // Quand la view envoie un repeater ou un objet structuré, PHP reçoit un array.
        // La colonne SQL étant longtext, on encode explicitement en JSON.
        foreach (array('json_table', 'json_object') as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = wp_json_encode($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Notification après sauvegarde.
     *
     * Exemple volontairement simple : on expose un hook WordPress classique pour que
     * d'autres morceaux du plugin puissent réagir sans modifier cette classe.
     */
    public function afterSave(array $row, array $context = array())
    {
        do_action('smbb_sample_test_saved', $row, $context);
    }

    /**
     * Possibilité de bloquer une suppression.
     *
     * true = suppression autorisée. Plus tard on pourrait retourner false ou WP_Error.
     */
    public function beforeDelete(array $row, array $context = array())
    {
        return true;
    }

    /**
     * Exemple de recherche liste custom.
     *
     * Ici on montre l'approche recommandee a la place d'un fragment SQL dans le JSON :
     * le modele reste declaratif, mais un hook PHP peut produire une clause sur mesure.
     *
     * Le JSON de test.json declare :
     * "search": {
     *   "enabled": true,
     *   "provider": "hook",
     *   ...
     * }
     *
     * Quand l'utilisateur tape dans la barre de recherche, CodeTool appelle cette
     * methode et injecte la clause retournee dans la liste SQL.
     */
    public function listSearchClause($search, array $context = array())
    {


        global $wpdb;

        $term = '%' . $wpdb->esc_like(trim((string) $search)) . '%';

        return array(
            'sql' => '(name LIKE %s OR json_object LIKE %s)',
            'params' => array($term, $term),
        );
    }
}
