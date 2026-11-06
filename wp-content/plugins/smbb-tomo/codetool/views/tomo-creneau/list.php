<?php
/**
 * Liste des créneaux avec leur remplissage.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 * @var array $rows
 */

use Smbb\Tomo\Admin\View;
use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-tomo';

// Remplissage calculé en une requête pour toute la page.
$stats = array();

foreach (Creneaux::withStats(isset($rows) && is_array($rows) ? $rows : array()) as $row) {
    $stats[(int) $row['id']] = $row;
}

$table->setColumns(array(
    'start_date' => array(
        'label' => __('Date', $d),
        'sort' => true,
        'actions' => array(
            // Feuille d'appel sur téléphone (pas pour un pass : on pointe chaque jour).
            array(
                'key' => 'mobile',
                'label' => __('Gestion mobile', $d),
                'icon' => 'dashicons-smartphone',
                'url' => function ($row) {
                    return View::url('tomo_espace', array('creneau' => (int) $row['id']));
                },
                'visible' => function ($row) {
                    return !\Smbb\Tomo\Service\Passes::isPass($row);
                },
                'primary' => false,
            ),
            'view',
            'edit',
            'duplicate',
            'delete',
        ),
        'callback' => function ($value, $row) {
            $past = Creneaux::isPast($row) ? ' tomo-muted' : '';

            return '<strong class="' . esc_attr($past) . '">' . esc_html(Lookup::creneauWhen($row)) . '</strong>';
        },
    ),
    'atelier_id' => array(
        'label' => __('Atelier', $d),
        'sort' => true,
        'callback' => function ($value) {
            $atelier = Lookup::atelier($value);
            $dot = $atelier && !empty($atelier['color']) ? '<span class="tomo-dot" style="background:' . esc_attr($atelier['color']) . '"></span>' : '';

            return $dot . esc_html(Lookup::atelierName($value));
        },
    ),
    'intervenant_ids' => array(
        'label' => __('Intervenants', $d),
        'callback' => function ($value, $row) {
            $names = Lookup::intervenantNames($value);

            if ($names !== '') {
                // Pastille de disponibilité devant chaque nom (dates à venir).
                if (!\Smbb\Tomo\Service\Availability::open($row)) {
                    return esc_html($names);
                }

                $dispos = \Smbb\Tomo\Service\Availability::forCreneaux(array((int) $row['id']));
                $all = Lookup::intervenants();
                $out = array();

                foreach (Lookup::ids($value) as $staff_id) {
                    if (isset($all[$staff_id])) {
                        $state = \Smbb\Tomo\Service\Availability::state(isset($dispos[(int) $row['id']][$staff_id]) ? $dispos[(int) $row['id']][$staff_id] : null);
                        $titles = array('yes' => __('Dispo', 'smbb-tomo'), 'no' => __('Non dispo', 'smbb-tomo'), 'asked' => __('En attente de réponse', 'smbb-tomo'), 'none' => __('Dispo pas encore demandée', 'smbb-tomo'));
                        $out[] = '<span class="tomo-dispo-dot tomo-dispo-dot--' . esc_attr($state) . '" title="' . esc_attr($titles[$state]) . '"></span>' . esc_html(Lookup::personName($all[$staff_id]));
                    }
                }

                return implode(', ', $out);
            }

            // Un pass n'a pas d'intervenants propres ; une date annulée ou passée n'en réclame plus.
            if (\Smbb\Tomo\Service\Passes::isPass($row) || $row['status'] === 'cancelled' || Creneaux::isPast($row)) {
                return '<span class="tomo-muted">—</span>';
            }

            return View::noIntervenant();
        },
    ),
    'capacity' => array(
        'label' => __('Remplissage', $d),
        'sort' => true,
        'callback' => function ($value, $row) use ($stats) {
            $stat = isset($stats[(int) $row['id']]) ? $stats[(int) $row['id']] : array('booked' => 0, 'waiting' => 0);

            return View::fill($stat['booked'], $value, $stat['waiting']);
        },
    ),
    'price' => array(
        'label' => __('Prix', $d),
        'callback' => function ($value, $row) {
            if ($value === null || $value === '') {
                $atelier = Lookup::atelier($row['atelier_id']);
                $value = $atelier ? $atelier['default_price'] : null;
            }

            return View::money($value);
        },
    ),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value, $row) {
            return Choices::creneauBadge($row);
        },
    ),
), 'start_date');

$switch = '<p class="tomo-switch"><a class="button" href="' . esc_url(View::url('tomo_calendrier')) . '"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> ' . esc_html__('Voir le calendrier', $d) . '</a> '
    . '<a class="button" href="' . esc_url(View::filtered('tomo_creneau', 'start_date', current_time('Y-m-d'), 'gte')) . '">' . esc_html__('À venir seulement', $d) . '</a></p>';

// Fenêtre de l'action groupée « Affecter intervenant(s)… » : elle s'ouvre à « Appliquer » et
// ajoute les intervenants cochés au formulaire de la liste (CreneauHooks::assignIntervenants).
$choices = '';

foreach (Lookup::intervenantOptions() as $label => $id) {
    $choices .= '<label><input type="checkbox" value="' . esc_attr($id) . '" data-tomo-assign-id> ' . esc_html($label) . '</label>';
}

$assign = '<dialog class="smbb-ui-dialog tomo-assign-dialog" data-tomo-assign-dialog><form method="dialog" class="smbb-ui-dialog-card">'
    . '<p class="smbb-ui-dialog-title">' . esc_html__('Affecter des intervenants', $d) . '</p>'
    . '<p class="smbb-ui-dialog-text" data-tomo-assign-count data-one="' . esc_attr__('%d créneau sélectionné', $d) . '" data-many="' . esc_attr__('%d créneaux sélectionnés', $d) . '"></p>'
    . ($choices !== ''
        ? '<div class="tomo-assign-choices">' . $choices . '</div>'
            . '<fieldset class="tomo-assign-mode">'
            . '<label><input type="radio" name="tomo_assign_mode_choice" value="add" checked> ' . esc_html__('Ajouter aux intervenants déjà prévus', $d) . '</label>'
            . '<label><input type="radio" name="tomo_assign_mode_choice" value="replace"> ' . esc_html__('Remplacer les intervenants prévus', $d) . '</label>'
            . '<label><input type="radio" name="tomo_assign_mode_choice" value="remove"> ' . esc_html__('Retirer ces intervenants', $d) . '</label>'
            . '</fieldset>'
            . '<p class="description">' . esc_html__('Les nouveaux intervenants sont prévenus, comme depuis la fiche du créneau. Les créneaux annulés et les pass ne changent pas.', $d) . '</p>'
        : '<p>' . esc_html__('Aucun intervenant actif : créez-en un d\'abord.', $d) . '</p>')
    . '<p class="tomo-assign-error" data-tomo-assign-error hidden>' . esc_html__('Cochez au moins un intervenant.', $d) . '</p>'
    . '<div class="smbb-ui-dialog-actions"><button value="cancel" class="is-cancel">' . esc_html__('Annuler', $d) . '</button>'
    . ($choices !== '' ? '<button value="ok" class="is-confirm" data-tomo-assign-ok>' . esc_html__('Appliquer', $d) . '</button>' : '')
    . '</div></form></dialog>';

$assign .= <<<'JS'
<script>
(function () {
    var dialog = document.querySelector('[data-tomo-assign-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') { return; }

    function chosenAction(form) {
        var names = ['smbb_codetool_batch_action', 'smbb_codetool_batch_action_bottom'];
        for (var i = 0; i < names.length; i++) {
            var select = form.querySelector('[name="' + names[i] + '"]');
            if (select && select.value) { return select.value; }
        }
        return '';
    }

    // Phase de capture : passe avant le traitement des actions groupées de CodeTool.
    window.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-smbb-batch-form]') || chosenAction(form) !== 'tomo_assign' || form.dataset.tomoAssignReady) { return; }
        var selected = form.querySelectorAll('[data-smbb-row-select]:checked').length;
        if (!selected) { return; } // CodeTool affiche « sélectionnez au moins un élément ».

        event.preventDefault();
        event.stopImmediatePropagation();
        var count = dialog.querySelector('[data-tomo-assign-count]');
        count.textContent = count.getAttribute(selected > 1 ? 'data-many' : 'data-one').replace('%d', selected);
        dialog.querySelector('[data-tomo-assign-error]').hidden = true;
        dialog.returnValue = '';
        dialog.showModal();

        var ok = dialog.querySelector('[data-tomo-assign-ok]');
        if (!ok) { return; }

        ok.onclick = function (e) {
            var ids = Array.prototype.map.call(dialog.querySelectorAll('[data-tomo-assign-id]:checked'), function (box) { return box.value; });
            var mode = (dialog.querySelector('[name="tomo_assign_mode_choice"]:checked') || {}).value || 'add';

            if (!ids.length && mode !== 'replace') {
                e.preventDefault();
                dialog.querySelector('[data-tomo-assign-error]').hidden = false;
                return;
            }

            form.querySelectorAll('[data-tomo-assign-input]').forEach(function (input) { input.remove(); });
            ids.forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'tomo_assign_ids[]'; input.value = id; input.setAttribute('data-tomo-assign-input', '');
                form.appendChild(input);
            });
            var modeInput = document.createElement('input');
            modeInput.type = 'hidden'; modeInput.name = 'tomo_assign_mode'; modeInput.value = mode; modeInput.setAttribute('data-tomo-assign-input', '');
            form.appendChild(modeInput);
            form.dataset.tomoAssignReady = '1';
            setTimeout(function () { form.submit(); }, 0);
        };
    }, true);
})();
</script>
JS;

View::table($table, $switch . $assign);
