<?php

namespace Smbb\SignConnectProxima\Front;

use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Memberships;
use Smbb\SignConnectProxima\Service\Billing;
use Smbb\SignConnectProxima\Service\MemberService;
use Smbb\SignConnectProxima\Service\StampService;
use Smbb\SignConnectProxima\Service\Usage;
use Smbb\SignConnectProxima\Support\Avatar;
use Smbb\SignConnectProxima\Support\Choices;
use Smbb\SignConnectProxima\Support\Inbox;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\UserPrefs;

defined('ABSPATH') || exit;

/**
 * Écrans de l'app autres que l'envoi : historique, consommation, utilisateurs,
 * facturation (admin d'organisation) et profil. Rendu serveur, sans dépendance JS
 * (sauf le pavé de signature du profil).
 */
final class AppViews
{
    const D = 'smbb-signaconnect-proxima';

    /* ------------------------------------------------------------------ Bandeau du mois */

    /**
     * Jauges du mois en cours, en tête de « Nouvel envoi ».
     */
    public static function monthStrip(array $memberships)
    {
        $month = Billing::currentMonth();
        $cards = '';

        foreach ($memberships as $membership) {
            $gauges = Usage::gauges($membership, $month);
            $items = '';

            foreach ($gauges as $level => $gauge) {
                if ($gauge['included'] === 0 && $gauge['consumed'] === 0 && empty($gauge['unlimited'])) {
                    continue;
                }

                $items .= self::gauge(Levels::shortLabel($level), $gauge, true);
            }

            if ($items === '') {
                continue;
            }

            $cards .= '<div class="scp-strip-item">'
                . (count($memberships) > 1 ? '<div class="scp-strip-org">' . esc_html($membership['organization_name']) . '</div>' : '')
                . '<div class="scp-strip-gauges">' . $items . '</div></div>';
        }

        if ($cards === '') {
            return '';
        }

        return '<a class="scp-strip" href="' . esc_url(self::url('usage')) . '"><div class="scp-strip-title">'
            . esc_html(sprintf(__('Ma consommation · %s', self::D), Billing::monthLabel($month))) . '</div>' . $cards . '</a>';
    }

    /* ------------------------------------------------------------------ Consommation */

    public static function usage($user_id, array $scope)
    {
        $html = '';
        $current = Billing::currentMonth();

        // Ma consommation mensuelle : une carte par organisation, mois au choix, puis l'historique.
        $team_month = self::query('month', $current);
        $team_id_param = absint(self::query('team_id', '0'));
        $multi = count($scope['memberships']) > 1;

        foreach ($scope['memberships'] as $membership) {
            $plan = Usage::plan((int) $membership['plan_id']);
            $first = substr((string) $membership['active_since'], 0, 7);
            $first = Billing::validMonth($first) ? $first : '';
            $options = self::monthOptions(24, $first);
            $my_month = self::query('my_month', $current);
            $my_month = isset($options[$my_month]) ? $my_month : $current;
            $months = array_slice(array_keys($options), 0, 12);
            $counts = Usage::counts(array((int) $membership['id']), end($months), $current);
            $mine = isset($counts[(int) $membership['id']]) ? $counts[(int) $membership['id']] : array();
            $gauges = Usage::gauges($membership, $my_month, isset($mine[$my_month]) ? $mine[$my_month] : array());
            $items = '';

            foreach ($gauges as $level => $gauge) {
                $items .= self::gauge(Levels::label($level), $gauge);
            }

            $history = '';

            foreach ($months as $month) {
                if ($month === $my_month) {
                    continue;
                }

                $cells = '';
                $month_gauges = Usage::gauges($membership, $month, isset($mine[$month]) ? $mine[$month] : array());

                foreach ($month_gauges as $gauge) {
                    $cells .= '<td>' . self::ratio($gauge) . '</td>';
                }

                $history .= '<tr><td>' . esc_html(ucfirst(Billing::monthLabel($month))) . '</td>' . $cells . '</tr>';
            }

            $plan_name = $plan ? (string) $plan['name'] : '';

            if ($plan_name !== '' && stripos($plan_name, 'forfait') !== 0) {
                $plan_name = sprintf(__('Forfait %s', self::D), $plan_name);
            }

            $form = '<form class="scp-filters scp-filters--inline" method="get">'
                . self::keepQuery(array_filter(array('scp_view' => 'usage', 'month' => $team_month !== $current ? $team_month : '', 'team_id' => $team_id_param ? (string) $team_id_param : '')))
                . self::select('my_month', $options, $my_month)
                . '</form>';

            $html .= '<section class="scp-card"><div class="scp-step-head"><div><h2>' . esc_html__('Ma consommation mensuelle', self::D) . '</h2>'
                . '<span class="scp-muted">' . esc_html(implode(' · ', array_filter(array($multi ? $membership['organization_name'] : '', $plan_name)))) . '</span></div>'
                . $form . '</div>'
                . '<div class="scp-gauges">' . $items . '</div>'
                . '<p class="scp-hint">' . esc_html__('Une signature compte quand elle aboutit. Au-delà des signatures incluses, rien n\'est bloqué : le dépassement est facturé à votre organisation. Les signatures non utilisées ne sont pas reportées.', self::D) . '</p>';

            if ($history !== '') {
                $html .= '<h3 class="scp-subtitle">' . esc_html__('Sur les derniers mois', self::D) . '</h3>' . self::levelTable($history, __('Mois', self::D));
            }

            $html .= '</section>';
        }

        // Superviseurs : leur périmètre, mois au choix.
        if (Memberships::isSupervisor($scope)) {
            $month = self::query('month', $current);
            $month = Billing::validMonth($month) ? $month : $current;
            $team_id = absint(self::query('team_id', '0'));
            $members = Memberships::inScope($scope);

            if ($team_id > 0) {
                $members = array_filter($members, function ($member) use ($team_id) {
                    return in_array($team_id, $member['team_id_list'], true);
                });
            }

            $counts = Usage::counts(array_keys($members), $month, $month);
            $rows = '';
            $totals = array_fill_keys(Levels::all(), 0);

            foreach ($members as $member_id => $member) {
                $gauges = Usage::gauges($member, $month, isset($counts[$member_id][$month]) ? $counts[$member_id][$month] : array());
                $cells = '';

                foreach ($gauges as $level => $gauge) {
                    $cells .= '<td>' . self::ratio($gauge, true) . '</td>';
                    $totals[$level] += $gauge['consumed'];
                }

                $team_names = array();

                foreach ($member['team_id_list'] as $member_team) {
                    $team_names[] = Lookup::teamName($member_team);
                }

                $rows .= '<tr><td><strong>' . esc_html($member['user_name']) . '</strong><small>' . esc_html(implode(', ', array_filter($team_names))) . '</small></td>' . $cells . '</tr>';
            }

            $total_cells = '';

            foreach ($totals as $total) {
                $total_cells .= '<td><strong>' . esc_html(number_format_i18n($total)) . '</strong></td>';
            }

            $my_month_param = self::query('my_month', '');
            $form = '<form class="scp-filters" method="get">' . self::keepQuery(array_filter(array('scp_view' => 'usage', 'my_month' => Billing::validMonth($my_month_param) ? $my_month_param : '')))
                . self::select('month', self::monthOptions(24, ''), $month)
                . self::select('team_id', array('0' => __('Toutes les équipes', self::D)) + self::scopeTeams($scope), (string) $team_id)
                . '</form>';

            $html .= '<section class="scp-card"><div class="scp-step-head"><h2>' . esc_html__('Consommation des collaborateurs', self::D) . '</h2></div>' . $form
                . ($rows === ''
                    ? '<p class="scp-muted">' . esc_html__('Aucun utilisateur dans ce périmètre.', self::D) . '</p>'
                    : self::levelTable($rows . '<tr class="scp-total"><td>' . esc_html__('Total', self::D) . '</td>' . $total_cells . '</tr>', __('Utilisateur', self::D)))
                . '</section>';
        }

        return $html;
    }

    /* ------------------------------------------------------------------ Utilisateurs (admin d'organisation) */

    public static function members($user_id, array $scope)
    {
        $organization_id = self::adminOrganizationId($scope);
        $organization = MemberService::adminOrganization($user_id, $organization_id);
        $teams = MemberService::teams($organization_id);
        $roles = Choices::roles();
        $members = Memberships::inScope(array('org_ids' => array($organization_id), 'team_ids' => array()), true, $organization_id);
        $html = self::organizationSwitch($scope, $organization_id, 'members');
        $rows = '';

        foreach ($members as $member) {
            $id = (int) $member['id'];
            $team_names = array();

            foreach ($member['team_id_list'] as $team_id) {
                $team_names[] = Lookup::teamName($team_id);
            }

            $rows .= '<details class="scp-member' . ((int) $member['is_active'] ? '' : ' is-inactive') . '"><summary>'
                . '<span class="scp-member-name">' . Avatar::html((int) $member['user_id'], (string) $member['user_name']) . '<span><strong>' . esc_html($member['user_name']) . '</strong><small>' . esc_html($member['user_email']) . '</small></span></span>'
                . '<span class="scp-member-meta">' . esc_html($roles[$member['role']] ?? $member['role']) . '<small>' . esc_html(implode(', ', array_filter($team_names))) . '</small></span>'
                . '<span class="scp-member-tags">' . ((int) $member['can_manage_templates'] ? '<span class="scp-badge">' . esc_html__('Modèles', self::D) . '</span>' : '')
                . ((int) $member['is_active'] ? '' : '<span class="scp-badge scp-badge--cancelled">' . esc_html__('Désactivé', self::D) . '</span>')
                . '<span class="scp-badge scp-badge--plan">' . esc_html(Lookup::planName($member['plan_id']) ?: '—') . '</span></span>'
                . '</summary>'
                . '<form class="scp-member-form" method="post" action="' . AppActions::formAction() . '">' . AppActions::fields(AppActions::MEMBER, 'members')
                . '<input type="hidden" name="membership_id" value="' . $id . '">'
                . self::memberFields($teams, $roles, $member['team_id_list'], $member['role'], (int) $member['can_manage_templates'], (int) $member['is_active'], (int) $member['user_id'] === (int) $user_id)
                . '<div class="scp-actions"><button class="scp-button">' . esc_html__('Enregistrer', self::D) . '</button></div>'
                . '</form></details>';
        }

        $html .= '<section class="scp-card"><div class="scp-step-head"><h2>' . esc_html__('Utilisateurs', self::D) . '</h2>'
            . '<span class="scp-muted">' . esc_html(sprintf(_n('%d personne', '%d personnes', count($members), self::D), count($members))) . '</span></div>'
            . '<div class="scp-members">' . $rows . '</div>'
            . '<p class="scp-hint">' . esc_html__('Le forfait de chaque utilisateur est fixé par votre prestataire.', self::D) . '</p></section>';

        // Invitation.
        $html .= '<section class="scp-card"><h2>' . esc_html__('Inviter une personne', self::D) . '</h2>'
            . '<form method="post" action="' . AppActions::formAction() . '">' . AppActions::fields(AppActions::INVITE, 'members')
            . '<input type="hidden" name="organization_id" value="' . (int) $organization_id . '">'
            . '<div class="scp-grid">'
            . '<label class="scp-field scp-field--wide"><span>' . esc_html__('Email', self::D) . '</span><input type="email" name="email" required autocomplete="off"></label>'
            . '<label class="scp-field"><span>' . esc_html__('Prénom', self::D) . '</span><input name="first_name" autocomplete="off"></label>'
            . '<label class="scp-field"><span>' . esc_html__('Nom', self::D) . '</span><input name="last_name" autocomplete="off"></label>'
            . '</div>'
            . self::memberFields($teams, $roles, count($teams) === 1 ? array_keys($teams) : array(), 'user', 0, 1, false, true)
            . '<p class="scp-hint">' . esc_html(sprintf(
                __('La personne reçoit un email pour choisir son mot de passe. Forfait appliqué : %s.', self::D),
                Lookup::planName($organization['default_plan_id']) ?: __('aucun (à demander à votre prestataire)', self::D)
            )) . '</p>'
            . '<div class="scp-actions"><button class="scp-button">' . esc_html__('Envoyer l\'invitation', self::D) . '</button></div>'
            . '</form></section>';

        return $html;
    }

    /* ------------------------------------------------------------------ Facturation (admin d'organisation) */

    public static function billing($user_id, array $scope)
    {
        $organization_id = self::adminOrganizationId($scope);
        MemberService::adminOrganization($user_id, $organization_id);
        $first = Billing::firstMonth($organization_id);
        $month = self::query('month', Billing::currentMonth());
        $month = Billing::validMonth($month) && $month >= $first ? $month : Billing::currentMonth();
        $invoice = Billing::get($organization_id, $month);
        $closed = $invoice['status'] === Billing::STATUS_CLOSED;

        $html = self::organizationSwitch($scope, $organization_id, 'billing');
        $html .= '<form class="scp-filters" method="get">' . self::keepQuery(array('scp_view' => 'billing', 'org' => $organization_id))
            . self::select('month', self::monthOptions(240, $first), $month) . '</form>';

        $html .= '<section class="scp-card"><div class="scp-invoice-head"><div><h2>' . esc_html(sprintf(__('Proforma %s', self::D), Billing::monthLabel($month))) . '</h2>'
            . '<span class="scp-badge ' . ($closed ? 'scp-badge--signed' : 'scp-badge--sent') . '">' . esc_html($closed ? __('Clôturée', self::D) : __('En cours, recalculée en direct', self::D)) . '</span></div>'
            . '<div class="scp-invoice-total">' . esc_html(Billing::money($invoice['total'])) . ' <small>' . esc_html__('HT', self::D) . '</small></div></div>';

        $chapters = array(
            'org' => array(__('Abonnement organisation', self::D), array(), 0.0),
            'team' => array(__('Abonnements équipes', self::D), array(), 0.0),
            'users' => array(__('Forfaits utilisateurs', self::D), array(), 0.0),
        );

        foreach ($invoice['lines'] as $line) {
            $key = $line['kind'] === 'org_fee' ? 'org' : ($line['kind'] === 'team_fee' ? 'team' : 'users');
            $chapters[$key][1][] = $line;
            $chapters[$key][2] += $line['amount'];
        }

        $body = '';

        foreach ($chapters as $key => $chapter) {
            if (!$chapter[1]) {
                continue;
            }

            $body .= '<tr class="scp-invoice-chapter"><td colspan="2">' . esc_html($chapter[0]) . '</td><td class="scp-num">' . esc_html(Billing::money($chapter[2])) . '</td></tr>';
            $previous = null;

            foreach ($chapter[1] as $line) {
                if ($key === 'users' && (string) $line['user_id'] !== $previous) {
                    $body .= '<tr class="scp-invoice-user"><td colspan="3"><strong>' . esc_html($line['user']) . '</strong>' . ($line['plan'] !== '' ? ' · ' . esc_html(sprintf(__('forfait %s', self::D), $line['plan'])) : '') . '</td></tr>';
                    $previous = (string) $line['user_id'];
                }

                $body .= '<tr class="scp-invoice-line scp-invoice-line--' . esc_attr($line['kind']) . '"><td>' . esc_html($line['label']) . '</td><td>' . esc_html($line['detail']) . '</td>'
                    . '<td class="scp-num">' . ($line['kind'] === 'usage' ? '<span class="scp-muted">' . esc_html__('inclus', self::D) . '</span>' : esc_html(Billing::money($line['amount']))) . '</td></tr>';
            }
        }

        $body .= '<tr class="scp-total"><td colspan="2">' . esc_html__('Total HT', self::D) . '</td><td class="scp-num">' . esc_html(Billing::money($invoice['total'])) . '</td></tr>';

        return $html . '<table class="scp-table scp-invoice">' . $body . '</table></section>';
    }

    /* ------------------------------------------------------------------ Profil */

    public static function profile($user_id)
    {
        $user = get_userdata($user_id);
        $notify = UserPrefs::notifications($user_id);
        $signature = UserPrefs::signatureDataUrl($user_id);
        $checks = '';

        $in_app = Inbox::preferences($user_id);
        $checks = '<div class="scp-notify-grid"><span></span><b>' . esc_html__('Email', self::D) . '</b><b>' . esc_html__('Dans l\'app', self::D) . '</b>';

        foreach (UserPrefs::events() as $event => $label) {
            $checks .= '<span>' . esc_html($label) . '</span>'
                . '<label class="scp-switch"><input type="checkbox" name="notify[]" value="' . esc_attr($event) . '"' . checked(in_array($event, $notify, true), true, false) . ' aria-label="' . esc_attr($label . ' — ' . __('Email', self::D)) . '"><i></i></label>'
                . '<label class="scp-switch"><input type="checkbox" name="notify_app[]" value="' . esc_attr($event) . '"' . checked(in_array($event, $in_app, true), true, false) . ' aria-label="' . esc_attr($label . ' — ' . __('Dans l\'app', self::D)) . '"><i></i></label>';
        }

        $checks .= '</div>';

        return '<form method="post" enctype="multipart/form-data" action="' . AppActions::formAction() . '" data-scp-profile>' . AppActions::fields(AppActions::PROFILE, 'profile')
            . '<section class="scp-card"><h2>' . esc_html__('Mon compte', self::D) . '</h2>'
            . self::photoField($user_id, $user ? $user->display_name : '')
            . '<div class="scp-grid">'
            . '<div class="scp-field"><span>' . esc_html__('Nom', self::D) . '</span><div class="scp-static">' . esc_html($user ? $user->display_name : '') . '</div></div>'
            . '<div class="scp-field"><span>' . esc_html__('Email', self::D) . '</span><div class="scp-static">' . esc_html($user ? $user->user_email : '') . '</div></div>'
            . '<label class="scp-field"><span>' . esc_html__('Mobile', self::D) . '</span><input type="tel" name="mobile" value="' . esc_attr(UserPrefs::mobile($user_id)) . '" placeholder="06 12 34 56 78"></label>'
            . '</div></section>'

            . '<section class="scp-card"><h2>' . esc_html__('Ma signature', self::D) . '</h2>'
            . '<p class="scp-hint">' . esc_html__('Posée dans les zones « Signature de l\'expéditeur » des documents que vous envoyez.', self::D) . '</p>'
            . ($signature !== ''
                ? '<div class="scp-signature-current" data-scp-signature-current><img src="' . esc_attr($signature) . '" alt="' . esc_attr__('Ma signature', self::D) . '">'
                    . '<label class="scp-check"><input type="checkbox" name="signature_delete" value="1"><span>' . esc_html__('Supprimer ma signature', self::D) . '</span></label>'
                    . '<button type="button" class="scp-link" data-scp-signature-replace>' . esc_html__('Remplacer', self::D) . '</button></div>'
                : '')
            . '<div class="scp-signature-edit" data-scp-signature-edit' . ($signature !== '' ? ' hidden' : '') . '>'
            . '<div class="scp-segmented" role="tablist"><button type="button" class="scp-segment is-active" data-scp-signature-mode="draw">' . esc_html__('Dessiner', self::D) . '</button>'
            . '<button type="button" class="scp-segment" data-scp-signature-mode="upload">' . esc_html__('Importer une image', self::D) . '</button></div>'
            . '<div class="scp-pad" data-scp-signature-draw><canvas data-scp-pad aria-label="' . esc_attr__('Zone de signature', self::D) . '"></canvas><button type="button" class="scp-link" data-scp-pad-clear>' . esc_html__('Effacer', self::D) . '</button></div>'
            . '<label class="scp-drop scp-drop--small" data-scp-signature-upload hidden><input type="file" name="signature_file" accept="image/png,image/jpeg" hidden data-scp-signature-file>'
            . '<strong>' . esc_html__('Choisir une image', self::D) . '</strong><span data-scp-signature-filename>' . esc_html__('PNG ou JPEG, fond blanc ou transparent, 400 Ko max.', self::D) . '</span></label>'
            . '<input type="hidden" name="signature_data" data-scp-signature-data>'
            . '</div></section>'

            . self::clientDevices($user_id)

            . '<section class="scp-card"><h2>' . esc_html__('Notifications', self::D) . '</h2>'
            . '<p class="scp-hint">' . esc_html__('Pour les documents que vous envoyez.', self::D) . '</p>' . $checks . '</section>'

            . '<div class="scp-actions"><button class="scp-button">' . esc_html__('Enregistrer', self::D) . '</button></div></form>';
    }

    /**
     * Photo de profil : aperçu rond, choix d'une image (recadrée en carré côté serveur) ou retrait.
     */
    private static function photoField($user_id, $name)
    {
        $has = Avatar::url($user_id) !== '';

        return '<div class="scp-photo" data-scp-photo>'
            . '<span class="scp-photo-preview" data-scp-photo-preview>' . Avatar::html($user_id, $name, 'scp-face--xl') . '</span>'
            . '<div class="scp-photo-body"><strong>' . esc_html__('Photo de profil', self::D) . '</strong>'
            . '<small data-scp-photo-hint>' . esc_html__('JPEG, PNG ou WebP. Recadrée en carré, au centre.', self::D) . '</small>'
            . '<div class="scp-photo-actions">'
            . '<label class="scp-button scp-button--ghost scp-button--small"><input type="file" name="avatar_file" accept="image/jpeg,image/png,image/webp" hidden data-scp-photo-file>'
            . esc_html($has ? __('Changer la photo', self::D) : __('Ajouter une photo', self::D)) . '</label>'
            . ($has ? '<label class="scp-check"><input type="checkbox" name="avatar_delete" value="1" data-scp-photo-delete><span>' . esc_html__('Retirer', self::D) . '</span></label>' : '')
            . '</div></div></div>';
    }

    /* ------------------------------------------------------------------ Tampons (admin d'organisation) */

    /**
     * Tampon de l'organisation et, au besoin, un tampon par équipe (prioritaire sur celui de l'organisation).
     */
    public static function stamps($user_id, array $scope)
    {
        $organization_id = self::adminOrganizationId($scope);
        $organization = MemberService::adminOrganization($user_id, $organization_id);
        $org_stamp = !empty($organization['stamp_attachment_id']) ? (int) $organization['stamp_attachment_id'] : 0;
        $html = self::organizationSwitch($scope, $organization_id, 'stamps');

        $html .= '<section class="scp-card"><div class="scp-step-head"><h2>' . esc_html__("Tampon de l'organisation", self::D) . '</h2></div>'
            . '<p class="scp-hint">' . esc_html__("Posé dans les zones « Tampon » des documents, avec le nom de l'organisation. PNG sur fond transparent conseillé.", self::D) . '</p>'
            . '<div class="scp-stamps">' . self::stampItem($organization_id, 0, $organization['name'], $org_stamp, '') . '</div></section>';

        $teams = MemberService::teams($organization_id);
        $items = '';

        foreach ($teams as $team_id => $team_name) {
            $team = StampService::team($team_id);
            $own = $team && !empty($team['stamp_attachment_id']) ? (int) $team['stamp_attachment_id'] : 0;
            $items .= self::stampItem($organization_id, $team_id, $team_name, $own, $org_stamp ? __("Utilise celui de l'organisation", self::D) : __('Aucun tampon', self::D));
        }

        if ($items !== '') {
            $html .= '<section class="scp-card"><div class="scp-step-head"><h2>' . esc_html__('Tampons des équipes', self::D) . '</h2>'
                . '<span class="scp-muted">' . esc_html__('Optionnels', self::D) . '</span></div>'
                . '<p class="scp-hint">' . esc_html__("Le tampon d'une équipe remplace celui de l'organisation dans les documents envoyés depuis cette équipe.", self::D) . '</p>'
                . '<div class="scp-stamps">' . $items . '</div></section>';
        }

        return $html;
    }

    private static function stampItem($organization_id, $team_id, $name, $attachment_id, $fallback)
    {
        $url = StampService::url($attachment_id);
        $form = function ($inner, $class = '') use ($organization_id, $team_id) {
            return '<form class="scp-stamp-form' . $class . '" method="post" enctype="multipart/form-data" action="' . AppActions::formAction() . '">'
                . AppActions::fields(AppActions::STAMP, 'stamps')
                . '<input type="hidden" name="organization_id" value="' . (int) $organization_id . '">'
                . '<input type="hidden" name="team_id" value="' . (int) $team_id . '">' . $inner . '</form>';
        };

        return '<div class="scp-stamp' . ($url !== '' ? ' has-stamp' : '') . '" data-scp-stamp>'
            . '<div class="scp-stamp-preview" data-scp-stamp-preview>' . ($url !== '' ? '<img src="' . esc_url($url) . '" alt="">' : '<span>' . esc_html($fallback !== '' ? $fallback : __('Aucun tampon', self::D)) . '</span>') . '</div>'
            . '<div class="scp-stamp-body"><strong>' . esc_html($name) . '</strong>'
            . '<div class="scp-stamp-actions">'
            . $form('<label class="scp-button scp-button--ghost scp-button--small"><input type="file" name="stamp_file" accept="image/png,image/jpeg" hidden data-scp-stamp-file>'
                . esc_html($url !== '' ? __('Remplacer', self::D) : __('Importer', self::D)) . '</label>')
            . ($url !== '' ? $form('<input type="hidden" name="delete" value="1"><button class="scp-link scp-link--danger">' . esc_html__('Retirer', self::D) . '</button>') : '')
            . '</div></div></div>';
    }

    /**
     * Page « Connecteur Windows » : téléchargement du setup déposé dans clients/ et mise en route.
     */
    public static function connector()
    {
        // Nouveau nom du setup d'abord, l'ancien (signconnect-setup.exe) reste accepté.
        $file = is_readable(SMBB_SCP_PATH . 'clients/signaconnect-setup.exe') ? 'clients/signaconnect-setup.exe' : 'clients/signconnect-setup.exe';
        $path = SMBB_SCP_PATH . $file;

        if (!is_readable($path)) {
            return Assets::message(__('Connecteur bientôt disponible', self::D), __("Le programme d'installation n'a pas encore été publié.", self::D));
        }

        $url = add_query_arg('v', (string) filemtime($path), SMBB_SCP_URL . $file);
        $meta = sprintf(__('Windows 10 et 11 · %1$s · mis à jour le %2$s', self::D), size_format(filesize($path), 1), date_i18n(get_option('date_format'), filemtime($path)));
        // Le setup Inno Setup (32 bits) installe dans Program Files (x86).
        $exe = 'C:\\Program Files (x86)\\SignaConnect\\SignaConnectUpload.exe';
        $command = '"' . $exe . '" --delete "$file"';

        return '<section class="scp-card scp-connector">'
            . '<div class="scp-connector-head"><div class="scp-login-mark scp-connector-mark" aria-hidden="true"></div><div>'
            . '<h2>' . esc_html__('Connecteur Windows', self::D) . '</h2>'
            . '<p class="scp-hint">' . esc_html__('Imprimez n\'importe quel document vers l\'imprimante PDF « SignaConnect » : il arrive directement ici, prêt pour placer les signatures.', self::D) . '</p>'
            . '</div></div>'
            . '<div class="scp-connector-download"><a class="scp-button" href="' . esc_url($url) . '" download>'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>' . esc_html__('Télécharger', self::D) . '</a>'
            . '<small>' . esc_html($meta) . '</small></div>'
            . '<h3>' . esc_html__('Mise en route', self::D) . '</h3><ol class="scp-connector-steps">'
            . '<li>' . esc_html__('Installez le programme téléchargé.', self::D) . '</li>'
            . '<li>' . sprintf(esc_html__('Au premier lancement, indiquez l\'adresse %s, votre identifiant et votre mot de passe. Le mot de passe n\'est pas enregistré sur l\'ordinateur.', self::D), '<code>' . esc_html(untrailingslashit(home_url())) . '</code>') . '</li>'
            . '<li>' . esc_html__('Reliez-le à votre imprimante PDF :', self::D)
            . '<div class="scp-connector-printers">'
            . '<div><strong>PDFCreator (gratuit)</strong><ul>'
            . '<li>' . esc_html__('Profils › Ajouter : profil « SignaConnect ».', self::D) . '</li>'
            . '<li>' . esc_html__('Sauvegarder : « Automatique », nom <Title>, répertoire par exemple C:\\Temp\\SignaConnect.', self::D) . '</li>'
            . '<li>' . esc_html__('Actions › Ajouter une action › « Exécuter le programme » :', self::D) . '<code class="scp-connector-command">' . esc_html($exe) . '</code>'
            . esc_html__('Paramètres supplémentaires : --delete. Laissez « Attendre la fin d\'exécution » décoché.', self::D) . '</li>'
            . '<li>' . esc_html__('Imprimante › Ajouter une imprimante « SignaConnect » liée à ce profil.', self::D) . '</li>'
            . '</ul></div>'
            . '<div><strong>PDF24</strong><ul>'
            . '<li>' . esc_html__('Paramètres › Imprimante PDF : action « Enregistrer automatiquement ».', self::D) . '</li>'
            . '<li>' . esc_html__('Cochez « Exécuter la commande suivante après l\'enregistrement » :', self::D) . '<code class="scp-connector-command">' . esc_html($command) . '</code></li>'
            . '</ul></div>'
            . '</div></li>'
            . '<li>' . esc_html__('Imprimez : le navigateur s\'ouvre sur le document, il ne reste qu\'à placer les zones et envoyer.', self::D) . '</li>'
            . '</ol>'
            . '<p class="scp-hint">' . sprintf(esc_html__('Les ordinateurs reliés à votre compte se gèrent dans %s.', self::D), '<a href="' . esc_url(self::url('profile')) . '">' . esc_html__('Mon profil', self::D) . '</a>') . '</p>'
            . '</section>';
    }

    /** Postes connectés avec le client Windows (imprimante PDF), révocables. */
    private static function clientDevices($user_id)
    {
        $devices = ClientApi::devices($user_id);

        if (!$devices) {
            return '';
        }

        $rows = '';

        foreach ($devices as $device_id => $device) {
            $rows .= '<label class="scp-check"><input type="checkbox" name="client_revoke[]" value="' . esc_attr($device_id) . '"><span>'
                . esc_html(sprintf(__('Révoquer « %1$s » (utilisé le %2$s)', self::D), $device['name'], mysql2date(get_option('date_format') . ' H:i', $device['last_used'])))
                . '</span></label>';
        }

        return '<section class="scp-card"><h2>' . esc_html__('Postes connectés', self::D) . '</h2>'
            . '<p class="scp-hint">' . esc_html__('Ordinateurs reliés à votre compte par le client Windows SignaConnect (imprimante PDF). Un poste révoqué devra se reconnecter.', self::D) . '</p>'
            . $rows . '</section>';
    }

    /* ------------------------------------------------------------------ Outils */

    public static function url($view, array $args = array())
    {
        return add_query_arg(array_merge(array('scp_view' => $view), $args), remove_query_arg(array_merge(array('scp_view', 'status', 'org'), TransactionViews::PARAMS)));
    }

    private static function gauge($label, array $gauge, $compact = false)
    {
        if (!empty($gauge['unlimited'])) {
            return '<div class="scp-gauge is-unlimited' . ($compact ? ' is-compact' : '') . '">'
                . '<div class="scp-gauge-head"><span>' . esc_html($label) . '</span><strong>' . esc_html(sprintf(_n('%d signature', '%d signatures', $gauge['consumed'], self::D), $gauge['consumed'])) . '</strong></div>'
                . '<div class="scp-gauge-bar"><span style="width:100%"></span></div>'
                . (!$compact ? '<small>' . esc_html__('Illimité', self::D) . '</small>' : '')
                . '</div>';
        }

        $ratio = $gauge['included'] > 0 ? min(100, round($gauge['consumed'] * 100 / $gauge['included'])) : ($gauge['consumed'] > 0 ? 100 : 0);
        $over = $gauge['overage'] > 0;
        $text = $gauge['included'] > 0
            ? sprintf(__('%1$d sur %2$d incluses', self::D), $gauge['consumed'], $gauge['included'])
            : sprintf(_n('%d signature', '%d signatures', $gauge['consumed'], self::D), $gauge['consumed']);
        $note = $over
            ? sprintf(_n('%d en dépassement', '%d en dépassement', $gauge['overage'], self::D), $gauge['overage'])
            : ($gauge['included'] > 0 ? sprintf(_n('%d restante', '%d restantes', $gauge['remaining'], self::D), $gauge['remaining']) : '');

        return '<div class="scp-gauge' . ($over ? ' is-over' : '') . ($compact ? ' is-compact' : '') . '">'
            . '<div class="scp-gauge-head"><span>' . esc_html($label) . '</span><strong>' . esc_html($text) . '</strong></div>'
            . '<div class="scp-gauge-bar"><span style="width:' . (int) $ratio . '%"></span></div>'
            . ($note !== '' && !$compact ? '<small>' . esc_html($note) . '</small>' : '')
            . '</div>';
    }

    private static function ratio(array $gauge, $with_bar = false)
    {
        if (!empty($gauge['unlimited'])) {
            return esc_html($gauge['consumed']) . ' <span class="scp-muted">· ' . esc_html__('illimité', self::D) . '</span>';
        }

        if ($gauge['consumed'] === 0 && $gauge['included'] === 0) {
            return '<span class="scp-muted">—</span>';
        }

        $text = $gauge['included'] > 0 ? $gauge['consumed'] . ' / ' . $gauge['included'] : (string) $gauge['consumed'];
        $html = '<span class="' . ($gauge['overage'] > 0 ? 'scp-over' : '') . '">' . esc_html($text) . '</span>';

        if ($with_bar && $gauge['included'] > 0) {
            $ratio = min(100, round($gauge['consumed'] * 100 / $gauge['included']));
            $html .= '<div class="scp-gauge-bar is-thin' . ($gauge['overage'] > 0 ? ' is-over' : '') . '"><span style="width:' . (int) $ratio . '%"></span></div>';
        }

        return $html;
    }

    private static function levelTable($rows, $first_heading)
    {
        $heads = '';

        foreach (Levels::all() as $level) {
            $heads .= '<th>' . esc_html(Levels::shortLabel($level)) . '</th>';
        }

        return '<div class="scp-table-wrap"><table class="scp-table scp-table--compact"><thead><tr><th>' . esc_html($first_heading) . '</th>' . $heads . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private static function memberFields(array $teams, array $roles, array $team_ids, $role, $templates, $active, $is_self, $invite = false)
    {
        $team_html = '';

        foreach ($teams as $id => $name) {
            $team_html .= '<label class="scp-chip"><input type="checkbox" name="team_ids[]" value="' . (int) $id . '"' . checked(in_array((int) $id, array_map('intval', $team_ids), true), true, false) . '><span>' . esc_html($name) . '</span></label>';
        }

        $role_options = '';

        foreach ($roles as $key => $label) {
            $role_options .= '<option value="' . esc_attr($key) . '"' . selected($key, $role, false) . '>' . esc_html($label) . '</option>';
        }

        return '<div class="scp-field"><span>' . esc_html__('Équipes', self::D) . '</span><div class="scp-chips">' . $team_html . '</div></div>'
            . '<div class="scp-grid">'
            . '<label class="scp-field"><span>' . esc_html__('Rôle', self::D) . '</span><select name="role"' . ($is_self ? ' disabled' : '') . '>' . $role_options . '</select>'
            . ($is_self ? '<input type="hidden" name="role" value="' . esc_attr($role) . '">' : '') . '</label>'
            . '<div class="scp-field"><span>&nbsp;</span>'
            . '<label class="scp-check"><input type="checkbox" name="can_manage_templates" value="1"' . checked($templates, 1, false) . '><span>' . esc_html__('Peut gérer les modèles', self::D) . '</span></label>'
            . ($invite ? '' : ($is_self
                ? '<input type="hidden" name="is_active" value="1">'
                : '<label class="scp-check"><input type="checkbox" name="is_active" value="1"' . checked($active, 1, false) . '><span>' . esc_html__('Compte actif', self::D) . '</span></label>'))
            . '</div></div>';
    }

    private static function adminOrganizationId(array $scope)
    {
        $requested = absint(self::query('org', '0'));

        return in_array($requested, $scope['admin_org_ids'], true) ? $requested : (int) reset($scope['admin_org_ids']);
    }

    private static function organizationSwitch(array $scope, $organization_id, $view)
    {
        if (count($scope['admin_org_ids']) < 2) {
            return '';
        }

        $options = array();

        foreach ($scope['admin_org_ids'] as $id) {
            $options[(string) $id] = Lookup::organizationName($id);
        }

        return '<form class="scp-filters" method="get">' . self::keepQuery(array('scp_view' => $view)) . self::select('org', $options, (string) $organization_id) . '</form>';
    }

    /**
     * Équipes du périmètre d'un superviseur : id => « Organisation — Équipe ».
     */
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

    private static function monthOptions($count, $first)
    {
        $options = array();

        foreach (Usage::months($count, $first) as $month) {
            $options[$month] = ucfirst(Billing::monthLabel($month));
        }

        return $options;
    }

    public static function select($name, array $options, $value)
    {
        $html = '<select name="' . esc_attr($name) . '" onchange="this.form.submit()">';

        foreach ($options as $key => $label) {
            $html .= '<option value="' . esc_attr($key) . '"' . selected((string) $key, (string) $value, false) . '>' . esc_html($label) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * Champs cachés pour conserver la page (et ses autres paramètres) dans un formulaire GET.
     */
    public static function keepQuery(array $values)
    {
        $html = '';
        $page_id = get_queried_object_id();

        if ($page_id && !get_option('permalink_structure')) {
            $html .= '<input type="hidden" name="page_id" value="' . (int) $page_id . '">';
        }

        foreach ($values as $key => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }

        return $html;
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
