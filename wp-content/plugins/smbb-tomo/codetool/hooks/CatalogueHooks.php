<?php

namespace Smbb\Tomo\CodeTool;

use Smbb\Tomo\Repository\Catalogues;
use Smbb\Tomo\Support\Input;

defined('ABSPATH') || exit;

/**
 * Catalogue sur place : listes ordonnées de produits WooCommerce, pour certains ateliers et
 * intervenants (aucun coché = tous), rangées en catalogues et sous-catalogues.
 */
final class CatalogueHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('name', 'notes'));
        $data = Input::integer($data, array('position'));
        $data = Input::nullableIds($data, array('parent_id'));

        // Cases à cocher et liste ordonnée : rien = vide (seulement depuis le formulaire).
        if (isset($_POST['tomo_catalogue_form'])) {
            foreach (array('atelier_ids', 'intervenant_ids', 'product_ids') as $key) {
                $data = Input::idList($data, $key);
            }

            $data['active'] = !empty($data['active']) && $data['active'] !== '0' ? '1' : '0';
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();
        $id = !empty($current['id']) ? (int) $current['id'] : 0;

        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
            $errors['name'] = __('Donnez un nom au catalogue.', 'smbb-tomo');
        }

        // Un catalogue ne peut pas être rangé dans lui-même ni dans un de ses sous-catalogues.
        if ($id && !empty($data['parent_id']) && in_array((int) $data['parent_id'], array_merge(array($id), Catalogues::descendantIds($id)), true)) {
            $errors['parent_id'] = __('Un catalogue ne peut pas être rangé dans lui-même ou dans un de ses sous-catalogues.', 'smbb-tomo');
        }

        return $errors;
    }
}
