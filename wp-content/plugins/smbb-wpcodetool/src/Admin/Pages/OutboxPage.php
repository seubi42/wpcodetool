<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Outbox\Outbox;

defined('ABSPATH') || exit;

/**
 * Page « File d'envoi » du groupe Communication de CodeTool (2e niveau de la barre d'app, à côté
 * de E-mails et SMS) : tous les envois groupés déposés par les plugins (Passflow, SignaConnect…),
 * où en est chacun, le détail par destinataire, et la cadence de diffusion.
 *
 * Le détail d'un envoi reste sur la même page (?outbox=ID), comme une fiche.
 */
final class OutboxPage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-outbox';
    const NONCE = 'smbb_codetool_outbox';
    const PER_PAGE = 30;
    const ITEMS_PER_PAGE = 50;

    public static function url(array $args = array())
    {
        return add_query_arg($args, admin_url('admin.php?page=' . self::SLUG));
    }

    // ------------------------------------------------------------------ Actions

    public static function handle()
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        if (empty($_POST['smbb_outbox_do'])) {
            return;
        }

        if (!current_user_can(Outbox::capability())
            || !isset($_POST['smbb_outbox_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_outbox_nonce'])), self::NONCE)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de gérer la file d\'envoi.', 'smbb-wpcodetool'));
        }

        $do = sanitize_key(wp_unslash($_POST['smbb_outbox_do']));
        $id = isset($_POST['outbox']) ? absint($_POST['outbox']) : 0;
        $back = $id ? self::url(array('outbox' => $id)) : self::url();

        if ($do === 'save') {
            $before = Outbox::settings();
            $after = Outbox::saveSettings(array(
                'batch' => isset($_POST['batch']) ? absint($_POST['batch']) : 60,
                'every' => isset($_POST['every']) ? absint($_POST['every']) : 1,
            ));

            if ($before !== $after) {
                do_action('smbb_wpcodetool_activity', 'outbox.settings_saved', array(
                    'category' => 'communication',
                    'level' => 'notice',
                    'message' => __('Cadence de la file d\'envoi modifiée', 'smbb-wpcodetool'),
                    'context' => array('changes' => array(
                        __('Messages par lot', 'smbb-wpcodetool') => array((string) $before['batch'], (string) $after['batch']),
                        __('Minutes entre deux lots', 'smbb-wpcodetool') => array((string) $before['every'], (string) $after['every']),
                    )),
                    'source' => 'smbb-wpcodetool',
                ));
            }

            self::flash('success', sprintf(__('Cadence enregistrée : %1$d messages toutes les %2$d minute(s).', 'smbb-wpcodetool'), $after['batch'], $after['every']));
        } elseif ($do === 'run') {
            $count = Outbox::run(true);
            self::flash($count ? 'success' : 'info', $count
                ? sprintf(_n('%d message traité.', '%d messages traités.', $count, 'smbb-wpcodetool'), $count)
                : __('Rien à envoyer pour l\'instant (ou un lot est déjà en cours).', 'smbb-wpcodetool'));
        } elseif ($do === 'stop' && $id) {
            $count = Outbox::stop($id);
            self::flash('success', sprintf(_n('Envoi arrêté : %d message ne partira pas.', 'Envoi arrêté : %d messages ne partiront pas.', $count, 'smbb-wpcodetool'), $count));
        } elseif ($do === 'retry' && $id) {
            $count = Outbox::retry($id);
            self::flash($count ? 'success' : 'info', $count
                ? sprintf(_n('%d message remis en file.', '%d messages remis en file.', $count, 'smbb-wpcodetool'), $count)
                : __('Aucun échec à relancer.', 'smbb-wpcodetool'));
        }

        wp_safe_redirect($back);
        exit;
    }

    private static function flash($type, $message)
    {
        set_transient('smbb_outbox_flash_' . get_current_user_id(), array($type, $message), 120);
    }

    private function notices()
    {
        $key = 'smbb_outbox_flash_' . get_current_user_id();
        $flash = get_transient($key);
        $notices = array();

        if (is_array($flash)) {
            delete_transient($key);
            $notices[] = '<div class="smbb-codetool-notice is-' . esc_attr($flash[0]) . '"><p class="smbb-codetool-notice-message">' . esc_html($flash[1]) . '</p></div>';
        }

        if (!Outbox::ready()) {
            $notices[] = '<div class="smbb-codetool-notice is-info"><p class="smbb-codetool-notice-message">' . esc_html__('Les tables de la file se créent au prochain chargement d\'une page d\'admin.', 'smbb-wpcodetool') . '</p></div>';
        }

        return $notices;
    }

    private static function actionForm($do, $label, $id = 0, $class = 'button', $confirm = '', $icon = '')
    {
        return '<form method="post" class="smbb-outbox-inline">'
            . wp_nonce_field(self::NONCE, 'smbb_outbox_nonce', true, false)
            . '<input type="hidden" name="smbb_outbox_do" value="' . esc_attr($do) . '">'
            . ($id ? '<input type="hidden" name="outbox" value="' . (int) $id . '">' : '')
            . '<button type="submit" class="' . esc_attr($class) . '"' . ($confirm !== '' ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"' : '') . '>'
            . ($icon !== '' ? '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>' : '')
            . esc_html($label) . '</button></form>';
    }

    // ------------------------------------------------------------------ Libellés

    /**
     * @return array<string,array{0:string,1:string}> statut d'un envoi => [libellé, ton]
     */
    private static function outboxStates()
    {
        return array(
            'queued' => array(__('En file', 'smbb-wpcodetool'), 'wait'),
            'sending' => array(__('En cours', 'smbb-wpcodetool'), 'run'),
            'done' => array(__('Terminé', 'smbb-wpcodetool'), 'ok'),
            'stopped' => array(__('Arrêté', 'smbb-wpcodetool'), 'off'),
        );
    }

    /**
     * @return array<string,array{0:string,1:string}> statut d'un message => [libellé, ton]
     */
    private static function itemStates()
    {
        return array(
            'queued' => array(__('En attente', 'smbb-wpcodetool'), 'wait'),
            'sent' => array(__('Envoyé', 'smbb-wpcodetool'), 'ok'),
            'failed' => array(__('Échec', 'smbb-wpcodetool'), 'bad'),
            'skipped' => array(__('Ignoré', 'smbb-wpcodetool'), 'off'),
            'cancelled' => array(__('Abandonné', 'smbb-wpcodetool'), 'off'),
        );
    }

    private static function badge(array $states, $status)
    {
        $state = isset($states[$status]) ? $states[$status] : array((string) $status, 'off');

        return '<span class="smbb-outbox-badge is-' . esc_attr($state[1]) . '">' . esc_html($state[0]) . '</span>';
    }

    private static function channels($channels)
    {
        $out = '';
        $labels = array(
            'sms' => array(__('SMS', 'smbb-wpcodetool'), 'dashicons-smartphone'),
            'email' => array(__('E-mail', 'smbb-wpcodetool'), 'dashicons-email-alt'),
        );

        foreach (array_filter(explode(',', (string) $channels)) as $c) {
            $l = isset($labels[$c]) ? $labels[$c] : array($c, 'dashicons-format-chat');
            $out .= '<span class="smbb-outbox-chan"><span class="dashicons ' . esc_attr($l[1]) . '" aria-hidden="true"></span>' . esc_html($l[0]) . '</span>';
        }

        return $out;
    }

    private static function when($mysql)
    {
        if (empty($mysql)) {
            return '';
        }

        $ts = strtotime((string) $mysql);
        $today = current_time('Y-m-d');

        return substr((string) $mysql, 0, 10) === $today
            ? sprintf(__('aujourd\'hui %s', 'smbb-wpcodetool'), wp_date('H:i', $ts - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS)))
            : mysql2date('d/m/Y H:i', (string) $mysql);
    }

    /**
     * Barre de progression + « 42 / 60 » (envoyés + ignorés + échecs définitifs sur le total).
     */
    private static function progress(array $outbox)
    {
        $t = $outbox['tally'];
        $total = max(1, (int) $outbox['total']);
        $ok = (int) $t['sent'];
        $bad = (int) $t['failed'];
        $off = (int) $t['skipped'] + (int) $t['cancelled'];

        return '<div class="smbb-outbox-progress" role="img" aria-label="' . esc_attr(sprintf(__('%1$d envoyés sur %2$d', 'smbb-wpcodetool'), $ok, (int) $outbox['total'])) . '">'
            . '<span class="is-ok" style="width:' . esc_attr((string) round($ok * 100 / $total, 2)) . '%"></span>'
            . '<span class="is-bad" style="width:' . esc_attr((string) round($bad * 100 / $total, 2)) . '%"></span>'
            . '<span class="is-off" style="width:' . esc_attr((string) round($off * 100 / $total, 2)) . '%"></span>'
            . '</div>'
            . '<p class="smbb-outbox-counts"><strong>' . esc_html(sprintf(__('%1$s / %2$s envoyés', 'smbb-wpcodetool'), number_format_i18n($ok), number_format_i18n((int) $outbox['total']))) . '</strong>'
            . ($t['queued'] ? ' · ' . esc_html(sprintf(__('%s en attente', 'smbb-wpcodetool'), number_format_i18n($t['queued']))) : '')
            . ($bad ? ' · <span class="is-bad">' . esc_html(sprintf(_n('%s échec', '%s échecs', $bad, 'smbb-wpcodetool'), number_format_i18n($bad))) . '</span>' : '')
            . ($t['skipped'] ? ' · ' . esc_html(sprintf(_n('%s ignoré', '%s ignorés', $t['skipped'], 'smbb-wpcodetool'), number_format_i18n($t['skipped']))) : '')
            . ($t['cancelled'] ? ' · ' . esc_html(sprintf(_n('%s abandonné', '%s abandonnés', $t['cancelled'], 'smbb-wpcodetool'), number_format_i18n($t['cancelled']))) : '')
            . '</p>';
    }

    /**
     * Temps restant estimé d'un envoi en cours, à la cadence actuelle.
     */
    private static function eta($left)
    {
        if ($left <= 0) {
            return '';
        }

        $settings = Outbox::settings();
        $minutes = (int) (ceil($left / max(1, $settings['batch'])) - 1) * $settings['every'];

        return $minutes < 1
            ? __('fini dans la minute', 'smbb-wpcodetool')
            : sprintf(_n('fini dans %d minute environ', 'fini dans %d minutes environ', $minutes, 'smbb-wpcodetool'), $minutes);
    }

    // ------------------------------------------------------------------ Rendu

    public function render()
    {
        if (!current_user_can(Outbox::capability())) {
            wp_die(esc_html__('Vous n\'avez pas le droit de voir la file d\'envoi.', 'smbb-wpcodetool'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $outbox = isset($_GET['outbox']) ? Outbox::find(absint($_GET['outbox'])) : null;
        $live = false;
        ?>
        <div class="wrap smbb-codetool smbb-outbox-page">
            <?php
            echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
                $outbox ? Outbox::title($outbox) : __('File d\'envoi', 'smbb-wpcodetool'),
                $outbox
                    ? sprintf(__('Envoi n° %1$d · %2$s', 'smbb-wpcodetool'), (int) $outbox['id'], Outbox::sourceLabel($outbox['source']))
                    : __('Les SMS et e-mails groupés déposés par les extensions (communications Passflow…), diffusés par lots. Où en est chaque envoi, et le détail par destinataire.', 'smbb-wpcodetool'),
                'dashicons-controls-forward',
                $this->notices()
            );

            if ($outbox) {
                $live = $this->renderDetail($outbox);
            } else {
                $live = $this->renderList();
            }

            if ($live) :
                ?>
                <script>
                (function () {
                    // Rafraîchit tant qu'un envoi tourne, sauf si on est en train de saisir.
                    setTimeout(function () {
                        var a = document.activeElement;
                        if (a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName)) { return; }
                        window.location.reload();
                    }, 15000);
                })();
                </script>
                <?php
            endif;
            ?>
        </div>
        <?php
    }

    /**
     * @return bool un envoi tourne (rafraîchissement automatique)
     */
    private function renderList()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $filters = array(
            'status' => isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '',
            'source' => isset($_GET['source']) ? sanitize_key(wp_unslash($_GET['source'])) : '',
            's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
        );
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable
        $keep = array_filter($filters, 'strlen');
        $data = Outbox::query($filters, $paged, self::PER_PAGE);
        $stats = Outbox::stats();
        $settings = Outbox::settings();
        $pages = max(1, (int) ceil($data['total'] / self::PER_PAGE));
        $sources = Outbox::sources();
        $states = self::outboxStates();
        $next = $stats['next'] > 0
            ? ($stats['next'] <= time() ? __('imminent', 'smbb-wpcodetool') : sprintf(__('dans %s', 'smbb-wpcodetool'), human_time_diff(time(), $stats['next'])))
            : __('rien de prévu', 'smbb-wpcodetool');
        ?>
        <div class="smbb-outbox-kpis">
            <div class="smbb-outbox-kpi<?php echo $stats['waiting'] ? ' is-live' : ''; ?>">
                <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                <strong><?php echo esc_html(number_format_i18n($stats['waiting'])); ?></strong>
                <span><?php esc_html_e('messages en attente', 'smbb-wpcodetool'); ?></span>
                <small><?php echo esc_html(sprintf(_n('%d envoi en cours', '%d envois en cours', $stats['running'], 'smbb-wpcodetool'), $stats['running'])); ?></small>
            </div>
            <div class="smbb-outbox-kpi">
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <strong><?php echo esc_html(number_format_i18n($stats['sent_24h'])); ?></strong>
                <span><?php esc_html_e('envoyés en 24 h', 'smbb-wpcodetool'); ?></span>
                <small><?php echo $stats['last'] ? esc_html(sprintf(__('dernier lot il y a %s', 'smbb-wpcodetool'), human_time_diff($stats['last'], time()))) : esc_html__('aucun lot encore', 'smbb-wpcodetool'); ?></small>
            </div>
            <div class="smbb-outbox-kpi<?php echo $stats['failed_24h'] ? ' is-bad' : ''; ?>">
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <strong><?php echo esc_html(number_format_i18n($stats['failed_24h'])); ?></strong>
                <span><?php esc_html_e('échecs en 24 h', 'smbb-wpcodetool'); ?></span>
                <small><?php esc_html_e('après 3 tentatives', 'smbb-wpcodetool'); ?></small>
            </div>
            <div class="smbb-outbox-kpi">
                <span class="dashicons dashicons-controls-forward" aria-hidden="true"></span>
                <strong><?php echo esc_html(sprintf(__('%1$d / %2$d min', 'smbb-wpcodetool'), $settings['batch'], $settings['every'])); ?></strong>
                <span><?php esc_html_e('cadence', 'smbb-wpcodetool'); ?></span>
                <small><?php echo esc_html(sprintf(__('prochain lot : %s', 'smbb-wpcodetool'), $next)); ?></small>
            </div>
        </div>

        <div class="smbb-outbox-layout">
            <div class="smbb-outbox-main">
                <form method="get" class="smbb-outbox-filters">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                    <label class="smbb-outbox-search">
                        <span class="screen-reader-text"><?php esc_html_e('Rechercher', 'smbb-wpcodetool'); ?></span>
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <input type="search" name="s" value="<?php echo esc_attr($filters['s']); ?>" placeholder="<?php esc_attr_e('Message, objet, cible…', 'smbb-wpcodetool'); ?>">
                    </label>
                    <label>
                        <span class="smbb-outbox-filter-label"><?php esc_html_e('État', 'smbb-wpcodetool'); ?></span>
                        <select name="status">
                            <option value=""><?php esc_html_e('Tous', 'smbb-wpcodetool'); ?></option>
                            <option value="running"<?php selected($filters['status'], 'running'); ?>><?php esc_html_e('En file ou en cours', 'smbb-wpcodetool'); ?></option>
                            <option value="done"<?php selected($filters['status'], 'done'); ?>><?php esc_html_e('Terminés', 'smbb-wpcodetool'); ?></option>
                            <option value="stopped"<?php selected($filters['status'], 'stopped'); ?>><?php esc_html_e('Arrêtés', 'smbb-wpcodetool'); ?></option>
                            <option value="failed"<?php selected($filters['status'], 'failed'); ?>><?php esc_html_e('Avec des échecs', 'smbb-wpcodetool'); ?></option>
                        </select>
                    </label>
                    <?php if (count($sources) > 1 || $filters['source'] !== '') : ?>
                        <label>
                            <span class="smbb-outbox-filter-label"><?php esc_html_e('Origine', 'smbb-wpcodetool'); ?></span>
                            <select name="source">
                                <option value=""><?php esc_html_e('Toutes', 'smbb-wpcodetool'); ?></option>
                                <?php foreach ($sources as $key => $source) : ?>
                                    <option value="<?php echo esc_attr($key); ?>"<?php selected($filters['source'], $key); ?>><?php echo esc_html((string) ($source['label'] ?? $key)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <span class="smbb-outbox-filter-actions">
                        <button class="button button-primary"><?php esc_html_e('Filtrer', 'smbb-wpcodetool'); ?></button>
                        <?php if ($keep) : ?>
                            <a class="button" href="<?php echo esc_url(self::url()); ?>"><?php esc_html_e('Effacer', 'smbb-wpcodetool'); ?></a>
                        <?php endif; ?>
                    </span>
                </form>

                <div class="smbb-outbox-list">
                    <?php if (!$data['rows']) : ?>
                        <p class="smbb-outbox-empty"><?php echo esc_html($keep ? __('Aucun envoi ne correspond.', 'smbb-wpcodetool') : __('Aucun envoi pour l\'instant. Les communications de Passflow (et des autres extensions) apparaîtront ici.', 'smbb-wpcodetool')); ?></p>
                    <?php endif; ?>
                    <?php
                    $live = false;

                    foreach ($data['rows'] as $row) {
                        $running = in_array($row['status'], Outbox::RUNNING, true);
                        $live = $live || $running;
                        $context = Outbox::contextLabel($row);
                        $link = Outbox::contextLink($row);
                        $author = !empty($row['created_by']) ? get_userdata((int) $row['created_by']) : null;
                        $detail = self::url(array('outbox' => (int) $row['id']));
                        ?>
                        <article class="smbb-outbox-row is-<?php echo esc_attr($states[$row['status']][1] ?? 'off'); ?>">
                            <div class="smbb-outbox-row-head">
                                <a class="smbb-outbox-row-title" href="<?php echo esc_url($detail); ?>"><?php echo esc_html(Outbox::title($row)); ?></a>
                                <?php echo self::badge($states, $row['status']); // phpcs:ignore -- échappé dans badge(). ?>
                                <span class="smbb-outbox-row-chans"><?php echo self::channels($row['channels']); // phpcs:ignore -- échappé. ?></span>
                            </div>
                            <p class="smbb-outbox-row-meta">
                                <span><?php echo esc_html(self::when($row['created_at'])); ?></span>
                                <span class="smbb-outbox-source"><?php echo esc_html(Outbox::sourceLabel($row['source'])); ?></span>
                                <?php if ($context !== '') : ?>
                                    <?php if ($link !== '') : ?><a href="<?php echo esc_url($link); ?>"><?php echo esc_html($context); ?></a><?php else : ?><span><?php echo esc_html($context); ?></span><?php endif; ?>
                                <?php endif; ?>
                                <?php if ($author) : ?><span><?php echo esc_html($author->display_name); ?></span><?php endif; ?>
                            </p>
                            <p class="smbb-outbox-row-excerpt"><?php echo esc_html(wp_trim_words((string) $row['message'], 28)); ?></p>
                            <?php echo self::progress($row); // phpcs:ignore -- échappé dans progress(). ?>
                            <div class="smbb-outbox-row-actions">
                                <a class="button button-small" href="<?php echo esc_url($detail); ?>"><?php esc_html_e('Détail', 'smbb-wpcodetool'); ?></a>
                                <?php if ($running) : ?>
                                    <span class="smbb-outbox-eta"><?php echo esc_html(self::eta((int) $row['tally']['queued'])); ?></span>
                                    <?php echo self::actionForm('stop', __('Arrêter', 'smbb-wpcodetool'), (int) $row['id'], 'button button-small button-link-delete', __('Arrêter cet envoi ? Les messages pas encore partis seront abandonnés.', 'smbb-wpcodetool')); // phpcs:ignore ?>
                                <?php elseif ($row['tally']['failed']) : ?>
                                    <?php echo self::actionForm('retry', __('Relancer les échecs', 'smbb-wpcodetool'), (int) $row['id'], 'button button-small'); // phpcs:ignore ?>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php
                    }
                    ?>
                </div>

                <?php if ($pages > 1) : ?>
                    <div class="tablenav bottom"><div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(paginate_links(array(
                            'base' => add_query_arg('paged', '%#%', self::url($keep)),
                            'format' => '',
                            'current' => $paged,
                            'total' => $pages,
                        )));
                        ?>
                    </div></div>
                <?php endif; ?>
            </div>

            <aside class="smbb-outbox-side">
                <section class="smbb-outbox-card">
                    <h2><span class="dashicons dashicons-performance" aria-hidden="true"></span><?php esc_html_e('Cadence de diffusion', 'smbb-wpcodetool'); ?></h2>
                    <p class="description"><?php esc_html_e('Le premier lot part tout de suite, les suivants à ce rythme. Un SMS et un e-mail à la même personne comptent pour deux.', 'smbb-wpcodetool'); ?></p>
                    <form method="post" class="smbb-outbox-settings">
                        <?php wp_nonce_field(self::NONCE, 'smbb_outbox_nonce'); ?>
                        <input type="hidden" name="smbb_outbox_do" value="save">
                        <label><span><?php esc_html_e('Messages par lot', 'smbb-wpcodetool'); ?></span>
                            <input type="number" name="batch" min="1" max="500" step="1" value="<?php echo esc_attr($settings['batch']); ?>"></label>
                        <label><span><?php esc_html_e('Un lot toutes les (minutes)', 'smbb-wpcodetool'); ?></span>
                            <input type="number" name="every" min="1" max="60" step="1" value="<?php echo esc_attr($settings['every']); ?>"></label>
                        <p class="description"><?php esc_html_e('Par défaut : 60 messages toutes les minutes.', 'smbb-wpcodetool'); ?></p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button>
                    </form>
                </section>
                <section class="smbb-outbox-card">
                    <h2><span class="dashicons dashicons-controls-play" aria-hidden="true"></span><?php esc_html_e('Envoyer maintenant', 'smbb-wpcodetool'); ?></h2>
                    <p class="description"><?php esc_html_e('Traite un lot tout de suite sans attendre l\'intervalle (utile si le cron du site est lent).', 'smbb-wpcodetool'); ?></p>
                    <?php echo self::actionForm('run', __('Lancer un lot', 'smbb-wpcodetool'), 0, 'button', '', 'dashicons-controls-play'); // phpcs:ignore ?>
                </section>
            </aside>
        </div>
        <?php

        return $live;
    }

    /**
     * @return bool l'envoi tourne (rafraîchissement automatique)
     */
    private function renderDetail(array $outbox)
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $status = isset($_GET['item_status']) ? sanitize_key(wp_unslash($_GET['item_status'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable
        $item_states = self::itemStates();
        $status = isset($item_states[$status]) ? $status : '';
        $tally = Outbox::tally((int) $outbox['id']);
        $outbox['tally'] = $tally;
        $running = in_array($outbox['status'], Outbox::RUNNING, true);
        $items = Outbox::items((int) $outbox['id'], $status, $paged, self::ITEMS_PER_PAGE);
        $pages = max(1, (int) ceil($items['total'] / self::ITEMS_PER_PAGE));
        $context = Outbox::contextLabel($outbox);
        $link = Outbox::contextLink($outbox);
        $author = !empty($outbox['created_by']) ? get_userdata((int) $outbox['created_by']) : null;
        $base = array('outbox' => (int) $outbox['id']);
        ?>
        <p class="smbb-outbox-back"><a href="<?php echo esc_url(self::url()); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e('Toute la file', 'smbb-wpcodetool'); ?></a></p>

        <div class="smbb-outbox-layout">
            <div class="smbb-outbox-main">
                <section class="smbb-outbox-card">
                    <div class="smbb-outbox-row-head">
                        <?php echo self::badge(self::outboxStates(), $outbox['status']); // phpcs:ignore ?>
                        <span class="smbb-outbox-row-chans"><?php echo self::channels($outbox['channels']); // phpcs:ignore ?></span>
                        <?php if ($running) : ?><span class="smbb-outbox-eta"><?php echo esc_html(self::eta((int) $tally['queued'])); ?></span><?php endif; ?>
                    </div>
                    <?php echo self::progress($outbox); // phpcs:ignore ?>
                    <div class="smbb-outbox-row-actions">
                        <?php if ($running) : ?>
                            <?php echo self::actionForm('stop', __('Arrêter l\'envoi', 'smbb-wpcodetool'), (int) $outbox['id'], 'button button-link-delete', __('Arrêter cet envoi ? Les messages pas encore partis seront abandonnés.', 'smbb-wpcodetool')); // phpcs:ignore ?>
                        <?php endif; ?>
                        <?php if ($tally['failed']) : ?>
                            <?php echo self::actionForm('retry', __('Relancer les échecs', 'smbb-wpcodetool'), (int) $outbox['id'], 'button', '', 'dashicons-update'); // phpcs:ignore ?>
                        <?php endif; ?>
                    </div>
                </section>

                <nav class="smbb-outbox-status-filter" aria-label="<?php esc_attr_e('Filtrer les destinataires', 'smbb-wpcodetool'); ?>">
                    <a class="<?php echo $status === '' ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::url($base)); ?>"><?php esc_html_e('Tous', 'smbb-wpcodetool'); ?> <span><?php echo esc_html(number_format_i18n((int) $outbox['total'])); ?></span></a>
                    <?php foreach ($item_states as $key => $state) : ?>
                        <?php if (empty($tally[$key]) && $status !== $key) { continue; } ?>
                        <a class="is-<?php echo esc_attr($state[1]); ?><?php echo $status === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url(self::url($base + array('item_status' => $key))); ?>"><?php echo esc_html($state[0]); ?> <span><?php echo esc_html(number_format_i18n((int) ($tally[$key] ?? 0))); ?></span></a>
                    <?php endforeach; ?>
                </nav>

                <table class="widefat striped smbb-outbox-items">
                    <thead><tr>
                        <th><?php esc_html_e('Destinataire', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Canal', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('État', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Tentatives', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Quand', 'smbb-wpcodetool'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if (!$items['rows']) : ?>
                            <tr><td colspan="5"><?php esc_html_e('Aucun destinataire dans cet état.', 'smbb-wpcodetool'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($items['rows'] as $item) : ?>
                            <tr>
                                <td data-label="<?php esc_attr_e('Destinataire', 'smbb-wpcodetool'); ?>">
                                    <strong><?php echo esc_html($item['name'] !== '' ? $item['name'] : $item['address']); ?></strong>
                                    <?php if ($item['name'] !== '' && $item['address'] !== '') : ?><br><span class="smbb-outbox-muted"><?php echo esc_html($item['address']); ?></span><?php endif; ?>
                                    <?php if (!empty($item['error'])) : ?><br><span class="smbb-outbox-error"><?php echo esc_html($item['error']); ?></span><?php endif; ?>
                                </td>
                                <td data-label="<?php esc_attr_e('Canal', 'smbb-wpcodetool'); ?>"><?php echo self::channels($item['channel']); // phpcs:ignore ?></td>
                                <td data-label="<?php esc_attr_e('État', 'smbb-wpcodetool'); ?>"><?php echo self::badge($item_states, $item['status']); // phpcs:ignore ?></td>
                                <td data-label="<?php esc_attr_e('Tentatives', 'smbb-wpcodetool'); ?>"><?php echo esc_html((string) (int) $item['attempts']); ?></td>
                                <td data-label="<?php esc_attr_e('Quand', 'smbb-wpcodetool'); ?>"><?php echo esc_html(self::when($item['sent_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($pages > 1) : ?>
                    <div class="tablenav bottom"><div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(paginate_links(array(
                            'base' => add_query_arg('paged', '%#%', self::url($base + ($status !== '' ? array('item_status' => $status) : array()))),
                            'format' => '',
                            'current' => $paged,
                            'total' => $pages,
                        )));
                        ?>
                    </div></div>
                <?php endif; ?>
            </div>

            <aside class="smbb-outbox-side">
                <section class="smbb-outbox-card">
                    <h2><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e('L\'envoi', 'smbb-wpcodetool'); ?></h2>
                    <dl class="smbb-outbox-facts">
                        <dt><?php esc_html_e('Origine', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(Outbox::sourceLabel($outbox['source'])); ?></dd>
                        <?php if ($context !== '') : ?>
                            <dt><?php esc_html_e('Pour', 'smbb-wpcodetool'); ?></dt>
                            <dd><?php if ($link !== '') : ?><a href="<?php echo esc_url($link); ?>"><?php echo esc_html($context); ?></a><?php else : echo esc_html($context); endif; ?></dd>
                        <?php endif; ?>
                        <dt><?php esc_html_e('Créé', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(self::when($outbox['created_at'])); ?><?php echo $author ? ' · ' . esc_html($author->display_name) : ''; ?></dd>
                        <?php if (!empty($outbox['started_at'])) : ?><dt><?php esc_html_e('Démarré', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(self::when($outbox['started_at'])); ?></dd><?php endif; ?>
                        <?php if (!empty($outbox['finished_at'])) : ?><dt><?php esc_html_e('Fini', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(self::when($outbox['finished_at'])); ?></dd><?php endif; ?>
                    </dl>
                </section>
                <section class="smbb-outbox-card">
                    <h2><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><?php esc_html_e('Message', 'smbb-wpcodetool'); ?></h2>
                    <?php if ($outbox['subject'] !== '' && strpos((string) $outbox['channels'], 'email') !== false) : ?>
                        <p class="smbb-outbox-subject"><strong><?php esc_html_e('Objet :', 'smbb-wpcodetool'); ?></strong> <?php echo esc_html($outbox['subject']); ?></p>
                    <?php endif; ?>
                    <div class="smbb-outbox-message"><?php echo nl2br(esc_html((string) $outbox['message'])); ?></div>
                    <p class="description"><?php esc_html_e('Texte avant personnalisation : les {variables} sont remplacées pour chaque destinataire.', 'smbb-wpcodetool'); ?></p>
                </section>
            </aside>
        </div>
        <?php

        return $running;
    }
}
