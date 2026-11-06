<?php

namespace Smbb\Tomo\Admin;

defined('ABSPATH') || exit;

/**
 * Petits gabarits partagés par les vues d'admin CodeTool du plugin.
 */
final class View
{
    const NOTICE = 'smbb_tomo_notice_';

    /**
     * Affiche une page d'admin : en-tête CodeTool, notices, puis le contenu.
     */
    public static function page($content, $page_header_html = '', $notices_html = '', $fallback_title = '')
    {
        echo '<div class="wrap smbb-codetool tomo-admin">';

        if ($page_header_html !== '') {
            echo $page_header_html; // phpcs:ignore -- HTML fourni par CodeTool.
        } elseif ($fallback_title !== '') {
            echo '<h1>' . esc_html($fallback_title) . '</h1>';
        }

        echo self::notice(); // phpcs:ignore -- HTML échappé.

        if ($notices_html !== '') {
            echo $notices_html; // phpcs:ignore -- HTML fourni par CodeTool.
        }

        echo $content; // phpcs:ignore -- HTML construit et échappé par les vues.
        echo '</div>';
    }

    /**
     * Rend une liste CodeTool (la Table produit elle-même l'en-tête de page).
     *
     * @param \Smbb\WpCodeTool\Admin\Table $table
     * @param string $before HTML affiché au-dessus de la table (déjà échappé)
     */
    public static function table($table, $before = '')
    {
        echo '<div class="tomo-admin">';
        echo self::notice(); // phpcs:ignore -- HTML échappé.
        echo $before; // phpcs:ignore -- HTML échappé par l'appelant.
        $table->render();
        echo '</div>';
    }

    /**
     * Onglets entre deux écrans rangés sous la même entrée de menu
     * (Inscriptions / Liste d'attente, Journal : mouvements / messages).
     *
     * @param array<string,array{0:string,1:string}> $items ressource => [libellé, url]
     * @param string $current ressource affichée
     */
    public static function sections(array $items, $current)
    {
        $html = '<nav class="tomo-segments tomo-segments--tabs tomo-sections">';

        foreach ($items as $resource => $item) {
            $html .= '<a class="' . ($resource === $current ? 'is-active' : '') . '" href="' . esc_url($item[1]) . '"' . ($resource === $current ? ' aria-current="page"' : '') . '>' . esc_html($item[0]) . '</a>';
        }

        return $html . '</nav>';
    }

    /**
     * Onglets Inscriptions / Liste d'attente.
     */
    public static function bookingSections($current)
    {
        $d = 'smbb-tomo';
        $waiting = (int) $GLOBALS['wpdb']->get_var('SELECT COALESCE(SUM(places), 0) FROM ' . \Smbb\Tomo\Repository\Lookup::table('tomo_attente') . " WHERE deleted_flag = 0 AND status IN ('waiting','notified')");

        return self::sections(array(
            'tomo_inscription' => array(__('Inscriptions', $d), self::url('tomo_inscription')),
            'tomo_attente' => array($waiting > 0 ? sprintf(__("Liste d'attente (%d)", $d), $waiting) : __("Liste d'attente", $d), self::url('tomo_attente')),
        ), $current);
    }

    /**
     * Onglets du Journal : mouvements / messages envoyés.
     */
    public static function journalSections($current)
    {
        $d = 'smbb-tomo';

        return self::sections(array(
            'tomo_historique' => array(__('Mouvements', $d), self::url('tomo_historique')),
            'tomo_envoi' => array(__('Messages envoyés', $d), self::url('tomo_envoi')),
        ), $current);
    }

    /**
     * Tableau compact des messages envoyés (fiches créneau et inscription).
     *
     * @param array<int,array<string,mixed>> $rows lignes de tomo_envoi
     */
    public static function messagesTable(array $rows, $with_recipient = true)
    {
        $d = 'smbb-tomo';

        if (!$rows) {
            return '<p class="tomo-muted">' . esc_html__('Aucun message envoyé pour le moment.', $d) . '</p>';
        }

        $html = '<table class="widefat striped tomo-table"><thead><tr><th>' . esc_html__('Date', $d) . '</th><th>' . esc_html__('Message', $d) . '</th>'
            . ($with_recipient ? '<th>' . esc_html__('Destinataire', $d) . '</th>' : '')
            . '<th>' . esc_html__('Résultat', $d) . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td><a href="' . esc_url(self::url('tomo_envoi', array('view' => 'details', 'id' => (int) $row['id']))) . '">' . self::datetime($row['sent_at']) . '</a></td>'
                . '<td>' . \Smbb\Tomo\Support\Choices::badge('channels', $row['channel']) . ' ' . esc_html(\Smbb\Tomo\Support\Choices::label('envoiEvents', $row['event'])) . '</td>'
                . ($with_recipient ? '<td>' . esc_html($row['recipient_name']) . '<br><span class="tomo-muted">' . esc_html($row['recipient']) . '</span></td>' : '')
                . '<td>' . \Smbb\Tomo\Support\Choices::badge('envoiStatuses', $row['status']) . ($row['error'] ? '<br><span class="tomo-error">' . esc_html(wp_trim_words((string) $row['error'], 14)) . '</span>' : '') . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * Retire le bouton « Ajouter » d'une liste en lecture seule (journal).
     *
     * @param \Smbb\WpCodeTool\Admin\Table $table
     */
    public static function readOnlyTable($table)
    {
        try {
            $property = new \ReflectionProperty($table, 'create_url');
            $property->setAccessible(true);
            $property->setValue($table, '');
        } catch (\ReflectionException $exception) {
            // Version de CodeTool différente : le bouton reste, sans conséquence.
        }

        return $table;
    }

    /**
     * Encart d'information à glisser dans un formulaire CodeTool (il accepte du HTML brut).
     */
    public static function note($title, $html, $icon = 'dashicons-info-outline')
    {
        return '<div class="smbb-codetool-section tomo-note">'
            . '<header class="smbb-codetool-section-header">'
            . '<span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons ' . esc_attr($icon) . '"></span></span>'
            . '<div class="smbb-codetool-section-heading"><h3>' . esc_html($title) . '</h3></div>'
            . '</header>'
            . '<div class="smbb-codetool-section-body">' . $html . '</div>'
            . '</div>';
    }

    /**
     * Carte blanche avec un titre.
     */
    public static function card($title, $html, $extra_class = '')
    {
        return '<section class="tomo-card ' . esc_attr($extra_class) . '">'
            . ($title !== '' ? '<h2>' . esc_html($title) . '</h2>' : '')
            . $html
            . '</section>';
    }

    /**
     * Le même formulaire CodeTool avec des valeurs complétées (valeurs par défaut, champs déchiffrés).
     * Les erreurs de validation et le reste du contexte sont gardés.
     *
     * @param \Smbb\WpCodeTool\Admin\Form $form
     * @param callable $fill reçoit l'item et le renvoie complété
     * @return \Smbb\WpCodeTool\Admin\Form
     */
    public static function refillForm($form, callable $fill)
    {
        try {
            $property = new \ReflectionProperty($form, 'context');
            $property->setAccessible(true);
            $context = $property->getValue($form);
        } catch (\ReflectionException $exception) {
            return $form;
        }

        $context = is_array($context) ? $context : array();
        $context['item'] = $fill(isset($context['item']) && is_array($context['item']) ? $context['item'] : array());

        return new \Smbb\WpCodeTool\Admin\Form($context);
    }

    /**
     * Onglets au format CodeTool (son JS les active et retient l'onglet ouvert après une action).
     *
     * @param array<int,array{0:string,1:string,2?:string}> $tabs libellé, HTML du panneau, classe du panneau
     */
    public static function tabs(array $tabs, $id = 'tomo')
    {
        $nav = '<div class="smbb-codetool-tabs-nav no-print" role="tablist">';
        $panels = '<div class="smbb-codetool-tabs-panels">';
        $index = 0;

        foreach ($tabs as $tab) {
            $tab_id = 'tomo-' . $id . '-tab-' . $index;
            $panel_id = 'tomo-' . $id . '-panel-' . $index;
            $active = $index === 0;
            $class = isset($tab[2]) ? ' ' . $tab[2] : '';

            $nav .= '<button type="button" class="smbb-codetool-tab-button' . ($active ? ' is-active' : '') . '" role="tab" id="' . esc_attr($tab_id) . '" aria-controls="' . esc_attr($panel_id) . '" aria-selected="' . ($active ? 'true' : 'false') . '" data-smbb-tab-button="' . esc_attr($panel_id) . '">'
                . esc_html($tab[0]) . '</button>';
            $panels .= '<section class="smbb-codetool-tab-panel' . ($active ? ' is-active' : '') . esc_attr($class) . '" role="tabpanel" id="' . esc_attr($panel_id) . '" aria-labelledby="' . esc_attr($tab_id) . '"' . ($active ? '' : ' hidden') . '>'
                . $tab[1] . '</section>';
            $index++;
        }

        return '<div class="smbb-codetool-tabs tomo-tabs">' . $nav . '</div>' . $panels . '</div></div>';
    }

    /**
     * Bloc repliable (accordéon) pour les actions moins courantes.
     */
    public static function fold($title, $html, $open = false, $icon = '', $extra_class = '')
    {
        return '<details class="tomo-fold ' . esc_attr($extra_class) . '"' . ($open ? ' open' : '') . '>'
            . '<summary>' . ($icon !== '' ? '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>' : '') . '<span>' . esc_html($title) . '</span></summary>'
            . '<div class="tomo-fold-body">' . $html . '</div>'
            . '</details>';
    }

    /**
     * Boutons d'action rapide d'une inscription selon son statut :
     * « Confirmer » tant qu'elle est à confirmer, puis Présent / Absent à partir du jour de l'atelier.
     *
     * @param array $inscription
     * @param array|null $creneau
     * @param string $size '' ou 'small'
     */
    /**
     * Pastille « Pass » d'un jour de pass, avec un lien vers la réservation principale.
     */
    public static function passBadge(array $inscription)
    {
        if (empty($inscription['pass_id'])) {
            return '';
        }

        return ' <a class="tomo-badge tomo-badge--pass" href="' . esc_url(self::url('tomo_inscription', array('view' => 'details', 'id' => (int) $inscription['pass_id']))) . '" title="' . esc_attr__('Place comprise dans un pass : billet, QR et documents sont ceux du pass.', 'smbb-tomo') . '">'
            . '<span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>' . esc_html__('Pass', 'smbb-tomo') . '</a>';
    }

    public static function quickActions(array $inscription, $creneau, $size = 'small')
    {
        $d = 'smbb-tomo';
        $small = $size === 'small' ? ' button-small' : '';
        $id = (int) $inscription['id'];

        if (!$creneau || $creneau['status'] === 'cancelled' || !in_array($inscription['status'], \Smbb\Tomo\Support\Choices::seatStatuses(), true)) {
            return '';
        }

        if ($inscription['status'] === 'pending') {
            return '<a class="button tomo-btn--confirm' . $small . '" href="' . esc_url(self::actionUrl(Actions::PRESENCE, array('id' => $id, 'status' => 'confirmed'))) . '"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__('Marquer payée', $d) . '</a>';
        }

        // Un pass se pointe jour par jour, sur les créneaux de ses jours.
        if ($creneau['start_date'] > current_time('Y-m-d') || \Smbb\Tomo\Service\Passes::isPass($creneau)) {
            return '';
        }

        $html = '';

        $icons = \Smbb\Tomo\Support\Choices::inscriptionIcons();

        foreach (array('present' => __('Présent', $d), 'absent' => __('Absent', $d)) as $status => $label) {
            $active = $inscription['status'] === $status;
            $html .= '<a class="button tomo-btn--' . $status . $small . ($active ? ' is-on' : '') . '" href="' . esc_url(self::actionUrl(Actions::PRESENCE, array('id' => $id, 'status' => $active ? 'confirmed' : $status))) . '"' . ($active ? ' title="' . esc_attr__('Cliquer pour annuler le pointage', $d) . '"' : '') . '>'
                . '<span class="dashicons ' . esc_attr($icons[$status]) . '" aria-hidden="true"></span> ' . esc_html($label) . '</a> ';
        }

        return trim($html);
    }

    /**
     * Liste de paires libellé / valeur (valeurs déjà échappées).
     *
     * @param array<string,string> $pairs
     */
    public static function definitionList(array $pairs)
    {
        $html = '<dl class="tomo-dl">';

        foreach ($pairs as $label => $value) {
            $html .= '<dt>' . esc_html($label) . '</dt><dd>' . ($value !== '' ? $value : '<span class="tomo-muted">—</span>') . '</dd>';
        }

        return $html . '</dl>';
    }

    public static function money($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        return esc_html(number_format_i18n((float) $value, 2) . ' €');
    }

    public static function datetime($value)
    {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return '';
        }

        return esc_html(mysql2date('j M Y, H:i', (string) $value));
    }

    /**
     * Jauge de remplissage « 8 / 12 » avec une barre colorée.
     */
    /**
     * Alerte « Aucun » quand un créneau n'a pas encore d'intervenant.
     */
    public static function noIntervenant()
    {
        return '<span class="tomo-warning-tag" title="' . esc_attr__('Aucun intervenant affecté à ce créneau', 'smbb-tomo') . '"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' . esc_html__('Aucun', 'smbb-tomo') . '</span>';
    }

    public static function fill($booked, $capacity, $waiting = 0)
    {
        $capacity = max(0, (int) $capacity);
        $booked = max(0, (int) $booked);
        $ratio = $capacity > 0 ? min(1, $booked / $capacity) : 0;
        $level = $ratio >= 1 ? 'full' : ($ratio >= 0.75 ? 'high' : ($ratio >= 0.4 ? 'mid' : 'low'));

        $html = '<span class="tomo-fill tomo-fill--' . $level . '" title="' . esc_attr(sprintf(__('%1$d inscrits sur %2$d places', 'smbb-tomo'), $booked, $capacity)) . '">'
            . '<span class="tomo-fill-bar"><span style="width:' . esc_attr((string) round($ratio * 100)) . '%"></span></span>'
            . '<span class="tomo-fill-text">' . esc_html($booked . ' / ' . $capacity) . '</span>'
            . '</span>';

        if ($waiting > 0) {
            $html .= ' <span class="tomo-waiting" title="' . esc_attr__("En liste d'attente", 'smbb-tomo') . '">+' . esc_html((string) (int) $waiting) . '</span>';
        }

        return $html;
    }

    /**
     * Rappel des places d'un créneau : « 3 places libres », « Complet », « Annulé »…
     */
    public static function seats(array $creneau)
    {
        $d = 'smbb-tomo';
        $remaining = isset($creneau['remaining']) ? (int) $creneau['remaining'] : 0;

        if ($creneau['status'] === 'cancelled') {
            $level = 'off';
            $text = __('Créneau annulé', $d);
        } elseif (\Smbb\Tomo\Repository\Creneaux::isPast($creneau)) {
            $level = 'off';
            $text = __('Créneau passé', $d);
        } elseif ($remaining < 1) {
            $level = 'full';
            $text = __('Complet', $d);
        } else {
            $level = $remaining <= 2 ? 'high' : 'low';
            $text = sprintf(_n('%d place libre', '%d places libres', $remaining, $d), $remaining);
        }

        return '<span class="tomo-seats tomo-seats--' . esc_attr($level) . '" title="' . esc_attr(sprintf(__('%1$d inscrits sur %2$d places', $d), isset($creneau['booked']) ? (int) $creneau['booked'] : 0, (int) $creneau['capacity'])) . '">'
            . esc_html($text) . '</span>';
    }

    /**
     * URL d'un écran CodeTool Tomo : View::url('tomo_creneau', array('view' => 'details', 'id' => 3)).
     */
    public static function url($resource, array $args = array())
    {
        return add_query_arg($args, admin_url('admin.php?page=smbb-codetool-' . $resource));
    }

    /**
     * Liste CodeTool filtrée : View::filtered('tomo_inscription', 'status', 'pending').
     */
    public static function filtered($resource, $field, $value, $operator = 'eq')
    {
        return self::url($resource, array('filter' => array('field' => $field, 'operator' => $operator, 'value' => $value)));
    }

    /**
     * Lien d'action admin-post signé (GET).
     */
    public static function actionUrl($action, array $args = array())
    {
        $url = add_query_arg(array_merge(array('action' => $action), $args), admin_url('admin-post.php'));

        return wp_nonce_url($url, $action . '_' . (isset($args['id']) ? (int) $args['id'] : 0));
    }

    /**
     * Ouverture d'un formulaire admin-post signé (POST).
     */
    public static function formStart($action, $id, $class = '')
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="' . esc_attr($class) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="id" value="' . esc_attr((string) (int) $id) . '">'
            . wp_nonce_field($action . '_' . (int) $id, '_wpnonce', true, false);
    }

    /**
     * Message à afficher après une redirection (une fois).
     */
    public static function flash(array $result)
    {
        set_transient(self::NOTICE . get_current_user_id(), $result, 120);
    }

    public static function notice()
    {
        $key = self::NOTICE . get_current_user_id();
        $result = get_transient($key);
        $test = '';

        if ((int) \Smbb\Tomo\Support\Settings::get('test_mode') === 1) {
            $test = '<div class="notice notice-warning tomo-flash"><p>' . esc_html__('Mode test actif : les e-mails et SMS partent vers l\'adresse et le téléphone de test, pas vers les vrais destinataires.', 'smbb-tomo')
                . ' <a href="' . esc_url(self::url('tomo_settings')) . '">' . esc_html__('Réglages', 'smbb-tomo') . '</a></p></div>';
        }

        if (!is_array($result) || empty($result['message'])) {
            return $test;
        }

        delete_transient($key);

        return $test . '<div class="notice notice-' . (!empty($result['success']) ? 'success' : 'error') . ' is-dismissible tomo-flash"><p>' . esc_html($result['message']) . '</p></div>';
    }

    /**
     * Select HTML simple (options libellé => valeur).
     */
    public static function select($name, array $options, $selected = '', $attrs = '')
    {
        $html = '<select name="' . esc_attr($name) . '" ' . $attrs . '>';

        foreach ($options as $label => $value) {
            $html .= '<option value="' . esc_attr((string) $value) . '"' . selected((string) $selected, (string) $value, false) . '>' . esc_html($label) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * Petit message quand les tables n'existent pas encore.
     */
    public static function schemaMissing($page_header_html = '', $notices_html = '', $title = 'Tomo')
    {
        self::page(
            self::card('', '<p>' . esc_html__("Les tables Tomo n'existent pas encore. Rechargez la page ; si le message reste, synchronisez le schéma depuis CodeTool > Ressources.", 'smbb-tomo') . '</p>'),
            $page_header_html,
            $notices_html,
            $title
        );
    }
}
