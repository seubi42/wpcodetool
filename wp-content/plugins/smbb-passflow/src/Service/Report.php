<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Bookings;
use Smbb\Passflow\Repository\Deliveries;
use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Support\Choices;
use Smbb\Passflow\WooCommerce\Module;
use Smbb\Passflow\WooCommerce\OnsiteSales;

defined('ABSPATH') || exit;

/**
 * Bilan d'un créneau après l'atelier : présences, ventes sur place, documents, avis et messages.
 * Mêmes chiffres pour l'écran (vue CodeTool « report » du créneau) et pour l'export CSV.
 */
final class Report
{
    /**
     * @param array<string,mixed> $slot
     * @return array<string,mixed>
     */
    public static function data(array $slot)
    {
        $id = (int) $slot['id'];
        $all = Bookings::forSlot($id, true);
        $seats = array();
        $cancelled = 0;

        foreach ($all as $b) {
            if (in_array($b['status'], Choices::seatStatuses(), true)) {
                $seats[] = $b;
            } else {
                $cancelled++;
            }
        }

        $presence = array('booked' => count($seats), 'present' => 0, 'absent' => 0, 'unpointed' => 0, 'walkin' => 0, 'cancelled' => $cancelled, 'revenue' => 0.0);

        foreach ($seats as $b) {
            $presence['present'] += $b['status'] === 'present' ? 1 : 0;
            $presence['absent'] += $b['status'] === 'absent' ? 1 : 0;
            $presence['unpointed'] += in_array($b['status'], array('pending', 'confirmed'), true) ? 1 : 0;
            $presence['walkin'] += $b['source'] === 'walkin' ? 1 : 0;
            $presence['revenue'] += (float) $b['price_paid'];
        }

        $presence['rate'] = $presence['booked'] > 0 ? (int) round($presence['present'] * 100 / $presence['booked']) : 0;

        // Documents : un compteur par document, et la liste des personnes à régulariser.
        $docs = Documents::all();
        $answers = $docs ? Documents::answers(array_column($seats, 'id')) : array();
        $doc_stats = array();
        $missing = array();

        foreach ($docs as $key => $doc) {
            $doc_stats[$key] = array('title' => $doc['title'], 'required' => $doc['required'], 'ok' => 0, 'no' => 0, 'todo' => 0);
        }

        foreach ($seats as $b) {
            $mine = isset($answers[(int) $b['id']]) ? $answers[(int) $b['id']] : array();

            foreach ($docs as $key => $doc) {
                $state = Documents::status($doc, isset($mine[$key]) ? $mine[$key] : null)['state'];
                $doc_stats[$key][$state]++;
            }

            // Les absents ne sont pas à relancer pour leurs documents.
            if ($docs && $b['status'] !== 'absent') {
                $titles = array_values(array_map(function ($doc) {
                    return $doc['title'];
                }, Documents::missing($mine)));

                if ($titles) {
                    $missing[] = array('booking' => $b, 'titles' => $titles);
                }
            }
        }

        // Ventes sur place (caisse) : commandes WooCommerce rattachées à la date.
        $orders = Module::active() ? OnsiteSales::forSlot($id) : array();
        $sales = array();

        foreach ($orders as $order) {
            $lines = array();

            foreach ($order->get_items() as $item) {
                $lines[] = sprintf('%d × %s', (int) $item->get_quantity(), $item->get_name());
            }

            $sales[] = array(
                'id' => (int) $order->get_id(),
                'number' => (string) $order->get_order_number(),
                'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i:s') : '',
                'customer' => trim($order->get_formatted_billing_full_name()) ?: (string) $order->get_billing_email(),
                'lines' => $lines,
                'total' => (float) $order->get_total() - (float) $order->get_total_refunded(),
                'method' => self::methodLabel($order),
                'status' => wc_get_order_status_name($order->get_status()),
                'paid' => $order->is_paid(),
                'url' => $order->get_edit_order_url(),
            );
        }

        // Messages envoyés pour la date, regroupés par type et canal.
        $messages = array();
        $message_totals = array('sent' => 0, 'failed' => 0, 'skipped' => 0, 'sms' => 0, 'email' => 0);

        foreach (Deliveries::forSlot($id, 5000) as $row) {
            $key = $row['event'] . '|' . $row['channel'];

            if (!isset($messages[$key])) {
                $messages[$key] = array('event' => Choices::label('deliveryEvents', $row['event']), 'channel' => $row['channel'], 'sent' => 0, 'failed' => 0, 'skipped' => 0);
            }

            $state = in_array($row['status'], array('sent', 'test'), true) ? 'sent' : ($row['status'] === 'failed' ? 'failed' : 'skipped');
            $messages[$key][$state]++;
            $message_totals[$state]++;

            if ($state === 'sent' && isset($message_totals[$row['channel']])) {
                $message_totals[$row['channel']]++;
            }
        }

        $hosts = array();
        $all_hosts = Lookup::intervenants();

        foreach (Lookup::ids($slot['host_ids']) as $host_id) {
            if (isset($all_hosts[$host_id])) {
                $hosts[] = Lookup::personName($all_hosts[$host_id]);
            }
        }

        return array(
            'slot' => $slot,
            'hosts' => $hosts,
            'seats' => $seats,
            'answers' => $answers,
            'presence' => $presence,
            'docs' => $doc_stats,
            'missing' => $missing,
            'cashdesk' => (bool) $orders,
            'sales' => $sales,
            'sales_summary' => OnsiteSales::summary($orders),
            'messages' => array_values($messages),
            'message_totals' => $message_totals,
            'broadcasts' => count(Broadcasts::forSlot($id, 500)),
            'reviews' => Reviews::stats($slot, $seats),
        );
    }

    /**
     * Moyen de paiement lisible (même règle que le résumé de caisse).
     *
     * @param \WC_Order $order
     */
    private static function methodLabel($order)
    {
        $labels = OnsiteSales::methods();
        $method = (string) $order->get_meta(OnsiteSales::META_METHOD);

        if (isset($labels[$method])) {
            return $labels[$method];
        }

        return $order->get_payment_method() === OnsiteSales::GATEWAY ? __('Sur place', 'smbb-passflow') : __('Paiement en ligne (lien)', 'smbb-passflow');
    }

    /**
     * Bilan en CSV (« ; » et BOM pour Excel) : une partie par bloc, séparées par une ligne vide.
     */
    public static function csv(array $slot)
    {
        $d = 'smbb-passflow';
        $r = self::data($slot);
        $p = $r['presence'];
        $filename = sanitize_file_name('bilan-' . sanitize_title(Lookup::activityName($slot['activity_id'])) . '-' . $slot['start_date'] . '.csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        $put = function (array $row) use ($out) {
            fputcsv($out, $row, ';');
        };
        $money = function ($amount) {
            return number_format((float) $amount, 2, ',', '');
        };

        fwrite($out, "\xEF\xBB\xBF");
        $put(array(__('Bilan', $d), Lookup::activityName($slot['activity_id']), Lookup::slotWhen($slot)));
        $put(array(__('Intervenants', $d), implode(', ', $r['hosts'])));
        $put(array());

        $put(array(__('Présence', $d)));
        $put(array(__('Inscrits', $d), $p['booked']));
        $put(array(__('Présents', $d), $p['present']));
        $put(array(__('Absents', $d), $p['absent']));
        $put(array(__('Non pointés', $d), $p['unpointed']));
        $put(array(__('Imprévus (ajoutés sur place)', $d), $p['walkin']));
        $put(array(__('Annulés', $d), $p['cancelled']));
        $put(array(__('Taux de présence (%)', $d), $p['rate']));
        $put(array(__('Montant des réservations', $d), $money($p['revenue'])));
        $put(array());

        if ($r['cashdesk']) {
            $s = $r['sales_summary'];
            $put(array(__('Ventes sur place', $d)));
            $put(array(__('Encaissé', $d), $money($s['paid'])));
            $put(array(__('En attente de paiement', $d), $money($s['pending'])));
            $put(array(__('Bons d\'achat émis (retours)', $d), $money($s['vouchers'])));

            foreach ($s['methods'] as $label => $amount) {
                $put(array(__('Moyen de paiement', $d), $label, $money($amount)));
            }

            $put(array());
            $put(array(__('Produit', $d), __('Référence', $d), __('Quantité', $d), __('Montant', $d)));

            foreach ($s['products'] as $name => $row) {
                $put(array($name, isset($row['sku']) ? $row['sku'] : '', $row['qty'], $money($row['total'])));
            }

            $put(array());
            $put(array(__('Commande', $d), __('Date', $d), __('Client', $d), __('Détail', $d), __('Montant', $d), __('Paiement', $d), __('Statut', $d)));

            foreach ($r['sales'] as $sale) {
                $put(array($sale['number'], $sale['date'], $sale['customer'], implode(', ', $sale['lines']), $money($sale['total']), $sale['method'], $sale['status']));
            }

            $put(array());
        }

        if ($r['docs']) {
            $put(array(__('Documents', $d), __('Faits', $d), __('Refusés', $d), __('Manquants', $d), __('Obligatoire', $d)));

            foreach ($r['docs'] as $doc) {
                $put(array($doc['title'], $doc['ok'], $doc['no'], $doc['todo'], $doc['required'] ? __('Oui', $d) : __('Non', $d)));
            }

            $put(array());
        }

        $rv = $r['reviews'];

        if ($rv['answered'] || $rv['asked']) {
            $put(array(__('Avis des participants', $d)));
            $put(array(__("Demandes d'avis envoyées", $d), $rv['asked']));
            $put(array(__('Réponses', $d), $rv['answered']));
            $put(array(__('Note moyenne (sur 5)', $d), $rv['average'] !== null ? number_format((float) $rv['average'], 1, ',', '') : ''));

            foreach ($rv['fields'] as $field) {
                if ($field['avg'] !== null) {
                    $put(array($field['label'], __('Moyenne', $d), number_format((float) $field['avg'], 1, ',', '')));
                }

                foreach ($field['dist'] as $choice => $n) {
                    $put(array($field['label'], (string) $choice, $n));
                }
            }

            $put(array());
            $labels = array();

            foreach ($rv['fields'] as $key => $field) {
                $labels[$key] = $field['label'];
            }

            $put(array_merge(array(__('Participant', $d), __('Répondu le', $d)), array_values($labels)));

            foreach ($rv['answers'] as $a) {
                $values = array();

                foreach (array_keys($labels) as $key) {
                    $value = '';

                    foreach ($a['entry']['fields'] as $f) {
                        if (($f['key'] ?? '') === $key) {
                            $value = is_array($f['value']) ? implode(', ', $f['value']) : (string) $f['value'];
                        }
                    }

                    $values[] = $value;
                }

                $put(array_merge(array($a['booking'] ? Lookup::personName($a['booking']) : '', get_date_from_gmt($a['entry']['created_at'])), $values));
            }

            $put(array());
        }

        $put(array(__('Messages', $d), __('Canal', $d), __('Envoyés', $d), __('Échecs', $d), __('Non envoyés', $d)));

        foreach ($r['messages'] as $m) {
            $put(array($m['event'], Choices::label('channels', $m['channel']), $m['sent'], $m['failed'], $m['skipped']));
        }

        $put(array());

        // Liste nominative.
        $put(array_merge(array(
            __('Nom', $d), __('Prénom', $d), __('E-mail', $d), __('Téléphone', $d), __('Source', $d), __('Statut', $d), __('Pointé le', $d),
            __('Montant payé', $d), __("Note de l'intervenant", $d),
        ), Documents::titles()));

        foreach ($r['seats'] as $b) {
            $put(array_merge(array(
                $b['last_name'], $b['first_name'], $b['email'], $b['phone'],
                Choices::label('sources', $b['source']), Choices::label('bookingStatuses', $b['status']),
                (string) $b['checked_at'], $b['price_paid'] !== null && $b['price_paid'] !== '' ? $money($b['price_paid']) : '', (string) $b['host_note'],
            ), Documents::csvValues(isset($r['answers'][(int) $b['id']]) ? $r['answers'][(int) $b['id']] : array())));
        }

        fclose($out);
        exit;
    }
}
