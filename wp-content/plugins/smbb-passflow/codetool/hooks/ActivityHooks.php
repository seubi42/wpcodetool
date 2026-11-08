<?php

namespace Smbb\Passflow\CodeTool;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\Support\Input;

defined('ABSPATH') || exit;

final class ActivityHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('name', 'location', 'address', 'color'));
        $data = Input::decimal($data, array('default_price'));
        $data = Input::integer($data, array('duration_minutes', 'default_capacity'));
        $data = Input::nullableIds($data, array('image_id', 'product_id'));

        // Produits vendus sur place : cases à cocher, rien de coché = liste vide (seulement depuis la fiche).
        if (isset($_POST['passflow_extra_products_form'])) {
            $data = Input::idList($data, 'extra_product_ids');
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();

        if (empty($data['name'])) {
            $errors['name'] = __("Le nom de l'atelier est obligatoire.", 'smbb-passflow');
        }

        if (isset($data['status']) && !array_key_exists($data['status'], Choices::activityStatuses())) {
            $errors['status'] = __('Statut inconnu.', 'smbb-passflow');
        }

        if (isset($data['default_price']) && $data['default_price'] !== null && (!is_numeric($data['default_price']) || (float) $data['default_price'] < 0)) {
            $errors['default_price'] = __('Prix invalide.', 'smbb-passflow');
        }

        foreach (array('duration_minutes', 'default_capacity') as $key) {
            if (isset($data[$key]) && $data[$key] !== null && (!is_numeric($data[$key]) || (int) $data[$key] < 0)) {
                $errors[$key] = __('Nombre invalide.', 'smbb-passflow');
            }
        }

        if (!empty($data['product_id'])) {
            $product = get_post((int) $data['product_id']);
            $self = isset($context['current']['id']) ? (int) $context['current']['id'] : (isset($context['id']) ? (int) $context['id'] : 0);

            if (!$product || $product->post_type !== 'product') {
                $errors['product_id'] = __('Produit introuvable.', 'smbb-passflow');
            } else {
                foreach (Lookup::ateliers() as $other) {
                    if ((int) $other['product_id'] === (int) $data['product_id'] && (int) $other['id'] !== $self) {
                        $errors['product_id'] = sprintf(__('Ce produit vend déjà l\'atelier « %s ».', 'smbb-passflow'), $other['name']);
                        break;
                    }
                }
            }
        }

        if (!empty($data['color']) && !preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $data['color'])) {
            $errors['color'] = __('Couleur invalide.', 'smbb-passflow');
        }

        return $errors;
    }

    public function afterSave(array $row, array $context = array())
    {
        Lookup::flush();

        if (\Smbb\Passflow\WooCommerce\Module::active() && !empty($row['id'])) {
            $activity = Lookup::atelier($row['id']) ?: $row;
            $previous = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();
            \Smbb\Passflow\WooCommerce\Products::syncActivity($activity, $previous);
        }
    }

    public function beforeDelete(array $row, array $context = array())
    {
        if (Lookup::count('passflow_slot', 'activity_id', $row['id']) > 0) {
            return __('Cet atelier a des créneaux : archivez-le plutôt que de le supprimer.', 'smbb-passflow');
        }

        Lookup::flush();

        return true;
    }
}
