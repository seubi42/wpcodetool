<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Mail\MailHealth;
use Smbb\WpCodeTool\Mail\MailLog;
use Smbb\WpCodeTool\Mail\Mailer;
use Smbb\WpCodeTool\Mail\WpMailSmtpImport;

defined('ABSPATH') || exit;

/**
 * Pages « E-mails » (SLUG) et « SMS » (SMS_SLUG) du groupe Communication de CodeTool : la barre d'app
 * les montre en niveau 2, chacune garde ses onglets Réglages / Test / Journal (+ Statistiques pour les SMS,
 * rendu par SmsPanel). Anciennes adresses (smbb-wpcodetool-mail, ?channel=sms) redirigées.
 *
 * Canal E-mails :
 * - Réglages : activation, connecteur (SMTP), expéditeur, journal, alertes, import WP Mail SMTP ;
 * - Test : envoi d'un e-mail de test avec la transcription SMTP (identifiants masqués) ;
 * - Journal : e-mails des 15 derniers jours, filtre envoyés / en erreur, renvoi et suppression groupés.
 */
final class MailPage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-communication';
    /** Page « SMS » du groupe Communication (même rendu, canal SMS). */
    const SMS_SLUG = 'smbb-wpcodetool-sms';
    const OLD_SLUG = 'smbb-wpcodetool-mail';
    const NONCE = 'smbb_codetool_mail';
    const PER_PAGE = 50;

    /**
     * Adresse d'un onglet : $tab = settings | test | log (| stats pour les SMS), $channel = mail | sms.
     */
    public static function url($tab = 'settings', array $args = array(), $channel = 'mail')
    {
        $base = array('page' => $channel === 'sms' ? self::SMS_SLUG : self::SLUG, 'tab' => $tab);

        return add_query_arg(array_merge($base, $args), admin_url('admin.php'));
    }

    private static function tab()
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification

        $tabs = self::channel() === 'sms' ? array('settings', 'test', 'log', 'stats') : array('settings', 'test', 'log');

        return in_array($tab, $tabs, true) ? $tab : 'settings';
    }

    public static function channel()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification

        return $page === self::SMS_SLUG ? 'sms' : 'mail';
    }

    /**
     * admin_init : anciennes adresses (page « E-mails » d'avant, onglet ?channel=sms ou ?tab=sms
     * de la page Communication) renvoyées vers la bonne page (favoris, liens déjà envoyés).
     */
    public static function redirectOldSlug()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
        $sms = (isset($_GET['channel']) && $_GET['channel'] === 'sms') || (isset($_GET['tab']) && $_GET['tab'] === 'sms');

        if ($page !== self::OLD_SLUG && !($page === self::SLUG && $sms)) {
            return;
        }

        $args = wp_unslash($_GET);
        // phpcs:enable WordPress.Security.NonceVerification
        unset($args['page'], $args['channel']);
        $tab = isset($args['tab']) && $args['tab'] !== 'sms' ? sanitize_key($args['tab']) : 'settings';
        unset($args['tab']);

        wp_safe_redirect(self::url($tab, array_map('sanitize_text_field', array_filter($args, 'is_scalar')), $sms ? 'sms' : 'mail'));
        exit;
    }

    /**
     * Actions postées, avant tout affichage (appelé par AdminManager::handleAdminActions).
     */
    public static function handle()
    {
        if (!isset($_POST['smbb_codetool_mail_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_codetool_mail_nonce'])), self::NONCE)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier les réglages de communication de CodeTool.', 'smbb-wpcodetool'));
        }

        $do = isset($_POST['smbb_mail_do']) ? sanitize_key(wp_unslash($_POST['smbb_mail_do'])) : '';

        if ($do === 'save') {
            self::save();
        } elseif ($do === 'test') {
            self::test();
        } elseif ($do === 'log') {
            self::logAction();
        } elseif (strpos($do, 'sms_') === 0) {
            SmsPanel::handle($do);
        }
    }

    private static function save()
    {
        $previous = Mailer::settings();
        $bool = static function ($key) {
            return isset($_POST[$key]) && sanitize_key(wp_unslash($_POST[$key])) === '1'; // phpcs:ignore WordPress.Security.NonceVerification
        };

        $transports = Mailer::transports();
        $transport = isset($_POST['transport']) ? sanitize_key(wp_unslash($_POST['transport'])) : 'smtp';
        $transport = isset($transports[$transport]) ? $transport : 'smtp';
        $posted = isset($_POST['transports']) && is_array($_POST['transports']) ? wp_unslash($_POST['transports']) : array(); // phpcs:ignore -- nettoyé par chaque connecteur.
        $own = $previous['transports'];

        // Chaque connecteur nettoie ses propres champs ; les réglages des autres connecteurs sont gardés.
        foreach ($transports as $key => $object) {
            if (isset($posted[$key]) && is_array($posted[$key])) {
                $own[$key] = $object->sanitize($posted[$key], isset($own[$key]) && is_array($own[$key]) ? $own[$key] : array());
            }
        }

        $from_email = isset($_POST['from_email']) ? sanitize_email(wp_unslash($_POST['from_email'])) : '';

        Mailer::save(array(
            'enabled' => $bool('enabled'),
            'transport' => $transport,
            'from_email' => $from_email,
            'from_name' => isset($_POST['from_name']) ? sanitize_text_field(wp_unslash($_POST['from_name'])) : '',
            'force_from' => $bool('force_from'),
            'force_name' => $bool('force_name'),
            'return_path' => $bool('return_path'),
            'log' => $bool('log'),
            'log_body' => $bool('log_body'),
            'log_days' => isset($_POST['log_days'], MailLog::durations()[absint($_POST['log_days'])]) ? absint($_POST['log_days']) : 15,
            'log_attachments' => $bool('log_attachments'),
            'alerts' => $bool('alerts'),
            'transports' => $own,
        ));

        $then_test = $bool('then_test');
        wp_safe_redirect($then_test ? self::url('test') : self::url('settings', array('saved' => 1)));
        exit;
    }

    private static function test()
    {
        $to = isset($_POST['to']) ? sanitize_email(wp_unslash($_POST['to'])) : '';

        if (!is_email($to)) {
            $result = array('ok' => false, 'error' => __('Adresse de destination invalide.', 'smbb-wpcodetool'), 'transcript' => array(), 'duration' => 0.0);
        } else {
            MailLog::$source = 'test';
            $result = Mailer::sendTest($to);
            MailLog::$source = '';
        }

        $result['to'] = $to;
        $result['at'] = time();
        set_transient('smbb_codetool_mail_test_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
        update_user_meta(get_current_user_id(), 'smbb_codetool_mail_test_to', $to);

        wp_safe_redirect(self::url('test', array('tested' => 1)));
        exit;
    }

    private static function logAction()
    {
        $ids = isset($_POST['ids']) ? array_map('absint', (array) wp_unslash($_POST['ids'])) : array();
        $single = isset($_POST['resend_one']) ? absint(wp_unslash($_POST['resend_one'])) : 0;
        $bulk = isset($_POST['bulk']) ? sanitize_key(wp_unslash($_POST['bulk'])) : '';
        $back = array(
            'status' => isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '',
            's' => isset($_POST['s']) ? sanitize_text_field(wp_unslash($_POST['s'])) : '',
        );

        if ($single) {
            $bulk = 'resend';
            $ids = array($single);
        }

        if (!$ids || !in_array($bulk, array('resend', 'delete'), true)) {
            wp_safe_redirect(self::url('log', array_filter($back + array('done' => 'none'))));
            exit;
        }

        if ($bulk === 'delete') {
            $count = MailLog::delete($ids);
            wp_safe_redirect(self::url('log', array_filter($back + array('done' => 'deleted', 'n' => $count))));
            exit;
        }

        $result = MailLog::resend($ids);
        wp_safe_redirect(self::url('log', array_filter($back + array(
            'done' => 'resent',
            'ok' => $result['sent'],
            'ko' => $result['failed'],
            'skip' => $result['skipped'],
        ))));
        exit;
    }

    public function render()
    {
        $tab = self::tab();
        $channel = self::channel();
        $notices = $channel === 'sms' ? array() : $this->notices($tab);

        if ($channel === 'sms') {
            foreach (SmsPanel::notices($tab) as $notice) {
                $notices[] = $this->notice($notice[0], $notice[1]);
            }
        }

        $tabs = array(
            'settings' => array(__('Réglages', 'smbb-wpcodetool'), 'dashicons-admin-generic'),
            'test' => array(__('Test d\'envoi', 'smbb-wpcodetool'), 'dashicons-controls-play'),
            'log' => array(__('Journal', 'smbb-wpcodetool'), 'dashicons-list-view'),
        );

        if ($channel === 'sms') {
            // Les SMS sont facturés à l'unité : suivi de la conso, mois par mois.
            $tabs['stats'] = array(__('Statistiques', 'smbb-wpcodetool'), 'dashicons-chart-bar');
        }
        ?>
        <div class="wrap smbb-codetool smbb-mail-page">
            <?php
            echo $this->manager()->pageIntroHtml( // phpcs:ignore -- HTML construit par AdminManager.
                $channel === 'sms' ? __('SMS', 'smbb-wpcodetool') : __('E-mails', 'smbb-wpcodetool'),
                $channel === 'sms'
                    ? __('SMS envoyés par le site et ses plugins : connecteur, test, journal, coûts et alertes en cas de panne.', 'smbb-wpcodetool')
                    : __('E-mails envoyés par WordPress et ses plugins : connecteur, test, journal et alertes en cas de panne.', 'smbb-wpcodetool'),
                $channel === 'sms' ? 'dashicons-smartphone' : 'dashicons-email-alt',
                $notices
            );
            ?>
            <nav class="nav-tab-wrapper smbb-mail-tabs">
                <?php foreach ($tabs as $key => $def) : ?>
                    <a class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::url($key, array(), $channel)); ?>"><span class="dashicons <?php echo esc_attr($def[1]); ?>" aria-hidden="true"></span> <?php echo esc_html($def[0]); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php
            if ($channel === 'sms') {
                $panel = new SmsPanel($this);

                if ($tab === 'test') {
                    $panel->renderTest();
                } elseif ($tab === 'log') {
                    $panel->renderLog();
                } elseif ($tab === 'stats') {
                    $panel->renderStats();
                } else {
                    $panel->renderSettings();
                }
            } elseif ($tab === 'test') {
                $this->renderTest();
            } elseif ($tab === 'log') {
                $this->renderLog();
            } else {
                $this->renderSettings();
            }
            ?>
        </div>
        <?php
        $this->css();
        $this->script();
    }

    public function notice($type, $message)
    {
        return '<div class="smbb-codetool-notice is-' . esc_attr($type) . '"><p class="smbb-codetool-notice-message">' . esc_html($message) . '</p></div>';
    }

    private function notices($tab)
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $notices = array();

        if (Mailer::conflict()) {
            $notices[] = $this->notice('error', __('WP Mail SMTP est encore actif : tant qu\'il l\'est, c\'est lui qui envoie et CodeTool ne touche à rien. Importez ses réglages puis désactivez-le.', 'smbb-wpcodetool'));
        }

        if (isset($_GET['saved'])) {
            $notices[] = $this->notice('success', __('Réglages e-mail enregistrés.', 'smbb-wpcodetool'));
        }

        if (isset($_GET['imported'])) {
            $imported = sanitize_key(wp_unslash($_GET['imported']));

            if ($imported === 'none') {
                $notices[] = $this->notice('error', __('Plus de réglages WP Mail SMTP à importer.', 'smbb-wpcodetool'));
            } else {
                $message = __('Réglages de WP Mail SMTP importés. Faites un test d\'envoi pour vérifier.', 'smbb-wpcodetool');

                if (!empty($_GET['deactivated'])) {
                    $message .= ' ' . __('WP Mail SMTP a été désactivé (ses réglages restent en base, rien n\'est supprimé).', 'smbb-wpcodetool');
                }

                $notices[] = $this->notice('success', $message);

                if ($imported === 'check_pass') {
                    $notices[] = $this->notice('error', __('Le mot de passe de WP Mail SMTP n\'a pas pu être déchiffré (clé introuvable) : il a été repris tel quel, ressaisissez-le si le test échoue.', 'smbb-wpcodetool'));
                }
            }
        }

        if ($tab === 'log' && isset($_GET['done'])) {
            $done = sanitize_key(wp_unslash($_GET['done']));

            if ($done === 'deleted') {
                $notices[] = $this->notice('success', sprintf(_n('%d e-mail supprimé du journal.', '%d e-mails supprimés du journal.', absint($_GET['n'] ?? 0), 'smbb-wpcodetool'), absint($_GET['n'] ?? 0)));
            } elseif ($done === 'resent') {
                $ok = absint($_GET['ok'] ?? 0);
                $ko = absint($_GET['ko'] ?? 0);
                $skip = absint($_GET['skip'] ?? 0);
                $parts = array(sprintf(_n('%d e-mail renvoyé', '%d e-mails renvoyés', $ok, 'smbb-wpcodetool'), $ok));

                if ($ko) {
                    $parts[] = sprintf(_n('%d en erreur', '%d en erreur', $ko, 'smbb-wpcodetool'), $ko);
                }

                if ($skip) {
                    $parts[] = sprintf(_n('%d sans contenu gardé', '%d sans contenu gardé', $skip, 'smbb-wpcodetool'), $skip);
                }

                $notices[] = $this->notice($ko ? 'error' : 'success', implode(', ', $parts) . '.');
            } elseif ($done === 'none') {
                $notices[] = $this->notice('info', __('Cochez au moins un e-mail et choisissez une action.', 'smbb-wpcodetool'));
            }
        }

        // phpcs:enable WordPress.Security.NonceVerification
        return $notices;
    }

    public function sectionStart($icon, $title, $copy = '')
    {
        ?>
        <section class="smbb-codetool-section">
            <header class="smbb-codetool-section-header">
                <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr($icon); ?>"></span></span>
                <div class="smbb-codetool-section-heading">
                    <h3><?php echo esc_html($title); ?></h3>
                    <?php if ($copy !== '') : ?><p class="description"><?php echo esc_html($copy); ?></p><?php endif; ?>
                </div>
            </header>
            <div class="smbb-codetool-section-body">
        <?php
    }

    public function sectionEnd()
    {
        echo '</div></section>';
    }

    private function renderSettings()
    {
        $settings = Mailer::settings();
        $transports = Mailer::transports();
        $current = Mailer::transport($settings);
        $found = WpMailSmtpImport::detect();
        $reason = Mailer::inactiveReason();

        if ($found) {
            $this->renderImportCard($found);
        }
        ?>
        <form method="post" action="<?php echo esc_url(self::url('settings')); ?>" autocomplete="off">
            <?php wp_nonce_field(self::NONCE, 'smbb_codetool_mail_nonce'); ?>
            <input type="hidden" name="smbb_mail_do" value="save">

            <?php $this->sectionStart('dashicons-email-alt', __('Envoi', 'smbb-wpcodetool'), __('Sans module, WordPress envoie avec la fonction mail de PHP : souvent classé en spam, voire jamais livré.', 'smbb-wpcodetool')); ?>
                <?php AppearancePage::toggle('enabled', $settings['enabled'], __('CodeTool envoie les e-mails du site', 'smbb-wpcodetool'), __('Tous les e-mails (WordPress, WooCommerce, formulaires, plugins CodeTool) passent par le connecteur ci-dessous.', 'smbb-wpcodetool')); ?>

                <?php if ($settings['enabled'] && $reason !== '') : ?>
                    <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html($reason); ?></p>
                <?php elseif ($settings['enabled'] && MailHealth::broken()) : ?>
                    <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('Le dernier envoi a échoué : voir l\'onglet Test d\'envoi.', 'smbb-wpcodetool'); ?></p>
                <?php elseif ($settings['enabled']) : ?>
                    <p class="smbb-codetool-encryption-state is-good"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php echo esc_html(sprintf(__('Actif : %s.', 'smbb-wpcodetool'), $current ? $current->summary(Mailer::transportSettings($settings)) : '')); ?></p>
                <?php endif; ?>

                <?php if (count($transports) > 1) : ?>
                    <p>
                        <label class="smbb-codetool-field-label" for="smbb-mail-transport"><?php esc_html_e('Connecteur', 'smbb-wpcodetool'); ?></label><br>
                        <select id="smbb-mail-transport" name="transport" data-smbb-mail-transport>
                            <?php foreach ($transports as $key => $transport) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['transport'], $key); ?>><?php echo esc_html($transport->label()); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                <?php else : ?>
                    <input type="hidden" name="transport" value="<?php echo esc_attr((string) key($transports)); ?>">
                <?php endif; ?>
            <?php $this->sectionEnd(); ?>

            <?php foreach ($transports as $key => $transport) : ?>
                <div data-smbb-mail-transport-panel="<?php echo esc_attr($key); ?>"<?php echo $settings['transport'] === $key ? '' : ' hidden'; ?>>
                    <?php $this->sectionStart('dashicons-networking', sprintf(__('Serveur %s', 'smbb-wpcodetool'), $transport->label()), __('Les informations données par votre hébergeur ou votre messagerie.', 'smbb-wpcodetool')); ?>
                        <?php $transport->renderFields(isset($settings['transports'][$key]) && is_array($settings['transports'][$key]) ? $settings['transports'][$key] : array()); ?>
                    <?php $this->sectionEnd(); ?>
                </div>
            <?php endforeach; ?>

            <?php $this->sectionStart('dashicons-id', __('Expéditeur', 'smbb-wpcodetool'), __('L\'adresse doit appartenir au compte SMTP (ou à son domaine), sinon le serveur refuse ou l\'e-mail part en spam.', 'smbb-wpcodetool')); ?>
                <div class="smbb-mail-grid">
                    <p class="smbb-mail-field is-wide">
                        <label class="smbb-codetool-field-label" for="smbb-mail-from"><?php esc_html_e('Adresse d\'expédition', 'smbb-wpcodetool'); ?></label>
                        <input type="email" class="regular-text" id="smbb-mail-from" name="from_email" value="<?php echo esc_attr($settings['from_email']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                    </p>
                    <p class="smbb-mail-field is-wide">
                        <label class="smbb-codetool-field-label" for="smbb-mail-from-name"><?php esc_html_e('Nom affiché', 'smbb-wpcodetool'); ?></label>
                        <input type="text" class="regular-text" id="smbb-mail-from-name" name="from_name" value="<?php echo esc_attr($settings['from_name']); ?>" placeholder="<?php echo esc_attr(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)); ?>">
                    </p>
                </div>
                <?php AppearancePage::toggle('force_from', $settings['force_from'], __('Toujours cette adresse', 'smbb-wpcodetool'), __('Même si un plugin en demande une autre (conseillé : le serveur SMTP refuse souvent les autres adresses). Coupé : seulement à la place de « wordpress@… ».', 'smbb-wpcodetool')); ?>
                <?php AppearancePage::toggle('force_name', $settings['force_name'], __('Toujours ce nom', 'smbb-wpcodetool'), __('Coupé : seulement à la place de « WordPress », un plugin peut donner son propre nom (ex. WooCommerce).', 'smbb-wpcodetool')); ?>
                <?php AppearancePage::toggle('return_path', $settings['return_path'], __('Retours à l\'expéditeur', 'smbb-wpcodetool'), __('Les e-mails non distribués reviennent à l\'adresse d\'expédition (Return-Path).', 'smbb-wpcodetool')); ?>
            <?php $this->sectionEnd(); ?>

            <?php $this->sectionStart('dashicons-list-view', __('Journal et alertes', 'smbb-wpcodetool')); ?>
                <?php AppearancePage::toggle('log', $settings['log'], __('Garder un journal des e-mails', 'smbb-wpcodetool'), __('E-mails envoyés et en erreur. Sert aussi aux compteurs du widget.', 'smbb-wpcodetool')); ?>
                <div data-smbb-mail-log-options<?php echo $settings['log'] ? '' : ' hidden'; ?>>
                    <p>
                        <label class="smbb-codetool-field-label" for="smbb-mail-log-days"><?php esc_html_e('Durée de conservation', 'smbb-wpcodetool'); ?></label><br>
                        <select id="smbb-mail-log-days" name="log_days">
                            <?php foreach (MailLog::durations() as $days => $label) : ?>
                                <option value="<?php echo esc_attr((string) $days); ?>" <?php selected((int) $settings['log_days'], $days); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="description"><?php esc_html_e('Au-delà, les e-mails et leurs pièces jointes sont effacés tout seuls, chaque jour (tâche cron « Purge du journal des e-mails »).', 'smbb-wpcodetool'); ?></span>
                    </p>
                    <?php AppearancePage::toggle('log_body', $settings['log_body'], __('Garder le contenu des e-mails', 'smbb-wpcodetool'), __('Nécessaire pour les renvoyer. Attention : le journal contient alors aussi les liens de réinitialisation de mot de passe (visibles des seuls administrateurs).', 'smbb-wpcodetool')); ?>
                    <?php AppearancePage::toggle('log_attachments', $settings['log_attachments'], __('Garder une copie des pièces jointes', 'smbb-wpcodetool'), sprintf(__('Les originaux sont souvent des fichiers temporaires (factures PDF...) effacés après l\'envoi : la copie permet de les renvoyer. Dossier protégé dans uploads, %s maximum par e-mail, effacé avec le journal.', 'smbb-wpcodetool'), size_format(MailLog::maxAttachmentBytes()))); ?>
                </div>
                <?php AppearancePage::toggle('alerts', $settings['alerts'], __('Alerte en cas de panne', 'smbb-wpcodetool'), __('Bandeau rouge en haut de l\'administration dès qu\'un envoi échoue, jusqu\'au prochain envoi réussi.', 'smbb-wpcodetool')); ?>
            <?php $this->sectionEnd(); ?>

            <p class="submit">
                <input type="hidden" name="then_test" value="0" data-smbb-mail-then-test>
                <button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button>
                <button type="submit" class="button" data-smbb-mail-save-test><?php esc_html_e('Enregistrer et tester', 'smbb-wpcodetool'); ?></button>
            </p>
        </form>
        <?php
    }

    private function renderImportCard(array $found)
    {
        $status = get_option(WpMailSmtpImport::STATUS_OPTION, '');
        $states = array(
            'decrypted' => __('chiffré par WP Mail SMTP, déchiffré avec sa clé', 'smbb-wpcodetool'),
            'plain' => __('trouvé', 'smbb-wpcodetool'),
            'constant' => __('donné par la constante WPMS_SMTP_PASS', 'smbb-wpcodetool'),
            'uncertain' => __('clé de WP Mail SMTP introuvable : repris tel quel, à vérifier', 'smbb-wpcodetool'),
            'empty' => __('aucun', 'smbb-wpcodetool'),
        );
        $highlight = isset($_GET['import']) || $status === ''; // phpcs:ignore WordPress.Security.NonceVerification
        ?>
        <details class="smbb-codetool-section smbb-mail-import<?php echo $highlight ? ' is-highlight' : ''; ?>"<?php echo $highlight ? ' open' : ''; ?>>
            <summary class="smbb-codetool-section-header">
                <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-migrate"></span></span>
                <span class="smbb-codetool-section-heading">
                    <h3><?php esc_html_e('Importer depuis WP Mail SMTP', 'smbb-wpcodetool'); ?></h3>
                    <span class="description"><?php echo esc_html($status === 'done' ? __('Déjà importé : vous pouvez réimporter pour écraser les réglages ci-dessous.', 'smbb-wpcodetool') : __('Ses réglages sont en base : reprenez-les en un clic.', 'smbb-wpcodetool')); ?></span>
                </span>
            </summary>
            <div class="smbb-codetool-section-body">
                <table class="widefat striped smbb-mail-import-table">
                    <tbody>
                        <tr><th><?php esc_html_e('Source', 'smbb-wpcodetool'); ?></th><td><?php echo esc_html($found['source'] === 'constants' ? __('Constantes WPMS_* de wp-config.php', 'smbb-wpcodetool') : __('Réglages en base', 'smbb-wpcodetool')); ?><?php echo Mailer::conflict() ? ' · ' . esc_html__('plugin encore actif', 'smbb-wpcodetool') : ''; ?></td></tr>
                        <tr><th><?php esc_html_e('Méthode', 'smbb-wpcodetool'); ?></th><td><code><?php echo esc_html($found['mailer']); ?></code><?php echo $found['supported'] ? '' : ' <span class="smbb-codetool-badge is-warning">' . esc_html__('pas encore gérée : seul l\'expéditeur sera repris', 'smbb-wpcodetool') . '</span>'; ?></td></tr>
                        <?php if ($found['supported']) : ?>
                            <tr><th><?php esc_html_e('Serveur', 'smbb-wpcodetool'); ?></th><td><?php echo esc_html($found['smtp']['host'] . ':' . $found['smtp']['port'] . ' · ' . $found['smtp']['encryption']); ?></td></tr>
                            <tr><th><?php esc_html_e('Identifiant', 'smbb-wpcodetool'); ?></th><td><?php echo esc_html($found['smtp']['auth'] ? $found['smtp']['user'] : __('sans authentification', 'smbb-wpcodetool')); ?></td></tr>
                            <tr><th><?php esc_html_e('Mot de passe', 'smbb-wpcodetool'); ?></th><td><?php echo esc_html($states[$found['pass_state']] ?? $found['pass_state']); ?></td></tr>
                        <?php endif; ?>
                        <tr><th><?php esc_html_e('Expéditeur', 'smbb-wpcodetool'); ?></th><td><?php echo esc_html(trim($found['from_name'] . ' <' . $found['from_email'] . '>')); ?><?php echo $found['force_from'] ? ' · ' . esc_html__('forcé', 'smbb-wpcodetool') : ''; ?></td></tr>
                    </tbody>
                </table>
                <p class="smbb-mail-import-actions">
                    <?php if ($found['plugin'] !== '' && current_user_can('activate_plugins')) : ?>
                        <a class="button button-primary" href="<?php echo esc_url(WpMailSmtpImport::actionUrl('import_deactivate')); ?>"><?php esc_html_e('Importer et désactiver WP Mail SMTP', 'smbb-wpcodetool'); ?></a>
                        <a class="button" href="<?php echo esc_url(WpMailSmtpImport::actionUrl('import')); ?>"><?php esc_html_e('Importer seulement', 'smbb-wpcodetool'); ?></a>
                    <?php else : ?>
                        <a class="button button-primary" href="<?php echo esc_url(WpMailSmtpImport::actionUrl('import')); ?>"><?php esc_html_e('Importer', 'smbb-wpcodetool'); ?></a>
                    <?php endif; ?>
                    <?php if ($status === '') : ?>
                        <a class="button-link" href="<?php echo esc_url(WpMailSmtpImport::actionUrl('dismiss')); ?>"><?php esc_html_e('Ne plus proposer', 'smbb-wpcodetool'); ?></a>
                    <?php endif; ?>
                </p>
                <p class="description"><?php esc_html_e('Rien n\'est modifié ni supprimé chez WP Mail SMTP. Son journal et ses connexions de secours ne sont pas repris.', 'smbb-wpcodetool'); ?></p>
            </div>
        </details>
        <?php
    }

    private function renderTest()
    {
        $user_id = get_current_user_id();
        $result = isset($_GET['tested']) ? get_transient('smbb_codetool_mail_test_' . $user_id) : false; // phpcs:ignore WordPress.Security.NonceVerification
        $to = get_user_meta($user_id, 'smbb_codetool_mail_test_to', true);
        $to = is_email($to) ? $to : wp_get_current_user()->user_email;
        $reason = Mailer::inactiveReason();
        $settings = Mailer::settings();
        $transport = Mailer::transport($settings);
        ?>
        <?php if (is_array($result)) : ?>
            <section class="smbb-codetool-section smbb-mail-result <?php echo $result['ok'] ? 'is-ok' : 'is-ko'; ?>">
                <div class="smbb-mail-result-head">
                    <span class="dashicons <?php echo $result['ok'] ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>" aria-hidden="true"></span>
                    <div>
                        <h3><?php echo esc_html($result['ok'] ? __('E-mail de test envoyé', 'smbb-wpcodetool') : __('L\'envoi a échoué', 'smbb-wpcodetool')); ?></h3>
                        <p>
                            <?php
                            echo esc_html($result['ok']
                                ? sprintf(__('Le serveur l\'a accepté en %1$s s pour %2$s. Vérifiez la boîte de réception (et les indésirables).', 'smbb-wpcodetool'), number_format_i18n($result['duration'], 1), $result['to'])
                                : $result['error']);
                            ?>
                        </p>
                        <?php if (!$result['ok']) : ?>
                            <p class="smbb-mail-hint"><?php echo esc_html(self::hint($result['error'], $result['transcript'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($result['transcript'])) : ?>
                    <details class="smbb-mail-transcript"<?php echo $result['ok'] ? '' : ' open'; ?>>
                        <summary><?php esc_html_e('Dialogue avec le serveur (identifiants masqués)', 'smbb-wpcodetool'); ?></summary>
                        <pre><?php echo esc_html(implode("\n", $result['transcript'])); ?></pre>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(self::url('test')); ?>">
            <?php wp_nonce_field(self::NONCE, 'smbb_codetool_mail_nonce'); ?>
            <input type="hidden" name="smbb_mail_do" value="test">
            <?php $this->sectionStart('dashicons-email-alt', __('Envoyer un e-mail de test', 'smbb-wpcodetool'), $transport ? sprintf(__('Avec les réglages enregistrés : %s.', 'smbb-wpcodetool'), $transport->summary(Mailer::transportSettings($settings))) : ''); ?>
                <?php if ($reason !== '' && empty($settings['enabled'])) : ?>
                    <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e('Le module est coupé : le test utilise quand même les réglages enregistrés, pour vérifier avant de l\'allumer.', 'smbb-wpcodetool'); ?></p>
                <?php elseif (Mailer::conflict()) : ?>
                    <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('WP Mail SMTP est actif et peut prendre la main sur ce test : désactivez-le pour un résultat fiable.', 'smbb-wpcodetool'); ?></p>
                <?php endif; ?>
                <p>
                    <label class="smbb-codetool-field-label" for="smbb-mail-test-to"><?php esc_html_e('Destinataire', 'smbb-wpcodetool'); ?></label><br>
                    <input type="email" class="regular-text" id="smbb-mail-test-to" name="to" value="<?php echo esc_attr($to); ?>" required>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Envoyer le test', 'smbb-wpcodetool'); ?></button>
                </p>
                <p class="description"><?php esc_html_e('Le test apparaît aussi dans le journal, marqué « test ».', 'smbb-wpcodetool'); ?></p>
            <?php $this->sectionEnd(); ?>
        </form>
        <?php
    }

    /**
     * Piste de solution à partir du message d'erreur, en français courant.
     */
    private static function hint($error, array $transcript)
    {
        $haystack = strtolower($error . ' ' . implode(' ', $transcript));

        if (strpos($haystack, 'could not authenticate') !== false || preg_match('/\b535\b/', $haystack)) {
            return __('Identifiant ou mot de passe refusé. Chez Gmail / Microsoft 365, il faut un « mot de passe d\'application ».', 'smbb-wpcodetool');
        }

        if (strpos($haystack, 'could not connect') !== false || strpos($haystack, 'connection refused') !== false || strpos($haystack, 'timed out') !== false) {
            return __('Serveur injoignable : vérifiez le nom et le port. Beaucoup d\'hébergeurs bloquent les ports 25/465/587 vers l\'extérieur, essayez l\'autre port (587 STARTTLS ou 465 SSL).', 'smbb-wpcodetool');
        }

        if (strpos($haystack, 'certificate') !== false || strpos($haystack, 'ssl') !== false && strpos($haystack, 'error') !== false) {
            return __('Problème de certificat ou de chiffrement : essayez l\'autre mode (STARTTLS sur 587 ou SSL sur 465) et le nom exact du serveur indiqué par l\'hébergeur.', 'smbb-wpcodetool');
        }

        if (strpos($haystack, 'sender') !== false || strpos($haystack, 'from') !== false && preg_match('/\b55[0-9]\b/', $haystack)) {
            return __('Adresse d\'expédition refusée : utilisez l\'adresse du compte SMTP et cochez « Toujours cette adresse ».', 'smbb-wpcodetool');
        }

        return __('Le dialogue ci-dessous montre la dernière réponse du serveur.', 'smbb-wpcodetool');
    }

    private function renderLog()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable WordPress.Security.NonceVerification
        $settings = Mailer::settings();

        MailLog::purge();
        $data = MailLog::query($status, $search, $paged, self::PER_PAGE);
        $stats = MailLog::stats(MailLog::days());
        $pages = max(1, (int) ceil($data['total'] / self::PER_PAGE));
        $filters = array(
            '' => array(__('Tous', 'smbb-wpcodetool'), $stats['sent'] + $stats['failed']),
            'sent' => array(__('Envoyés', 'smbb-wpcodetool'), $stats['sent']),
            'failed' => array(__('En erreur', 'smbb-wpcodetool'), $stats['failed']),
        );
        $sources = array('test' => __('test', 'smbb-wpcodetool'), 'resend' => __('renvoi', 'smbb-wpcodetool'));
        ?>
        <?php if (empty($settings['log'])) : ?>
            <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e('Le journal est coupé (onglet Réglages) : plus rien n\'est noté.', 'smbb-wpcodetool'); ?></p>
        <?php endif; ?>

        <div class="smbb-mail-log-bar">
            <ul class="subsubsub">
                <?php $i = 0; foreach ($filters as $key => $def) : ?>
                    <li><?php echo $i++ ? ' | ' : ''; ?><a href="<?php echo esc_url(self::url('log', array_filter(array('status' => $key, 's' => $search)))); ?>"<?php echo $status === $key ? ' class="current"' : ''; ?>><?php echo esc_html($def[0]); ?> <span class="count">(<?php echo esc_html(number_format_i18n($def[1])); ?>)</span></a></li>
                <?php endforeach; ?>
            </ul>
            <form method="get" class="smbb-mail-search">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <input type="hidden" name="tab" value="log">
                <?php if ($status !== '') : ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>"><?php endif; ?>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Destinataire, sujet, erreur…', 'smbb-wpcodetool'); ?>">
                <button class="button"><?php esc_html_e('Rechercher', 'smbb-wpcodetool'); ?></button>
            </form>
        </div>

        <form method="post" action="<?php echo esc_url(self::url('log')); ?>" data-smbb-mail-log>
            <?php wp_nonce_field(self::NONCE, 'smbb_codetool_mail_nonce'); ?>
            <input type="hidden" name="smbb_mail_do" value="log">
            <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
            <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">

            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="bulk">
                        <option value=""><?php esc_html_e('Actions groupées', 'smbb-wpcodetool'); ?></option>
                        <option value="resend"><?php esc_html_e('Renvoyer', 'smbb-wpcodetool'); ?></option>
                        <option value="delete"><?php esc_html_e('Supprimer du journal', 'smbb-wpcodetool'); ?></option>
                    </select>
                    <button type="submit" class="button" data-smbb-mail-bulk><?php esc_html_e('Appliquer', 'smbb-wpcodetool'); ?></button>
                    <?php if ($status === 'failed' && $data['total']) : ?>
                        <button type="button" class="button" data-smbb-mail-check-all><?php esc_html_e('Tout cocher', 'smbb-wpcodetool'); ?></button>
                    <?php endif; ?>
                </div>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo esc_html(sprintf(_n('%s e-mail', '%s e-mails', $data['total'], 'smbb-wpcodetool'), number_format_i18n($data['total']))); ?></span>
                    <?php if ($pages > 1) : ?>
                        <?php
                        echo wp_kses_post(paginate_links(array(
                            'base' => add_query_arg('paged', '%#%', self::url('log', array_filter(array('status' => $status, 's' => $search)))),
                            'format' => '',
                            'current' => $paged,
                            'total' => $pages,
                        )));
                        ?>
                    <?php endif; ?>
                </div>
            </div>

            <table class="widefat striped smbb-codetool-table smbb-mail-log">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" data-smbb-mail-toggle-all aria-label="<?php esc_attr_e('Tout sélectionner', 'smbb-wpcodetool'); ?>"></td>
                        <th><?php esc_html_e('Date', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('État', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Destinataire', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Sujet', 'smbb-wpcodetool'); ?></th>
                        <th class="smbb-mail-log-actions"><?php esc_html_e('Actions', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$data['rows']) : ?>
                        <tr><td colspan="6" class="smbb-mail-empty"><?php echo esc_html($search !== '' || $status !== '' ? __('Aucun e-mail ne correspond.', 'smbb-wpcodetool') : sprintf(__('Aucun e-mail sur les %d derniers jours.', 'smbb-wpcodetool'), MailLog::days())); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($data['rows'] as $row) : ?>
                        <?php
                        $failed = $row['status'] === 'failed';
                        $resendable = MailLog::canResend($row);
                        $attachments = json_decode((string) $row['attachments'], true);
                        $missing = is_array($attachments) && $attachments ? MailLog::missingAttachments($row) : 0;
                        ?>
                        <tr class="<?php echo $failed ? 'is-failed' : 'is-sent'; ?>">
                            <th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr($row['id']); ?>"<?php echo $failed ? ' data-failed' : ''; ?>></th>
                            <td class="smbb-mail-date"><?php echo esc_html(get_date_from_gmt($row['created_at'], 'd/m H:i:s')); ?></td>
                            <td>
                                <span class="smbb-codetool-badge <?php echo $failed ? 'is-error' : 'is-success'; ?>"><?php echo esc_html($failed ? __('Erreur', 'smbb-wpcodetool') : __('Envoyé', 'smbb-wpcodetool')); ?></span>
                                <?php if (isset($sources[$row['source']])) : ?><span class="smbb-mail-source"><?php echo esc_html($sources[$row['source']]); ?></span><?php endif; ?>
                                <?php if ((int) $row['resends'] > 0) : ?><span class="smbb-mail-source" title="<?php esc_attr_e('Renvoyé depuis le journal', 'smbb-wpcodetool'); ?>">↻ <?php echo esc_html((string) (int) $row['resends']); ?></span><?php endif; ?>
                            </td>
                            <td class="smbb-mail-to"><?php echo esc_html($row['to_email']); ?></td>
                            <td class="smbb-mail-subject">
                                <strong><?php echo esc_html($row['subject'] !== '' ? $row['subject'] : __('(sans sujet)', 'smbb-wpcodetool')); ?></strong>
                                <?php if ($failed && $row['error'] !== '') : ?><span class="smbb-mail-error"><?php echo esc_html(wp_trim_words($row['error'], 24)); ?></span><?php endif; ?>
                                <?php if (is_array($attachments) && $attachments) : ?><span class="smbb-mail-attach<?php echo $missing ? ' is-missing' : ''; ?>" title="<?php echo esc_attr($missing ? sprintf(_n('%d pièce jointe introuvable : elle ne sera pas renvoyée', '%d pièces jointes introuvables : elles ne seront pas renvoyées', $missing, 'smbb-wpcodetool'), $missing) : __('Pièces jointes gardées', 'smbb-wpcodetool')); ?>"><span class="dashicons dashicons-paperclip" aria-hidden="true"></span><?php echo esc_html((string) count($attachments)); ?><?php echo $missing ? ' ⚠' : ''; ?></span><?php endif; ?>
                            </td>
                            <td class="smbb-mail-log-actions">
                                <button type="button" class="button button-small" data-smbb-mail-view="<?php echo esc_attr($row['id']); ?>"><?php esc_html_e('Voir', 'smbb-wpcodetool'); ?></button>
                                <?php if ($resendable) : ?>
                                    <button type="submit" class="button button-small" name="resend_one" value="<?php echo esc_attr($row['id']); ?>"><?php esc_html_e('Renvoyer', 'smbb-wpcodetool'); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <dialog class="smbb-mail-viewer" data-smbb-mail-viewer data-url="<?php echo esc_url(add_query_arg(array('action' => 'smbb_codetool_mail_view', 'nonce' => wp_create_nonce(self::NONCE)), admin_url('admin-ajax.php'))); ?>">
            <div class="smbb-mail-viewer-head">
                <strong data-smbb-mail-viewer-title></strong>
                <button type="button" class="button-link" data-smbb-mail-viewer-close aria-label="<?php esc_attr_e('Fermer', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-no-alt"></span></button>
            </div>
            <div class="smbb-mail-viewer-body" data-smbb-mail-viewer-body></div>
        </dialog>
        <?php
    }

    /**
     * AJAX : détail d'un e-mail du journal (chargé à la demande, le contenu peut être lourd).
     */
    public static function ajaxView()
    {
        if (!current_user_can('manage_options') || !check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(array('message' => __('Action non autorisée.', 'smbb-wpcodetool')), 403);
        }

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $row = $id ? MailLog::get($id) : null;

        if (!$row) {
            wp_send_json_error(array('message' => __('E-mail introuvable (déjà purgé ?).', 'smbb-wpcodetool')), 404);
        }

        ob_start();
        self::renderDetail($row);
        wp_send_json_success(array('title' => (string) $row['subject'], 'html' => (string) ob_get_clean()));
    }

    /**
     * admin-post : télécharge la copie d'une pièce jointe du journal.
     */
    public static function downloadAttachment()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Action non autorisée.', 'smbb-wpcodetool'));
        }

        check_admin_referer('smbb_codetool_mail_attachment');
        $row = MailLog::get(isset($_GET['id']) ? absint($_GET['id']) : 0);
        $index = isset($_GET['file']) ? absint($_GET['file']) : -1;
        $stored = $row ? MailLog::storedAttachments($row) : array();

        if (!isset($stored[$index])) {
            wp_die(esc_html__('Pièce jointe introuvable (déjà purgée ?).', 'smbb-wpcodetool'));
        }

        $path = $stored[$index];
        $type = wp_check_filetype(wp_basename($path));
        nocache_headers();
        header('Content-Type: ' . ($type['type'] ? $type['type'] : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', wp_basename($path)) . '"');
        header('Content-Length: ' . (int) filesize($path));
        readfile($path); // phpcs:ignore
        exit;
    }

    /**
     * Détail d'un e-mail : en-têtes, erreur, pièces jointes, contenu (HTML dans une iframe sans scripts).
     */
    private static function renderDetail(array $row)
    {
        $headers = json_decode((string) $row['headers'], true);
        $headers = is_array($headers) ? $headers : array_filter(array_map('trim', explode("\n", (string) $headers)));
        $attachments = json_decode((string) $row['attachments'], true);
        ?>
        <dl class="smbb-mail-detail">
            <dt><?php esc_html_e('Date', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(get_date_from_gmt($row['created_at'], get_option('date_format') . ' H:i:s')); ?></dd>
            <dt><?php esc_html_e('À', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($row['to_email']); ?></dd>
            <dt><?php esc_html_e('Sujet', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($row['subject']); ?></dd>
            <dt><?php esc_html_e('Envoi', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($row['transport'] === 'wordpress' ? __('méthode par défaut de WordPress', 'smbb-wpcodetool') : strtoupper($row['transport'])); ?></dd>
            <?php if ($row['error'] !== '') : ?>
                <dt><?php esc_html_e('Erreur', 'smbb-wpcodetool'); ?></dt><dd class="smbb-mail-error"><?php echo esc_html($row['error']); ?></dd>
            <?php endif; ?>
            <?php if ($headers) : ?>
                <dt><?php esc_html_e('En-têtes', 'smbb-wpcodetool'); ?></dt><dd><code><?php echo esc_html(implode("\n", array_map('strval', $headers))); ?></code></dd>
            <?php endif; ?>
            <?php if (is_array($attachments) && $attachments) : ?>
                <?php $stored = MailLog::storedAttachments($row); ?>
                <dt><?php esc_html_e('Pièces jointes', 'smbb-wpcodetool'); ?></dt>
                <dd>
                    <?php foreach ($stored as $index => $path) : ?>
                        <a class="smbb-mail-attach-file" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=smbb_codetool_mail_attachment&id=' . (int) $row['id'] . '&file=' . (int) $index), 'smbb_codetool_mail_attachment')); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php echo esc_html(wp_basename($path) . ' (' . size_format((int) filesize($path)) . ')'); ?></a>
                    <?php endforeach; ?>
                    <?php if (!$stored) : ?>
                        <?php foreach ($attachments as $file) : ?>
                            <span class="smbb-mail-attach-file<?php echo is_readable($file) ? '' : ' is-missing'; ?>"><?php echo esc_html(wp_basename($file)); ?><?php echo is_readable($file) ? ' (' . esc_html__('original, pas de copie', 'smbb-wpcodetool') . ')' : ' (' . esc_html__('fichier disparu, non renvoyé', 'smbb-wpcodetool') . ')'; ?></span>
                        <?php endforeach; ?>
                    <?php elseif (count($stored) < count($attachments)) : ?>
                        <span class="smbb-mail-attach-file is-missing"><?php echo esc_html(sprintf(__('%d autre(s) non copiée(s) (trop lourde ou illisible)', 'smbb-wpcodetool'), count($attachments) - count($stored))); ?></span>
                    <?php endif; ?>
                </dd>
            <?php endif; ?>
        </dl>
        <?php if ((int) $row['truncated'] === 1 && $row['message'] === '') : ?>
            <p class="description"><?php esc_html_e('Contenu non gardé (réglage « Garder le contenu des e-mails » coupé) : renvoi impossible.', 'smbb-wpcodetool'); ?></p>
        <?php elseif (MailLog::isHtml($row)) : ?>
            <iframe class="smbb-mail-frame" sandbox="" referrerpolicy="no-referrer" srcdoc="<?php echo esc_attr($row['message']); ?>"></iframe>
        <?php else : ?>
            <pre class="smbb-mail-text"><?php echo esc_html($row['message']); ?></pre>
        <?php endif; ?>
        <?php if ((int) $row['truncated'] === 1 && $row['message'] !== '') : ?>
            <p class="description"><?php esc_html_e('Contenu trop long, coupé dans le journal : renvoi impossible.', 'smbb-wpcodetool'); ?></p>
        <?php endif; ?>
        <?php
    }

    private function css()
    {
        ?>
        <style>
            .smbb-sms-bubble{max-width:420px;padding:12px 16px;border-radius:18px 18px 18px 4px;background:#e9e9eb;color:#1d2327;font-size:14px;line-height:1.45;white-space:normal;word-break:break-word}
            .smbb-sms-import{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f1}
            .smbb-codetool-badge.is-success{background:#edf8f1;color:#1a7f4b;border-color:#b7e1c7}
            .smbb-codetool-badge.is-info{background:#f0f6fc;color:#2271b1;border-color:#c5d9ed}
            .smbb-sms-callback{display:inline-block;max-width:100%;overflow-wrap:anywhere;padding:4px 8px}
            .smbb-sms-stats-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:0 0 16px}
            .smbb-sms-card{display:flex;flex-direction:column;gap:4px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:10px}
            .smbb-sms-card-label{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
            .smbb-sms-card strong{font-size:24px;line-height:1.2;color:#1d2327}
            .smbb-sms-card-note{color:#646970;font-size:12px}
            .smbb-sms-chart{display:flex;align-items:stretch;gap:6px;height:200px;padding:8px 0 0;overflow-x:auto}
            .smbb-sms-col{flex:1 0 28px;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;min-width:28px}
            .smbb-sms-col-bar{display:block;width:70%;max-width:46px;background:var(--smbb-codetool-accent,#2271b1);border-radius:4px 4px 0 0;opacity:.85}
            .smbb-sms-col.is-current .smbb-sms-col-bar{opacity:.45}
            .smbb-sms-col.is-peak .smbb-sms-col-bar{background:#dba617;opacity:1}
            .smbb-sms-col-value{font-size:11px;color:#50575e;margin-bottom:3px;white-space:nowrap}
            .smbb-sms-col-label{font-size:11px;color:#646970;margin-top:6px;padding-top:4px;border-top:1px solid #dcdcde;width:100%;text-align:center;white-space:nowrap}
            .smbb-sms-stats-table .num{text-align:right;white-space:nowrap}
            .smbb-sms-stats-table tr.is-peak td{background:#fcf9e8}
            .smbb-mail-grid{display:flex;flex-wrap:wrap;gap:4px 18px}
            .smbb-mail-field{margin:0 0 12px}
            .smbb-mail-field.is-wide{flex:1 1 260px}
            .smbb-mail-field input.regular-text{width:100%}
            .smbb-mail-inline{display:block;margin-top:6px}
            .smbb-mail-import.is-highlight{border-color:var(--smbb-codetool-accent,#2271b1)}
            .smbb-mail-import>summary{cursor:pointer;list-style:none}
            .smbb-mail-import>summary::-webkit-details-marker{display:none}
            .smbb-mail-import-table th{width:140px;font-weight:600}
            .smbb-mail-import-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
            .smbb-mail-result .smbb-mail-result-head{display:flex;gap:12px;padding:16px 18px}
            .smbb-mail-result .smbb-mail-result-head .dashicons{font-size:30px;width:30px;height:30px}
            .smbb-mail-result h3{margin:0 0 4px}
            .smbb-mail-result p{margin:0 0 4px}
            .smbb-mail-result.is-ok .smbb-mail-result-head .dashicons{color:#1a7f4b}
            .smbb-mail-result.is-ko{border-color:#f3b5b5}
            .smbb-mail-result.is-ko .smbb-mail-result-head .dashicons{color:#c62828}
            .smbb-mail-hint{font-weight:600}
            .smbb-mail-transcript{padding:0 18px 16px}
            .smbb-mail-transcript summary{cursor:pointer;margin-bottom:8px}
            .smbb-mail-transcript pre,.smbb-mail-text{max-height:360px;overflow:auto;background:#1e1e24;color:#e6e6ea;padding:12px 14px;border-radius:8px;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
            .smbb-mail-log-bar{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px}
            .smbb-mail-log-bar .subsubsub{margin:0}
            .smbb-mail-search{display:flex;gap:6px}
            .smbb-mail-log td,.smbb-mail-log th{vertical-align:middle}
            .smbb-mail-date{white-space:nowrap;font-variant-numeric:tabular-nums}
            .smbb-mail-to{word-break:break-all;max-width:240px}
            .smbb-mail-subject strong{display:block}
            .smbb-mail-error{display:block;color:#c62828;font-size:12px}
            .smbb-mail-attach{font-size:12px;color:#646970}.smbb-mail-attach .dashicons{font-size:14px;width:14px;height:14px}
            .smbb-mail-source{display:inline-block;margin-left:4px;font-size:11px;color:#646970;background:#f0f0f1;border-radius:4px;padding:1px 6px}
            .smbb-mail-log-actions{white-space:nowrap;text-align:right}
            .smbb-mail-log tr.is-failed td:first-of-type{box-shadow:inset 3px 0 0 #c62828}
            .smbb-mail-empty{text-align:center;padding:28px!important;color:#646970}
            .smbb-mail-viewer{width:min(860px,94vw);max-height:90vh;padding:0;border:0;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
            .smbb-mail-viewer::backdrop{background:rgba(20,20,30,.45)}
            .smbb-mail-viewer-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #e0e0e0;position:sticky;top:0;background:#fff}
            .smbb-mail-viewer-body{padding:16px 18px;overflow:auto}
            .smbb-mail-detail{display:grid;grid-template-columns:120px 1fr;gap:4px 12px;margin:0 0 14px}
            .smbb-mail-detail dt{font-weight:600;color:#50575e}
            .smbb-mail-detail dd{margin:0;word-break:break-word}
            .smbb-mail-detail code{white-space:pre-wrap;display:block;font-size:11px}
            .smbb-mail-attach-file{display:block}.smbb-mail-attach-file.is-missing{color:#8c8f94}
            .smbb-mail-attach-file .dashicons{font-size:16px;width:16px;height:16px;vertical-align:text-bottom;margin-right:2px}
            .smbb-mail-attach.is-missing{color:#b26200}
            .smbb-mail-frame{width:100%;height:56vh;border:1px solid #e0e0e0;border-radius:8px;background:#fff}
            @media (max-width:782px){.smbb-mail-detail{grid-template-columns:1fr}.smbb-mail-to{max-width:none}}
        </style>
        <?php
    }

    private function script()
    {
        ?>
        <script>
            (function () {
                function byName(name) { return document.querySelector('input[type="checkbox"][name="' + name + '"]'); }
                function bind(name, selector, invert) {
                    var box = byName(name), target = document.querySelector(selector);
                    if (!box || !target) { return; }
                    var sync = function () { target.hidden = invert ? box.checked : !box.checked; };
                    box.addEventListener('change', sync);
                }
                bind('transports[smtp][auth]', '[data-smbb-mail-auth]');
                bind('log', '[data-smbb-mail-log-options]');
                bind('sms_log', '[data-smbb-sms-log-options]');

                var enc = document.querySelector('[data-smbb-mail-encryption]');
                var port = document.querySelector('[data-smbb-mail-port]');
                var autotls = document.querySelector('[data-smbb-mail-autotls]');
                if (enc && port) {
                    var ports = { tls: '587', ssl: '465', none: '25' };
                    enc.addEventListener('change', function () {
                        if (['587', '465', '25', ''].indexOf(port.value) !== -1) { port.value = ports[enc.value]; }
                        if (autotls) { autotls.hidden = enc.value !== 'none'; }
                    });
                }

                var provider = document.querySelector('[data-smbb-sms-provider]');
                if (provider) {
                    provider.addEventListener('change', function () {
                        document.querySelectorAll('[data-smbb-sms-provider-panel]').forEach(function (panel) {
                            panel.hidden = panel.getAttribute('data-smbb-sms-provider-panel') !== provider.value;
                        });
                    });
                }

                var transport = document.querySelector('[data-smbb-mail-transport]');
                if (transport) {
                    transport.addEventListener('change', function () {
                        document.querySelectorAll('[data-smbb-mail-transport-panel]').forEach(function (panel) {
                            panel.hidden = panel.getAttribute('data-smbb-mail-transport-panel') !== transport.value;
                        });
                    });
                }

                var saveTest = document.querySelector('[data-smbb-mail-save-test]');
                if (saveTest) {
                    saveTest.addEventListener('click', function () { document.querySelector('[data-smbb-mail-then-test]').value = '1'; });
                }

                var all = document.querySelector('[data-smbb-mail-toggle-all]');
                if (all) {
                    all.addEventListener('change', function () {
                        document.querySelectorAll('.smbb-mail-log input[name="ids[]"]').forEach(function (box) { box.checked = all.checked; });
                    });
                }
                var failed = document.querySelector('[data-smbb-mail-check-all]');
                if (failed) {
                    failed.addEventListener('click', function () {
                        document.querySelectorAll('.smbb-mail-log input[data-failed]').forEach(function (box) { box.checked = true; });
                    });
                }

                var bulk = document.querySelector('[data-smbb-mail-bulk]');
                if (bulk) {
                    bulk.addEventListener('click', function (event) {
                        var action = bulk.form.querySelector('select[name="bulk"]').value;
                        var count = bulk.form.querySelectorAll('input[name="ids[]"]:checked').length;
                        if (action === 'delete' && count && !window.confirm(<?php echo wp_json_encode(__('Supprimer ces messages du journal ?', 'smbb-wpcodetool')); ?>)) { event.preventDefault(); }
                        if (action === 'resend' && count && !window.confirm(<?php echo wp_json_encode(__('Renvoyer ces messages à leurs destinataires ?', 'smbb-wpcodetool')); ?>)) { event.preventDefault(); }
                    });
                }

                var viewer = document.querySelector('[data-smbb-mail-viewer]');
                if (viewer && typeof viewer.showModal === 'function') {
                    var body = viewer.querySelector('[data-smbb-mail-viewer-body]');
                    var title = viewer.querySelector('[data-smbb-mail-viewer-title]');
                    document.querySelectorAll('[data-smbb-mail-view]').forEach(function (button) {
                        button.addEventListener('click', function () {
                            body.innerHTML = '<p class="description">' + <?php echo wp_json_encode(__('Chargement…', 'smbb-wpcodetool')); ?> + '</p>';
                            title.textContent = '';
                            viewer.showModal();
                            fetch(viewer.getAttribute('data-url') + '&id=' + encodeURIComponent(button.getAttribute('data-smbb-mail-view')), { credentials: 'same-origin' })
                                .then(function (response) { return response.json(); })
                                .then(function (json) {
                                    if (!json || !json.success) { throw new Error(json && json.data ? json.data.message : ''); }
                                    title.textContent = json.data.title;
                                    body.innerHTML = json.data.html;
                                })
                                .catch(function (error) {
                                    body.innerHTML = '';
                                    var p = document.createElement('p');
                                    p.className = 'smbb-mail-error';
                                    p.textContent = error.message || <?php echo wp_json_encode(__('Impossible de charger cet e-mail.', 'smbb-wpcodetool')); ?>;
                                    body.appendChild(p);
                                });
                        });
                    });
                    document.querySelectorAll('[data-smbb-sms-view]').forEach(function (button) {
                        button.addEventListener('click', function () {
                            var tpl = document.querySelector('template[data-smbb-sms-detail="' + button.getAttribute('data-smbb-sms-view') + '"]');
                            if (!tpl) { return; }
                            body.innerHTML = '';
                            body.appendChild(tpl.content.cloneNode(true));
                            title.textContent = tpl.getAttribute('data-title') || '';
                            viewer.showModal();
                        });
                    });
                    viewer.querySelector('[data-smbb-mail-viewer-close]').addEventListener('click', function () { viewer.close(); });
                    viewer.addEventListener('click', function (event) { if (event.target === viewer) { viewer.close(); } });
                }
            })();
        </script>
        <?php
    }
}
