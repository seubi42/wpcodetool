<?php

namespace Smbb\SignConnectProxima\Front;

use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Memberships;
use Smbb\SignConnectProxima\Repository\Transactions;
use Smbb\SignConnectProxima\Service\Billing;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\Placeholders;
use Smbb\SignConnectProxima\Support\Schema;

defined('ABSPATH') || exit;

/**
 * Onglet « Transactions » (ex-Historique) : boîtes, libellés personnels façon Gmail, archivage,
 * recherche par nom / email / téléphone du signataire, pagination et fiche détaillée en panneau.
 * Rendu serveur complet (fonctionne sans JS) ; assets/transactions.js ajoute la navigation sans rechargement,
 * la sélection multiple, l'étoile, les libellés et l'annulation de l'archivage.
 */
final class TransactionViews
{
    const D = 'smbb-signaconnect-proxima';

    /** Paramètres d'URL propres à la page (préfixés : « tag » ou « paged » sont réservés par WordPress). */
    const PARAMS = array('scp_box', 'scp_tag', 'scp_q', 'scp_p', 'who', 'team_id', 'level', 'month');

    public static function render($user_id, array $scope)
    {
        $ready = Schema::ensure();
        $supervisor = Memberships::isSupervisor($scope);
        $filters = array(
            'who' => self::query('who', $supervisor ? 'all' : 'me'),
            'team_id' => absint(self::query('team_id', '0')),
            'level' => self::query('level', ''),
            'month' => self::query('month', ''),
        );

        if (!$supervisor) {
            $filters['who'] = 'me';
            $filters['team_id'] = 0;
        }

        if (!in_array($filters['level'], Levels::all(), true)) {
            $filters['level'] = '';
        }

        if (!Billing::validMonth($filters['month'])) {
            $filters['month'] = '';
        }

        $tags = $ready ? Transactions::tags($user_id) : array();
        $view = array(
            'box' => self::query('scp_box', 'inbox'),
            'tag' => absint(self::query('scp_tag', '0')),
            'q' => mb_substr(self::query('scp_q', ''), 0, 80),
            'page' => absint(self::query('scp_p', '1')),
        );

        if (!array_key_exists($view['box'], Transactions::boxes())) {
            $view['box'] = 'inbox';
        }

        if (!isset($tags[$view['tag']])) {
            $view['tag'] = 0;
        }

        if (!$ready) {
            // Tables pas encore créées : on montre quand même la liste, sans rangement personnel.
            $view['box'] = in_array($view['box'], array('starred', 'archived'), true) ? 'inbox' : $view['box'];
        }

        $state = array('filters' => $filters, 'view' => $view, 'supervisor' => $supervisor);
        $result = $ready ? Transactions::page($user_id, $scope, $filters, $view) : self::legacyPage($user_id, $scope, $filters, $view);
        $counts = $ready ? Transactions::boxCounts($user_id, $scope, $filters) : array();
        $stats = Transactions::stats($user_id, $scope, $filters);
        $document_tags = $ready ? Transactions::documentTags($user_id, wp_list_pluck($result['rows'], 'id')) : array();

        $html = '<div class="scp-tx" data-scp-tx data-tags="' . esc_attr(wp_json_encode(array_values($tags))) . '" data-colors="' . esc_attr(wp_json_encode(Transactions::COLORS)) . '"'
            . ($ready ? '' : ' data-readonly="1"') . '>'
            . self::head($result, $stats, $view)
            . self::stats($stats, $state)
            . self::search($state, $scope, $user_id)
            . '<div class="scp-tx-layout">'
            . self::sidebar($state, $counts, $tags, $ready)
            . '<section class="scp-tx-main" aria-live="polite">'
            . self::toolbar($state, $result, $ready)
            . self::rows($user_id, $result['rows'], $state, $tags, $document_tags, $ready)
            . self::pager($state, $result)
            . '</section></div>'
            . (!$ready ? '<p class="scp-hint">' . esc_html__('Libellés et archivage indisponibles : les tables ne sont pas encore créées (CodeTool › schéma).', self::D) . '</p>' : '')
            . '<div class="scp-toast" data-scp-toast hidden></div>'
            . '</div>';

        return $html;
    }

    /* ------------------------------------------------------------------ Blocs */

    private static function head(array $result, array $stats, array $view)
    {
        $sub = sprintf(_n('%s document', '%s documents', $result['total'], self::D), number_format_i18n($result['total']));

        return '<header class="scp-tx-head"><div><h1>' . esc_html__('Transactions', self::D) . '</h1>'
            . '<p class="scp-tx-sub">' . esc_html($sub) . ($view['q'] !== '' ? ' · ' . esc_html(sprintf(__('recherche « %s »', self::D), $view['q'])) : '') . '</p></div>'
            . '<a class="scp-button scp-tx-new" href="' . esc_url(remove_query_arg(array_merge(array('scp_view'), self::PARAMS))) . '">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>' . esc_html__('Nouvel envoi', self::D) . '</a></header>';
    }

    private static function stats(array $stats, array $state)
    {
        $delay = '—';

        if ($stats['delay'] !== null) {
            $delay = $stats['delay'] < 1
                ? sprintf(__('%d min', self::D), max(1, (int) round($stats['delay'] * 60)))
                : ($stats['delay'] < 48 ? sprintf(__('%s h', self::D), number_format_i18n($stats['delay'], $stats['delay'] < 10 ? 1 : 0)) : sprintf(__('%s j', self::D), number_format_i18n($stats['delay'] / 24, 1)));
        }

        $tiles = array(
            array('pending', __('En attente', self::D), number_format_i18n($stats['pending']), $stats['expiring'] ? sprintf(_n('%d expire sous 3 jours', '%d expirent sous 3 jours', $stats['expiring'], self::D), $stats['expiring']) : __('de signature', self::D), self::url($state, array('scp_box' => 'pending', 'scp_tag' => '', 'scp_q' => '', 'scp_p' => ''))),
            array('signed', __('Signés ce mois-ci', self::D), number_format_i18n($stats['signed_month']), ucfirst(Billing::monthLabel(Billing::currentMonth())), self::url($state, array('scp_box' => 'signed', 'scp_tag' => '', 'scp_q' => '', 'scp_p' => ''))),
            array('rate', __('Taux de signature', self::D), $stats['rate'] === null ? '—' : $stats['rate'] . ' %', __('sur 90 jours', self::D), ''),
            array('delay', __('Délai moyen', self::D), $delay, __('entre envoi et signature', self::D), ''),
        );
        $html = '';

        foreach ($tiles as $tile) {
            $inner = '<span class="scp-tx-stat-label">' . esc_html($tile[1]) . '</span><strong>' . esc_html($tile[2]) . '</strong><small>' . esc_html($tile[3]) . '</small>';
            $class = 'scp-tx-stat scp-tx-stat--' . $tile[0] . ($tile[0] === 'pending' && $stats['expiring'] ? ' is-warning' : '');
            $html .= $tile[4] !== ''
                ? '<a class="' . $class . '" href="' . esc_url($tile[4]) . '" data-scp-tx-nav>' . $inner . '</a>'
                : '<div class="' . $class . '">' . $inner . '</div>';
        }

        if ($stats['rate'] !== null) {
            $html = str_replace('<div class="scp-tx-stat scp-tx-stat--rate">', '<div class="scp-tx-stat scp-tx-stat--rate" style="--scp-rate:' . (int) $stats['rate'] . '%">', $html);
        }

        return '<div class="scp-tx-stats">' . $html . '</div>';
    }

    private static function search(array $state, array $scope, $user_id)
    {
        $filters = $state['filters'];
        $view = $state['view'];
        $keep = array('scp_view' => 'documents');

        if ($view['tag']) {
            $keep['scp_tag'] = $view['tag'];
        } elseif ($view['box'] !== 'inbox') {
            $keep['scp_box'] = $view['box'];
        }

        $selects = '';
        $active = 0;

        if ($state['supervisor']) {
            $people = array('all' => __('Tout mon périmètre', self::D), 'me' => __('Mes envois', self::D));

            foreach (Memberships::inScope($scope, true) as $member) {
                if ((int) $member['user_id'] !== (int) $user_id) {
                    $people[(string) $member['user_id']] = $member['user_name'];
                }
            }

            $selects .= self::select('who', $people, $filters['who'], __('Expéditeur', self::D));
            $selects .= self::select('team_id', array('0' => __('Toutes les équipes', self::D)) + self::scopeTeams($scope), (string) $filters['team_id'], __('Équipe', self::D));
            $active += ($filters['who'] !== 'all' ? 1 : 0) + ($filters['team_id'] ? 1 : 0);
        }

        $selects .= self::select('level', array('' => __('Tous les niveaux', self::D)) + Levels::labels(), $filters['level'], __('Niveau', self::D));
        $selects .= self::select('month', array('' => __('Toutes les dates', self::D)) + self::monthOptions(), $filters['month'], __("Mois d'envoi", self::D));
        $active += ($filters['level'] ? 1 : 0) + ($filters['month'] ? 1 : 0);

        return '<form class="scp-tx-search" method="get" role="search" data-scp-tx-form>' . AppViews::keepQuery($keep)
            . '<div class="scp-tx-searchbox"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>'
            . '<input type="search" name="scp_q" value="' . esc_attr($view['q']) . '" placeholder="' . esc_attr__('Nom, email, téléphone ou fichier', self::D) . '" aria-label="' . esc_attr__('Rechercher', self::D) . '" autocomplete="off" enterkeyhint="search" data-scp-tx-q>'
            . '<kbd aria-hidden="true">/</kbd>'
            . ($view['q'] !== '' ? '<a class="scp-tx-clear" href="' . esc_url(self::url($state, array('scp_q' => '', 'scp_p' => ''))) . '" data-scp-tx-nav aria-label="' . esc_attr__('Effacer la recherche', self::D) . '">×</a>' : '')
            . '</div>'
            . '<details class="scp-tx-filters"' . ($active ? ' open' : '') . '><summary><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4"/></svg>'
            . esc_html__('Filtres', self::D) . ($active ? '<span class="scp-tx-pill">' . (int) $active . '</span>' : '') . '</summary>'
            . '<div class="scp-tx-filter-grid">' . $selects
            . ($active ? '<a class="scp-link" href="' . esc_url(self::url($state, array('who' => '', 'team_id' => '', 'level' => '', 'month' => '', 'scp_p' => ''))) . '" data-scp-tx-nav>' . esc_html__('Réinitialiser', self::D) . '</a>' : '')
            . '</div></details>'
            . '<noscript><button class="scp-button scp-button--ghost">' . esc_html__('Rechercher', self::D) . '</button></noscript></form>';
    }

    private static function sidebar(array $state, array $counts, array $tags, $ready)
    {
        $view = $state['view'];
        $boxes = array(
            'inbox' => array(__('Toutes', self::D), '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.7 4H7.3a2 2 0 0 0-1.8 1.1z"/>'),
            'pending' => array(__('En attente', self::D), '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
            'signed' => array(__('Signés', self::D), '<path d="M20 6 9 17l-5-5"/>'),
            'closed' => array(__('Sans suite', self::D), '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>'),
            'starred' => array(__('Suivis', self::D), '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/>'),
            'archived' => array(__('Archives', self::D), '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/>'),
        );
        $html = '<nav class="scp-tx-boxes" aria-label="' . esc_attr__('Boîtes', self::D) . '">';

        foreach ($boxes as $key => $box) {
            if (!$ready && in_array($key, array('starred', 'archived'), true)) {
                continue;
            }

            $current = !$view['tag'] && $view['q'] === '' && $view['box'] === $key;
            $count = isset($counts[$key]) ? (int) $counts[$key] : 0;
            $html .= '<a class="scp-tx-box scp-tx-box--' . $key . ($current ? ' is-active' : '') . '" href="' . esc_url(self::url($state, array('scp_box' => $key === 'inbox' ? '' : $key, 'scp_tag' => '', 'scp_q' => '', 'scp_p' => ''))) . '" data-scp-tx-nav' . ($current ? ' aria-current="page"' : '') . '>'
                . '<svg viewBox="0 0 24 24" aria-hidden="true">' . $box[1] . '</svg><span>' . esc_html($box[0]) . '</span>'
                . ($count && $key !== 'archived' ? '<em>' . esc_html(number_format_i18n($count)) . '</em>' : '') . '</a>';
        }

        $html .= '</nav>';

        if (!$ready) {
            return '<aside class="scp-tx-side">' . $html . '</aside>';
        }

        $list = '';

        foreach ($tags as $tag) {
            $current = $view['tag'] === $tag['id'];
            $list .= '<a class="scp-tx-box scp-tx-label' . ($current ? ' is-active' : '') . '" style="--scp-tag:' . esc_attr($tag['color']) . '" href="' . esc_url(self::url($state, array('scp_tag' => $tag['id'], 'scp_box' => '', 'scp_q' => '', 'scp_p' => ''))) . '" data-scp-tx-nav' . ($current ? ' aria-current="page"' : '') . '>'
                . '<i aria-hidden="true"></i><span>' . esc_html($tag['name']) . '</span>' . ($tag['count'] ? '<em>' . esc_html(number_format_i18n($tag['count'])) . '</em>' : '') . '</a>';
        }

        return '<aside class="scp-tx-side">' . $html
            . '<div class="scp-tx-labels"><div class="scp-tx-side-head"><span>' . esc_html__('Libellés', self::D) . '</span>'
            . ($tags ? '<button type="button" class="scp-tx-icon-button" data-scp-tag-manage aria-label="' . esc_attr__('Gérer les libellés', self::D) . '" title="' . esc_attr__('Gérer les libellés', self::D) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>' : '')
            . '</div>' . $list
            . '<button type="button" class="scp-tx-box scp-tx-addlabel" data-scp-tag-new><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>' . esc_html__('Nouveau libellé', self::D) . '</span></button>'
            . '</div></aside>';
    }

    private static function toolbar(array $state, array $result, $ready)
    {
        $view = $state['view'];
        $title = __('Toutes les transactions', self::D);

        if ($view['q'] !== '') {
            $title = __('Résultats de recherche', self::D);
        } elseif ($view['box'] !== 'inbox' || $view['tag']) {
            $titles = array(
                'pending' => __('En attente de signature', self::D),
                'signed' => __('Documents signés', self::D),
                'closed' => __('Refusés, expirés ou annulés', self::D),
                'starred' => __('Transactions suivies', self::D),
                'archived' => __('Archives', self::D),
            );
            $title = isset($titles[$view['box']]) ? $titles[$view['box']] : $title;
        }

        $range = $result['total']
            ? sprintf(__('%1$s–%2$s sur %3$s', self::D), number_format_i18n(($result['page'] - 1) * $result['per_page'] + 1), number_format_i18n(min($result['total'], $result['page'] * $result['per_page'])), number_format_i18n($result['total']))
            : '';
        $nav = '';

        if ($result['pages'] > 1) {
            $nav = '<span class="scp-tx-arrows">'
                . self::arrow($state, $result['page'] - 1, $result['page'] > 1, __('Page précédente', self::D), 'm15 18-6-6 6-6')
                . self::arrow($state, $result['page'] + 1, $result['page'] < $result['pages'], __('Page suivante', self::D), 'm9 18 6-6-6-6')
                . '</span>';
        }

        $bulk = '';

        if ($ready && $result['rows']) {
            $archived = $view['box'] === 'archived';
            $bulk = '<label class="scp-tx-check scp-tx-check--all" title="' . esc_attr__('Tout sélectionner', self::D) . '"><input type="checkbox" data-scp-tx-all aria-label="' . esc_attr__('Tout sélectionner', self::D) . '"></label>'
                . '<div class="scp-tx-bulk" data-scp-tx-bulk hidden><span data-scp-tx-selected></span>'
                . '<button type="button" class="scp-tx-chip-button" data-scp-tx-archive="' . ($archived ? '0' : '1') . '">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/></svg>'
                . esc_html($archived ? __('Désarchiver', self::D) : __('Archiver', self::D)) . '</button>'
                . '<button type="button" class="scp-tx-chip-button" data-scp-tx-labels><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>' . esc_html__('Libellés', self::D) . '</button>'
                . '</div>';
        }

        return '<div class="scp-tx-bar">' . $bulk . '<h2 class="scp-tx-title" data-scp-tx-title>' . esc_html($title) . '</h2>'
            . '<span class="scp-tx-range">' . esc_html($range) . '</span>' . $nav . '</div>';
    }

    private static function rows($user_id, array $rows, array $state, array $tags, array $document_tags, $ready)
    {
        $view = $state['view'];

        if (!$rows) {
            if ($view['q'] !== '') {
                return '<div class="scp-tx-empty scp-tx-empty--search"><div class="scp-tx-empty-art" aria-hidden="true"></div><h3>' . esc_html(sprintf(__('Aucun résultat pour « %s »', self::D), $view['q'])) . '</h3>'
                    . '<p>' . esc_html__('La recherche porte sur le nom, l\'email et le téléphone du signataire, et sur le nom du fichier, archives comprises.', self::D) . '</p>'
                    . '<a class="scp-button scp-button--ghost" href="' . esc_url(self::url($state, array('scp_q' => '', 'scp_p' => ''))) . '" data-scp-tx-nav>' . esc_html__('Effacer la recherche', self::D) . '</a></div>';
            }

            $messages = array(
                'inbox' => array(__('Aucune transaction pour l\'instant', self::D), __('Vos documents envoyés à la signature apparaîtront ici.', self::D)),
                'pending' => array(__('Rien en attente', self::D), __('Tous vos documents ont reçu une réponse.', self::D)),
                'signed' => array(__('Aucun document signé', self::D), __('Les documents signés apparaîtront ici.', self::D)),
                'closed' => array(__('Rien de ce côté', self::D), __('Aucun document refusé, expiré ou annulé.', self::D)),
                'starred' => array(__('Aucune transaction suivie', self::D), __('Cliquez sur l\'étoile d\'une transaction pour la retrouver ici.', self::D)),
                'archived' => array(__('Aucune archive', self::D), __('Archivez une transaction terminée pour alléger la liste : elle reste trouvable par la recherche.', self::D)),
            );
            $message = $view['tag']
                ? array(__('Aucun document avec ce libellé', self::D), __('Sélectionnez des transactions puis « Libellés » pour les ranger ici.', self::D))
                : $messages[$view['box']];

            return '<div class="scp-tx-empty scp-tx-empty--' . esc_attr($view['tag'] ? 'tag' : $view['box']) . '"><div class="scp-tx-empty-art" aria-hidden="true"></div><h3>' . esc_html($message[0]) . '</h3><p>' . esc_html($message[1]) . '</p>'
                . ($view['box'] === 'inbox' && !$view['tag'] ? '<a class="scp-button" href="' . esc_url(remove_query_arg(array_merge(array('scp_view'), self::PARAMS))) . '">' . esc_html__('Envoyer un document', self::D) . '</a>' : '')
                . '</div>';
        }

        $show_sender = $state['filters']['who'] !== 'me';
        $html = '';

        foreach ($rows as $index => $document) {
            $html .= self::row($user_id, $document, $view['q'], $tags, isset($document_tags[(int) $document['id']]) ? $document_tags[(int) $document['id']] : array(), $show_sender, $ready, $index);
        }

        return '<ul class="scp-tx-list" data-scp-tx-list>' . $html . '</ul>';
    }

    private static function row($user_id, array $document, $q, array $tags, array $tag_ids, $show_sender, $ready, $index)
    {
        $id = (int) $document['id'];
        $name = Documents::recipientName($document);
        $name = $name !== '' ? $name : (string) $document['recipient_email'];
        $contact = array_filter(array((string) $document['recipient_email'], self::phone($document['recipient_phone'])));
        $starred = !empty($document['scp_starred']);
        $archived = !empty($document['scp_archived_at']);
        $chips = '';

        foreach ($tag_ids as $tag_id) {
            if (isset($tags[$tag_id])) {
                $chips .= '<span class="scp-tx-tag" style="--scp-tag:' . esc_attr($tags[$tag_id]['color']) . '">' . esc_html($tags[$tag_id]['name']) . '</span>';
            }
        }

        $meta = array(Levels::shortLabel($document['signature_level']));

        if ($show_sender && (int) $document['sender_id'] !== (int) $user_id) {
            $meta[] = sprintf(__('par %s', self::D), self::userName((int) $document['sender_id']));
        }

        $date = $document['signed_at'] ?: ($document['closed_at'] ?: $document['sent_at']);
        $quick = '';

        if (Ajax::hasFile($document, 'signed')) {
            $quick .= '<a class="scp-tx-icon-button" href="' . esc_url(Ajax::appPdfUrl($id, true, true)) . '" title="' . esc_attr__('Télécharger le PDF signé', self::D) . '" aria-label="' . esc_attr__('Télécharger le PDF signé', self::D) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg></a>';
        }

        if ($ready) {
            $quick .= '<button type="button" class="scp-tx-icon-button" data-scp-tx-archive="' . ($archived ? '0' : '1') . '" data-id="' . $id . '" title="' . esc_attr($archived ? __('Désarchiver', self::D) : __('Archiver', self::D)) . '" aria-label="' . esc_attr($archived ? __('Désarchiver', self::D) : __('Archiver', self::D)) . '">'
                . ($archived
                    ? '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M12 18v-6M9.5 14.5 12 12l2.5 2.5"/></svg>'
                    : '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/></svg>')
                . '</button>'
                . '<button type="button" class="scp-tx-icon-button" data-scp-tx-labels data-id="' . $id . '" title="' . esc_attr__('Libellés', self::D) . '" aria-label="' . esc_attr__('Libellés', self::D) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg></button>';
        }

        return '<li class="scp-tx-row is-' . esc_attr($document['status']) . ($archived ? ' is-archived' : '') . '" data-scp-tx-row="' . $id . '" data-tags="' . esc_attr(implode(',', $tag_ids)) . '" style="--i:' . (int) $index . '">'
            . ($ready ? '<label class="scp-tx-check"><input type="checkbox" value="' . $id . '" data-scp-tx-select aria-label="' . esc_attr(sprintf(__('Sélectionner %s', self::D), $document['filename'])) . '"></label>'
                . '<button type="button" class="scp-tx-star' . ($starred ? ' is-on' : '') . '" data-scp-tx-star="' . $id . '" aria-pressed="' . ($starred ? 'true' : 'false') . '" aria-label="' . esc_attr__('Suivre', self::D) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/></svg></button>' : '')
            . '<span class="scp-tx-avatar" style="--h:' . (int) (crc32(strtolower($name)) % 360) . '" aria-hidden="true">' . esc_html(self::initials($name)) . '</span>'
            . '<button type="button" class="scp-tx-open" data-scp-tx-open aria-label="' . esc_attr(sprintf(__('Ouvrir la fiche de %s', self::D), $document['filename'])) . '">'
            . '<span class="scp-tx-who"><strong>' . self::mark($name, $q) . '</strong><small>' . self::mark(implode(' · ', $contact), $q) . '</small></span>'
            . '<span class="scp-tx-doc"><span class="scp-tx-docline">' . $chips . '<span class="scp-tx-file">' . self::mark($document['filename'], $q) . '</span></span>'
            . '<small>' . esc_html(implode(' · ', $meta)) . '</small>' . self::customLine($document, $q) . '</span>'
            . '</button>'
            . '<span class="scp-tx-status">' . Choices::badge('documentStatuses', $document['status'])
            . ($document['reminder_count'] && in_array($document['status'], array('sent', 'opened'), true) ? '<small>' . esc_html(sprintf(_n('%d relance', '%d relances', (int) $document['reminder_count'], self::D), (int) $document['reminder_count'])) . '</small>' : '')
            . '</span>'
            . '<time class="scp-tx-date" datetime="' . esc_attr($date ? mysql2date('c', $date) : '') . '" title="' . esc_attr($date ? mysql2date('d/m/Y H:i', $date) : '') . '">' . esc_html(self::shortDate($date)) . '</time>'
            . '<span class="scp-tx-quick">' . $quick . '</span>'
            . '<template data-scp-tx-detail>' . self::detail($user_id, $document, $name, $chips, $archived, $ready) . '</template>'
            . '</li>';
    }

    /** Fiche du panneau latéral : signataire, frise des étapes, actions. */
    private static function detail($user_id, array $document, $name, $chips, $archived, $ready)
    {
        $id = (int) $document['id'];
        $steps = array();
        $steps[] = array('done', __('Envoyé', self::D), $document['sent_at'], sprintf(__('par %s', self::D), self::userName((int) $document['sender_id'])) . ($document['team_id'] ? ' · ' . Lookup::teamName($document['team_id']) : ''));

        if ($document['opened_at']) {
            $steps[] = array('done', __('Ouvert par le signataire', self::D), $document['opened_at'], '');
        }

        if ((int) $document['reminder_count']) {
            $steps[] = array('done', sprintf(_n('%d relance', '%d relances', (int) $document['reminder_count'], self::D), (int) $document['reminder_count']), $document['last_reminder_at'], __('dernière relance', self::D));
        }

        switch ($document['status']) {
            case 'signed':
                $steps[] = array('success', __('Signé', self::D), $document['signed_at'], '');
                break;
            case 'refused':
                $steps[] = array('danger', __('Refusé', self::D), $document['closed_at'], '');
                break;
            case 'expired':
                $steps[] = array('warning', __('Expiré', self::D), $document['closed_at'] ?: $document['expires_at'], '');
                break;
            case 'cancelled':
                $steps[] = array('danger', __('Annulé', self::D), $document['closed_at'], '');
                break;
            case 'sent':
            case 'opened':
                $steps[] = array('waiting', __('En attente de signature', self::D), '', $document['expires_at'] ? sprintf(__('expire le %s', self::D), mysql2date('d/m/Y à H:i', $document['expires_at'])) : '');
                break;
        }

        $timeline = '';

        foreach ($steps as $step) {
            $timeline .= '<li class="is-' . esc_attr($step[0]) . '"><strong>' . esc_html($step[1]) . '</strong>'
                . ($step[2] ? '<time>' . esc_html(mysql2date('d/m/Y à H:i', $step[2])) . '</time>' : '')
                . ($step[3] !== '' ? '<small>' . esc_html($step[3]) . '</small>' : '') . '</li>';
        }

        $actions = '';

        if (Ajax::hasFile($document, 'signed')) {
            $actions .= '<a class="scp-button" href="' . esc_url(Ajax::appPdfUrl($id, true, true)) . '">' . esc_html__('Télécharger le PDF signé', self::D) . '</a>';

            if ($document['signature_level'] !== Levels::INTERNAL && Ajax::hasFile($document, 'evidence')) {
                $actions .= '<a class="scp-button scp-button--ghost" href="' . esc_url(Ajax::appPdfUrl($id, 'evidence', true)) . '">' . esc_html__('Preuve de signature', self::D) . '</a>';
            }
        } elseif (!empty($document['source_storage_path'])) {
            $actions .= '<a class="scp-button scp-button--ghost" href="' . esc_url(Ajax::appPdfUrl($id)) . '" target="_blank" rel="noopener">' . esc_html__('Voir le document', self::D) . '</a>';
        }

        if (in_array($document['status'], array('sent', 'opened'), true) && Memberships::canManage($user_id, $document)) {
            $actions .= '<button type="button" class="scp-button scp-button--ghost" data-scp-remind="' . $id . '">' . esc_html__('Relancer', self::D) . '</button>'
                . '<button type="button" class="scp-button scp-button--ghost scp-tx-danger" data-scp-cancel="' . $id . '">' . esc_html__("Annuler l'envoi", self::D) . '</button>';
        }

        $contact = '';

        if ($document['recipient_email']) {
            $contact .= '<a href="mailto:' . esc_attr($document['recipient_email']) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>' . esc_html($document['recipient_email']) . '</a>';
        }

        if ($document['recipient_phone']) {
            $contact .= '<a href="tel:' . esc_attr(preg_replace('/[^\d+]/', '', $document['recipient_phone'])) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg>' . esc_html(self::phone($document['recipient_phone'])) . '</a>';
        }

        return '<div class="scp-tx-detail">'
            . '<div class="scp-tx-detail-head">' . Choices::badge('documentStatuses', $document['status']) . '<span class="scp-badge scp-badge--level">' . esc_html(Levels::shortLabel($document['signature_level'])) . '</span>'
            . ($archived ? '<span class="scp-badge">' . esc_html__('Archivé', self::D) . '</span>' : '') . '</div>'
            . '<h2>' . esc_html($document['filename']) . '</h2>'
            . '<p class="scp-tx-detail-meta">' . esc_html(sprintf(_n('%d page', '%d pages', max(1, (int) $document['page_count']), self::D), max(1, (int) $document['page_count']))) . ($document['file_size'] ? ' · ' . esc_html(size_format((int) $document['file_size'])) : '') . ' · ' . esc_html(sprintf(__('n° %d', self::D), $id)) . '</p>'
            . '<div class="scp-tx-detail-tags">' . $chips . ($ready ? '<button type="button" class="scp-link" data-scp-tx-labels data-id="' . $id . '">' . esc_html($chips !== '' ? __('Modifier les libellés', self::D) : __('Ajouter un libellé', self::D)) . '</button>' : '') . '</div>'
            . '<section class="scp-tx-person"><span class="scp-tx-avatar is-large" style="--h:' . (int) (crc32(strtolower($name)) % 360) . '" aria-hidden="true">' . esc_html(self::initials($name)) . '</span>'
            . '<div><strong>' . esc_html($name) . '</strong><span class="scp-tx-contact">' . $contact . '</span></div></section>'
            . '<h3>' . esc_html__('Suivi', self::D) . '</h3><ol class="scp-tx-timeline">' . $timeline . '</ol>'
            . self::customList($document)
            . ($document['send_message'] ? '<h3>' . esc_html__('Message au signataire', self::D) . '</h3><blockquote class="scp-tx-message">' . nl2br(esc_html($document['send_message'])) . '</blockquote>' : '')
            . '<div class="scp-tx-detail-actions">' . $actions
            . ($ready ? '<button type="button" class="scp-button scp-button--ghost" data-scp-tx-archive="' . ($archived ? '0' : '1') . '" data-id="' . $id . '">' . esc_html($archived ? __('Désarchiver', self::D) : __('Archiver', self::D)) . '</button>' : '')
            . '</div></div>';
    }

    private static function pager(array $state, array $result)
    {
        if ($result['pages'] < 2) {
            return '';
        }

        $page = $result['page'];
        $pages = $result['pages'];
        $numbers = array_unique(array_filter(array(1, $page - 1, $page, $page + 1, $pages), function ($n) use ($pages) {
            return $n >= 1 && $n <= $pages;
        }));
        sort($numbers);
        $html = self::arrow($state, $page - 1, $page > 1, __('Page précédente', self::D), 'm15 18-6-6 6-6');
        $previous = 0;

        foreach ($numbers as $number) {
            if ($previous && $number > $previous + 1) {
                $html .= '<span class="scp-tx-gap">…</span>';
            }

            $html .= $number === $page
                ? '<span class="scp-tx-page is-current" aria-current="page">' . (int) $number . '</span>'
                : '<a class="scp-tx-page" href="' . esc_url(self::url($state, array('scp_p' => $number > 1 ? $number : ''))) . '" data-scp-tx-nav>' . (int) $number . '</a>';
            $previous = $number;
        }

        $html .= self::arrow($state, $page + 1, $page < $pages, __('Page suivante', self::D), 'm9 18 6-6-6-6');

        return '<nav class="scp-tx-pager" aria-label="' . esc_attr__('Pagination', self::D) . '">' . $html . '</nav>';
    }

    private static function arrow(array $state, $page, $enabled, $label, $path)
    {
        $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . esc_attr($path) . '"/></svg>';

        return $enabled
            ? '<a class="scp-tx-page scp-tx-page--arrow" href="' . esc_url(self::url($state, array('scp_p' => $page > 1 ? $page : ''))) . '" data-scp-tx-nav aria-label="' . esc_attr($label) . '" title="' . esc_attr($label) . '">' . $icon . '</a>'
            : '<span class="scp-tx-page scp-tx-page--arrow is-disabled" aria-hidden="true">' . $icon . '</span>';
    }

    /* ------------------------------------------------------------------ Outils */

    /**
     * URL de la page avec l'état courant, modifié par $changes (valeur vide = paramètre retiré).
     */
    public static function url(array $state, array $changes)
    {
        $filters = $state['filters'];
        $view = $state['view'];
        $args = array(
            'scp_box' => $view['box'] !== 'inbox' ? $view['box'] : '',
            'scp_tag' => $view['tag'] ?: '',
            'scp_q' => $view['q'],
            'scp_p' => $view['page'] > 1 ? $view['page'] : '',
            'who' => $state['supervisor'] && $filters['who'] !== 'all' ? $filters['who'] : '',
            'team_id' => $filters['team_id'] ?: '',
            'level' => $filters['level'],
            'month' => $filters['month'],
        );
        $args = array_filter(array_merge($args, $changes), function ($value) {
            return $value !== '' && $value !== null && $value !== 0;
        });

        return add_query_arg(array_merge(array('scp_view' => 'documents'), array_map('rawurlencode', array_map('strval', $args))), remove_query_arg(array_merge(array('scp_view', 'org', 'my_month', 'template', 'status'), self::PARAMS)));
    }

    /**
     * Repli si les tables de rangement n'existent pas encore : liste simple, paginée, sans libellés.
     */
    private static function legacyPage($user_id, array $scope, array $filters, array $view)
    {
        global $wpdb;

        $where = array_merge(Transactions::baseWhere($user_id, $scope, $filters), Transactions::searchWhere($view['q']));
        $boxes = Transactions::boxes();

        if (isset($boxes[$view['box']]) && is_array($boxes[$view['box']])) {
            $where[] = "d.status IN ('" . implode("','", array_map('esc_sql', $boxes[$view['box']])) . "')";
        }

        $from = ' FROM ' . Lookup::table('scp_document') . ' d WHERE ' . implode(' AND ', $where);
        $total = (int) $wpdb->get_var('SELECT COUNT(*)' . $from);
        $pages = max(1, (int) ceil($total / Transactions::PER_PAGE));
        $page = min(max(1, $view['page']), $pages);
        $rows = $wpdb->get_results('SELECT d.*' . $from . ' ORDER BY d.id DESC LIMIT ' . Transactions::PER_PAGE . ' OFFSET ' . (($page - 1) * Transactions::PER_PAGE), ARRAY_A);

        return array('rows' => is_array($rows) ? $rows : array(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => Transactions::PER_PAGE);
    }

    /** Surligne les mots cherchés dans un texte (échappé). */
    /** Champs perso du modèle (N° de commande…) sous le nom du document. */
    private static function customLine(array $document, $q)
    {
        $parts = array();

        foreach (Placeholders::custom($document) as $field) {
            $parts[] = $field['label'] . ' : ' . $field['value'];
        }

        return $parts ? '<small class="scp-tx-custom">' . self::mark(implode(' · ', $parts), $q) . '</small>' : '';
    }

    private static function customList(array $document)
    {
        $rows = '';

        foreach (Placeholders::custom($document) as $field) {
            $rows .= '<dt>' . esc_html($field['label']) . '</dt><dd>' . esc_html($field['value']) . '</dd>';
        }

        if (!empty($document['source_filename']) && $document['source_filename'] !== $document['filename']) {
            $rows .= '<dt>' . esc_html__('Fichier d\'origine', self::D) . '</dt><dd>' . esc_html($document['source_filename']) . '</dd>';
        }

        return $rows !== '' ? '<h3>' . esc_html__('Informations', self::D) . '</h3><dl class="scp-tx-info">' . $rows . '</dl>' : '';
    }

    private static function mark($text, $q)
    {
        $html = esc_html((string) $text);
        $words = array_filter(preg_split('/\s+/u', trim((string) $q), -1, PREG_SPLIT_NO_EMPTY), function ($word) {
            return mb_strlen($word) >= 2;
        });

        if (!$words || $html === '') {
            return $html;
        }

        $patterns = array_map(function ($word) {
            return preg_quote(esc_html($word), '/');
        }, $words);

        $marked = preg_replace('/(' . implode('|', $patterns) . ')/iu', '<mark>$1</mark>', $html);

        return is_string($marked) ? $marked : $html;
    }

    /** Date courte façon messagerie : heure aujourd'hui, jour et mois cette année, sinon date complète. */
    private static function shortDate($date)
    {
        if (!$date) {
            return '';
        }

        $now = current_time('mysql');

        if (substr($date, 0, 10) === substr($now, 0, 10)) {
            return mysql2date('H:i', $date);
        }

        if (substr($date, 0, 4) === substr($now, 0, 4)) {
            return date_i18n('j M', strtotime($date . ' UTC'), true);
        }

        return mysql2date('d/m/Y', $date);
    }

    /** +33612345678 => +33 6 12 34 56 78 (lecture plus facile, l'enregistrement reste au format international). */
    private static function phone($phone)
    {
        $phone = (string) $phone;

        if (preg_match('/^\+33(\d)(\d{2})(\d{2})(\d{2})(\d{2})$/', $phone, $m)) {
            return '+33 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3] . ' ' . $m[4] . ' ' . $m[5];
        }

        return $phone;
    }

    private static function initials($name)
    {
        $parts = preg_split('/[\s.\-_@]+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';

        foreach (array_slice($parts ?: array('?'), 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials;
    }

    private static function select($name, array $options, $value, $label)
    {
        $html = '';

        foreach ($options as $key => $text) {
            $html .= '<option value="' . esc_attr($key) . '"' . selected((string) $key, (string) $value, false) . '>' . esc_html($text) . '</option>';
        }

        return '<label class="scp-tx-filter"><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '">' . $html . '</select></label>';
    }

    private static function scopeTeams(array $scope)
    {
        $teams = array();

        foreach (Lookup::teams() as $id => $team) {
            if (in_array((int) $team['organization_id'], $scope['org_ids'], true) || in_array((int) $id, $scope['team_ids'], true)) {
                $teams[(string) $id] = (count($scope['memberships']) > 1 ? Lookup::organizationName($team['organization_id']) . ' — ' : '') . $team['name'];
            }
        }

        return $teams;
    }

    private static function monthOptions()
    {
        $options = array();

        foreach (\Smbb\SignConnectProxima\Service\Usage::months(24, '') as $month) {
            $options[$month] = ucfirst(Billing::monthLabel($month));
        }

        return $options;
    }

    private static function query($key, $default)
    {
        return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : $default;
    }

    private static function userName($user_id)
    {
        $user = $user_id ? get_userdata($user_id) : false;

        return $user ? (string) $user->display_name : '';
    }
}
