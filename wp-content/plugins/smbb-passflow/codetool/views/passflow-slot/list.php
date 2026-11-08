<?php
/**
 * Liste des créneaux avec leur remplissage.
 *
 * @var \Smbb\WpCodeTool\Admin\Table $table
 * @var array $rows
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';

// Remplissage calculé en une requête pour toute la page.
$stats = array();

foreach (Slots::withStats(isset($rows) && is_array($rows) ? $rows : array()) as $row) {
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
                    return View::url('passflow_portal', array('creneau' => (int) $row['id']));
                },
                'visible' => function ($row) {
                    return !\Smbb\Passflow\Service\Passes::isPass($row);
                },
                'primary' => false,
            ),
            'view',
            'edit',
            'duplicate',
            'delete',
        ),
        'callback' => function ($value, $row) {
            $past = Slots::isPast($row) ? ' passflow-muted' : '';

            return '<strong class="' . esc_attr($past) . '">' . esc_html(Lookup::slotWhen($row)) . '</strong>';
        },
    ),
    'activity_id' => array(
        'label' => __('Atelier', $d),
        'sort' => true,
        'callback' => function ($value) {
            $activity = Lookup::atelier($value);
            $dot = $activity && !empty($activity['color']) ? '<span class="passflow-dot" style="background:' . esc_attr($activity['color']) . '"></span>' : '';

            return $dot . esc_html(Lookup::activityName($value));
        },
    ),
    'host_ids' => array(
        'label' => __('Intervenants', $d),
        'callback' => function ($value, $row) {
            $names = Lookup::hostNames($value);

            if ($names !== '') {
                // Pastille de disponibilité devant chaque nom (dates à venir).
                if (!\Smbb\Passflow\Service\Availability::open($row)) {
                    return esc_html($names);
                }

                $availabilities = \Smbb\Passflow\Service\Availability::forSlots(array((int) $row['id']));
                $all = Lookup::intervenants();
                $out = array();

                foreach (Lookup::ids($value) as $staff_id) {
                    if (isset($all[$staff_id])) {
                        $state = \Smbb\Passflow\Service\Availability::state(isset($availabilities[(int) $row['id']][$staff_id]) ? $availabilities[(int) $row['id']][$staff_id] : null);
                        $titles = array('yes' => __('Dispo', 'smbb-passflow'), 'no' => __('Non dispo', 'smbb-passflow'), 'asked' => __('En attente de réponse', 'smbb-passflow'), 'none' => __('Dispo pas encore demandée', 'smbb-passflow'));
                        $out[] = '<span class="passflow-availability-dot passflow-availability-dot--' . esc_attr($state) . '" title="' . esc_attr($titles[$state]) . '"></span>' . esc_html(Lookup::personName($all[$staff_id]));
                    }
                }

                return implode(', ', $out);
            }

            // Un pass n'a pas d'intervenants propres ; une date annulée ou passée n'en réclame plus.
            if (\Smbb\Passflow\Service\Passes::isPass($row) || $row['status'] === 'cancelled' || Slots::isPast($row)) {
                return '<span class="passflow-muted">—</span>';
            }

            return View::noHost();
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
                $activity = Lookup::atelier($row['activity_id']);
                $value = $activity ? $activity['default_price'] : null;
            }

            return View::money($value);
        },
    ),
    'status' => array(
        'label' => __('Statut', $d),
        'sort' => true,
        'callback' => function ($value, $row) {
            return Choices::slotBadge($row);
        },
    ),
), 'start_date');

$switch = '<p class="passflow-switch"><a class="button" href="' . esc_url(View::url('passflow_calendar')) . '"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> ' . esc_html__('Voir le calendrier', $d) . '</a> '
    . '<a class="button" href="' . esc_url(View::filtered('passflow_slot', 'start_date', current_time('Y-m-d'), 'gte')) . '">' . esc_html__('À venir seulement', $d) . '</a></p>';

// Fenêtre de l'action groupée « Affecter intervenant(s)… » : elle s'ouvre à « Appliquer » et
// ajoute les intervenants cochés au formulaire de la liste (SlotHooks::assignHosts).
$choices = '';

foreach (Lookup::hostOptions() as $label => $id) {
    $choices .= '<label><input type="checkbox" value="' . esc_attr($id) . '" data-passflow-assign-id> ' . esc_html($label) . '</label>';
}

$assign = '<dialog class="smbb-ui-dialog passflow-assign-dialog" data-passflow-assign-dialog><form method="dialog" class="smbb-ui-dialog-card">'
    . '<p class="smbb-ui-dialog-title">' . esc_html__('Affecter des intervenants', $d) . '</p>'
    . '<p class="smbb-ui-dialog-text" data-passflow-assign-count data-one="' . esc_attr__('%d créneau sélectionné', $d) . '" data-many="' . esc_attr__('%d créneaux sélectionnés', $d) . '"></p>'
    . ($choices !== ''
        ? '<div class="passflow-assign-choices">' . $choices . '</div>'
            . '<fieldset class="passflow-assign-mode">'
            . '<label><input type="radio" name="passflow_assign_mode_choice" value="add" checked> ' . esc_html__('Ajouter aux intervenants déjà prévus', $d) . '</label>'
            . '<label><input type="radio" name="passflow_assign_mode_choice" value="replace"> ' . esc_html__('Remplacer les intervenants prévus', $d) . '</label>'
            . '<label><input type="radio" name="passflow_assign_mode_choice" value="remove"> ' . esc_html__('Retirer ces intervenants', $d) . '</label>'
            . '</fieldset>'
            . '<p class="description">' . esc_html__('Les nouveaux intervenants sont prévenus, comme depuis la fiche du créneau. Les créneaux annulés et les pass ne changent pas.', $d) . '</p>'
        : '<p>' . esc_html__('Aucun intervenant actif : créez-en un d\'abord.', $d) . '</p>')
    . '<p class="passflow-assign-error" data-passflow-assign-error hidden>' . esc_html__('Cochez au moins un intervenant.', $d) . '</p>'
    . '<div class="smbb-ui-dialog-actions"><button value="cancel" class="is-cancel">' . esc_html__('Annuler', $d) . '</button>'
    . ($choices !== '' ? '<button value="ok" class="is-confirm" data-passflow-assign-ok>' . esc_html__('Appliquer', $d) . '</button>' : '')
    . '</div></form></dialog>';

$assign .= <<<'JS'
<script>
(function () {
    var dialog = document.querySelector('[data-passflow-assign-dialog]');
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
        if (!form.matches || !form.matches('[data-smbb-batch-form]') || chosenAction(form) !== 'passflow_assign' || form.dataset.passflowAssignReady) { return; }
        var selected = form.querySelectorAll('[data-smbb-row-select]:checked').length;
        if (!selected) { return; } // CodeTool affiche « sélectionnez au moins un élément ».

        event.preventDefault();
        event.stopImmediatePropagation();
        var count = dialog.querySelector('[data-passflow-assign-count]');
        count.textContent = count.getAttribute(selected > 1 ? 'data-many' : 'data-one').replace('%d', selected);
        dialog.querySelector('[data-passflow-assign-error]').hidden = true;
        dialog.returnValue = '';
        dialog.showModal();

        var ok = dialog.querySelector('[data-passflow-assign-ok]');
        if (!ok) { return; }

        ok.onclick = function (e) {
            var ids = Array.prototype.map.call(dialog.querySelectorAll('[data-passflow-assign-id]:checked'), function (box) { return box.value; });
            var mode = (dialog.querySelector('[name="passflow_assign_mode_choice"]:checked') || {}).value || 'add';

            if (!ids.length && mode !== 'replace') {
                e.preventDefault();
                dialog.querySelector('[data-passflow-assign-error]').hidden = false;
                return;
            }

            form.querySelectorAll('[data-passflow-assign-input]').forEach(function (input) { input.remove(); });
            ids.forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'passflow_assign_ids[]'; input.value = id; input.setAttribute('data-passflow-assign-input', '');
                form.appendChild(input);
            });
            var modeInput = document.createElement('input');
            modeInput.type = 'hidden'; modeInput.name = 'passflow_assign_mode'; modeInput.value = mode; modeInput.setAttribute('data-passflow-assign-input', '');
            form.appendChild(modeInput);
            form.dataset.passflowAssignReady = '1';
            setTimeout(function () { form.submit(); }, 0);
        };
    }, true);
})();
</script>
JS;

View::table($table, $switch . $assign);
