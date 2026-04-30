<?php

namespace Smbb\WpCodeTool\Store;

// Ce store est une classe interne du plugin. Il ne doit pas être exécuté directement.
defined('ABSPATH') || exit;

/**
 * Store très simple pour les ressources stockées dans wp_options.
 *
 * Ce store sert au cas "page de réglages" : une ressource CodeTool ne représente pas
 * une table avec plusieurs lignes, mais un seul objet de configuration sérialisé par
 * WordPress dans la table des options.
 */
final class OptionStore implements OptionStoreInterface
{
    // Contrôle du champ autoload de wp_options.
    // null signifie : laisser WordPress garder son comportement par défaut.
    private $autoload;

    // Valeurs par défaut déclarées dans le JSON de la ressource.
    // Elles permettent de toujours retourner un objet complet même si l'option n'existe pas.
    private $defaults;

    // Nom réel de l'option WordPress, par exemple smbb_sample_settings.
    private $option_name;

    /**
     * @param string    $option_name Nom de l'option WordPress.
     * @param array     $defaults    Valeurs par défaut de l'objet stocké.
     * @param bool|null $autoload    null = WordPress choisit, false = ne pas autoload.
     */
    public function __construct($option_name, array $defaults = array(), $autoload = null)
    {
        $this->autoload = $autoload;
        $this->defaults = $defaults;
        $this->option_name = (string) $option_name;
    }

    /**
     * Lit l'option et la fusionne avec les valeurs par défaut.
     *
     * Ce comportement est pratique pour les réglages : ajouter une nouvelle clé dans le JSON
     * ne casse pas les installations existantes, car la valeur par défaut réapparaît.
     */
    public function get()
    {
        $value = get_option($this->option_name, $this->defaults);

        // Si l'option a été corrompue ou remplacée par une valeur scalaire, on revient
        // aux defaults plutôt que de propager une structure inattendue.
        if (!is_array($value)) {
            return $this->defaults;
        }

        return array_replace_recursive($this->defaults, $value);
    }

    /**
     * Remplace l'objet de réglages complet.
     *
     * On fusionne tout de même avec les defaults pour garantir que les clés attendues
     * restent présentes après sauvegarde.
     */
    public function replace(array $data)
    {
        $value = array_replace_recursive($this->defaults, $data);
        $current = get_option($this->option_name, $this->defaults);

        // update_option() retourne false si la valeur est identique. Pour l'admin,
        // "rien n'a change" reste un succes, pas une erreur de sauvegarde.
        if (is_array($current) && $current == $value) {
            return true;
        }

        // Quand autoload vaut null, on appelle update_option sans troisième paramètre
        // pour ne pas forcer une stratégie que WordPress ou le site auraient déjà choisie.
        if ($this->autoload === null) {
            return update_option($this->option_name, $value);
        }

        return update_option($this->option_name, $value, (bool) $this->autoload);
    }

    /**
     * Met à jour seulement certaines clés de l'objet.
     *
     * C'est l'équivalent "PATCH" pour une ressource option : les clés absentes sont gardées,
     * les clés présentes remplacent la valeur existante.
     */
    public function patch(array $data)
    {
        return $this->replace(array_replace_recursive($this->get(), $data));
    }

    /**
     * Supprime complètement l'option WordPress.
     *
     * Au prochain get(), les valeurs par défaut seront retournées.
     */
    public function delete()
    {
        return delete_option($this->option_name);
    }
}
