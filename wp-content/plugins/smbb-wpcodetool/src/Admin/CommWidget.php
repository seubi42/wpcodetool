<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Widget d'accueil commun aux canaux de CodeTool › Communication (E-mails, SMS) : même présentation,
 * une illustration par canal (enveloppe, bulle de message), état, compteurs 7 jours, graphique, liens.
 * Et le bandeau d'alerte « ne partent plus », lui aussi commun.
 */
final class CommWidget
{
    /** @var bool Styles déjà imprimés sur la page. */
    private static $css = false;

    /**
     * @param array $c {
     *     kind: 'mail'|'sms', tone: 'is-good'|'is-bad'|'is-idle', title, copy, log: bool, stats: array,
     *     noun_sent, noun_failed, urls: {sent, failed, test, log, settings}, test_label,
     *     extra: {value, label, url, title, alert} (facultatif, 3e cadran : conso SMS du mois, IP bloquées…),
     *     rows: [{icon, label, value, tone, url, title}] (facultatif, lignes d'état sous l'en-tête),
     *     bar_title: format sprintf des infobulles des barres (%1$d, %2$d), test_icon, log_label (facultatifs)
     * }
     */
    public static function render(array $c)
    {
        $stats = $c['stats'];
        $max = 1;

        foreach ($stats['days'] as $day) {
            $max = max($max, $day['sent'] + $day['failed']);
        }

        $today = wp_date('Y-m-d');
        $badge = $c['tone'] === 'is-bad' ? '!' : ($c['tone'] === 'is-good' ? '✓' : '•');
        ?>
        <div class="smbb-comm-widget is-<?php echo esc_attr($c['kind']); ?>">
            <div class="smbb-comm-widget-head <?php echo esc_attr($c['tone']); ?>">
                <span class="smbb-comm-widget-art" aria-hidden="true">
                    <?php echo self::art($c['kind']); // phpcs:ignore -- SVG fixe. ?>
                    <span class="smbb-comm-widget-badge"><?php echo esc_html($badge); ?></span>
                </span>
                <div class="smbb-comm-widget-state">
                    <strong><?php echo esc_html($c['title']); ?></strong>
                    <span><?php echo esc_html($c['copy']); ?></span>
                </div>
            </div>

            <?php if (!empty($c['rows'])) : ?>
                <ul class="smbb-comm-widget-rows">
                    <?php foreach ($c['rows'] as $row) : ?>
                        <li class="<?php echo esc_attr(isset($row['tone']) ? $row['tone'] : 'is-idle'); ?>">
                            <span class="dashicons <?php echo esc_attr($row['icon']); ?>" aria-hidden="true"></span>
                            <a href="<?php echo esc_url($row['url']); ?>"><?php echo esc_html($row['label']); ?></a>
                            <span class="smbb-comm-widget-row-value"<?php echo !empty($row['title']) ? ' title="' . esc_attr($row['title']) . '"' : ''; ?>><?php echo esc_html($row['value']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($c['log'])) : ?>
                <div class="smbb-comm-widget-counts<?php echo !empty($c['extra']) ? ' has-extra' : ''; ?>">
                    <a class="smbb-comm-widget-count is-sent" href="<?php echo esc_url($c['urls']['sent']); ?>">
                        <span class="smbb-comm-widget-value"><?php echo esc_html(number_format_i18n($stats['sent'])); ?></span>
                        <span class="smbb-comm-widget-label"><?php echo esc_html($c['noun_sent']); ?></span>
                    </a>
                    <a class="smbb-comm-widget-count is-failed<?php echo $stats['failed'] ? ' has-errors' : ''; ?>" href="<?php echo esc_url($c['urls']['failed']); ?>">
                        <span class="smbb-comm-widget-value"><?php echo esc_html(number_format_i18n($stats['failed'])); ?></span>
                        <span class="smbb-comm-widget-label"><?php echo esc_html($c['noun_failed']); ?></span>
                    </a>
                    <?php if (!empty($c['extra'])) : ?>
                        <a class="smbb-comm-widget-count is-extra<?php echo !empty($c['extra']['alert']) ? ' has-errors' : ''; ?>" href="<?php echo esc_url($c['extra']['url']); ?>"<?php echo !empty($c['extra']['title']) ? ' title="' . esc_attr($c['extra']['title']) . '"' : ''; ?>>
                            <span class="smbb-comm-widget-value"><?php echo esc_html($c['extra']['value']); ?></span>
                            <span class="smbb-comm-widget-label"><?php echo esc_html($c['extra']['label']); ?></span>
                        </a>
                    <?php endif; ?>
                    <div class="smbb-comm-widget-bars" aria-hidden="true">
                        <?php foreach ($stats['days'] as $day => $count) : ?>
                            <span class="smbb-comm-widget-bar<?php echo $day === $today ? ' is-today' : ''; ?>" title="<?php echo esc_attr(wp_date('D j', strtotime($day . ' 12:00:00')) . ' : ' . sprintf(!empty($c['bar_title']) ? $c['bar_title'] : __('%1$d envoyés, %2$d en erreur', 'smbb-wpcodetool'), $count['sent'], $count['failed'])); ?>">
                                <span class="is-failed" style="height:<?php echo esc_attr((string) round($count['failed'] / $max * 78)); ?>%"></span>
                                <span class="is-sent" style="height:<?php echo esc_attr((string) round($count['sent'] / $max * 78)); ?>%"></span>
                                <em><?php echo esc_html(wp_date('D', strtotime($day . ' 12:00:00'))); ?></em>
                                <?php if ($count['sent'] + $count['failed'] > 0) : ?><b><?php echo esc_html(number_format_i18n($count['sent'] + $count['failed'])); ?></b><?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="smbb-comm-widget-period"><?php esc_html_e('7 derniers jours', 'smbb-wpcodetool'); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Le journal est coupé : pas de compteurs.', 'smbb-wpcodetool'); ?></p>
            <?php endif; ?>

            <p class="smbb-comm-widget-actions">
                <a class="button<?php echo $c['tone'] === 'is-bad' ? ' button-primary' : ''; ?>" href="<?php echo esc_url($c['urls']['test']); ?>"><span class="dashicons <?php echo esc_attr(!empty($c['test_icon']) ? $c['test_icon'] : 'dashicons-controls-play'); ?>" aria-hidden="true"></span> <?php echo esc_html($c['test_label']); ?></a>
                <a class="button" href="<?php echo esc_url($c['urls']['log']); ?>"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> <?php echo esc_html(!empty($c['log_label']) ? $c['log_label'] : __('Journal', 'smbb-wpcodetool')); ?></a>
                <a class="button-link" href="<?php echo esc_url($c['urls']['settings']); ?>"><?php esc_html_e('Réglages', 'smbb-wpcodetool'); ?></a>
            </p>
        </div>
        <?php
        self::css();
    }

    /**
     * Illustration du canal : enveloppe (e-mails) ou bulle de message (SMS). Couleurs par variables CSS.
     */
    public static function art($kind)
    {
        if ($kind === 'forms') {
            return '<svg viewBox="0 0 48 48" width="40" height="40" focusable="false">'
                . '<rect x="9" y="5" width="30" height="38" rx="5" fill="var(--smbb-comm-fill)" stroke="var(--smbb-comm-ink)" stroke-width="2.4"/>'
                . '<rect x="15" y="13" width="18" height="5" rx="1.5" fill="none" stroke="var(--smbb-comm-ink)" stroke-width="2"/>'
                . '<rect x="15" y="22" width="18" height="5" rx="1.5" fill="none" stroke="var(--smbb-comm-ink)" stroke-width="2"/>'
                . '<rect x="15" y="31" width="10" height="5" rx="2.5" fill="var(--smbb-comm-ink)"/>'
                . '</svg>';
        }

        if ($kind === 'security') {
            return '<svg viewBox="0 0 48 48" width="40" height="40" focusable="false">'
                . '<path d="M24 4 40 10v12c0 10.5-6.8 18.6-16 22-9.2-3.4-16-11.5-16-22V10z" fill="var(--smbb-comm-fill)" stroke="var(--smbb-comm-ink)" stroke-width="2.4" stroke-linejoin="round"/>'
                . '<rect x="17" y="22" width="14" height="11" rx="2.5" fill="var(--smbb-comm-ink)"/>'
                . '<path d="M20 22v-3.5a4 4 0 0 1 8 0V22" fill="none" stroke="var(--smbb-comm-ink)" stroke-width="2.4" stroke-linecap="round"/>'
                . '</svg>';
        }

        if ($kind === 'sms') {
            return '<svg viewBox="0 0 48 48" width="40" height="40" focusable="false">'
                . '<path d="M10 9h28a6 6 0 0 1 6 6v14a6 6 0 0 1-6 6H22l-8.5 7.2c-.9.8-2.3.1-2.3-1.1V35H10a6 6 0 0 1-6-6V15a6 6 0 0 1 6-6z" fill="var(--smbb-comm-fill)" stroke="var(--smbb-comm-ink)" stroke-width="2.4" stroke-linejoin="round"/>'
                . '<circle cx="16" cy="22" r="2.6" fill="var(--smbb-comm-ink)"/><circle cx="24" cy="22" r="2.6" fill="var(--smbb-comm-ink)"/><circle cx="32" cy="22" r="2.6" fill="var(--smbb-comm-ink)"/>'
                . '</svg>';
        }

        return '<svg viewBox="0 0 48 48" width="40" height="40" focusable="false">'
            . '<rect x="4" y="10" width="40" height="28" rx="5" fill="var(--smbb-comm-fill)" stroke="var(--smbb-comm-ink)" stroke-width="2.4"/>'
            . '<path d="M6.5 13.5 24 27l17.5-13.5" fill="none" stroke="var(--smbb-comm-ink)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<path d="M6.5 35 18 23.5M41.5 35 30 23.5" fill="none" stroke="var(--smbb-comm-ink)" stroke-width="1.8" stroke-linecap="round" opacity=".45"/>'
            . '</svg>';
    }

    /**
     * Bandeau rouge « … ne partent plus » (rangé dans la cloche si elle est active).
     *
     * @param array $c {kind, title, message, test_url, test_label, failed_url, failed_label, snooze_url}
     */
    public static function notice(array $c)
    {
        ?>
        <div class="notice notice-error smbb-comm-alert is-<?php echo esc_attr($c['kind']); ?>" data-smbb-bell>
            <p><strong><?php echo esc_html($c['title']); ?></strong> <?php echo esc_html($c['message']); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($c['test_url']); ?>"><?php echo esc_html($c['test_label']); ?></a>
                <a class="button" href="<?php echo esc_url($c['failed_url']); ?>"><?php echo esc_html($c['failed_label']); ?></a>
                <a class="button-link" href="<?php echo esc_url($c['snooze_url']); ?>"><?php esc_html_e('Masquer 24 h', 'smbb-wpcodetool'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Masquée 24 h, sauf nouvel échec après le moment où on l'a masquée.
     */
    public static function snoozed(array $state)
    {
        return !empty($state['snooze_until']) && $state['snooze_until'] > time() && $state['last_fail'] <= $state['snooze_until'] - DAY_IN_SECONDS;
    }

    /**
     * Styles en ligne (une fois par page) : couleurs du thème CodeTool (--smbb-t-*, clair et sombre) quand ses variables sont là, gris WordPress sinon.
     */
    private static function css()
    {
        if (self::$css) {
            return;
        }

        self::$css = true;
        ?>
        <style>
            .smbb-comm-widget{container-type:inline-size;--smbb-comm-ok:#1a7f4b;--smbb-comm-bad:#c62828;--smbb-comm-accent:var(--smbb-t-accent,var(--smbb-codetool-accent,#2271b1));--smbb-comm-ink:var(--smbb-comm-accent);--smbb-comm-card:var(--smbb-t-card,#fff);--smbb-comm-fill:var(--smbb-t-fill,#f6f7f7);--smbb-comm-line:var(--smbb-t-line,#e0e0e0);--smbb-comm-text:var(--smbb-t-text,#1d2327);--smbb-comm-muted:var(--smbb-t-muted,#646970)}
            html.smbb-dark .smbb-comm-widget{--smbb-comm-ok:#5cc98a;--smbb-comm-bad:#f07a70}
            .smbb-comm-widget-head{display:flex;gap:14px;align-items:center;padding:12px 14px;border-radius:12px;margin:0 0 14px;background:var(--smbb-comm-fill)}
            .smbb-comm-widget-head.is-good{background:color-mix(in srgb,var(--smbb-comm-ok) 12%,var(--smbb-comm-card))}
            .smbb-comm-widget-head.is-bad{background:color-mix(in srgb,var(--smbb-comm-bad) 12%,var(--smbb-comm-card))}
            .smbb-comm-widget-head.is-bad .smbb-comm-widget-state strong{color:var(--smbb-comm-bad)}
            .smbb-comm-widget-art{position:relative;flex:0 0 auto;display:grid;place-items:center;width:56px;height:56px;border-radius:14px;background:var(--smbb-comm-card);box-shadow:0 1px 3px rgba(0,0,0,.08)}
            .smbb-comm-widget-art svg{display:block}
            .smbb-comm-widget-badge{position:absolute;right:-5px;bottom:-5px;display:grid;place-items:center;width:20px;height:20px;border-radius:50%;border:2px solid var(--smbb-comm-card);background:#8c8f94;color:#fff;font-size:11px;font-weight:700;line-height:1}
            .is-good .smbb-comm-widget-badge{background:var(--smbb-comm-ok)}
            .is-bad .smbb-comm-widget-badge{background:var(--smbb-comm-bad)}
            .is-bad .smbb-comm-widget-art{--smbb-comm-ink:var(--smbb-comm-bad)}
            .smbb-comm-widget-state strong{display:block;font-size:14px;margin-bottom:2px;color:var(--smbb-comm-text)}
            .smbb-comm-widget-state span{color:var(--smbb-comm-muted);font-size:12px;word-break:break-word}
            .smbb-comm-widget-counts{display:grid;grid-template-columns:1fr 1fr;gap:10px}
            .smbb-comm-widget-counts.has-extra{grid-template-columns:1fr 1fr 1fr}
            .smbb-comm-widget-count.is-extra .smbb-comm-widget-value{color:var(--smbb-comm-accent);white-space:nowrap}
            .smbb-comm-widget-counts.has-extra .smbb-comm-widget-count{padding:10px 12px;min-width:0}
            .smbb-comm-widget-counts.has-extra .smbb-comm-widget-value{font-size:clamp(18px,5.5cqi,26px)}
            .smbb-comm-widget-count{display:flex;flex-direction:column;padding:10px 14px;border:1px solid var(--smbb-comm-line);border-radius:10px;text-decoration:none;color:inherit}
            .smbb-comm-widget-count:hover{border-color:var(--smbb-comm-accent)}
            .smbb-comm-widget-value{font-size:26px;font-weight:700;line-height:1.1}
            .smbb-comm-widget-count.is-sent .smbb-comm-widget-value{color:var(--smbb-comm-ok)}
            .smbb-comm-widget-count.has-errors{border-color:color-mix(in srgb,var(--smbb-comm-bad) 40%,var(--smbb-comm-card));background:color-mix(in srgb,var(--smbb-comm-bad) 7%,var(--smbb-comm-card))}
            .smbb-comm-widget-count.has-errors .smbb-comm-widget-value{color:var(--smbb-comm-bad)}
            .smbb-comm-widget-label{font-size:12px;color:var(--smbb-comm-muted)}
            .smbb-comm-widget-bars{grid-column:1/-1;display:flex;gap:8px;height:92px;align-items:stretch;margin-top:8px;padding-top:8px;border-top:1px solid var(--smbb-comm-line)}
            .smbb-comm-widget-bar{position:relative;flex:1 1 0;display:flex;flex-direction:column-reverse;justify-content:flex-start;padding-bottom:18px;background:linear-gradient(to top,transparent 18px,var(--smbb-comm-fill) 18px);border-radius:4px 4px 0 0}
            .smbb-comm-widget-bar>span{display:block;width:100%;border-radius:3px 3px 0 0}
            .smbb-comm-widget-bar>span.is-sent{background:var(--smbb-comm-ok);opacity:.75}
            .smbb-comm-widget-bar>span.is-failed{background:var(--smbb-comm-bad)}
            .smbb-comm-widget-bar b{position:absolute;top:-2px;left:0;right:0;text-align:center;font-size:10px;font-weight:600;color:var(--smbb-comm-muted)}
            .smbb-comm-widget-bar em{position:absolute;bottom:0;left:0;right:0;text-align:center;font-size:10px;font-style:normal;color:var(--smbb-comm-muted);text-transform:uppercase;white-space:nowrap}
            .smbb-comm-widget-bar.is-today em{color:var(--smbb-comm-text);font-weight:700}
            .smbb-comm-widget-period{margin:6px 0 0;font-size:11px;color:var(--smbb-comm-muted);text-align:right}
            .smbb-comm-widget-rows{margin:0 0 14px;padding:0;list-style:none;border:1px solid var(--smbb-comm-line);border-radius:10px;overflow:hidden}
            .smbb-comm-widget-rows li{display:grid;grid-template-columns:18px minmax(0,auto) minmax(0,1fr);gap:8px;align-items:baseline;margin:0;padding:7px 12px;font-size:12px;border-top:1px solid var(--smbb-comm-line)}
            .smbb-comm-widget-rows li:first-child{border-top:0}
            .smbb-comm-widget-rows .dashicons{font-size:16px;width:16px;height:16px;align-self:center;color:var(--smbb-comm-muted)}
            .smbb-comm-widget-rows li.is-good .dashicons{color:var(--smbb-comm-ok)}
            .smbb-comm-widget-rows li.is-bad .dashicons,.smbb-comm-widget-rows li.is-bad .smbb-comm-widget-row-value{color:var(--smbb-comm-bad)}
            .smbb-comm-widget-rows a{color:var(--smbb-comm-text);font-weight:600;text-decoration:none;white-space:nowrap}
            .smbb-comm-widget-rows a:hover{color:var(--smbb-comm-accent)}
            .smbb-comm-widget-row-value{color:var(--smbb-comm-muted);text-align:right;word-break:break-word}
            @container (max-width:360px){.smbb-comm-widget-rows li{grid-template-columns:18px 1fr}.smbb-comm-widget-row-value{grid-column:2;text-align:left}}
            .smbb-comm-widget-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 0}
            .smbb-comm-widget-actions .dashicons{font-size:16px;width:16px;height:16px;vertical-align:text-bottom}
        </style>
        <?php
    }
}
