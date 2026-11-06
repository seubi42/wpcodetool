<?php

namespace Smbb\SignConnectProxima\Service;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Support\Levels;

defined('ABSPATH') || exit;

/**
 * Facture proforma d'une organisation pour un mois civil (montants HT).
 *
 * Règles (spec) :
 * - un forfait par rattachement ; le forfait en vigueur s'applique à tout le mois, sans prorata ;
 * - tout mois entamé est dû (arrivée, désactivation ou suppression en cours de mois) ;
 * - les signatures incluses sont partagées entre les équipes de l'utilisateur dans l'organisation ;
 * - au-delà, recharges entières ; chaque recharge se rattache à l'équipe du document qui l'a déclenchée ;
 * - remises organisation et équipe additionnées, chacune sur sa ligne, sur l'abonnement et sur les dépassements ;
 *   pour l'abonnement d'un utilisateur multi-équipes, on prend la remise d'équipe la plus élevée ;
 * - abonnements fixes de l'équipe et de l'organisation ;
 * - tant que le mois est ouvert tout est recalculé ; la clôture fige la proforma (snapshot).
 */
final class Billing
{
    const STATUS_OPEN = 'open';
    const STATUS_CLOSED = 'closed';

    public static function validMonth($month)
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
    }

    public static function currentMonth()
    {
        return current_time('Y-m');
    }

    public static function monthBounds($month)
    {
        $start = $month . '-01 00:00:00';
        $next = gmdate('Y-m-01 00:00:00', strtotime($month . '-01 00:00:00 UTC +1 month'));

        return array($start, $next);
    }

    public static function previousMonth($month)
    {
        return gmdate('Y-m', strtotime($month . '-01 00:00:00 UTC -1 month'));
    }

    /**
     * Premier mois facturable : premier rattachement, première signature ou premier mois clôturé
     * (d'une organisation, ou de toutes avec 0). Le mois en cours s'il n'y a rien.
     */
    public static function firstMonth($organization_id = 0)
    {
        global $wpdb;

        $where = (int) $organization_id > 0 ? $wpdb->prepare(' AND organization_id = %d', (int) $organization_id) : '';
        $candidates = array(
            $wpdb->get_var('SELECT MIN(active_since) FROM ' . Lookup::table('scp_membership') . ' WHERE active_since IS NOT NULL' . $where),
            $wpdb->get_var('SELECT MIN(billing_month) FROM ' . Lookup::table('scp_document') . ' WHERE billable = 1 AND billing_month IS NOT NULL' . $where),
            $wpdb->get_var('SELECT MIN(month) FROM ' . Lookup::table('scp_billing_month') . " WHERE month <> ''" . $where),
        );
        $first = self::currentMonth();

        foreach ($candidates as $candidate) {
            $candidate = substr((string) $candidate, 0, 7);

            if (self::validMonth($candidate) && $candidate < $first) {
                $first = $candidate;
            }
        }

        return $first;
    }

    public static function monthLabel($month)
    {
        return date_i18n('F Y', strtotime($month . '-01 12:00:00'));
    }

    /**
     * Ligne de clôture du mois (ou null si le mois n'a jamais été clôturé).
     */
    public static function record($organization_id, $month)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Lookup::table('scp_billing_month') . ' WHERE organization_id = %d AND month = %s AND deleted_flag = 0',
            (int) $organization_id,
            $month
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public static function isClosed($organization_id, $month)
    {
        $record = self::record($organization_id, $month);

        return $record && $record['status'] === self::STATUS_CLOSED;
    }

    /**
     * Proforma à afficher : le snapshot si le mois est clôturé, sinon le calcul en direct.
     */
    public static function get($organization_id, $month)
    {
        $record = self::record($organization_id, $month);

        if ($record && $record['status'] === self::STATUS_CLOSED) {
            $snapshot = json_decode((string) $record['snapshot'], true);

            if (is_array($snapshot)) {
                $snapshot['status'] = self::STATUS_CLOSED;
                $snapshot['closed_at'] = $record['closed_at'];
                $snapshot['closed_by'] = (int) $record['closed_by'];
                $snapshot['late_documents'] = max(0, self::billableCount($organization_id, $month) - (int) $record['document_count']);

                return $snapshot;
            }
        }

        $invoice = self::compute($organization_id, $month);
        $invoice['status'] = self::STATUS_OPEN;

        return $invoice;
    }

    /**
     * Calcul complet, toujours en direct.
     */
    public static function compute($organization_id, $month)
    {
        global $wpdb;

        if (!self::validMonth($month)) {
            throw new \InvalidArgumentException(__('Mois invalide.', 'smbb-signaconnect-proxima'));
        }

        $organization = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_organization') . ' WHERE id = %d', (int) $organization_id), ARRAY_A);

        if (!is_array($organization)) {
            throw new \RuntimeException(__('Organisation introuvable.', 'smbb-signaconnect-proxima'));
        }

        list($start, $next) = self::monthBounds($month);
        $teams = self::indexed($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Lookup::table('scp_team') . ' WHERE organization_id = %d ORDER BY name ASC', (int) $organization_id), ARRAY_A));
        $documents = self::documents($organization_id, $month);
        $memberships = self::memberships($organization_id, $start, $next, $documents);
        $plans = self::plans($memberships);
        $org_sub_pct = (float) $organization['discount_subscription_pct'];
        $org_over_pct = (float) $organization['discount_overage_pct'];
        $lines = array();
        $usage = array();
        $warnings = array();

        // Documents rattachés à leur ligne : par membership_id, sinon par expéditeur.
        $by_membership = array();
        $by_user = array();

        foreach ($memberships as $membership) {
            $by_user[(int) $membership['user_id']] = (int) $membership['id'];
        }

        foreach ($documents as $document) {
            $membership_id = (int) $document['membership_id'];

            if (!isset($memberships[$membership_id])) {
                $membership_id = isset($by_user[(int) $document['sender_id']]) ? $by_user[(int) $document['sender_id']] : 0;
            }

            if ($membership_id < 1) {
                $warnings[] = sprintf(__('Document #%d signé sans rattachement retrouvé : il n\'est pas facturé.', 'smbb-signaconnect-proxima'), (int) $document['id']);
                continue;
            }

            $by_membership[$membership_id][$document['signature_level']][] = $document;
        }

        foreach ($memberships as $membership_id => $membership) {
            $user = self::user((int) $membership['user_id']);
            $plan = isset($plans[(int) $membership['plan_id']]) ? $plans[(int) $membership['plan_id']] : null;
            $team_ids = array_values(array_filter(array_map('intval', (array) json_decode((string) $membership['team_ids'], true))));
            $team_names = array();

            foreach ($team_ids as $team_id) {
                if (isset($teams[$team_id])) {
                    $team_names[] = (string) $teams[$team_id]['name'];
                }
            }

            $base = array(
                'user_id' => (int) $membership['user_id'],
                'user' => $user['name'],
                'email' => $user['email'],
                'team' => implode(', ', $team_names),
                'plan' => $plan ? (string) $plan['name'] : '',
            );

            if (!$plan) {
                $warnings[] = sprintf(__('%s n\'a pas de forfait : ni abonnement ni signatures incluses.', 'smbb-signaconnect-proxima'), $user['name']);
            }

            // Abonnement du forfait et ses remises.
            $fee = $plan ? (float) $plan['monthly_fee'] : 0.0;
            $lines[] = self::line($base, array(
                'kind' => 'subscription',
                'label' => __('Abonnement', 'smbb-signaconnect-proxima'),
                'detail' => $plan ? sprintf(__('Forfait %s', 'smbb-signaconnect-proxima'), $plan['name']) : __('Aucun forfait', 'smbb-signaconnect-proxima'),
                'quantity' => 1,
                'unit_price' => $fee,
                'amount' => $fee,
            ));

            if ($fee > 0) {
                if ($org_sub_pct > 0) {
                    $lines[] = self::discount($base, __('Remise organisation', 'smbb-signaconnect-proxima'), $org_sub_pct, $fee, __('sur l\'abonnement', 'smbb-signaconnect-proxima'));
                }

                $best = null;

                foreach ($team_ids as $team_id) {
                    if (isset($teams[$team_id]) && (float) $teams[$team_id]['discount_subscription_pct'] > 0
                        && ($best === null || (float) $teams[$team_id]['discount_subscription_pct'] > (float) $best['discount_subscription_pct'])) {
                        $best = $teams[$team_id];
                    }
                }

                if ($best) {
                    $lines[] = self::discount(array_merge($base, array('team' => (string) $best['name'])), sprintf(__('Remise équipe %s', 'smbb-signaconnect-proxima'), $best['name']), (float) $best['discount_subscription_pct'], $fee, __('sur l\'abonnement', 'smbb-signaconnect-proxima'));
                }
            }

            // Consommation par niveau, puis recharges par équipe déclenchante.
            $overage_by_team = array();
            $user_usage = array();

            foreach (Levels::all() as $level) {
                $docs = isset($by_membership[$membership_id][$level]) ? $by_membership[$membership_id][$level] : array();
                $included = $plan ? (int) $plan['included_' . $level] : 0;
                $price = $plan ? (float) $plan['overage_price_' . $level] : 0.0;
                $size = $plan ? max(1, (int) $plan['recharge_size_' . $level]) : 1;
                $consumed = count($docs);
                $unlimited = $plan && !empty($plan['unlimited_' . $level]);
                $overage = $unlimited ? 0 : max(0, $consumed - $included);
                $packs = (int) ceil($overage / $size);
                $user_usage[$level] = array('consumed' => $consumed, 'included' => $included, 'overage' => $overage, 'packs' => $packs, 'pack_size' => $size, 'unlimited' => $unlimited);

                if ($consumed === 0) {
                    continue;
                }

                $level_base = array_merge($base, array('level' => $level, 'included' => $unlimited ? __('illimité', 'smbb-signaconnect-proxima') : $included, 'consumed' => $consumed));

                if ($overage === 0) {
                    $lines[] = self::line($level_base, array(
                        'kind' => 'usage',
                        'label' => sprintf(__('Signatures %s', 'smbb-signaconnect-proxima'), Levels::label($level)),
                        'detail' => $unlimited
                            ? sprintf(_n('%d signée, illimité', '%d signées, illimité', $consumed, 'smbb-signaconnect-proxima'), $consumed)
                            : sprintf(__('%1$d signées sur %2$d incluses', 'smbb-signaconnect-proxima'), $consumed, $included),
                        'quantity' => $consumed,
                        'unit_price' => 0,
                        'amount' => 0,
                        'overage' => 0,
                    ));
                    continue;
                }

                // Recharge k : couvre les dépassements k*size … k*size+size-1, déclenchée par le premier.
                $extra = array_slice($docs, $included);
                $groups = array();

                for ($k = 0; $k < $packs; $k++) {
                    $trigger = $extra[$k * $size];
                    $team_id = (int) $trigger['team_id'];

                    if (!isset($groups[$team_id])) {
                        $groups[$team_id] = array('packs' => 0, 'overage' => 0);
                    }

                    $groups[$team_id]['packs']++;
                    $groups[$team_id]['overage'] += count(array_slice($extra, $k * $size, $size));
                }

                foreach ($groups as $team_id => $group) {
                    $team_name = isset($teams[$team_id]) ? (string) $teams[$team_id]['name'] : '';
                    $amount = round($group['packs'] * $size * $price, 2);
                    $detail = sprintf(
                        _n('%1$d signées, %2$d incluses, donc %3$d en trop : %4$d recharge de %5$d × %6$s', '%1$d signées, %2$d incluses, donc %3$d en trop : %4$d recharges de %5$d × %6$s', $group['packs'], 'smbb-signaconnect-proxima'),
                        $consumed,
                        $included,
                        $overage,
                        $group['packs'],
                        $size,
                        self::money($price)
                    );

                    if (count($groups) > 1) {
                        $detail .= ' — ' . sprintf(__('équipe %s', 'smbb-signaconnect-proxima'), $team_name);
                    }

                    $lines[] = self::line(array_merge($level_base, array('team' => $team_name)), array(
                        'kind' => 'recharge',
                        'label' => sprintf(__('Dépassement %s', 'smbb-signaconnect-proxima'), Levels::label($level)),
                        'detail' => $detail,
                        'overage' => $group['overage'],
                        'packs' => $group['packs'],
                        'pack_size' => $size,
                        'quantity' => $group['packs'] * $size,
                        'unit_price' => $price,
                        'amount' => $amount,
                    ));

                    if (!isset($overage_by_team[$team_id])) {
                        $overage_by_team[$team_id] = 0.0;
                    }

                    $overage_by_team[$team_id] += $amount;
                }
            }

            $overage_total = array_sum($overage_by_team);

            if ($overage_total > 0 && $org_over_pct > 0) {
                $lines[] = self::discount($base, __('Remise organisation', 'smbb-signaconnect-proxima'), $org_over_pct, $overage_total, __('sur les dépassements', 'smbb-signaconnect-proxima'));
            }

            foreach ($overage_by_team as $team_id => $amount) {
                if ($amount > 0 && isset($teams[$team_id]) && (float) $teams[$team_id]['discount_overage_pct'] > 0) {
                    $lines[] = self::discount(array_merge($base, array('team' => (string) $teams[$team_id]['name'])), sprintf(__('Remise équipe %s', 'smbb-signaconnect-proxima'), $teams[$team_id]['name']), (float) $teams[$team_id]['discount_overage_pct'], $amount, __('sur les dépassements', 'smbb-signaconnect-proxima'));
                }
            }

            $usage[] = array_merge($base, array('membership_id' => (int) $membership_id, 'levels' => $user_usage));
        }

        // Abonnements fixes en tête : organisation, puis équipes actives, puis les forfaits utilisateurs.
        $fixed = array();

        if ((float) $organization['fixed_monthly_fee'] > 0) {
            $fixed[] = self::line(array(), array(
                'kind' => 'org_fee',
                'label' => __('Organisation', 'smbb-signaconnect-proxima'),
                'detail' => __('abonnement fixe', 'smbb-signaconnect-proxima'),
                'quantity' => 1,
                'unit_price' => (float) $organization['fixed_monthly_fee'],
                'amount' => (float) $organization['fixed_monthly_fee'],
            ));
        }

        foreach ($teams as $team) {
            if ((int) $team['deleted_flag'] === 0 && $team['status'] === 'active' && (float) $team['fixed_monthly_fee'] > 0) {
                $fixed[] = self::line(array('team' => (string) $team['name']), array(
                    'kind' => 'team_fee',
                    'label' => sprintf(__('Équipe « %s »', 'smbb-signaconnect-proxima'), $team['name']),
                    'detail' => __('abonnement fixe', 'smbb-signaconnect-proxima'),
                    'quantity' => 1,
                    'unit_price' => (float) $team['fixed_monthly_fee'],
                    'amount' => (float) $team['fixed_monthly_fee'],
                ));
            }
        }

        $lines = array_merge($fixed, $lines);
        $total = 0.0;

        foreach ($lines as $line) {
            $total += $line['amount'];
        }

        return array(
            'version' => 1,
            'month' => $month,
            'organization' => array(
                'id' => (int) $organization['id'],
                'name' => (string) $organization['name'],
                'customer_reference' => (string) $organization['customer_reference'],
                'billing_company_name' => (string) $organization['billing_company_name'],
                'billing_address' => (string) $organization['billing_address'],
                'billing_siret' => (string) $organization['billing_siret'],
                'billing_vat_number' => (string) $organization['billing_vat_number'],
                'billing_email' => (string) $organization['billing_email'],
            ),
            'lines' => $lines,
            'usage' => $usage,
            'total' => round($total, 2),
            'document_count' => count($documents),
            'user_count' => count($memberships),
            'warnings' => $warnings,
            'computed_at' => current_time('mysql'),
        );
    }

    /**
     * Clôture : fige la proforma telle que calculée maintenant.
     */
    public static function close($organization_id, $month)
    {
        global $wpdb;

        if (!self::validMonth($month) || $month >= self::currentMonth()) {
            throw new \RuntimeException(__('Seul un mois terminé peut être clôturé.', 'smbb-signaconnect-proxima'));
        }

        if (self::isClosed($organization_id, $month)) {
            throw new \RuntimeException(__('Ce mois est déjà clôturé.', 'smbb-signaconnect-proxima'));
        }

        $invoice = self::compute($organization_id, $month);
        $data = array(
            'status' => self::STATUS_CLOSED,
            'total_ht' => $invoice['total'],
            'document_count' => $invoice['document_count'],
            'snapshot' => wp_json_encode($invoice),
            'closed_at' => current_time('mysql'),
            'closed_by' => get_current_user_id() ?: null,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => get_current_user_id() ?: null,
        );
        $record = self::record($organization_id, $month);

        if ($record) {
            $ok = $wpdb->update(Lookup::table('scp_billing_month'), $data, array('id' => (int) $record['id']));
        } else {
            $ok = $wpdb->insert(Lookup::table('scp_billing_month'), array_merge($data, array(
                'organization_id' => (int) $organization_id,
                'month' => $month,
                'creation_date' => current_time('mysql'),
                'creation_by' => get_current_user_id() ?: null,
            )));
        }

        if ($ok === false) {
            throw new \RuntimeException(sprintf(__('Erreur base de données : %s', 'smbb-signaconnect-proxima'), $wpdb->last_error ?: '?'));
        }

        AuditLog::record('billing_month_closed', sprintf(__('Mois %1$s clôturé : %2$s.', 'smbb-signaconnect-proxima'), $month, self::money($invoice['total'])), array(
            'month' => $month,
            'total_ht' => $invoice['total'],
            'documents' => $invoice['document_count'],
        ), (int) $organization_id, null, get_current_user_id() ? 'user' : 'system', get_current_user_id() ?: null);

        return $invoice;
    }

    /**
     * Réouverture (correction) : le mois repart en calcul direct.
     */
    public static function reopen($organization_id, $month)
    {
        global $wpdb;

        $record = self::record($organization_id, $month);

        if (!$record || $record['status'] !== self::STATUS_CLOSED) {
            throw new \RuntimeException(__("Ce mois n'est pas clôturé.", 'smbb-signaconnect-proxima'));
        }

        $wpdb->update(Lookup::table('scp_billing_month'), array(
            'status' => self::STATUS_OPEN,
            'snapshot' => null,
            'closed_at' => null,
            'closed_by' => null,
            'lastupdate_date' => current_time('mysql'),
            'lastupdate_by' => get_current_user_id() ?: null,
        ), array('id' => (int) $record['id']));

        AuditLog::record('billing_month_reopened', sprintf(__('Mois %1$s rouvert (il était clôturé à %2$s).', 'smbb-signaconnect-proxima'), $month, self::money($record['total_ht'])), array(
            'month' => $month,
            'previous_total_ht' => (float) $record['total_ht'],
        ), (int) $organization_id, null, 'user', get_current_user_id() ?: null);
    }

    /**
     * Clôture automatique du mois précédent, à partir du jour réglé (0 = manuelle).
     */
    public static function autoClose()
    {
        $settings = get_option('smbb_scp_settings', array());
        $day = is_array($settings) && isset($settings['billing_auto_close_day']) ? (int) $settings['billing_auto_close_day'] : 0;

        if ($day < 1 || (int) current_time('j') < $day) {
            return;
        }

        $month = self::previousMonth(self::currentMonth());

        foreach (array_keys(Lookup::organizations()) as $organization_id) {
            if (!self::isClosed($organization_id, $month)) {
                self::close($organization_id, $month);
            }
        }
    }

    /**
     * Export CSV (séparateur « ; », décimales à virgule, UTF-8 avec BOM : s'ouvre tel quel dans Excel).
     */
    public static function csv(array $invoice)
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('Mois', 'Organisation', 'Référence client', 'Équipe', 'Utilisateur', 'Email', 'Poste', 'Détail', 'Forfait', 'Niveau',
            'Signatures incluses', 'Signatures consommées', 'Dépassement', 'Nombre de recharges', 'Taille de recharge', 'Prix unitaire HT', 'Montant HT'), ';');

        $posts = self::kinds();

        foreach ($invoice['lines'] as $line) {
            fputcsv($out, array(
                $invoice['month'],
                $invoice['organization']['name'],
                $invoice['organization']['customer_reference'],
                $line['team'],
                $line['user'],
                $line['email'],
                isset($posts[$line['kind']]) ? $posts[$line['kind']] : $line['kind'],
                $line['label'] . ($line['detail'] !== '' ? ' — ' . $line['detail'] : ''),
                $line['plan'],
                $line['level'] !== '' ? Levels::label($line['level']) : '',
                $line['included'],
                $line['consumed'],
                $line['overage'],
                $line['packs'],
                $line['pack_size'],
                self::decimal($line['unit_price']),
                self::decimal($line['amount']),
            ), ';');
        }

        fputcsv($out, array($invoice['month'], $invoice['organization']['name'], $invoice['organization']['customer_reference'], '', '', '', 'Total', '', '', '', '', '', '', '', '', '', self::decimal($invoice['total'])), ';');
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public static function kinds()
    {
        return array(
            'subscription' => __('Forfait', 'smbb-signaconnect-proxima'),
            'usage' => __('Consommation incluse', 'smbb-signaconnect-proxima'),
            'recharge' => __('Recharge', 'smbb-signaconnect-proxima'),
            'discount' => __('Remise', 'smbb-signaconnect-proxima'),
            'team_fee' => __("Abonnement d'équipe", 'smbb-signaconnect-proxima'),
            'org_fee' => __("Abonnement d'organisation", 'smbb-signaconnect-proxima'),
        );
    }

    public static function money($value)
    {
        return number_format((float) $value, 2, ',', ' ') . ' €';
    }

    private static function decimal($value)
    {
        return number_format((float) $value, 2, ',', '');
    }

    public static function billableCount($organization_id, $month)
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Lookup::table('scp_document') . ' WHERE organization_id = %d AND billable = 1 AND billing_month = %s',
            (int) $organization_id,
            $month
        ));
    }

    /**
     * Signatures facturables du mois (même purgées ou anonymisées : la ligne reste), dans l'ordre de signature.
     */
    private static function documents($organization_id, $month)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, membership_id, sender_id, team_id, signature_level, signed_at FROM ' . Lookup::table('scp_document')
            . ' WHERE organization_id = %d AND billable = 1 AND billing_month = %s ORDER BY signed_at ASC, id ASC',
            (int) $organization_id,
            $month
        ), ARRAY_A);

        $documents = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $row['signature_level'] = Levels::normalize($row['signature_level']);
            $documents[] = $row;
        }

        return $documents;
    }

    /**
     * Rattachements facturés : actifs pendant au moins une partie du mois, plus ceux qui ont signé ce mois-là.
     */
    private static function memberships($organization_id, $start, $next, array $documents)
    {
        global $wpdb;

        $table = Lookup::table('scp_membership');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE organization_id = %d"
            . ' AND (active_since IS NULL OR active_since < %s)'
            . ' AND (is_active = 1 OR (inactive_since IS NOT NULL AND inactive_since >= %s))'
            . ' AND (deleted_flag = 0 OR (deleted_date IS NOT NULL AND deleted_date >= %s))',
            (int) $organization_id,
            $next,
            $start,
            $start
        ), ARRAY_A);

        $memberships = self::indexed($rows);
        $missing = array();
        $senders = array();

        foreach ($documents as $document) {
            if ((int) $document['membership_id'] > 0 && !isset($memberships[(int) $document['membership_id']])) {
                $missing[] = (int) $document['membership_id'];
            }

            $senders[(int) $document['sender_id']] = true;
        }

        if ($missing) {
            $extra = $wpdb->get_results("SELECT * FROM {$table} WHERE id IN (" . implode(',', array_map('intval', array_unique($missing))) . ')', ARRAY_A);
            $memberships += self::indexed($extra);
        }

        uasort($memberships, function ($a, $b) {
            return strcasecmp(self::user((int) $a['user_id'])['name'], self::user((int) $b['user_id'])['name']);
        });

        return $memberships;
    }

    private static function plans(array $memberships)
    {
        global $wpdb;

        $ids = array();

        foreach ($memberships as $membership) {
            if ((int) $membership['plan_id'] > 0) {
                $ids[] = (int) $membership['plan_id'];
            }
        }

        if (!$ids) {
            return array();
        }

        return self::indexed($wpdb->get_results('SELECT * FROM ' . Lookup::table('scp_plan') . ' WHERE id IN (' . implode(',', array_unique($ids)) . ')', ARRAY_A));
    }

    private static function user($user_id)
    {
        static $cache = array();

        if (!isset($cache[$user_id])) {
            $user = $user_id ? get_userdata($user_id) : false;
            $name = $user ? trim($user->first_name . ' ' . $user->last_name) : '';
            $cache[$user_id] = array(
                'name' => $user ? ($name !== '' ? $name : (string) $user->display_name) : sprintf(__('Utilisateur #%d', 'smbb-signaconnect-proxima'), $user_id),
                'email' => $user ? (string) $user->user_email : '',
            );
        }

        return $cache[$user_id];
    }

    private static function line(array $base, array $values)
    {
        $line = array_merge(array(
            'kind' => '',
            'user_id' => 0,
            'user' => '',
            'email' => '',
            'team' => '',
            'plan' => '',
            'level' => '',
            'label' => '',
            'detail' => '',
            'included' => '',
            'consumed' => '',
            'overage' => '',
            'packs' => '',
            'pack_size' => '',
            'quantity' => 1,
            'unit_price' => 0.0,
            'amount' => 0.0,
        ), $base, $values);
        $line['unit_price'] = round((float) $line['unit_price'], 2);
        $line['amount'] = round((float) $line['amount'], 2);

        return $line;
    }

    private static function discount(array $base, $label, $pct, $on, $suffix)
    {
        return self::line($base, array(
            'kind' => 'discount',
            'label' => sprintf('%1$s −%2$s %%', $label, rtrim(rtrim(number_format((float) $pct, 2, ',', ''), '0'), ',')),
            'detail' => sprintf('%1$s (%2$s)', $suffix, self::money($on)),
            'quantity' => 1,
            'unit_price' => -round($on * $pct / 100, 2),
            'amount' => -round($on * $pct / 100, 2),
        ));
    }

    private static function indexed($rows)
    {
        $indexed = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $indexed[(int) $row['id']] = $row;
        }

        return $indexed;
    }
}
