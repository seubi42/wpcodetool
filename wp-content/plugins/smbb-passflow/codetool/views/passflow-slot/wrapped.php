<?php
/**
 * Récap animé d'une journée (façon « Wrapped ») : cartes plein écran, à rejouer ou à partager.
 * Ouvert depuis le Bilan ; le lecteur et la page publique sont dans Service\Recap.
 *
 * @var array $item
 */

use Smbb\Passflow\Admin\View;
use Smbb\Passflow\Repository\Slots;
use Smbb\Passflow\Service\Passes;
use Smbb\Passflow\Service\Recap;
use Smbb\Passflow\Support\Roles;

defined('ABSPATH') || exit;

$d = 'smbb-passflow';
$header = isset($page_header_html) ? $page_header_html : '';
$notices = isset($notices_html) ? $notices_html : '';
$slot = !empty($item['id']) ? Slots::find($item['id']) : null;

if (!$slot || !Roles::canManage() || Passes::isPass($slot)) {
    View::page(View::card('', '<p>' . esc_html__('Créneau introuvable.', $d) . '</p>'), $header, $notices, __('Récap', $d));
    return;
}

$id = (int) $slot['id'];
$report_url = View::url('passflow_slot', array('view' => 'report', 'id' => $id));

// Sous le lecteur (plein écran) : un lien de secours vers le Bilan.
$fallback = '<p class="passflow-bc-back"><a href="' . esc_url($report_url) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> ' . esc_html__('Retour au bilan', $d) . '</a></p>';

View::page(
    $fallback . Recap::player(Recap::data($slot), array(
        'close' => $report_url,
        'share' => Recap::shareUrl($id, false),
        'share_money' => Recap::shareUrl($id, true),
    )),
    $header,
    $notices,
    __('Récap', $d),
    'compact'
);
