<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Mail\MailLog;
use Smbb\WpCodeTool\Sms\Provider\TrackingInterface;
use Smbb\WpCodeTool\Sms\Sms;
use Smbb\WpCodeTool\Sms\SmsCosts;
use Smbb\WpCodeTool\Sms\SmsHealth;
use Smbb\WpCodeTool\Sms\SmsLog;
use Smbb\WpCodeTool\Sms\SmsTracking;

defined('ABSPATH') || exit;

/**
 * Page « SMS » de CodeTool › Communication (slug MailPage::SMS_SLUG) : mêmes onglets que les e-mails (Réglages, Test, Journal),
 * plus Statistiques (coûts mois par mois, tirés du registre à vie SmsCosts).
 * Le cadre (en-tête, onglets, styles, scripts) est celui de MailPage.
 */
final class SmsPanel
{
    const PER_PAGE = 50;

    /** @var MailPage */
    private $page;

    public function __construct(MailPage $page)
    {
        $this->page = $page;
    }

    private static function url($tab, array $args = array())
    {
        return MailPage::url($tab, $args, 'sms');
    }

    /**
     * Actions postées (appelé par MailPage::handle, nonce et droits déjà vérifiés).
     */
    public static function handle($do)
    {
        // phpcs:disable WordPress.Security.NonceVerification -- vérifié par MailPage::handle.
        if ($do === 'sms_save') {
            $settings = Sms::settings();
            $providers = Sms::providers();
            $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : 'twilio';
            $posted = isset($_POST['providers']) && is_array($_POST['providers']) ? wp_unslash($_POST['providers']) : array(); // phpcs:ignore -- nettoyé par chaque connecteur.
            $bool = static function ($key) {
                return isset($_POST[$key]) && sanitize_key(wp_unslash($_POST[$key])) === '1';
            };
            $days = isset($_POST['sms_log_days']) ? absint($_POST['sms_log_days']) : 15;

            $settings['provider'] = isset($providers[$provider]) ? $provider : 'twilio';
            $settings['log'] = $bool('sms_log');
            $settings['log_days'] = isset(MailLog::durations()[$days]) ? $days : 15;
            $settings['alerts'] = $bool('sms_alerts');
            $settings['tracking'] = $bool('sms_tracking');

            foreach ($providers as $key => $object) {
                if (isset($posted[$key]) && is_array($posted[$key])) {
                    $previous = isset($settings['providers'][$key]) && is_array($settings['providers'][$key]) ? $settings['providers'][$key] : array();
                    $settings['providers'][$key] = $object->sanitize($posted[$key], $previous);
                }
            }

            Sms::save($settings);
            wp_safe_redirect($bool('then_test') ? self::url('test') : self::url('settings', array('saved' => 1)));
            exit;
        }

        if ($do === 'sms_test') {
            $to = isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '';
            $body = isset($_POST['body']) ? sanitize_textarea_field(wp_unslash($_POST['body'])) : '';
            $result = Sms::send($to, $body, array('source' => 'test'));

            set_transient('smbb_codetool_sms_test_' . get_current_user_id(), array(
                'ok' => !is_wp_error($result),
                'error' => is_wp_error($result) ? $result->get_error_message() : '',
                'id' => is_wp_error($result) ? '' : $result['id'],
                'status' => is_wp_error($result) ? '' : $result['status'],
                'to' => is_wp_error($result) ? $to : $result['to'],
            ), 10 * MINUTE_IN_SECONDS);
            update_user_meta(get_current_user_id(), 'smbb_codetool_sms_test_to', $to);

            wp_safe_redirect(self::url('test', array('tested' => 1)));
            exit;
        }

        if ($do === 'sms_import') {
            $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
            $ok = Sms::import($source);
            wp_safe_redirect(self::url('settings', array('imported' => $ok ? $source : 'none')));
            exit;
        }

        if ($do === 'sms_costs_csv') {
            self::exportCsv();
        }

        if ($do === 'sms_log') {
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
                $count = SmsLog::delete($ids);
                wp_safe_redirect(self::url('log', array_filter($back + array('done' => 'deleted', 'n' => $count))));
                exit;
            }

            $result = SmsLog::resend($ids);
            wp_safe_redirect(self::url('log', array_filter($back + array('done' => 'resent', 'ok' => $result['sent'], 'ko' => $result['failed'], 'skip' => $result['skipped']))));
            exit;
        }
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * @return array<int,array{0:string,1:string}> [type, message]
     */
    public static function notices($tab)
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $notices = array();

        if (isset($_GET['saved'])) {
            $notices[] = array('success', __('Réglages SMS enregistrés.', 'smbb-wpcodetool'));
        }

        if (isset($_GET['imported'])) {
            $imported = sanitize_key(wp_unslash($_GET['imported']));
            $sources = Sms::importSources();
            $notices[] = $imported === 'none' || !isset($sources[$imported])
                ? array('error', __('Rien à reprendre.', 'smbb-wpcodetool'))
                : array('success', sprintf(__('Identifiants Twilio repris de %s. Envoyez un SMS de test pour vérifier.', 'smbb-wpcodetool'), $sources[$imported]['label']));
        }

        if ($tab === 'log' && isset($_GET['done'])) {
            $done = sanitize_key(wp_unslash($_GET['done']));
            $n = absint($_GET['n'] ?? 0);
            $ok = absint($_GET['ok'] ?? 0);
            $ko = absint($_GET['ko'] ?? 0);
            $skip = absint($_GET['skip'] ?? 0);

            if ($done === 'deleted') {
                $notices[] = array('success', sprintf(_n('%d SMS supprimé du journal.', '%d SMS supprimés du journal.', $n, 'smbb-wpcodetool'), $n));
            } elseif ($done === 'resent') {
                $parts = array(sprintf(_n('%d SMS renvoyé', '%d SMS renvoyés', $ok, 'smbb-wpcodetool'), $ok));

                if ($ko) {
                    $parts[] = sprintf(__('%d en erreur', 'smbb-wpcodetool'), $ko);
                }

                if ($skip) {
                    $parts[] = sprintf(__('%d sans texte gardé', 'smbb-wpcodetool'), $skip);
                }

                $notices[] = array($ko ? 'error' : 'success', implode(', ', $parts) . '.');
            } elseif ($done === 'none') {
                $notices[] = array('info', __('Cochez au moins un SMS et choisissez une action.', 'smbb-wpcodetool'));
            }
        }

        // phpcs:enable WordPress.Security.NonceVerification
        return $notices;
    }

    public function renderSettings()
    {
        $settings = Sms::settings();
        $providers = Sms::providers();
        $current = Sms::provider($settings);
        $ready = Sms::ready();
        $sources = Sms::importSources();
        ?>
        <?php if ($sources) : ?>
            <?php $this->page->sectionStart('dashicons-migrate', __('Reprendre des identifiants existants', 'smbb-wpcodetool'), __('Des identifiants Twilio sont déjà saisis dans un plugin : reprenez-les ici pour qu\'ils servent à tous.', 'smbb-wpcodetool')); ?>
                <?php foreach ($sources as $key => $source) : ?>
                    <form method="post" action="<?php echo esc_url(self::url('settings')); ?>" class="smbb-sms-import">
                        <?php wp_nonce_field(MailPage::NONCE, 'smbb_codetool_mail_nonce'); ?>
                        <input type="hidden" name="smbb_mail_do" value="sms_import">
                        <input type="hidden" name="source" value="<?php echo esc_attr($key); ?>">
                        <span><strong><?php echo esc_html($source['label']); ?></strong> · <code><?php echo esc_html($source['account_sid']); ?></code> · <?php echo esc_html(sprintf(__('service %s', 'smbb-wpcodetool'), $source['service_sid'])); ?> · <?php echo esc_html($source['token'] !== '' ? __('jeton présent', 'smbb-wpcodetool') : __('sans jeton', 'smbb-wpcodetool')); ?></span>
                        <button type="submit" class="button" onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Remplacer les identifiants SMS de CodeTool par ceux-ci ?', 'smbb-wpcodetool'))); ?>);"><?php esc_html_e('Reprendre', 'smbb-wpcodetool'); ?></button>
                    </form>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e('Rien n\'est modifié dans le plugin d\'origine. Une fois les SMS prêts ici, Passflow envoie par CodeTool (et ses SMS apparaissent dans ce journal) : ses propres identifiants ne servent plus qu\'en secours.', 'smbb-wpcodetool'); ?></p>
            <?php $this->page->sectionEnd(); ?>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(self::url('settings')); ?>" autocomplete="off">
            <?php wp_nonce_field(MailPage::NONCE, 'smbb_codetool_mail_nonce'); ?>
            <input type="hidden" name="smbb_mail_do" value="sms_save">

            <?php $this->page->sectionStart('dashicons-smartphone', __('Envoi', 'smbb-wpcodetool'), __('Utilisé par tous les plugins qui envoient des SMS via CodeTool (rappels, confirmations…).', 'smbb-wpcodetool')); ?>
                <?php if ($ready !== true) : ?>
                    <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php echo esc_html($ready); ?></p>
                <?php elseif (SmsHealth::broken()) : ?>
                    <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('Le dernier envoi a échoué : voir l\'onglet Test d\'envoi.', 'smbb-wpcodetool'); ?></p>
                <?php else : ?>
                    <p class="smbb-codetool-encryption-state is-good"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php echo esc_html(sprintf(__('Actif : %s.', 'smbb-wpcodetool'), $current ? $current->summary(Sms::providerSettings($settings)) : '')); ?></p>
                <?php endif; ?>

                <?php if (count($providers) > 1) : ?>
                    <p>
                        <label class="smbb-codetool-field-label" for="smbb-sms-provider"><?php esc_html_e('Connecteur', 'smbb-wpcodetool'); ?></label><br>
                        <select id="smbb-sms-provider" name="provider" data-smbb-sms-provider>
                            <?php foreach ($providers as $key => $provider) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['provider'], $key); ?>><?php echo esc_html($provider->label()); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                <?php else : ?>
                    <input type="hidden" name="provider" value="<?php echo esc_attr((string) key($providers)); ?>">
                <?php endif; ?>
            <?php $this->page->sectionEnd(); ?>

            <?php foreach ($providers as $key => $provider) : ?>
                <div data-smbb-sms-provider-panel="<?php echo esc_attr($key); ?>"<?php echo $settings['provider'] === $key ? '' : ' hidden'; ?>>
                    <?php $this->page->sectionStart('dashicons-networking', sprintf(__('Compte %s', 'smbb-wpcodetool'), $provider->label()), __('Les identifiants donnés par votre fournisseur de SMS.', 'smbb-wpcodetool')); ?>
                        <?php $provider->renderFields(isset($settings['providers'][$key]) && is_array($settings['providers'][$key]) ? $settings['providers'][$key] : array()); ?>
                    <?php $this->page->sectionEnd(); ?>
                </div>
            <?php endforeach; ?>

            <?php $this->renderTrackingSection($settings, $current); ?>

            <?php $this->page->sectionStart('dashicons-list-view', __('Journal et alertes', 'smbb-wpcodetool')); ?>
                <?php AppearancePage::toggle('sms_log', $settings['log'], __('Garder un journal des SMS', 'smbb-wpcodetool'), __('SMS envoyés et en erreur, avec leur texte pour pouvoir les renvoyer. Sert aussi aux compteurs du widget.', 'smbb-wpcodetool')); ?>
                <div data-smbb-sms-log-options<?php echo $settings['log'] ? '' : ' hidden'; ?>>
                    <p>
                        <label class="smbb-codetool-field-label" for="smbb-sms-log-days"><?php esc_html_e('Durée de conservation', 'smbb-wpcodetool'); ?></label><br>
                        <select id="smbb-sms-log-days" name="sms_log_days">
                            <?php foreach (MailLog::durations() as $days => $label) : ?>
                                <option value="<?php echo esc_attr((string) $days); ?>" <?php selected((int) $settings['log_days'], $days); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="description"><?php esc_html_e('Au-delà, les SMS sont effacés tout seuls, chaque jour (tâche cron « Purge du journal des SMS »).', 'smbb-wpcodetool'); ?></span>
                    </p>
                </div>
                <?php AppearancePage::toggle('sms_alerts', $settings['alerts'], __('Alerte en cas de panne', 'smbb-wpcodetool'), __('Bandeau rouge en haut de l\'administration dès qu\'un SMS échoue, jusqu\'au prochain envoi réussi.', 'smbb-wpcodetool')); ?>
            <?php $this->page->sectionEnd(); ?>

            <p class="submit">
                <input type="hidden" name="then_test" value="0" data-smbb-mail-then-test>
                <button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button>
                <button type="submit" class="button" data-smbb-mail-save-test><?php esc_html_e('Enregistrer et tester', 'smbb-wpcodetool'); ?></button>
            </p>
        </form>
        <?php
    }

    public function renderTest()
    {
        $user_id = get_current_user_id();
        $result = isset($_GET['tested']) ? get_transient('smbb_codetool_sms_test_' . $user_id) : false; // phpcs:ignore WordPress.Security.NonceVerification
        $to = (string) get_user_meta($user_id, 'smbb_codetool_sms_test_to', true);
        $ready = Sms::ready();
        $provider = Sms::provider();
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        ?>
        <?php if (is_array($result)) : ?>
            <section class="smbb-codetool-section smbb-mail-result <?php echo $result['ok'] ? 'is-ok' : 'is-ko'; ?>">
                <div class="smbb-mail-result-head">
                    <span class="dashicons <?php echo $result['ok'] ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>" aria-hidden="true"></span>
                    <div>
                        <h3><?php echo esc_html($result['ok'] ? __('SMS de test envoyé', 'smbb-wpcodetool') : __('L\'envoi a échoué', 'smbb-wpcodetool')); ?></h3>
                        <p>
                            <?php
                            echo esc_html($result['ok']
                                ? sprintf(__('Accepté pour %1$s (identifiant %2$s, état « %3$s »). Il arrive en général en quelques secondes.', 'smbb-wpcodetool'), $result['to'], $result['id'], $result['status'])
                                : $result['error']);
                            ?>
                        </p>
                        <?php if (!$result['ok']) : ?>
                            <p class="smbb-mail-hint"><?php echo esc_html(self::hint($result['error'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(self::url('test')); ?>">
            <?php wp_nonce_field(MailPage::NONCE, 'smbb_codetool_mail_nonce'); ?>
            <input type="hidden" name="smbb_mail_do" value="sms_test">
            <?php $this->page->sectionStart('dashicons-format-chat', __('Envoyer un SMS de test', 'smbb-wpcodetool'), $provider ? sprintf(__('Avec les réglages enregistrés : %s. Un SMS de test est facturé comme un vrai.', 'smbb-wpcodetool'), $provider->summary(Sms::providerSettings())) : ''); ?>
                <?php if ($ready !== true) : ?>
                    <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php echo esc_html($ready); ?></p>
                <?php endif; ?>
                <div class="smbb-mail-grid">
                    <p class="smbb-mail-field">
                        <label class="smbb-codetool-field-label" for="smbb-sms-test-to"><?php esc_html_e('Numéro', 'smbb-wpcodetool'); ?></label><br>
                        <input type="tel" id="smbb-sms-test-to" name="to" value="<?php echo esc_attr($to); ?>" placeholder="06 12 34 56 78" required>
                    </p>
                    <p class="smbb-mail-field is-wide">
                        <label class="smbb-codetool-field-label" for="smbb-sms-test-body"><?php esc_html_e('Texte', 'smbb-wpcodetool'); ?></label><br>
                        <input type="text" class="regular-text" id="smbb-sms-test-body" name="body" maxlength="160" value="<?php echo esc_attr(sprintf(__('Test SMS de %s : ça marche !', 'smbb-wpcodetool'), $site)); ?>" required>
                    </p>
                </div>
                <p><button type="submit" class="button button-primary"<?php disabled($ready !== true); ?>><?php esc_html_e('Envoyer le test', 'smbb-wpcodetool'); ?></button></p>
                <p class="description"><?php esc_html_e('Un numéro français 06… est converti en +33 ; sinon, saisissez-le au format international (+41…). Le test apparaît aussi dans le journal, marqué « test ».', 'smbb-wpcodetool'); ?></p>
            <?php $this->page->sectionEnd(); ?>
        </form>
        <?php
    }

    /**
     * Piste de solution à partir du message d'erreur du fournisseur.
     */
    private static function hint($error)
    {
        $haystack = strtolower((string) $error);

        if (strpos($haystack, 'authenticate') !== false || strpos($haystack, '20003') !== false) {
            return __('Account SID ou Auth Token refusé : recopiez-les depuis l\'accueil de la console Twilio.', 'smbb-wpcodetool');
        }

        if (strpos($haystack, 'messaging service') !== false || strpos($haystack, 'messagingservicesid') !== false) {
            return __('Messaging Service SID inconnu de ce compte : vérifiez-le dans Messaging › Services.', 'smbb-wpcodetool');
        }

        if (strpos($haystack, "'to'") !== false || strpos($haystack, 'phone number') !== false || strpos($haystack, '21211') !== false) {
            return __('Numéro refusé : saisissez-le au format international (+33…). Un compte Twilio d\'essai n\'envoie qu\'aux numéros vérifiés.', 'smbb-wpcodetool');
        }

        if (strpos($haystack, 'permission') !== false || strpos($haystack, '21408') !== false) {
            return __('Envoi vers ce pays non autorisé : activez-le dans Twilio › Messaging › Settings › Geo permissions.', 'smbb-wpcodetool');
        }

        return __('Le message ci-dessus est la réponse du fournisseur.', 'smbb-wpcodetool');
    }

    public function renderLog()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable WordPress.Security.NonceVerification
        $settings = Sms::settings();

        SmsLog::purge();
        $data = SmsLog::query($status, $search, $paged, self::PER_PAGE);
        $stats = SmsLog::stats(SmsLog::days());
        $pages = max(1, (int) ceil($data['total'] / self::PER_PAGE));
        $filters = array(
            '' => array(__('Tous', 'smbb-wpcodetool'), $stats['sent'] + $stats['failed']),
            'sent' => array(__('Envoyés', 'smbb-wpcodetool'), $stats['sent']),
            'failed' => array(__('En erreur', 'smbb-wpcodetool'), $stats['failed']),
        );
        $lost = SmsLog::lostCount();

        if ($lost || $status === 'lost') {
            $filters['lost'] = array(__('Non remis', 'smbb-wpcodetool'), $lost);
        }

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
                <input type="hidden" name="page" value="<?php echo esc_attr(MailPage::SMS_SLUG); ?>">
                <input type="hidden" name="tab" value="log">
                <?php if ($status !== '') : ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>"><?php endif; ?>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Numéro, texte, erreur, plugin…', 'smbb-wpcodetool'); ?>">
                <button class="button"><?php esc_html_e('Rechercher', 'smbb-wpcodetool'); ?></button>
            </form>
        </div>

        <form method="post" action="<?php echo esc_url(self::url('log')); ?>">
            <?php wp_nonce_field(MailPage::NONCE, 'smbb_codetool_mail_nonce'); ?>
            <input type="hidden" name="smbb_mail_do" value="sms_log">
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
                    <span class="displaying-num"><?php echo esc_html(sprintf(_n('%s SMS', '%s SMS', $data['total'], 'smbb-wpcodetool'), number_format_i18n($data['total']))); ?></span>
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
                        <th><?php esc_html_e('Message', 'smbb-wpcodetool'); ?></th>
                        <th class="smbb-mail-log-actions"><?php esc_html_e('Actions', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$data['rows']) : ?>
                        <tr><td colspan="6" class="smbb-mail-empty"><?php echo esc_html($search !== '' || $status !== '' ? __('Aucun SMS ne correspond.', 'smbb-wpcodetool') : sprintf(__('Aucun SMS sur les %d derniers jours.', 'smbb-wpcodetool'), SmsLog::days())); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($data['rows'] as $row) : ?>
                        <?php $failed = $row['status'] === 'failed'; ?>
                        <tr class="<?php echo $failed ? 'is-failed' : 'is-sent'; ?>">
                            <th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr($row['id']); ?>"<?php echo $failed ? ' data-failed' : ''; ?>></th>
                            <td class="smbb-mail-date"><?php echo esc_html(get_date_from_gmt($row['created_at'], 'd/m H:i:s')); ?></td>
                            <td>
                                <?php if ($failed) : ?>
                                    <span class="smbb-codetool-badge is-error"><?php esc_html_e('Erreur', 'smbb-wpcodetool'); ?></span>
                                <?php elseif (!empty($row['delivery'])) : ?>
                                    <?php $delivery = SmsTracking::label($row['delivery']); ?>
                                    <span class="smbb-codetool-badge <?php echo esc_attr($delivery[1]); ?>" title="<?php echo esc_attr(__('Statut donné par l\'opérateur', 'smbb-wpcodetool') . ($row['delivery_code'] !== '' ? ' · ' . sprintf(__('code %s', 'smbb-wpcodetool'), $row['delivery_code']) : '')); ?>"><?php echo esc_html($delivery[0]); ?></span>
                                <?php else : ?>
                                    <span class="smbb-codetool-badge is-success"><?php esc_html_e('Envoyé', 'smbb-wpcodetool'); ?></span>
                                <?php endif; ?>
                                <?php if ($row['price'] !== null && $row['price'] !== '') : ?><span class="smbb-mail-source" title="<?php esc_attr_e('Coût facturé par le fournisseur', 'smbb-wpcodetool'); ?>"><?php echo esc_html(SmsCosts::money($row['price'], $row['currency'], 4)); ?></span><?php endif; ?>
                                <?php if ($row['source'] !== '') : ?><span class="smbb-mail-source"><?php echo esc_html(isset($sources[$row['source']]) ? $sources[$row['source']] : $row['source']); ?></span><?php endif; ?>
                                <?php if ((int) $row['resends'] > 0) : ?><span class="smbb-mail-source" title="<?php esc_attr_e('Renvoyé depuis le journal', 'smbb-wpcodetool'); ?>">↻ <?php echo esc_html((string) (int) $row['resends']); ?></span><?php endif; ?>
                            </td>
                            <td class="smbb-mail-to"><?php echo esc_html($row['to_number']); ?></td>
                            <td class="smbb-mail-subject">
                                <strong><?php echo esc_html(wp_html_excerpt($row['body'], 90, '…')); ?></strong>
                                <?php if ($failed && $row['error'] !== '') : ?><span class="smbb-mail-error"><?php echo esc_html(wp_trim_words($row['error'], 24)); ?></span><?php endif; ?>
                            </td>
                            <td class="smbb-mail-log-actions">
                                <button type="button" class="button button-small" data-smbb-sms-view="<?php echo esc_attr($row['id']); ?>"><?php esc_html_e('Voir', 'smbb-wpcodetool'); ?></button>
                                <?php if ($row['body'] !== '') : ?>
                                    <button type="submit" class="button button-small" name="resend_one" value="<?php echo esc_attr($row['id']); ?>"><?php esc_html_e('Renvoyer', 'smbb-wpcodetool'); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <dialog class="smbb-mail-viewer" data-smbb-mail-viewer>
            <div class="smbb-mail-viewer-head">
                <strong data-smbb-mail-viewer-title></strong>
                <button type="button" class="button-link" data-smbb-mail-viewer-close aria-label="<?php esc_attr_e('Fermer', 'smbb-wpcodetool'); ?>"><span class="dashicons dashicons-no-alt"></span></button>
            </div>
            <div class="smbb-mail-viewer-body" data-smbb-mail-viewer-body></div>
        </dialog>
        <?php foreach ($data['rows'] as $row) : ?>
            <template data-smbb-sms-detail="<?php echo esc_attr($row['id']); ?>" data-title="<?php echo esc_attr(sprintf(__('SMS à %s', 'smbb-wpcodetool'), $row['to_number'])); ?>">
                <dl class="smbb-mail-detail">
                    <dt><?php esc_html_e('Date', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(get_date_from_gmt($row['created_at'], get_option('date_format') . ' H:i:s')); ?></dd>
                    <dt><?php esc_html_e('À', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($row['to_number']); ?></dd>
                    <dt><?php esc_html_e('Envoi', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(ucfirst($row['provider']) . ($row['provider_id'] !== '' ? ' · ' . $row['provider_id'] : '')); ?></dd>
                    <?php if ($row['source'] !== '') : ?><dt><?php esc_html_e('Origine', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($row['source']); ?></dd><?php endif; ?>
                    <?php if (!empty($row['delivery'])) : ?><?php $delivery = SmsTracking::label($row['delivery']); ?><dt><?php esc_html_e('Remise', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html($delivery[0] . ($row['delivery_code'] !== '' ? ' · ' . sprintf(__('code d\'erreur %s', 'smbb-wpcodetool'), $row['delivery_code']) : '')); ?></dd><?php endif; ?>
                    <?php if ($row['price'] !== null && $row['price'] !== '') : ?><dt><?php esc_html_e('Coût', 'smbb-wpcodetool'); ?></dt><dd><?php echo esc_html(SmsCosts::money($row['price'], $row['currency'], 4) . ((int) $row['segments'] > 0 ? ' · ' . sprintf(_n('%d segment', '%d segments', (int) $row['segments'], 'smbb-wpcodetool'), (int) $row['segments']) : '')); ?></dd><?php endif; ?>
                    <?php if ($row['error'] !== '') : ?><dt><?php esc_html_e('Erreur', 'smbb-wpcodetool'); ?></dt><dd class="smbb-mail-error"><?php echo esc_html($row['error']); ?></dd><?php endif; ?>
                    <dt><?php esc_html_e('Longueur', 'smbb-wpcodetool'); ?></dt><dd><?php $len = function_exists('mb_strlen') ? mb_strlen($row['body']) : strlen($row['body']); echo esc_html(sprintf(_n('%1$d caractère (%2$d SMS)', '%1$d caractères (%2$d SMS)', $len, 'smbb-wpcodetool'), $len, max(1, (int) ceil($len / 160)))); ?></dd>
                </dl>
                <div class="smbb-sms-bubble"><?php echo nl2br(esc_html($row['body'])); ?></div>
            </template>
        <?php endforeach; ?>
        <?php
    }

    /**
     * Réglages › « Suivi de remise et coûts ».
     */
    private function renderTrackingSection(array $settings, $current)
    {
        $tracks = $current instanceof TrackingInterface;
        $callback = $tracks ? SmsTracking::routeUrl($current->key()) : '';
        $public = SmsTracking::publicSite();
        $filtered = $tracks ? (string) apply_filters('smbb_wpcodetool_sms_status_callback_url', '', $current->key()) : '';

        $this->page->sectionStart('dashicons-chart-line', __('Suivi de remise et coûts', 'smbb-wpcodetool'), __('Savoir si chaque SMS est bien arrivé et ce qu\'il a coûté. Les coûts sont gardés à vie (onglet Statistiques), même après la purge du journal.', 'smbb-wpcodetool'));

        if (!$tracks) {
            ?>
            <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e('Ce connecteur ne donne ni statut de remise ni coût.', 'smbb-wpcodetool'); ?></p>
            <?php
            $this->page->sectionEnd();

            return;
        }

        AppearancePage::toggle('sms_tracking', !empty($settings['tracking']), __('Suivre la remise en temps réel (webhook)', 'smbb-wpcodetool'), __('À chaque envoi, CodeTool donne à Twilio l\'adresse ci-dessous : Twilio y signale « remis » ou « non remis » dès que l\'opérateur répond. Rien à régler dans la console Twilio. Les appels sont vérifiés par leur signature (Auth Token).', 'smbb-wpcodetool'));
        ?>
        <p><code class="smbb-sms-callback"><?php echo esc_html($filtered !== '' ? $filtered : $callback); ?></code></p>
        <p class="description"><?php esc_html_e('Rien à coller dans Twilio : CodeTool donne cette adresse à chaque envoi. Pour couvrir aussi les SMS envoyés hors CodeTool, collez-la dans Twilio › Messaging › Services › votre service › Integration › « Delivery status callback ».', 'smbb-wpcodetool'); ?></p>
        <?php if (!$public && $filtered === '') : ?>
            <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e('Site local : Twilio ne peut pas joindre cette adresse, elle ne lui est donc pas donnée. Le statut et le coût sont quand même relevés chaque heure en interrogeant Twilio.', 'smbb-wpcodetool'); ?></p>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('Le prix est connu de Twilio quelques minutes après l\'envoi : CodeTool le relève tout seul (tâche cron « Relevé des coûts SMS », chaque heure). Le registre des coûts ne garde ni numéro ni texte.', 'smbb-wpcodetool'); ?></p>
        <?php
        $this->page->sectionEnd();
    }

    /**
     * Export du registre des coûts (CSV, séparateur ; pour Excel).
     */
    private static function exportCsv()
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="couts-sms-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('date', 'connecteur', 'identifiant', 'origine', 'remise', 'code_erreur', 'segments', 'prix', 'devise'), ';');

        SmsCosts::each(static function ($row) use ($out) {
            fputcsv($out, array(
                get_date_from_gmt($row['created_at'], 'Y-m-d H:i:s'),
                $row['provider'],
                $row['provider_id'],
                $row['source'],
                $row['status'],
                $row['error_code'],
                (int) $row['segments'],
                $row['price'] === null ? '' : str_replace('.', ',', (string) (float) $row['price']),
                $row['currency'],
            ), ';');
        });

        fclose($out);
        exit;
    }

    public function renderStats()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '12';
        // phpcs:enable WordPress.Security.NonceVerification
        $ranges = array('12' => __('12 mois', 'smbb-wpcodetool'), '24' => __('24 mois', 'smbb-wpcodetool'), 'all' => __('Depuis le début', 'smbb-wpcodetool'));
        $range = isset($ranges[$range]) ? $range : '12';
        $data = SmsCosts::monthly($range === 'all' ? 0 : (int) $range);
        $months = $data['months'];
        $currency = $data['currency'];
        $offset = (int) round((float) get_option('gmt_offset') * HOUR_IN_SECONDS);
        $now = time() + $offset;
        $current_key = gmdate('Y-m', $now);
        $previous_key = gmdate('Y-m', gmmktime(0, 0, 0, (int) gmdate('n', $now) - 1, 1, (int) gmdate('Y', $now)));
        $empty = array('n' => 0, 'segments' => 0, 'cost' => 0.0, 'unpriced' => 0, 'delivered' => 0, 'lost' => 0);
        $current = isset($months[$current_key]) ? $months[$current_key] : $empty;
        $previous = isset($months[$previous_key]) ? $months[$previous_key] : $empty;
        $label = static function ($ym, $format = 'F Y') {
            return wp_date($format, (int) strtotime($ym . '-01 00:00:00 UTC'), new \DateTimeZone('UTC'));
        };

        $totals = $empty;

        foreach ($months as $m) {
            foreach ($totals as $k => $v) {
                $totals[$k] += $m[$k];
            }
        }

        // Ce qu'on trace : le coût dès qu'il est connu, sinon le nombre de SMS.
        $by_cost = $totals['cost'] > 0;
        $value = static function ($m) use ($by_cost) {
            return $by_cost ? (float) $m['cost'] : (float) $m['n'];
        };
        $show = static function ($v, $decimals = 2) use ($by_cost, $currency) {
            return $by_cost ? SmsCosts::money($v, $currency, $decimals) : sprintf(_n('%s SMS', '%s SMS', (int) $v, 'smbb-wpcodetool'), number_format_i18n($v));
        };

        // Pics : un mois terminé qui dépasse 1,5 fois la moyenne des autres mois actifs.
        $peaks = array();
        $closed = array_filter($months, static function ($m) {
            return $m['n'] > 0;
        });
        unset($closed[$current_key]);

        if (count($closed) >= 3) {
            $sum = array_sum(array_map($value, $closed));

            foreach ($closed as $ym => $m) {
                $others = (count($closed) - 1) > 0 ? ($sum - $value($m)) / (count($closed) - 1) : 0;

                if ($others > 0 && $value($m) > 1.5 * $others) {
                    $peaks[$ym] = $value($m) / $others;
                }
            }
        }

        $max = max(array_merge(array(0.0), array_map($value, $months)));
        $days_in_month = (int) gmdate('t', $now);
        $day = max(1, (int) gmdate('j', $now));
        $projection = $value($current) / $day * $days_in_month;
        $priced = $totals['n'] - $totals['unpriced'];
        $since_key = (string) key($months);
        $sources = $since_key !== '' ? SmsCosts::bySource(gmdate('Y-m-d H:i:s', (int) strtotime($since_key . '-01 00:00:00 UTC') - $offset)) : array();
        $source_names = array('' => __('Non précisée', 'smbb-wpcodetool'), 'test' => __('Tests d\'envoi', 'smbb-wpcodetool'), 'resend' => __('Renvois du journal', 'smbb-wpcodetool'));
        $trend = static function ($now_v, $before_v) {
            if ($before_v <= 0) {
                return '';
            }

            $pct = ($now_v - $before_v) / $before_v * 100;

            return ($pct >= 0 ? '+' : '−') . number_format_i18n(abs($pct), 0) . ' %';
        };
        ?>
        <?php if ($data['first'] === '') : ?>
            <?php $this->page->sectionStart('dashicons-chart-bar', __('Consommation SMS', 'smbb-wpcodetool')); ?>
                <p><?php esc_html_e('Aucun SMS compté pour l\'instant. Chaque SMS accepté par le fournisseur est noté ici avec son coût, et gardé à vie.', 'smbb-wpcodetool'); ?></p>
            <?php $this->page->sectionEnd(); ?>
            <?php return; ?>
        <?php endif; ?>

        <div class="smbb-mail-log-bar">
            <ul class="subsubsub">
                <?php $i = 0; foreach ($ranges as $key => $text) : ?>
                    <li><?php echo $i++ ? ' | ' : ''; ?><a href="<?php echo esc_url(self::url('stats', $key === '12' ? array() : array('range' => $key))); ?>"<?php echo $range === (string) $key ? ' class="current"' : ''; ?>><?php echo esc_html($text); ?></a></li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?php echo esc_url(self::url('stats')); ?>">
                <?php wp_nonce_field(MailPage::NONCE, 'smbb_codetool_mail_nonce'); ?>
                <input type="hidden" name="smbb_mail_do" value="sms_costs_csv">
                <button class="button"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e('Exporter le registre (CSV)', 'smbb-wpcodetool'); ?></button>
            </form>
        </div>

        <div class="smbb-sms-stats-cards">
            <div class="smbb-sms-card">
                <span class="smbb-sms-card-label"><?php echo esc_html(sprintf(__('%s (en cours)', 'smbb-wpcodetool'), $label($current_key))); ?></span>
                <strong><?php echo esc_html($show($value($current))); ?></strong>
                <span class="smbb-sms-card-note"><?php echo esc_html(sprintf(_n('%s SMS', '%s SMS', $current['n'], 'smbb-wpcodetool'), number_format_i18n($current['n'])) . ($day < $days_in_month && $current['n'] ? ' · ' . sprintf(__('fin de mois estimée : %s', 'smbb-wpcodetool'), $show($projection)) : '')); ?></span>
            </div>
            <div class="smbb-sms-card">
                <span class="smbb-sms-card-label"><?php echo esc_html($label($previous_key)); ?></span>
                <strong><?php echo esc_html($show($value($previous))); ?></strong>
                <span class="smbb-sms-card-note"><?php echo esc_html(sprintf(_n('%s SMS', '%s SMS', $previous['n'], 'smbb-wpcodetool'), number_format_i18n($previous['n']))); ?></span>
            </div>
            <div class="smbb-sms-card">
                <span class="smbb-sms-card-label"><?php echo esc_html(sprintf(__('Total %s', 'smbb-wpcodetool'), $range === 'all' ? __('depuis le début', 'smbb-wpcodetool') : sprintf(__('sur %d mois', 'smbb-wpcodetool'), (int) $range))); ?></span>
                <strong><?php echo esc_html($show($value($totals))); ?></strong>
                <span class="smbb-sms-card-note"><?php echo esc_html(sprintf(_n('%s SMS', '%s SMS', $totals['n'], 'smbb-wpcodetool'), number_format_i18n($totals['n'])) . ($totals['segments'] > $totals['n'] ? ' · ' . sprintf(__('%s segments facturés', 'smbb-wpcodetool'), number_format_i18n($totals['segments'])) : '')); ?></span>
            </div>
            <div class="smbb-sms-card">
                <span class="smbb-sms-card-label"><?php esc_html_e('Coût moyen et remise', 'smbb-wpcodetool'); ?></span>
                <strong><?php echo esc_html($by_cost && $priced > 0 ? SmsCosts::money($totals['cost'] / $priced, $currency, 4) : '—'); ?></strong>
                <span class="smbb-sms-card-note"><?php echo esc_html(($totals['delivered'] + $totals['lost']) > 0 ? sprintf(__('%s remis à l\'opérateur', 'smbb-wpcodetool'), number_format_i18n($totals['delivered'] / ($totals['delivered'] + $totals['lost']) * 100, 1) . ' %') : __('taux de remise inconnu pour l\'instant', 'smbb-wpcodetool')); ?></span>
            </div>
        </div>

        <?php $this->page->sectionStart('dashicons-chart-bar', $by_cost ? __('Coût mois par mois', 'smbb-wpcodetool') : __('SMS mois par mois', 'smbb-wpcodetool'), $peaks ? __('Les mois en orange sont des pics : plus de 1,5 fois la moyenne des autres mois.', 'smbb-wpcodetool') : ''); ?>
            <div class="smbb-sms-chart" role="img" aria-label="<?php esc_attr_e('Consommation par mois', 'smbb-wpcodetool'); ?>">
                <?php foreach ($months as $ym => $m) : ?>
                    <?php $v = $value($m); $h = $max > 0 ? max($v > 0 ? 3 : 0, round($v / $max * 150)) : 0; ?>
                    <div class="smbb-sms-col<?php echo isset($peaks[$ym]) ? ' is-peak' : ''; ?><?php echo $ym === $current_key ? ' is-current' : ''; ?>" title="<?php echo esc_attr($label($ym) . ' : ' . $show($v) . ($by_cost ? ' · ' . sprintf(_n('%s SMS', '%s SMS', $m['n'], 'smbb-wpcodetool'), number_format_i18n($m['n'])) : '')); ?>">
                        <span class="smbb-sms-col-value"><?php echo $v > 0 ? esc_html($by_cost ? number_format_i18n($v, $v < 10 ? 2 : 0) : number_format_i18n($v)) : ''; ?></span>
                        <span class="smbb-sms-col-bar" style="height:<?php echo esc_attr((string) $h); ?>px"></span>
                        <span class="smbb-sms-col-label"><?php echo esc_html($label($ym, count($months) > 14 ? 'm/y' : 'M')); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($totals['unpriced'] > 0) : ?>
                <p class="description"><?php echo esc_html(sprintf(_n('%d SMS attend encore son prix : il est relevé chez le fournisseur chaque heure.', '%d SMS attendent encore leur prix : ils sont relevés chez le fournisseur chaque heure.', $totals['unpriced'], 'smbb-wpcodetool'), $totals['unpriced'])); ?></p>
            <?php endif; ?>
            <?php foreach ($data['others'] as $other => $amount) : ?>
                <p class="description"><?php echo esc_html(sprintf(__('Plus %s facturés dans une autre devise, non comptés ci-dessus.', 'smbb-wpcodetool'), SmsCosts::money($amount, $other))); ?></p>
            <?php endforeach; ?>
        <?php $this->page->sectionEnd(); ?>

        <?php $this->page->sectionStart('dashicons-calendar-alt', __('Détail par mois', 'smbb-wpcodetool')); ?>
            <table class="widefat striped smbb-codetool-table smbb-sms-stats-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Mois', 'smbb-wpcodetool'); ?></th>
                        <th class="num"><?php esc_html_e('SMS', 'smbb-wpcodetool'); ?></th>
                        <th class="num"><?php esc_html_e('Segments', 'smbb-wpcodetool'); ?></th>
                        <th class="num"><?php esc_html_e('Remis', 'smbb-wpcodetool'); ?></th>
                        <th class="num"><?php esc_html_e('Non remis', 'smbb-wpcodetool'); ?></th>
                        <th class="num"><?php esc_html_e('Coût', 'smbb-wpcodetool'); ?></th>
                        <th class="num"><?php esc_html_e('Par SMS', 'smbb-wpcodetool'); ?></th>
                        <th class="num"><?php esc_html_e('Évolution', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $keys = array_keys($months); ?>
                    <?php foreach (array_reverse($keys) as $pos => $ym) : ?>
                        <?php
                        $m = $months[$ym];
                        $before_key = array_search($ym, $keys, true) > 0 ? $keys[array_search($ym, $keys, true) - 1] : '';
                        $m_priced = $m['n'] - $m['unpriced'];
                        ?>
                        <tr class="<?php echo isset($peaks[$ym]) ? 'is-peak' : ''; ?>">
                            <td><?php echo esc_html($label($ym)); ?><?php echo $ym === $current_key ? ' <span class="smbb-mail-source">' . esc_html__('en cours', 'smbb-wpcodetool') . '</span>' : ''; ?><?php echo isset($peaks[$ym]) ? ' <span class="smbb-codetool-badge is-warning">' . esc_html(sprintf(__('pic ×%s', 'smbb-wpcodetool'), number_format_i18n($peaks[$ym], 1))) . '</span>' : ''; ?></td>
                            <td class="num"><?php echo esc_html(number_format_i18n($m['n'])); ?></td>
                            <td class="num"><?php echo esc_html(number_format_i18n($m['segments'])); ?></td>
                            <td class="num"><?php echo esc_html(number_format_i18n($m['delivered'])); ?></td>
                            <td class="num"><?php echo $m['lost'] && $ym === $current_key ? '<a href="' . esc_url(self::url('log', array('status' => 'lost'))) . '">' . esc_html(number_format_i18n($m['lost'])) . '</a>' : esc_html(number_format_i18n($m['lost'])); ?></td>
                            <td class="num"><?php echo esc_html($m_priced > 0 || $m['cost'] > 0 ? SmsCosts::money($m['cost'], $currency) : '—'); ?><?php echo $m['unpriced'] ? ' <span class="smbb-mail-source" title="' . esc_attr__('SMS dont le prix n\'est pas encore connu', 'smbb-wpcodetool') . '">+' . esc_html((string) $m['unpriced']) . '</span>' : ''; ?></td>
                            <td class="num"><?php echo esc_html($m_priced > 0 && $m['cost'] > 0 ? SmsCosts::money($m['cost'] / $m_priced, $currency, 4) : '—'); ?></td>
                            <td class="num"><?php echo esc_html($before_key !== '' && $ym !== $current_key ? $trend($value($m), $value($months[$before_key])) : ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php $this->page->sectionEnd(); ?>

        <?php if ($sources) : ?>
            <?php $this->page->sectionStart('dashicons-admin-plugins', __('Par origine', 'smbb-wpcodetool'), __('Quel plugin ou quelle action a envoyé les SMS, sur la période choisie.', 'smbb-wpcodetool')); ?>
                <table class="widefat striped smbb-codetool-table smbb-sms-stats-table">
                    <thead><tr><th><?php esc_html_e('Origine', 'smbb-wpcodetool'); ?></th><th class="num"><?php esc_html_e('SMS', 'smbb-wpcodetool'); ?></th><th class="num"><?php esc_html_e('Segments', 'smbb-wpcodetool'); ?></th><th class="num"><?php esc_html_e('Coût', 'smbb-wpcodetool'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($sources as $row) : ?>
                            <tr>
                                <td><?php echo esc_html(isset($source_names[$row['source']]) ? $source_names[$row['source']] : ucfirst($row['source'])); ?></td>
                                <td class="num"><?php echo esc_html(number_format_i18n((int) $row['n'])); ?></td>
                                <td class="num"><?php echo esc_html(number_format_i18n((int) $row['segments'])); ?></td>
                                <td class="num"><?php echo esc_html($row['cost'] !== null ? SmsCosts::money($row['cost'], $currency) : '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php $this->page->sectionEnd(); ?>
        <?php endif; ?>
        <?php
    }
}
