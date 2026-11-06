<?php
/**
 * Formulaire d'un atelier.
 *
 * @var \Smbb\WpCodeTool\Admin\Form $form
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';
$item = isset($item) && is_array($item) ? $item : array();
$is_new = empty($item['id']);
$shop = \Smbb\Tomo\WooCommerce\Module::active();

// Produits vendus sur place : cases à cocher avec un filtre (la liste peut être longue).
$extra_html = '';

if ($shop) {
    $checked = !$is_new ? array_map('intval', \Smbb\Tomo\Repository\Lookup::ids(isset($item['extra_product_ids']) ? $item['extra_product_ids'] : array())) : array();
    $choices = \Smbb\Tomo\WooCommerce\OnsiteSales::choices();
    $extra_html = '<input type="hidden" name="tomo_extra_products_form" value="1">';

    if ($choices) {
        $extra_html .= '<p><input type="search" class="regular-text" placeholder="' . esc_attr__('Filtrer les produits…', $d) . '" oninput="var q=this.value.toLowerCase();this.parentNode.nextElementSibling.querySelectorAll(\'label\').forEach(function(l){l.hidden=q&&l.textContent.toLowerCase().indexOf(q)<0&&!l.querySelector(\'input\').checked;});"></p><div class="tomo-extra-products">';

        foreach ($choices as $id => $label) {
            $extra_html .= '<label><input type="checkbox" name="extra_product_ids[]" value="' . (int) $id . '"' . (in_array((int) $id, $checked, true) ? ' checked' : '') . '> ' . esc_html($label) . '</label>';
        }

        $extra_html .= '</div>';
    } else {
        $extra_html .= '<p class="tomo-muted">' . esc_html__('Aucun produit WooCommerce publié (hors produits des ateliers).', $d) . '</p>';
    }
}

// Texte et photo : une seule source. Avec un produit WooCommerce lié, ce sont ceux du produit.
$product = $shop && !$is_new ? \Smbb\Tomo\WooCommerce\Products::linked($item) : null;

if ($product) {
    $thumb = $product->get_image_id() ? wp_get_attachment_image((int) $product->get_image_id(), 'thumbnail', false, array('class' => 'tomo-product-thumb')) : '';
    $presentation = array(
        '<div class="tomo-product-source">' . $thumb . '<p>'
        . esc_html(sprintf(__('Le texte et la photo affichés sur le site sont ceux du produit « %s ». Modifiez-les sur la fiche produit, pour ne les tenir qu\'à un seul endroit.', $d), $product->get_name()))
        . '<br><a class="button" href="' . esc_url((string) get_edit_post_link($product->get_id())) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span> ' . esc_html__('Modifier le texte et la photo', $d) . '</a></p></div>',
    );
} else {
    $presentation = array(
        $form->editor(__('Description', $d))->setName('description')->setHelp($shop ? __('Reprise dans le produit WooCommerce à sa création ; ensuite, c\'est la fiche produit qui fait foi.', $d) : ''),
        $form->image(__('Photo', $d))->setName('image_id'),
    );
}

// Créneaux de l'atelier : les prochains, avec liens vers la liste complète et le calendrier.
$creneaux_note = null;

if (!$is_new) {
    $upcoming = \Smbb\Tomo\Repository\Creneaux::upcoming(0, array('atelier_id' => (int) $item['id'], 'with_passes' => true));
    $shown = array_slice($upcoming, 0, 12);
    $rows = '';

    foreach ($shown as $creneau) {
        $names = \Smbb\Tomo\Repository\Lookup::intervenantNames($creneau['intervenant_ids']);
        $rows .= '<tr>'
            . '<td><a href="' . esc_url(View::url('tomo_creneau', array('view' => 'details', 'id' => (int) $creneau['id']))) . '"><strong>' . esc_html(\Smbb\Tomo\Repository\Lookup::creneauWhen($creneau)) . '</strong></a></td>'
            . '<td>' . View::fill($creneau['booked'], $creneau['capacity'], isset($creneau['waiting']) ? $creneau['waiting'] : 0) . '</td>'
            . '<td>' . ($names !== '' ? esc_html($names) : View::noIntervenant()) . '</td>'
            . '<td>' . Choices::creneauBadge($creneau) . '</td>'
            . '</tr>';
    }

    $links = '<p class="tomo-switch">'
        . '<a class="button button-primary" href="' . esc_url(View::url('tomo_creneau', array('view' => 'form', 'atelier' => (int) $item['id']))) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__('Ajouter un créneau', $d) . '</a> '
        . '<a class="button" href="' . esc_url(View::filtered('tomo_creneau', 'atelier_id', (int) $item['id'])) . '"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> ' . esc_html__('Tous les créneaux', $d) . '</a> '
        . '<a class="button" href="' . esc_url(View::url('tomo_calendrier', array('atelier' => (int) $item['id']))) . '"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> ' . esc_html__('Calendrier', $d) . '</a></p>';

    $body = $rows !== ''
        ? '<table class="widefat striped tomo-mini-table"><thead><tr><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Remplissage', $d) . '</th><th>' . esc_html__('Intervenants', $d) . '</th><th>' . esc_html__('Statut', $d) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        : '<p class="tomo-muted">' . esc_html__('Aucun créneau à venir.', $d) . '</p>';

    if (count($upcoming) > count($shown)) {
        $body .= '<p class="tomo-muted">' . esc_html(sprintf(__('… et %d autres à venir.', $d), count($upcoming) - count($shown))) . '</p>';
    }

    $creneaux_note = View::note(sprintf(__('Prochains créneaux (%d)', $d), count($upcoming)), $body . $links, 'dashicons-calendar-alt');
}

// Vente sur place : les catalogues qui servent cet atelier (réglés dans « Catalogue sur place »).
$catalogue_html = '';

if ($shop) {
    $names = array();

    foreach (\Smbb\Tomo\Repository\Catalogues::all(true) as $catalogue) {
        if ($is_new ? (bool) \Smbb\Tomo\Repository\Lookup::ids($catalogue['atelier_ids']) : !\Smbb\Tomo\Repository\Catalogues::matches($catalogue, (int) $item['id'], null)) {
            continue;
        }

        $names[] = '<a href="' . esc_url(\Smbb\Tomo\Admin\View::url('tomo_catalogue', array('view' => 'form', 'id' => (int) $catalogue['id']))) . '">' . esc_html(\Smbb\Tomo\Repository\Catalogues::path((int) $catalogue['id'])) . '</a>'
            . (\Smbb\Tomo\Repository\Lookup::ids($catalogue['atelier_ids']) ? '' : ' <span class="tomo-muted">(' . esc_html__('tous les ateliers', $d) . ')</span>');
    }

    $catalogue_html = '<div class="tomo-atelier-catalogues">'
        . ($names ? '<p>' . esc_html__('Catalogues proposés à la caisse pour cet atelier :', $d) . '</p><ul class="ul-disc"><li>' . implode('</li><li>', $names) . '</li></ul>' : '<p class="tomo-muted">' . esc_html__('Aucun catalogue sur place ne concerne cet atelier pour le moment.', $d) . '</p>')
        . '<p><a class="button" href="' . esc_url(\Smbb\Tomo\Admin\View::url('tomo_catalogue')) . '"><span class="dashicons dashicons-store" aria-hidden="true"></span> ' . esc_html__('Ouvrir le Catalogue sur place', $d) . '</a></p></div>';
}

$fields = array(
    $form->section(__('Atelier', $d), __('Ce qui est vendu : chaque créneau reprend ces valeurs par défaut.', $d), array(
        $form->row(
            $form->text(__('Nom', $d))->setName('name')->required()->setHelp(\Smbb\Tomo\Support\Vocabulary::example('name')),
            $form->select(__('Statut', $d))->setName('status')->setOptions(Choices::options('atelierStatuses')),
            $form->color(__('Couleur dans le calendrier', $d))->setName('color')
        ),
    ))->setIcon('dashicons-carrot'),
    $creneaux_note,
    $form->section(__('Présentation', $d), '', $presentation)->setIcon('dashicons-format-image'),
    $form->section(__('Lieu', $d), __('Un seul lieu par atelier.', $d), array(
        $form->row(
            $form->text(__('Lieu', $d))->setName('location')->setHelp(\Smbb\Tomo\Support\Vocabulary::example('location')),
            $form->textarea(__('Adresse', $d), array('rows' => 2))->setName('address')
        ),
        $form->row(
            $form->text(__('Latitude', $d), array('placeholder' => '45.7772'))->setName('latitude'),
            $form->text(__('Longitude', $d), array('placeholder' => '3.0870'))->setName('longitude')
        ),
        '<p class="description">' . esc_html__("Facultatif : pour que le billet Wallet apparaisse sur l'écran verrouillé à l'approche du lieu. Vide = calculé depuis l'adresse. Pour corriger : clic droit sur le lieu dans Google Maps, puis clic sur les coordonnées pour les copier.", $d) . '</p>',
    ))->setIcon('dashicons-location'),
    $form->section(__('Infos pratiques', $d), __('Au dos du billet Wallet et dans les e-mails ({infos_pratiques}).', $d), array(
        $form->textarea(__('À apporter, à savoir', $d), array('rows' => 3))->setName('practical_info')
            ->setHelp(__('Par exemple : tablier fourni, cheveux attachés, venez 10 minutes avant. Prévoyez une boîte pour rapporter vos créations.', $d)),
    ))->setIcon('dashicons-info-outline'),
    $form->section(__('Valeurs par défaut des créneaux', $d), __('Modifiables créneau par créneau.', $d), array(
        $form->row(
            $form->number(__('Durée (minutes)', $d), array('min' => 0, 'step' => 5))->setName('duration_minutes'),
            $form->number(sprintf(__('Prix par place (%s)', $d), \Smbb\Tomo\Support\Prices::label()), array('min' => 0, 'step' => '0.01'))->setName('default_price')
                ->setHelp(\Smbb\Tomo\Support\Prices::help()),
            $form->number(__('Places en vente', $d), array('min' => 1, 'step' => 1))->setName('default_capacity')
        ),
    ))->setIcon('dashicons-tickets-alt'),
    $shop ? $form->section(__('Boutique', $d), __('Le produit WooCommerce qui vend les places de cet atelier. Son prix, son stock et ses dates sont pilotés par Tomo.', $d), array(
        $form->select(__('Produit WooCommerce', $d))->setName('product_id')->setOptions(\Smbb\Tomo\WooCommerce\Products::options($is_new ? 0 : (int) $item['id']))
            ->setHelp(__('Un produit ne peut vendre qu\'un seul atelier. Il devient virtuel, sans stock WooCommerce, au prix par défaut ci-dessus.', $d)),
    ))->setIcon('dashicons-cart') : null,
    $shop ? $form->section(__('Vente sur place', $d), __("Les produits vendus depuis la feuille d'appel se rangent dans le Catalogue sur place : un catalogue peut servir à tous les ateliers ou seulement à certains.", $d), array(
        $catalogue_html,
    ))->setIcon('dashicons-store') : null,
    $form->section(__('Notes internes', $d), '', array(
        $form->textarea(__('Notes', $d), array('rows' => 3))->setName('notes')->setHelp(__('Visibles uniquement dans le back-office.', $d)),
    ))->setIcon('dashicons-edit'),
);

$fields = array_values(array_filter($fields));
$html = ($shop ? \Smbb\Tomo\WooCommerce\Products::formCard($item) : '') . '<div class="form_container">' . $form->save($is_new ? __("Créer l'atelier", $d) : __('Enregistrer', $d))->setFields($fields) . '</div>';

View::page($html, isset($page_header_html) ? $page_header_html : '', isset($notices_html) ? $notices_html : '', __('Atelier', $d));
