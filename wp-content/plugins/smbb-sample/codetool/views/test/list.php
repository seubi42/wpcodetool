<?php

/**
 * Vue liste de la ressource "test".
 *
 * Cette view illustre l'objectif recherché : écrire une table admin de façon déclarative,
 * proche de TypeRocket, sans recopier tout le HTML WordPress dans chaque plugin.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 */

defined('ABSPATH') || exit;

// En runtime normal, le moteur CodeTool injectera déjà $table avec :
// - les lignes récupérées par le store ;
// - les URLs admin ;
// - le nom de ressource ;
// - la clé primaire.
//
// Ce fallback garde la view lisible et testable tant que le moteur complet n'existe pas.
if (!isset($table)) {
    $table = new \Smbb\WpCodeTool\Admin\Table(array(
        'admin_url' => isset($admin_url) ? $admin_url : '',
        'create_url' => isset($create_url) ? $create_url : '',
        'primary_key' => isset($primary_key) ? $primary_key : 'id',
        'resource_label' => isset($resource_label) ? $resource_label : 'Tests',
        'resource_name' => isset($resource_name) ? $resource_name : 'test',
        'rows' => isset($rows) && is_array($rows) ? $rows : array(),
    ));
}

// La view choisit les colonnes visibles, les actions, les callbacks et la colonne principale.
// Le JSON garde la définition technique de la ressource ; la view garde la liberté de rendu.
$table->setColumns(array(
    'name' => array(
        'sort' => true,
        'label' => __('Name', 'smbb-sample'),
        // Actions conventionnelles :
        // edit      -> ouvre la view form ;
        // view      -> ouvre la view details ;
        // duplicate -> clone la ligne puis ouvre le formulaire du nouveau record ;
        // delete    -> déclenche une action technique protégée par nonce.
        'actions' => array('edit', 'view', 'duplicate', 'delete'),
        // Callback de colonne : on reçoit la valeur brute et la ligne complète.
        // Ici on affiche "id - name", comme on le ferait souvent dans une liste admin.
        'callback' => function ($text, $result) {
            $id = is_array($result) ? $result['id'] : $result->id;
            $name = trim((string) $text);

            return $name !== '' ? $id . ' - ' . esc_html($name) : (string) $id;
        },
    ),
    'parent_id' => array(
        'sort' => true,
        'label' => __('Parent', 'smbb-sample'),
        'callback' => function ($text) {
            return $text === null || $text === '' ? '-' : '#' . (string) $text;
        },
    ),
    'number' => array(
        'sort' => true,
        'label' => __('Number', 'smbb-sample'),
    ),
    'amount' => array(
        'sort' => true,
        'label' => __('Amount', 'smbb-sample'),
        // Exemple de formatage local à la view : la base stocke un nombre,
        // l'admin choisit comment l'afficher.
        'callback' => function ($text) {
            return $text === null || $text === '' ? '' : number_format((float) $text, 2, '.', ' ');
        },
    ),
    'lastupdate_date' => array(
        'sort' => true,
        'label' => __('Last update', 'smbb-sample'),
    ),
), 'name');

// Le helper Table s'occupe du rendu HTML final.
$table->render();
