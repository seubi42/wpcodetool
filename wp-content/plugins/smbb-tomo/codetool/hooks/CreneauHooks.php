<?php

namespace Smbb\Tomo\CodeTool;

use Smbb\Tomo\Repository\Creneaux;
use Smbb\Tomo\Repository\Lookup;
use Smbb\Tomo\Service\Capacity;
use Smbb\Tomo\Support\Choices;
use Smbb\Tomo\Support\Input;

defined('ABSPATH') || exit;

final class CreneauHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        $action = isset($context['action']) ? $context['action'] : '';
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('start_date', 'internal_note'));
        $data = Input::decimal($data, array('price'));
        $data = Input::integer($data, array('atelier_id', 'capacity'));
        // Le formulaire envoie les cases cochées ; aucune case cochée = aucun intervenant.
        $data = Input::idList($data, 'intervenant_ids', $action !== 'duplicate');

        foreach (array('start_time', 'end_time') as $key) {
            if (isset($data[$key])) {
                $data[$key] = Input::time($data[$key]);
            }
        }

        // Toute la journée : pas d'heure affichée ; début à 7h si laissé vide (rappels, tri), pas de fin.
        if (array_key_exists('start_date', $data) || array_key_exists('all_day', $data)) {
            $data['all_day'] = !empty($data['all_day']) && $data['all_day'] !== '0' ? '1' : '0';
        }

        $all_day = isset($data['all_day']) ? $data['all_day'] === '1' : !empty($current['all_day']);

        if ($all_day) {
            if (array_key_exists('start_time', $data) && empty($data['start_time'])) {
                $data['start_time'] = '07:00:00';
            }

            $data['end_time'] = null;
        }

        // Pass plusieurs jours : dates prises sur ses jours (le premier fixe la date de vente).
        $kind = isset($data['kind']) ? $data['kind'] : (isset($current['kind']) ? $current['kind'] : 'day');
        $data['kind'] = $kind === 'pass' ? 'pass' : 'day';

        if ($data['kind'] === 'pass') {
            $data = Input::idList($data, 'pass_creneau_ids', true);
            $days = array();

            foreach ($data['pass_creneau_ids'] as $day_id) {
                $day = Creneaux::find($day_id);

                if ($day && $day['kind'] !== 'pass') {
                    $days[] = $day;
                }
            }

            usort($days, function ($a, $b) {
                return strcmp($a['start_date'] . $a['start_time'], $b['start_date'] . $b['start_time']);
            });

            $data['pass_creneau_ids'] = array_map(function ($day) {
                return (string) (int) $day['id'];
            }, $days);

            if ($days) {
                $data['start_date'] = $days[0]['start_date'];
                $data['start_time'] = $days[0]['start_time'];
                $data['end_time'] = $days[0]['end_time'];
                $data['all_day'] = !empty($days[0]['all_day']) ? '1' : '0';
            }

            $data['capacity'] = isset($data['capacity']) && $data['capacity'] !== '' && $data['capacity'] !== null ? (string) max(0, (int) $data['capacity']) : '0';
        } elseif (array_key_exists('kind', $data)) {
            $data['pass_creneau_ids'] = array();
        }

        // Nouveau créneau : places reprises de l'atelier si non saisies.
        if ($data['kind'] !== 'pass' && empty($current) && (!isset($data['capacity']) || $data['capacity'] === null || $data['capacity'] === '') && !empty($data['atelier_id'])) {
            $atelier = Lookup::atelier($data['atelier_id']);
            $data['capacity'] = $atelier && $atelier['default_capacity'] !== null ? (string) (int) $atelier['default_capacity'] : null;
        }

        // Une copie repart en vente (ou en brouillon) et sans rappels envoyés.
        if ($action === 'duplicate') {
            $data['status'] = in_array(isset($data['status']) ? $data['status'] : '', array('open', 'full'), true) ? 'open' : 'draft';
            $data['reminders_sent'] = null;
            $data['cancel_reason'] = null;
        }

        // « Complet » n'est pas proposé dans le formulaire : on garde l'état calculé.
        if (!empty($current) && isset($data['status']) && $data['status'] === 'open' && $current['status'] === 'full') {
            $data['status'] = 'full';
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        if (empty($data['atelier_id']) || !Lookup::atelier($data['atelier_id'])) {
            $errors['atelier_id'] = __('Choisissez un atelier.', 'smbb-tomo');
        }

        if (array_key_exists('start_date', $data) && !Input::isDate((string) $data['start_date'])) {
            $errors['start_date'] = __('Date invalide.', 'smbb-tomo');
        }

        if (array_key_exists('start_time', $data) && $data['start_time'] === '') {
            $errors['start_time'] = __('Heure de début invalide.', 'smbb-tomo');
        }

        if (!empty($data['end_time']) && !empty($data['start_time']) && $data['end_time'] <= $data['start_time']) {
            $errors['end_time'] = __("L'heure de fin doit être après l'heure de début.", 'smbb-tomo');
        }

        $is_pass = isset($data['kind']) && $data['kind'] === 'pass';

        if ($is_pass) {
            if (empty($data['pass_creneau_ids']) || count($data['pass_creneau_ids']) < 2) {
                $errors['pass_creneau_ids'] = __('Un pass regroupe au moins deux dates : cochez-les.', 'smbb-tomo');
            }

            if (!empty($context['id']) && !empty($data['pass_creneau_ids']) && in_array((string) (int) $context['id'], $data['pass_creneau_ids'], true)) {
                $errors['pass_creneau_ids'] = __('Un pass ne peut pas se contenir lui-même.', 'smbb-tomo');
            }
        } elseif (!empty($current) && isset($current['kind']) && $current['kind'] === 'pass' && Capacity::booked($context['id']) > 0) {
            $errors['kind'] = __('Ce pass a des inscrits : il ne peut plus devenir une date simple.', 'smbb-tomo');
        }

        if ($is_pass) {
            // Places du pass : facultatives (0 = limitées par les jours).
        } elseif (!isset($data['capacity']) || $data['capacity'] === null || !is_numeric($data['capacity']) || (int) $data['capacity'] < 1) {
            $errors['capacity'] = __('Indiquez le nombre de places en vente (au moins 1).', 'smbb-tomo');
        } elseif (!empty($context['id'])) {
            $booked = Capacity::booked($context['id']);

            if ((int) $data['capacity'] < $booked) {
                $errors['capacity'] = sprintf(_n("Il y a déjà %d inscrit : les places ne peuvent pas descendre en dessous.", "Il y a déjà %d inscrits : les places ne peuvent pas descendre en dessous.", $booked, 'smbb-tomo'), $booked);
            }
        }

        if (isset($data['price']) && $data['price'] !== null && (!is_numeric($data['price']) || (float) $data['price'] < 0)) {
            $errors['price'] = __('Prix invalide.', 'smbb-tomo');
        }

        if (isset($data['status'])) {
            $was = isset($current['status']) ? $current['status'] : '';

            if (!array_key_exists($data['status'], Choices::creneauStatuses())) {
                $errors['status'] = __('Statut inconnu.', 'smbb-tomo');
            } elseif ($data['status'] === 'cancelled' && $was !== 'cancelled') {
                $errors['status'] = __("Pour annuler un créneau, utilisez le bouton « Annuler le créneau » de sa fiche : ses réservations passeront « à confirmer » en attendant une nouvelle date.", 'smbb-tomo');
            } elseif ($was === 'cancelled' && $data['status'] !== 'cancelled') {
                $errors['status'] = __('Un créneau annulé ne peut pas être remis en vente : dupliquez-le.', 'smbb-tomo');
            }
        }

        return $errors;
    }

    public function afterSave(array $row, array $context = array())
    {
        global $wpdb;

        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        // Changement d'atelier : les inscriptions suivent.
        if (!empty($current) && (int) $current['atelier_id'] !== (int) $row['atelier_id']) {
            $wpdb->update(Lookup::table('tomo_inscription'), array('atelier_id' => (int) $row['atelier_id']), array('creneau_id' => (int) $row['id']));
        }

        // Jours d'un pass modifiés : les réservations du pass suivent (jours retirés annulés, ajoutés créés).
        if (\Smbb\Tomo\Service\Passes::isPass($row) && !empty($current) && Lookup::ids($current['pass_creneau_ids']) !== Lookup::ids($row['pass_creneau_ids'])) {
            foreach (\Smbb\Tomo\Repository\Inscriptions::forCreneau($row['id']) as $mother) {
                \Smbb\Tomo\Service\Passes::cascade($mother['id'], array('creneau_id' => (int) $row['id']));
            }

            foreach (array_unique(array_merge(Lookup::ids($current['pass_creneau_ids']), Lookup::ids($row['pass_creneau_ids']))) as $day_id) {
                Capacity::refresh($day_id);
            }
        }

        Capacity::refresh($row['id']);

        // Nouveaux intervenants sur ce créneau : ils sont prévenus.
        $before = !empty($current) ? Lookup::ids($current['intervenant_ids']) : array();
        $added = array_values(array_diff(Lookup::ids($row['intervenant_ids']), $before));

        if ($added && $row['status'] !== 'cancelled') {
            do_action('smbb_tomo_intervenants_assigned', (int) $row['id'], $added);
        }
    }

    public function beforeDelete(array $row, array $context = array())
    {
        if (Capacity::booked($row['id']) > 0) {
            return __('Ce créneau a des inscrits : annulez-le depuis sa fiche plutôt que de le supprimer.', 'smbb-tomo');
        }

        return true;
    }

    /**
     * Action groupée « Mettre en vente » (déclarée dans tomo-creneau.json, admin.list.batchActions).
     * Un créneau sans place libre passe « complet ».
     */
    public function openCreneaux(array $ids, array $context = array())
    {
        unset($context);
        $result = $this->setStatus($ids, 'open');
        $message = sprintf(_n('%d créneau mis en vente.', '%d créneaux mis en vente.', $result['changed'], 'smbb-tomo'), $result['changed']);

        return $this->batchResult($message, $result);
    }

    /**
     * Action groupée « Repasser en brouillon ».
     */
    public function draftCreneaux(array $ids, array $context = array())
    {
        unset($context);
        $result = $this->setStatus($ids, 'draft');
        $message = sprintf(_n('%d créneau repassé en brouillon.', '%d créneaux repassés en brouillon.', $result['changed'], 'smbb-tomo'), $result['changed']);

        return $this->batchResult($message, $result);
    }

    /**
     * Action groupée « Affecter intervenant(s)… » : la fenêtre de la liste (views/tomo-creneau/list.php)
     * ajoute au formulaire tomo_assign_ids[] et tomo_assign_mode (add, replace ou remove).
     * Les nouveaux intervenants sont prévenus comme depuis la fiche du créneau.
     */
    public function assignIntervenants(array $ids, array $context = array())
    {
        global $wpdb;

        unset($context);
        $d = 'smbb-tomo';
        $mode = isset($_POST['tomo_assign_mode']) ? sanitize_key(wp_unslash($_POST['tomo_assign_mode'])) : '';
        $chosen = isset($_POST['tomo_assign_ids']) ? array_map('absint', (array) wp_unslash($_POST['tomo_assign_ids'])) : array();
        $known = Lookup::intervenants();
        $chosen = array_values(array_unique(array_filter($chosen, function ($id) use ($known) {
            return isset($known[$id]);
        })));

        if (!in_array($mode, array('add', 'replace', 'remove'), true)) {
            return array('success' => false, 'message' => __('Choisissez les intervenants dans la fenêtre qui s\'ouvre après « Appliquer ».', $d), 'count' => 0);
        }

        if (!$chosen && $mode !== 'replace') {
            return array('success' => false, 'message' => __('Cochez au moins un intervenant.', $d), 'count' => 0);
        }

        $changed = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $creneau = Creneaux::find((int) $id);

            // Un pass n'a pas d'intervenants propres (ce sont ceux de ses jours) ; un créneau annulé reste tel quel.
            if (!$creneau || $creneau['status'] === 'cancelled' || \Smbb\Tomo\Service\Passes::isPass($creneau)) {
                $skipped++;
                continue;
            }

            $before = Lookup::ids($creneau['intervenant_ids']);

            if ($mode === 'replace') {
                $after = $chosen;
            } elseif ($mode === 'add') {
                $after = array_values(array_unique(array_merge($before, $chosen)));
            } else {
                $after = array_values(array_diff($before, $chosen));
            }

            sort($after);
            $sorted_before = $before;
            sort($sorted_before);

            if ($after === $sorted_before) {
                continue;
            }

            $wpdb->update(Lookup::table('tomo_creneau'), array(
                'intervenant_ids' => wp_json_encode(array_map('intval', $after)),
                'lastupdate_date' => current_time('mysql'),
                'lastupdate_by' => get_current_user_id() ?: null,
            ), array('id' => (int) $id));
            $changed++;

            $added = array_values(array_diff($after, $before));

            if ($added) {
                do_action('smbb_tomo_intervenants_assigned', (int) $id, $added);
            }
        }

        $message = sprintf(_n('%d créneau mis à jour.', '%d créneaux mis à jour.', $changed, $d), $changed);

        if ($skipped) {
            $message .= ' ' . sprintf(_n('%d créneau ignoré (annulé ou pass).', '%d créneaux ignorés (annulés ou pass).', $skipped, $d), $skipped);
        }

        return array('success' => true, 'message' => $message, 'count' => $changed);
    }

    /**
     * Les créneaux annulés ne bougent pas : l'annulation a déjà retiré leurs réservations.
     *
     * @return array{changed:int,skipped:int}
     */
    private function setStatus(array $ids, $target)
    {
        global $wpdb;

        $changed = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $creneau = Creneaux::find((int) $id);

            if (!$creneau || $creneau['status'] === 'cancelled') {
                $skipped++;
                continue;
            }

            $status = $target === 'open' && (int) $creneau['remaining'] < 1 ? 'full' : $target;

            if ($status === $creneau['status']) {
                continue;
            }

            $wpdb->update(Lookup::table('tomo_creneau'), array(
                'status' => $status,
                'lastupdate_date' => current_time('mysql'),
                'lastupdate_by' => get_current_user_id() ?: null,
            ), array('id' => (int) $id));
            $changed++;
        }

        return array('changed' => $changed, 'skipped' => $skipped);
    }

    private function batchResult($message, array $result)
    {
        if ($result['skipped']) {
            $message .= ' ' . sprintf(_n('%d créneau annulé laissé tel quel.', '%d créneaux annulés laissés tels quels.', $result['skipped'], 'smbb-tomo'), $result['skipped']);
        }

        return array('success' => true, 'message' => $message, 'count' => $result['changed']);
    }
}
