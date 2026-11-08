<?php

namespace Smbb\Passflow\Service;

use Smbb\Passflow\Repository\Lookup;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\WooCommerce\Module;

defined('ABSPATH') || exit;

/**
 * Récap animé d'une journée, façon « Wrapped » : quelques cartes plein écran (présence, CA,
 * meilleure vente, avis, coulisses) tirées du Bilan, à rejouer ou à partager.
 *
 * Deux affichages, mêmes cartes :
 * - dans l'admin (vue CodeTool « wrapped » du créneau), ouvert depuis le Bilan ;
 * - en page publique signée (?passflow_recap=ID&k=…), sans aucun nom de participant.
 *   Le chiffre d'affaires n'y figure que si le lien a été créé avec (ca=1, compris dans la signature).
 */
final class Recap
{
    const QUERY = 'passflow_recap';

    public static function hooks()
    {
        add_action('template_redirect', array(__CLASS__, 'publicPage'));
    }

    // ------------------------------------------------------------------
    // Lien de partage
    // ------------------------------------------------------------------

    public static function token($slot_id, $money)
    {
        return substr(hash_hmac('sha256', 'passflow-recap|' . (int) $slot_id . '|' . ($money ? 1 : 0), wp_salt('auth')), 0, 24);
    }

    public static function shareUrl($slot_id, $money = false)
    {
        $args = array(self::QUERY => (int) $slot_id, 'k' => self::token($slot_id, $money));

        if ($money) {
            $args['ca'] = 1;
        }

        return add_query_arg($args, home_url('/'));
    }

    // ------------------------------------------------------------------
    // Données
    // ------------------------------------------------------------------

    /**
     * Les chiffres des cartes (mêmes calculs que le Bilan et la page Comparer).
     *
     * @param array<string,mixed> $slot
     * @return array<string,mixed>
     */
    public static function data(array $slot)
    {
        $r = Report::data($slot);
        $p = $r['presence'];
        $s = $r['sales_summary'];
        $rv = $r['reviews'];
        $capacity = max(0, (int) $slot['capacity']);

        // Les dates précédentes du même atelier (comme « Comparer avec d'autres dates »).
        $history = array();
        $compare = Compare::query(array('comparer' => 'dates', 'depuis' => (int) $slot['id']));

        foreach ($compare['columns'] as $column) {
            if ((int) $column['id'] !== (int) $slot['id'] && $column['past']) {
                $history[] = $column['values'];
            }
        }

        $avg = function ($key) use ($history) {
            $values = array_values(array_filter(array_column($history, $key), function ($v) {
                return $v !== null;
            }));

            return $values ? array_sum($values) / count($values) : null;
        };
        $best = function ($key, $value) use ($history) {
            if ($value === null || !$history) {
                return false;
            }

            foreach ($history as $h) {
                if (isset($h[$key]) && $h[$key] !== null && $h[$key] >= $value) {
                    return false;
                }
            }

            return true;
        };

        $rate = $p['booked'] > 0 ? $p['present'] / $p['booked'] : null;
        $fill = $capacity > 0 ? min(1, $p['booked'] / $capacity) : null;
        $revenue = (float) $p['revenue'] + (float) $s['paid'];

        // Meilleures ventes sur place, par quantité puis montant.
        $products = array();

        foreach ($s['products'] as $name => $row) {
            $products[] = array('name' => (string) $name, 'qty' => (int) $row['qty'], 'total' => (float) $row['total']);
        }

        usort($products, function ($a, $b) {
            return $b['qty'] === $a['qty'] ? ($b['total'] <=> $a['total']) : ($b['qty'] <=> $a['qty']);
        });

        // Avis : quelques phrases, sans nom (la carte se partage).
        $quotes = array();

        foreach ($rv['fields'] as $field) {
            foreach ($field['texts'] as $t) {
                $text = trim(preg_replace('/\s+/', ' ', (string) $t['text']));
                $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

                if ($len >= 12 && $len <= 220) {
                    $quotes[] = $text;
                }
            }
        }

        usort($quotes, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        // Documents : part des participants présents en règle.
        $docs_total = 0;
        $docs_ok = 0;

        foreach ($r['docs'] as $doc) {
            $docs_total += $doc['ok'] + $doc['no'] + $doc['todo'];
            $docs_ok += $doc['ok'];
        }

        $t = $r['message_totals'];

        return array(
            'slot_id' => (int) $slot['id'],
            'activity' => Lookup::activityName($slot['activity_id']),
            'when' => Lookup::slotWhen($slot),
            'hosts' => $r['hosts'],
            'past' => Slots::isPast($slot) || $slot['start_date'] === current_time('Y-m-d'),
            'cancelled' => $slot['status'] === 'cancelled',
            'site' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'brand' => EmailBrand::color(),
            'logo' => EmailBrand::logoUrl(),
            'currency' => Module::active() && function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
            'presence' => array(
                'booked' => (int) $p['booked'],
                'present' => (int) $p['present'],
                'absent' => (int) $p['absent'],
                'walkin' => (int) $p['walkin'],
                'capacity' => $capacity,
                'rate' => $rate,
                'fill' => $fill,
            ),
            'history' => array(
                'count' => count($history),
                'rate_avg' => $avg('rate'),
                'fill_avg' => $avg('fill'),
                'revenue_avg' => $avg('revenue'),
                'present_avg' => $avg('present'),
                'best_rate' => $best('rate', $rate),
                'best_fill' => $best('fill', $fill),
                'best_revenue' => $best('revenue', $revenue > 0 ? $revenue : null),
                'best_present' => $best('present', $p['present'] > 0 ? (float) $p['present'] : null),
            ),
            'money' => array(
                'total' => $revenue,
                'bookings' => (float) $p['revenue'],
                'onsite' => (float) $s['paid'],
                'sales' => (int) $s['count'],
                'per_head' => $p['booked'] > 0 ? $revenue / $p['booked'] : null,
            ),
            'products' => array_slice($products, 0, 3),
            'reviews' => array(
                'answered' => (int) $rv['answered'],
                'asked' => (int) $rv['asked'],
                'average' => $rv['average'] !== null ? (float) $rv['average'] : null,
                'quotes' => array_slice($quotes, 0, 3),
            ),
            'backstage' => array(
                'sms' => (int) $t['sms'],
                'email' => (int) $t['email'],
                'docs' => $docs_total > 0 ? $docs_ok / $docs_total : null,
                'docs_count' => count($r['docs']),
            ),
        );
    }

    /**
     * Version publique : pas de CA sans l'accord du lien.
     */
    public static function publicData(array $data, $money)
    {
        if (!$money) {
            $data['money'] = null;
            $data['products'] = array_map(function ($p) {
                return array('name' => $p['name'], 'qty' => $p['qty'], 'total' => null);
            }, $data['products']);
            $data['history']['revenue_avg'] = null;
            $data['history']['best_revenue'] = false;
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Affichage
    // ------------------------------------------------------------------

    /**
     * Le lecteur (cartes, barre de progression, commandes). Le script lit les données en JSON.
     *
     * @param array<string,mixed> $data data() ou publicData()
     * @param array<string,string> $links close (retour), share (sans CA), share_money (avec CA)
     */
    public static function player(array $data, array $links = array())
    {
        $d = 'smbb-passflow';
        $ver = SMBB_PASSFLOW_VERSION . '.' . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/recap.js') . (int) @filemtime(SMBB_PASSFLOW_PATH . 'assets/recap.css');
        $i18n = array(
            'kicker' => __('Le récap', $d),
            'intro' => __("C'était comment ?", $d),
            'introHint' => __('Touchez pour continuer', $d),
            'with' => __('avec %s', $d),
            'presentTitle' => __('Vous étiez', $d),
            'present' => __('présents', $d),
            'onBooked' => __('sur %d inscrits', $d),
            'presence' => __('de présence', $d),
            'fill' => __('de remplissage', $d),
            'walkin' => __('+ %d ajouté(s) sur place', $d),
            'nobody' => __("Personne n'a encore été pointé.", $d),
            'trendTitle' => __('Et par rapport aux autres dates ?', $d),
            'trendSub' => __('Comparé à vos %d dernières dates de cet atelier', $d),
            'trendOne' => __('Comparé à votre date précédente de cet atelier', $d),
            'rate' => __('Taux de présence', $d),
            'fillShort' => __('Remplissage', $d),
            'revenue' => __("Chiffre d'affaires", $d),
            'presentShort' => __('Présents', $d),
            'pts' => __('%s pts', $d),
            'record' => __('Record !', $d),
            'stable' => __('Stable', $d),
            'moneyTitle' => __("Chiffre d'affaires de la journée", $d),
            'bookings' => __('Réservations', $d),
            'onsite' => __('Ventes sur place', $d),
            'perHead' => __('soit %s par inscrit', $d),
            'salesCount' => __('%d vente(s) en caisse', $d),
            'topTitle' => __('La star du jour', $d),
            'sold' => __('%d vendu(s)', $d),
            'reviewsTitle' => __('Ils ont adoré', $d),
            'reviewsMixed' => __("Ce qu'ils en ont dit", $d),
            'onFive' => __('/ 5', $d),
            'answers' => __('%d avis reçu(s)', $d),
            'backstageTitle' => __('Dans les coulisses', $d),
            'sms' => __('SMS envoyés', $d),
            'emails' => __('e-mails envoyés', $d),
            'docs' => __('documents en règle', $d),
            'auto' => __('Tout ça, automatiquement.', $d),
            'outroTitle' => __('Merci et à la prochaine !', $d),
            'replay' => __('Rejouer', $d),
            'share' => __('Partager', $d),
            'image' => __("Télécharger l'image", $d),
            'close' => __('Fermer', $d),
            'back' => __('Retour au bilan', $d),
            'shareTitle' => __('Partager le récap', $d),
            'shareText' => __('Un lien public, sans aucun nom de participant. Il reste valable tant que la date existe.', $d),
            'withMoney' => __("Inclure le chiffre d'affaires", $d),
            'copy' => __('Copier le lien', $d),
            'copied' => __('Lien copié', $d),
            'openLink' => __('Ouvrir', $d),
            'pause' => __('Pause', $d),
            'play' => __('Lecture', $d),
            'prev' => __('Carte précédente', $d),
            'next' => __('Carte suivante', $d),
            'provisional' => __("L'atelier n'a pas encore eu lieu : chiffres provisoires.", $d),
            'cancelled' => __('Cette date a été annulée.', $d),
            'imageName' => sanitize_file_name('recap-' . sanitize_title($data['activity']) . '.png'),
        );

        $payload = wp_json_encode(array('data' => $data, 'links' => $links, 'i18n' => $i18n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        return '<link rel="stylesheet" href="' . esc_url(SMBB_PASSFLOW_URL . 'assets/recap.css?ver=' . rawurlencode($ver)) . '">'
            . '<div class="passflow-recap" id="passflow-recap" style="--rc-brand:' . esc_attr($data['brand']) . '" role="dialog" aria-modal="true" aria-label="' . esc_attr(sprintf(__('Récap : %s', $d), $data['activity'])) . '">'
            . '<noscript><p class="passflow-recap-noscript">' . esc_html__('Le récap animé a besoin de JavaScript.', $d) . '</p></noscript>'
            . '</div>'
            . '<script type="application/json" id="passflow-recap-data">' . $payload . '</script>'
            . '<script src="' . esc_url(SMBB_PASSFLOW_URL . 'assets/recap.js?ver=' . rawurlencode($ver)) . '" defer></script>';
    }

    /**
     * Page publique signée (?passflow_recap=ID&k=…[&ca=1]).
     */
    public static function publicPage()
    {
        if (empty($_GET[self::QUERY])) {
            return;
        }

        $d = 'smbb-passflow';
        $id = absint($_GET[self::QUERY]);
        $money = !empty($_GET['ca']);
        $token = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $slot = $id ? Slots::find($id) : null;

        if (!$slot || $token === '' || !hash_equals(self::token($id, $money), $token)) {
            status_header(404);
            Documents::render(__('Récap', $d), '<p>' . esc_html__('Ce lien de récap ne fonctionne pas (ou plus).', $d) . '</p>');
        }

        $data = self::publicData(self::data($slot), $money);
        $title = sprintf(__('Récap : %1$s · %2$s', $d), $data['activity'], $data['when']);

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow">'
            . '<meta name="theme-color" content="' . esc_attr($data['brand']) . '">'
            . '<title>' . esc_html($title) . '</title>'
            . '<meta property="og:title" content="' . esc_attr($title) . '">'
            . '<style>html,body{margin:0;height:100%;background:#111;}</style>'
            . '</head><body class="passflow-recap-public">'
            . self::player($data, array()) // phpcs:ignore -- échappé dans player().
            . '</body></html>';
        exit;
    }
}
