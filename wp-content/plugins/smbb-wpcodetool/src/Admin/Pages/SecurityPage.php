<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Security\LoginLimiter;
use Smbb\WpCodeTool\Security\Security;
use Smbb\WpCodeTool\Security\Turnstile;
use Smbb\WpCodeTool\Security\Totp;
use Smbb\WpCodeTool\Security\TwoFactor;
use Smbb\WpCodeTool\Sms\Sms;
use Smbb\WpCodeTool\Support\Secrets;

defined('ABSPATH') || exit;

/**
 * Pages du groupe « Sécurité » de CodeTool (2e niveau de la barre d'app, pas d'onglets maison) :
 * - Connexion : Cloudflare Turnstile et limitation des tentatives ;
 * - Double authentification : règle par rôle (non / SMS / e-mail / application + durée de confiance), mon compte ;
 * - IP bloquées : blocages en cours, liste blanche, liste noire.
 * La logique est dans src/Security/.
 */
final class SecurityPage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-security';
    const TWOFA_SLUG = 'smbb-wpcodetool-security-2fa';
    const BLOCKED_SLUG = 'smbb-wpcodetool-security-blocked';
    const TURNSTILE_SLUG = 'smbb-wpcodetool-security-turnstile';
    const NONCE = 'smbb_codetool_security';

    /**
     * @return string[]
     */
    public static function slugs()
    {
        return array(self::SLUG, self::TURNSTILE_SLUG, self::TWOFA_SLUG, self::BLOCKED_SLUG);
    }

    /**
     * Adresse d'une vue : login (défaut), turnstile, 2fa, blocked.
     */
    public static function url($view = '', array $args = array())
    {
        $slugs = array('turnstile' => self::TURNSTILE_SLUG, '2fa' => self::TWOFA_SLUG, 'blocked' => self::BLOCKED_SLUG);
        $slug = isset($slugs[$view]) ? $slugs[$view] : self::SLUG;

        return add_query_arg($args, admin_url('admin.php?page=' . $slug));
    }

    // ------------------------------------------------------------------ Actions

    public static function handle()
    {
        if (empty($_POST['smbb_security_action'])) {
            return;
        }

        if (!current_user_can('manage_options')
            || !isset($_POST['smbb_security_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['smbb_security_nonce'])), self::NONCE)) {
            wp_die(esc_html__('Vous n\'avez pas le droit de modifier les réglages de sécurité de CodeTool.', 'smbb-wpcodetool'));
        }

        $action = sanitize_key(wp_unslash($_POST['smbb_security_action']));
        $view = 'login';
        $user = wp_get_current_user();
        $int = function ($key, $min, $max, $default) {
            $value = isset($_POST[$key]) ? (int) $_POST[$key] : $default;

            return max($min, min($max, $value));
        };
        $on = function ($key) {
            return !empty($_POST[$key]) ? 1 : 0;
        };

        switch ($action) {
            case 'save_turnstile':
                $view = 'turnstile';
                $t = Security::part('turnstile');
                $t['enabled'] = $on('ts_enabled');
                $t['site_key'] = isset($_POST['ts_site_key']) ? trim(sanitize_text_field(wp_unslash($_POST['ts_site_key']))) : '';
                $t['login'] = $on('ts_login');
                $t['lostpassword'] = $on('ts_lostpassword');
                $t['register'] = $on('ts_register');
                $t['woocommerce'] = $on('ts_woocommerce');
                $theme = isset($_POST['ts_theme']) ? sanitize_key(wp_unslash($_POST['ts_theme'])) : 'auto';
                $t['theme'] = in_array($theme, array('auto', 'light', 'dark'), true) ? $theme : 'auto';

                // Clé secrète : vide = on garde ; case « effacer » = on retire. Pas de sanitize_text_field (clé brute).
                if (!empty($_POST['ts_secret_clear'])) {
                    $t['secret'] = '';
                } elseif (isset($_POST['ts_secret']) && (string) $_POST['ts_secret'] !== '') {
                    $t['secret'] = Security::protect((string) wp_unslash($_POST['ts_secret']));
                    $t['last_error'] = '';
                    $t['last_error_at'] = 0;
                }

                Security::savePart('turnstile', $t);
                self::logSaved(__('Réglages Turnstile enregistrés', 'smbb-wpcodetool'));

                if ($t['enabled'] && !Turnstile::active()) {
                    self::flash('warning', __('Réglages Turnstile enregistrés. Turnstile reste inactif tant que la clé du site et la clé secrète ne sont pas renseignées.', 'smbb-wpcodetool'));
                } else {
                    self::flash('success', __('Réglages Turnstile enregistrés.', 'smbb-wpcodetool'));
                }
                break;

            case 'save_login':
                $l = Security::part('limit');
                $l['enabled'] = $on('lim_enabled');
                $l['attempts'] = $int('lim_attempts', 2, 50, 5);
                $l['window'] = $int('lim_window', 1, 1440, 15);
                $l['lockout'] = $int('lim_lockout', 1, 1440, 30);
                $l['long_after'] = $int('lim_long_after', 0, 20, 3);
                $l['long_hours'] = $int('lim_long_hours', 1, 720, 24);
                $source = isset($_POST['lim_ip_source']) ? sanitize_key(wp_unslash($_POST['lim_ip_source'])) : 'remote_addr';
                $l['ip_source'] = isset(Security::ipSources()[$source]) ? $source : 'remote_addr';
                $l['generic_errors'] = $on('lim_generic_errors');
                $l['notify'] = $on('lim_notify');
                Security::savePart('limit', $l);

                self::logSaved(__('Réglages de connexion enregistrés', 'smbb-wpcodetool'));
                self::flash('success', __('Réglages de connexion enregistrés.', 'smbb-wpcodetool'));
                break;

            case 'clear_turnstile_error':
                $view = 'turnstile';
                Turnstile::clearError();
                self::flash('success', __('Alerte effacée.', 'smbb-wpcodetool'));
                break;

            case 'save_2fa':
                $view = '2fa';
                $f = Security::part('twofa');
                $f['enabled'] = $on('fa_enabled');
                $f['code_ttl'] = $int('fa_code_ttl', 2, 30, 10);
                $f['block_xmlrpc'] = $on('fa_block_xmlrpc');
                $f['fail_open'] = $on('fa_fail_open');
                $posted = isset($_POST['fa_roles']) && is_array($_POST['fa_roles']) ? wp_unslash($_POST['fa_roles']) : array();
                $roles = array();

                foreach (array_keys(wp_roles()->roles) as $role) {
                    $row = isset($posted[$role]) && is_array($posted[$role]) ? $posted[$role] : array();
                    $channel = isset($row['channel']) ? sanitize_key($row['channel']) : 'off';
                    $trust = isset($row['trust']) ? (int) $row['trust'] : 24;
                    $roles[$role] = array(
                        'channel' => isset(Security::channels()[$channel]) ? $channel : 'off',
                        'trust' => isset(Security::trustChoices()[$trust]) ? $trust : 24,
                    );
                }

                $f['roles'] = $roles;
                Security::savePart('twofa', $f);
                self::logSaved(__('Réglages de double authentification enregistrés', 'smbb-wpcodetool'));

                $mine = TwoFactor::ruleFor($user);
                $gaps = array();
                $admin_gap = false;

                // Rôles en SMS : comptes sans numéro (ils recevront le code par e-mail).
                foreach ($roles as $role => $rule) {
                    if ($rule['channel'] !== 'sms') {
                        continue;
                    }

                    $check = TwoFactor::roleWithoutPhone($role);

                    if ($check['missing']) {
                        $names = wp_list_pluck(array_slice($check['missing'], 0, 5), 'display_name');
                        $more = count($check['missing']) - count($names);
                        $gaps[] = sprintf('%1$s : %2$d/%3$d sans numéro (%4$s%5$s)', translate_user_role(wp_roles()->roles[$role]['name']), count($check['missing']), $check['total'], implode(', ', $names), $more > 0 ? sprintf(', +%d', $more) : '');
                        $admin_gap = $admin_gap || $role === 'administrator';
                    }
                }

                if ($gaps) {
                    self::flash($admin_gap ? 'error' : 'warning', sprintf(__('Enregistré, mais des comptes réglés en SMS n\'ont pas de numéro de téléphone : ils recevront le code par e-mail. %1$s.%2$s', 'smbb-wpcodetool'), implode(' · ', $gaps), $admin_gap ? ' ' . __('Des administrateurs sont concernés : ajoutez leur numéro (Comptes › Profil › Téléphone, dans la facturation si WooCommerce est actif).', 'smbb-wpcodetool') : ''));
                } elseif ($f['enabled'] && $mine !== null && TwoFactor::backupCount($user->ID) === 0) {
                    self::flash('warning', __('Enregistré. Votre compte est maintenant protégé : générez vos codes de secours ci-dessous, au cas où le code n\'arriverait pas.', 'smbb-wpcodetool'));
                } else {
                    self::flash('success', __('Réglages de double authentification enregistrés.', 'smbb-wpcodetool'));
                }
                break;

            case 'test_email':
            case 'test_sms':
                $view = '2fa';
                $channel = $action === 'test_sms' ? 'sms' : 'email';
                $result = TwoFactor::sendTest($user, $channel);

                if (is_wp_error($result)) {
                    self::flash('error', __('Envoi impossible :', 'smbb-wpcodetool') . ' ' . $result->get_error_message());
                } else {
                    self::flash('success', $channel === 'sms'
                        ? sprintf(__('Code d\'essai envoyé par SMS au %s.', 'smbb-wpcodetool'), Security::maskPhone(TwoFactor::phone($user)))
                        : sprintf(__('Code d\'essai envoyé à %s.', 'smbb-wpcodetool'), $user->user_email));
                }
                break;

            case 'backup_generate':
                $view = '2fa';
                $codes = TwoFactor::generateBackupCodes($user->ID);
                // Montrés une seule fois, juste après la redirection.
                set_transient('smbb_2fa_codes_' . $user->ID, $codes, 5 * MINUTE_IN_SECONDS);
                self::flash('success', __('Nouveaux codes de secours créés : les anciens ne marchent plus.', 'smbb-wpcodetool'));
                break;

            case 'trust_revoke':
                $view = '2fa';
                $ip = isset($_POST['ip']) ? sanitize_text_field(wp_unslash($_POST['ip'])) : '';
                TwoFactor::revokeTrust($user->ID, $ip);
                self::flash('success', sprintf(__('L\'adresse %s n\'est plus reconnue pour votre compte.', 'smbb-wpcodetool'), $ip));
                break;

            case 'trust_revoke_all':
                $view = '2fa';
                TwoFactor::revokeAllTrust();
                Security::log('security.trust_revoked', 'notice', __('Adresses de confiance oubliées pour tous les comptes', 'smbb-wpcodetool'));
                self::flash('success', __('Plus aucune adresse n\'est reconnue : chaque compte protégé devra saisir un code à sa prochaine connexion.', 'smbb-wpcodetool'));
                break;

            case 'unlock':
                $view = 'blocked';
                $ip = isset($_POST['ip']) ? sanitize_text_field(wp_unslash($_POST['ip'])) : '';

                if (LoginLimiter::unlock($ip)) {
                    Security::log('security.ip_unblocked', 'notice', sprintf(__('Adresse %s débloquée', 'smbb-wpcodetool'), $ip), array('object_type' => 'ip', 'object_id' => $ip));
                }

                self::flash('success', sprintf(__('Adresse %s débloquée.', 'smbb-wpcodetool'), $ip));
                break;

            case 'reset_failures':
                $view = 'blocked';
                $ip = isset($_POST['ip']) ? sanitize_text_field(wp_unslash($_POST['ip'])) : '';
                LoginLimiter::clearFailures($ip);
                Security::log('security.failures_reset', 'notice', sprintf(__('Échecs de l\'adresse %s remis à zéro', 'smbb-wpcodetool'), $ip), array('object_type' => 'ip', 'object_id' => $ip));
                self::flash('success', sprintf(__('Compteur de l\'adresse %s remis à zéro.', 'smbb-wpcodetool'), $ip));
                break;

            case 'reset_failures_all':
                $view = 'blocked';
                $count = LoginLimiter::clearFailures(null);
                self::flash('success', sprintf(_n('%d adresse remise à zéro.', '%d adresses remises à zéro.', $count, 'smbb-wpcodetool'), $count));
                break;

            case 'unlock_all':
                $view = 'blocked';
                $count = LoginLimiter::unlockAll();
                Security::log('security.ip_unblocked', 'notice', sprintf(__('%d adresse(s) débloquée(s) d\'un coup', 'smbb-wpcodetool'), $count));
                self::flash('success', sprintf(_n('%d adresse débloquée.', '%d adresses débloquées.', $count, 'smbb-wpcodetool'), $count));
                break;

            case 'save_lists':
                $view = 'blocked';
                $l = Security::part('limit');
                $white = Security::ipList(isset($_POST['lim_whitelist']) ? wp_unslash($_POST['lim_whitelist']) : '');
                $black = Security::ipList(isset($_POST['lim_blacklist']) ? wp_unslash($_POST['lim_blacklist']) : '');
                $l['whitelist'] = implode("\n", $white);
                $l['blacklist'] = implode("\n", $black);
                Security::savePart('limit', $l);
                self::logSaved(__('Listes blanche et noire enregistrées', 'smbb-wpcodetool'));

                if (Security::ipInList(Security::ip(), $black) && !Security::ipInList(Security::ip(), $white)) {
                    self::flash('warning', __('Attention : votre propre adresse est dans la liste noire. Vous ne pourrez plus vous connecter depuis ici une fois déconnecté.', 'smbb-wpcodetool'));
                } else {
                    self::flash('success', __('Listes enregistrées.', 'smbb-wpcodetool'));
                }
                break;
        }

        wp_safe_redirect(self::url($view));
        exit;
    }

    private static function logSaved($message)
    {
        Security::log('security.settings_saved', 'notice', $message, array('user_id' => get_current_user_id()));
    }

    private static function flash($type, $message)
    {
        set_transient('smbb_security_flash_' . get_current_user_id(), array($type, $message), 120);
    }

    private function flashNotices()
    {
        $key = 'smbb_security_flash_' . get_current_user_id();
        $flash = get_transient($key);
        $notices = array();

        if (is_array($flash)) {
            delete_transient($key);
            $notices[] = $this->notice($flash[0], $flash[1]);
        }

        if (Security::off()) {
            $notices[] = $this->notice('error', __('Accès de secours actif : la constante SMBB_SECURITY_OFF de wp-config.php coupe tout le module (Turnstile, blocages, double authentification). Retirez-la dès que vous avez repris la main.', 'smbb-wpcodetool'));
        }

        return $notices;
    }

    private function notice($type, $message)
    {
        // Pas de variante « warning » dans admin.css : une mise en garde s'affiche comme une erreur.
        $type = $type === 'warning' ? 'error' : $type;

        return '<div class="smbb-codetool-notice is-' . esc_attr($type) . '"><p class="smbb-codetool-notice-message">' . esc_html($message) . '</p></div>';
    }

    // ------------------------------------------------------------------ Rendu

    /**
     * @param string $view login | turnstile | 2fa | blocked
     */
    public function render($view = 'login')
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Vous n\'avez pas le droit de gérer la sécurité.', 'smbb-wpcodetool'));
        }

        $titles = array(
            'login' => array(__('Connexion', 'smbb-wpcodetool'), __('Blocage des adresses qui enchaînent les mauvais mots de passe, et accès de secours.', 'smbb-wpcodetool'), 'dashicons-lock'),
            'turnstile' => array(__('Turnstile', 'smbb-wpcodetool'), __('Vérification anti-robot Cloudflare, sans puzzle : sur la connexion, l\'inscription, le mot de passe perdu et les formulaires du site.', 'smbb-wpcodetool'), 'dashicons-shield'),
            '2fa' => array(__('Double authentification', 'smbb-wpcodetool'), __('Après le mot de passe, un code à 6 chiffres envoyé par e-mail ou SMS, ou lu dans une application d\'authentification (Google ou Microsoft Authenticator…). Réglé rôle par rôle, avec une durée pendant laquelle une même adresse IP n\'a plus besoin de code.', 'smbb-wpcodetool'), 'dashicons-smartphone'),
            'blocked' => array(__('IP bloquées', 'smbb-wpcodetool'), __('Les adresses bloquées après trop d\'échecs, et les listes d\'adresses toujours autorisées ou toujours refusées.', 'smbb-wpcodetool'), 'dashicons-dismiss'),
        );
        $view = isset($titles[$view]) ? $view : 'login';
        ?>
        <div class="wrap smbb-codetool smbb-security-page">
            <?php
            echo $this->manager()->pageIntroHtml($titles[$view][0], $titles[$view][1], $titles[$view][2], $this->flashNotices()); // phpcs:ignore -- HTML construit par AdminManager.

            if ($view === '2fa') {
                $this->render2fa();
            } elseif ($view === 'blocked') {
                $this->renderBlocked();
            } elseif ($view === 'turnstile') {
                $this->renderTurnstile();
            } else {
                $this->renderLogin();
            }
            ?>
        </div>
        <?php
    }

    private function fields($action)
    {
        wp_nonce_field(self::NONCE, 'smbb_security_nonce');
        echo '<input type="hidden" name="smbb_security_action" value="' . esc_attr($action) . '">';
    }

    private function sectionStart($icon, $title, $description)
    {
        ?>
        <section class="smbb-codetool-section">
            <header class="smbb-codetool-section-header">
                <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr($icon); ?>"></span></span>
                <div class="smbb-codetool-section-heading">
                    <h3><?php echo esc_html($title); ?></h3>
                    <p class="description"><?php echo esc_html($description); ?></p>
                </div>
            </header>
            <div class="smbb-codetool-section-body">
        <?php
    }

    private function sectionEnd()
    {
        echo '</div></section>';
    }

    private function state($ok, $text)
    {
        echo '<p class="smbb-codetool-encryption-state ' . ($ok ? 'is-good' : 'is-warn') . '"><span class="dashicons ' . ($ok ? 'dashicons-yes-alt' : 'dashicons-warning') . '" aria-hidden="true"></span>' . esc_html($text) . '</p>';
    }

    private function numberField($name, $value, $label, $suffix, $min, $max)
    {
        ?>
        <p class="smbb-security-field">
            <label for="smbb-sec-<?php echo esc_attr($name); ?>"><strong><?php echo esc_html($label); ?></strong></label><br>
            <input type="number" class="small-text" id="smbb-sec-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>"> <?php echo esc_html($suffix); ?>
        </p>
        <?php
    }

    /**
     * Adresse IP + lien vers sa fiche de réputation (AbuseIPDB, nouvel onglet) + « (vous) ».
     */
    private function ipCell($ip, $me = '')
    {
        $html = '<code>' . esc_html($ip) . '</code>';

        if ($ip === $me) {
            $html .= ' <em>' . esc_html__('(vous)', 'smbb-wpcodetool') . '</em>';
        }

        $html .= ' <a href="' . esc_url(LoginLimiter::reputationUrl($ip)) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__('Voir la réputation de cette adresse sur AbuseIPDB (nouvel onglet)', 'smbb-wpcodetool') . '" style="text-decoration:none;white-space:nowrap;font-size:12px;">'
            . '<span class="dashicons dashicons-search" aria-hidden="true" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;"></span> ' . esc_html__('AbuseIPDB', 'smbb-wpcodetool') . '</a>';

        return $html;
    }

    // ------------------------------------------------------------------ Connexion

    private function renderTurnstile()
    {
        $t = Security::part('turnstile');
        $stored_secret = (string) $t['secret'] !== '';
        $secret = Security::turnstileSecret();
        ?>
        <form method="post" action="<?php echo esc_url(self::url('turnstile')); ?>">
            <?php $this->fields('save_turnstile'); ?>
            <?php $this->sectionStart('dashicons-shield', __('Cloudflare Turnstile', 'smbb-wpcodetool'), __('Une vérification anti-robot sans puzzle à résoudre, gratuite. Le domaine n\'a pas besoin d\'être chez Cloudflare : il doit seulement figurer dans la liste des domaines du widget (Cloudflare › Turnstile).', 'smbb-wpcodetool')); ?>
            <?php
            if (!empty($t['enabled'])) {
                $this->state(Turnstile::active(), Turnstile::active() ? __('Turnstile actif sur les formulaires choisis.', 'smbb-wpcodetool') : __('Activé mais pas encore actif : il manque la clé du site ou la clé secrète.', 'smbb-wpcodetool'));
            }

            if ((string) $t['last_error'] !== '') :
                ?>
                <div class="smbb-codetool-notice is-error"><p class="smbb-codetool-notice-message">
                    <?php echo esc_html(sprintf(__('Le %1$s, Cloudflare a répondu « %2$s ». Pour ne bloquer personne, les formulaires ont été acceptés sans vérification. Vérifiez la clé secrète.', 'smbb-wpcodetool'), wp_date(get_option('date_format') . ' H:i', (int) $t['last_error_at']), $t['last_error'])); ?>
                    <button type="submit" class="button button-small" name="smbb_security_action" value="clear_turnstile_error" formnovalidate><?php esc_html_e('Effacer l\'alerte', 'smbb-wpcodetool'); ?></button>
                </p></div>
                <?php
            endif;

            AppearancePage::toggle('ts_enabled', $t['enabled'], __('Activer Turnstile', 'smbb-wpcodetool'), __('La case anti-robot apparaît sous les formulaires cochés ci-dessous. Si Cloudflare ne répond pas ou si la clé secrète est fausse, les formulaires restent utilisables (et une alerte s\'affiche ici).', 'smbb-wpcodetool'));
            ?>
            <p class="smbb-security-field">
                <label for="smbb-sec-ts-site"><strong><?php esc_html_e('Clé du site (site key)', 'smbb-wpcodetool'); ?></strong></label><br>
                <input type="text" class="regular-text code" id="smbb-sec-ts-site" name="ts_site_key" value="<?php echo esc_attr((string) $t['site_key']); ?>" autocomplete="off" spellcheck="false">
            </p>
            <p class="smbb-security-field">
                <label for="smbb-sec-ts-secret"><strong><?php esc_html_e('Clé secrète (secret key)', 'smbb-wpcodetool'); ?></strong></label><br>
                <?php if (defined(Security::TURNSTILE_SECRET_CONSTANT)) : ?>
                    <em><?php esc_html_e('Définie par la constante SMBB_TURNSTILE_SECRET dans wp-config.php.', 'smbb-wpcodetool'); ?></em>
                <?php else : ?>
                    <input type="password" class="regular-text code" id="smbb-sec-ts-secret" name="ts_secret" value="" autocomplete="new-password" placeholder="<?php echo $stored_secret ? esc_attr__('•••••••• enregistrée, laisser vide pour la garder', 'smbb-wpcodetool') : ''; ?>">
                    <?php if ($stored_secret) : ?>
                        <label><input type="checkbox" name="ts_secret_clear" value="1"> <?php esc_html_e('Effacer', 'smbb-wpcodetool'); ?></label>
                    <?php endif; ?>
                    <br><span class="description"><?php echo esc_html(Secrets::available()
                        ? __('Chiffrée en base comme les autres secrets (CodeTool › Chiffrement).', 'smbb-wpcodetool')
                        : __('Chiffrement indisponible sur ce serveur : la clé sera gardée en clair.', 'smbb-wpcodetool')); ?></span>
                    <?php if ($stored_secret && $secret === null) : ?>
                        <br><span class="description" style="color:#b32d2e;"><?php esc_html_e('La clé enregistrée ne se déchiffre plus (clé de chiffrement changée) : saisissez-la de nouveau.', 'smbb-wpcodetool'); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <p class="description"><?php esc_html_e('Pour essayer sans compte : clé du site 1x00000000000000000000AA et clé secrète 1x0000000000000000000000000000000AA (toujours validé). Clé du site 2x00000000000000000000AB : toujours refusé.', 'smbb-wpcodetool'); ?></p>
            <p class="smbb-security-field">
                <label for="smbb-sec-ts-theme"><strong><?php esc_html_e('Apparence de la case', 'smbb-wpcodetool'); ?></strong></label><br>
                <select id="smbb-sec-ts-theme" name="ts_theme">
                    <?php foreach (array('auto' => __('Selon l\'appareil (clair ou sombre)', 'smbb-wpcodetool'), 'light' => __('Claire', 'smbb-wpcodetool'), 'dark' => __('Sombre', 'smbb-wpcodetool')) as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($t['theme'], $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <?php
            $this->sectionEnd();

            $this->sectionStart('dashicons-forms', __('Où l\'afficher', 'smbb-wpcodetool'), __('Les formulaires de WordPress protégés par la case anti-robot.', 'smbb-wpcodetool'));
            AppearancePage::toggle('ts_login', $t['login'], __('Connexion', 'smbb-wpcodetool'));
            AppearancePage::toggle('ts_lostpassword', $t['lostpassword'], __('Mot de passe perdu', 'smbb-wpcodetool'));
            AppearancePage::toggle('ts_register', $t['register'], __('Inscription', 'smbb-wpcodetool'), get_option('users_can_register') ? '' : __('Les inscriptions sont fermées sur ce site (Réglages › Général) : sans effet pour wp-login.php.', 'smbb-wpcodetool'));
            AppearancePage::toggle('ts_woocommerce', $t['woocommerce'], __('Aussi sur « Mon compte » de WooCommerce', 'smbb-wpcodetool'), class_exists('WooCommerce') ? __('Mêmes formulaires (connexion, mot de passe perdu, inscription) sur la page Mon compte. Pas sur la création de compte au paiement.', 'smbb-wpcodetool') : __('WooCommerce n\'est pas actif.', 'smbb-wpcodetool'));

            $forms = \Smbb\WpCodeTool\Forms\Forms::enabled();

            if ($forms) {
                echo '<p class="description">' . sprintf(
                    /* translators: %s: lien vers Formulaires */
                    esc_html__('Formulaires du site (%s) : Turnstile se coche formulaire par formulaire, dans ses Réglages. Il est coché d\'office sur chaque nouveau formulaire : dès que les clés sont réglées ici, ils sont protégés (sauf ceux où on l\'a décoché).', 'smbb-wpcodetool'),
                    '<a href="' . esc_url(admin_url('admin.php?page=' . \Smbb\WpCodeTool\Admin\Pages\FormsPage::SLUG)) . '">' . esc_html__('menu Formulaires', 'smbb-wpcodetool') . '</a>'
                ) . '</p>';
            }

            $this->sectionEnd();
            ?>
            <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button></p>
        </form>
        <?php
    }

    private function renderLogin()
    {
        $l = Security::part('limit');
        ?>
        <form method="post" action="<?php echo esc_url(self::url('login')); ?>">
            <?php $this->fields('save_login'); ?>
            <?php
            echo '<span id="smbb-sec-lim"></span>';
            $this->sectionStart('dashicons-lock', __('Limiter les tentatives', 'smbb-wpcodetool'), __('Une adresse qui se trompe trop souvent est bloquée un moment, puis longtemps si elle recommence. Les codes de double authentification refusés comptent aussi.', 'smbb-wpcodetool'));
            AppearancePage::toggle('lim_enabled', $l['enabled'], __('Activer la limitation', 'smbb-wpcodetool'));
            $this->numberField('lim_attempts', $l['attempts'], __('Échecs permis', 'smbb-wpcodetool'), __('essais', 'smbb-wpcodetool'), 2, 50);
            $this->numberField('lim_window', $l['window'], __('… en', 'smbb-wpcodetool'), __('minutes', 'smbb-wpcodetool'), 1, 1440);
            $this->numberField('lim_lockout', $l['lockout'], __('Durée du blocage', 'smbb-wpcodetool'), __('minutes', 'smbb-wpcodetool'), 1, 1440);
            $this->numberField('lim_long_after', $l['long_after'], __('Blocage long à partir du', 'smbb-wpcodetool'), __('e blocage en 24 h (0 = jamais)', 'smbb-wpcodetool'), 0, 20);
            $this->numberField('lim_long_hours', $l['long_hours'], __('Durée du blocage long', 'smbb-wpcodetool'), __('heures', 'smbb-wpcodetool'), 1, 720);
            ?>
            <p class="smbb-security-field">
                <label for="smbb-sec-ip-source"><strong><?php esc_html_e('Adresse IP du visiteur', 'smbb-wpcodetool'); ?></strong></label><br>
                <select id="smbb-sec-ip-source" name="lim_ip_source">
                    <?php foreach (Security::ipSources() as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($l['ip_source'], $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <br><span class="description"><?php
                // phpcs:disable WordPress.Security.ValidatedSanitizedInput
                echo esc_html(sprintf(
                    __('Vue par le serveur : REMOTE_ADDR %1$s · CF-Connecting-IP %2$s · X-Forwarded-For %3$s. Adresse retenue : %4$s. Ne choisissez un en-tête que si un proxy le remplit vraiment, sinon un robot peut l\'inventer et ne jamais être bloqué.', 'smbb-wpcodetool'),
                    isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '—',
                    isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])) : '—',
                    isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])) : '—',
                    Security::ip()
                ));
                // phpcs:enable
                ?></span>
            </p>
            <?php
            AppearancePage::toggle('lim_generic_errors', $l['generic_errors'], __('Message d\'erreur unique', 'smbb-wpcodetool'), __('« Identifiant ou mot de passe incorrect » dans tous les cas : on ne dit plus si le compte existe. Le nombre d\'essais restants s\'affiche à partir de 3.', 'smbb-wpcodetool'));
            AppearancePage::toggle('lim_notify', $l['notify'], __('Prévenir par e-mail lors d\'un blocage long', 'smbb-wpcodetool'), sprintf(__('À l\'adresse d\'administration du site (%s).', 'smbb-wpcodetool'), get_option('admin_email')));
            $this->sectionEnd();
            ?>
            <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button></p>
        </form>
        <?php
        $this->renderRescue();
    }

    private function renderRescue()
    {
        $this->sectionStart('dashicons-sos', __('Accès de secours', 'smbb-wpcodetool'), __('Si plus personne ne peut se connecter (clé Turnstile fausse, code qui n\'arrive pas, adresse bloquée).', 'smbb-wpcodetool'));
        ?>
        <ol class="smbb-security-rescue">
            <li><?php esc_html_e('Codes de secours : chaque compte protégé peut en générer dans « Double authentification ». Un code se saisit à la place du code reçu, une seule fois.', 'smbb-wpcodetool'); ?></li>
            <li><?php esc_html_e('Liste blanche (page « IP bloquées ») : les adresses du bureau ne sont jamais bloquées.', 'smbb-wpcodetool'); ?></li>
            <li><?php esc_html_e('En dernier recours, ajoutez cette ligne dans wp-config.php (par FTP ou l\'hébergeur) : tout le module est coupé le temps de reprendre la main.', 'smbb-wpcodetool'); ?><br><code>define('SMBB_SECURITY_OFF', true);</code></li>
            <li><?php esc_html_e('Avec WP-CLI : wp option delete smbb_codetool_security_lockouts débloque toutes les adresses.', 'smbb-wpcodetool'); ?></li>
        </ol>
        <?php
        $this->sectionEnd();
    }

    // ------------------------------------------------------------------ Double authentification

    private function render2fa()
    {
        $f = Security::part('twofa');
        $user = wp_get_current_user();
        $sms_ready = class_exists(Sms::class) ? Sms::ready() : __('Module SMS absent.', 'smbb-wpcodetool');
        $counts = count_users();
        $counts = isset($counts['avail_roles']) ? $counts['avail_roles'] : array();
        $codes = get_transient('smbb_2fa_codes_' . $user->ID);

        if (is_array($codes)) {
            delete_transient('smbb_2fa_codes_' . $user->ID);
        }
        ?>
        <form method="post" action="<?php echo esc_url(self::url('2fa')); ?>">
            <?php $this->fields('save_2fa'); ?>
            <?php $this->sectionStart('dashicons-smartphone', __('Règles par rôle', 'smbb-wpcodetool'), __('Pour chaque type de compte : faut-il un code, par où l\'envoyer, et combien de temps une même adresse IP en est dispensée après un code réussi.', 'smbb-wpcodetool')); ?>
            <?php
            AppearancePage::toggle('fa_enabled', $f['enabled'], __('Activer la double authentification', 'smbb-wpcodetool'), __('Coupé : personne ne reçoit de code, quelles que soient les règles du tableau.', 'smbb-wpcodetool'));

            if ($sms_ready !== true) {
                $this->state(false, sprintf(__('SMS pas prêt (%s) : les rôles réglés sur SMS recevront le code par e-mail.', 'smbb-wpcodetool'), $sms_ready));
            }
            ?>
            <table class="widefat striped smbb-security-roles" style="max-width:820px;margin:12px 0;">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Rôle', 'smbb-wpcodetool'); ?></th>
                        <th scope="col"><?php esc_html_e('Comptes', 'smbb-wpcodetool'); ?></th>
                        <th scope="col"><?php esc_html_e('Double authentification', 'smbb-wpcodetool'); ?></th>
                        <th scope="col"><?php esc_html_e('IP de confiance', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (wp_roles()->roles as $role => $data) : ?>
                        <?php $rule = isset($f['roles'][$role]) ? $f['roles'][$role] : array('channel' => 'off', 'trust' => 24); ?>
                        <tr>
                            <td><strong><?php echo esc_html(translate_user_role($data['name'])); ?></strong><br><code style="font-size:11px;"><?php echo esc_html($role); ?></code></td>
                            <td><?php echo (int) (isset($counts[$role]) ? $counts[$role] : 0); ?></td>
                            <td>
                                <select name="fa_roles[<?php echo esc_attr($role); ?>][channel]" aria-label="<?php echo esc_attr(sprintf(__('Double authentification pour %s', 'smbb-wpcodetool'), translate_user_role($data['name']))); ?>">
                                    <?php foreach (Security::channels() as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($rule['channel'], $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php $this->phoneCheck($role, $rule['channel'] === 'sms'); ?>
                                <?php $this->appCheck($role, $rule['channel'] === 'app'); ?>
                            </td>
                            <td>
                                <select name="fa_roles[<?php echo esc_attr($role); ?>][trust]" aria-label="<?php echo esc_attr(sprintf(__('IP de confiance pour %s', 'smbb-wpcodetool'), translate_user_role($data['name']))); ?>">
                                    <?php foreach (Security::trustChoices() as $hours => $label) : ?>
                                        <option value="<?php echo (int) $hours; ?>" <?php selected((int) $rule['trust'], (int) $hours); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <script>
            (function () {
                document.querySelectorAll('.smbb-security-roles select[name$="[channel]"]').forEach(function (select) {
                    var hint = select.parentNode.querySelector('.smbb-phone-check');
                    var app = select.parentNode.querySelector('.smbb-app-check');
                    var sync = function () {
                        if (hint) { hint.hidden = select.value !== 'sms'; }
                        if (app) { app.hidden = select.value !== 'app'; }
                    };
                    select.addEventListener('change', sync);
                    sync();
                });
            })();
            </script>
            <p class="description"><?php esc_html_e('SMS : numéro pris dans le champ « Téléphone » du profil (le téléphone de facturation avec WooCommerce, ajouté par CodeTool sinon) ; sans numéro, le code part par e-mail. Application : Google Authenticator, Microsoft Authenticator, Authy… chaque compte la configure dans son profil en scannant un QR code ; tant qu\'il ne l\'a pas fait, il reçoit le code par e-mail. Un compte qui a configuré l\'application l\'utilise toujours, quel que soit son rôle. Plusieurs rôles : la règle la plus stricte (application, puis SMS, puis e-mail, et la confiance la plus courte).', 'smbb-wpcodetool'); ?></p>
            <?php
            $this->numberField('fa_code_ttl', $f['code_ttl'], __('Validité du code', 'smbb-wpcodetool'), __('minutes', 'smbb-wpcodetool'), 2, 30);
            AppearancePage::toggle('fa_fail_open', $f['fail_open'], __('Mode souple : sans envoi possible, pas de code', 'smbb-wpcodetool'), __('Ordre d\'envoi : SMS si c\'est la règle du rôle, puis e-mail (gratuit) si le SMS ne part pas. Si rien ne part, la personne entre avec son seul mot de passe, et c\'est noté en critique au journal. Coupé : elle reste bloquée à l\'écran du code (codes de secours ou SMBB_SECURITY_OFF).', 'smbb-wpcodetool'));
            AppearancePage::toggle('fa_block_xmlrpc', $f['block_xmlrpc'], __('Refuser XML-RPC aux comptes protégés', 'smbb-wpcodetool'), __('XML-RPC ne sait pas demander de code : sans ce refus, il contournerait la double authentification. Les mots de passe d\'application (API REST) ne sont pas concernés.', 'smbb-wpcodetool'));
            $this->sectionEnd();
            ?>
            <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer', 'smbb-wpcodetool'); ?></button></p>
        </form>

        <form method="post" action="<?php echo esc_url(self::url('2fa')); ?>">
            <?php wp_nonce_field(self::NONCE, 'smbb_security_nonce'); ?>
            <?php $this->sectionStart('dashicons-admin-users', __('Mon compte', 'smbb-wpcodetool'), sprintf(__('%1$s · %2$s', 'smbb-wpcodetool'), $user->display_name, $user->user_email)); ?>
            <?php
            $mine = TwoFactor::ruleFor($user);

            if (empty($f['enabled']) || $mine === null) {
                $this->state(false, __('Votre compte ne reçoit pas de code à la connexion.', 'smbb-wpcodetool'));
            } else {
                $channel = TwoFactor::channelFor($user);

                if ($channel === 'app') {
                    $this->state(true, __('Votre compte utilise son application d\'authentification.', 'smbb-wpcodetool'));
                } else {
                    $this->state(true, $channel === 'sms'
                        ? sprintf(__('Votre compte reçoit un code par SMS au %s.', 'smbb-wpcodetool'), Security::maskPhone(TwoFactor::phone($user)))
                        : sprintf(__('Votre compte reçoit un code par e-mail à %s.', 'smbb-wpcodetool'), $user->user_email));
                }
            }
            ?>
            <p>
                <a class="button" href="<?php echo esc_url(get_edit_profile_url($user->ID) . '#smbb-totp'); ?>"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span> <?php echo esc_html(Totp::enabled($user->ID) ? __('Gérer mon application d\'authentification', 'smbb-wpcodetool') : __('Configurer une application d\'authentification', 'smbb-wpcodetool')); ?></a>
            </p>
            <p>
                <button type="submit" class="button" name="smbb_security_action" value="test_email"><?php esc_html_e('M\'envoyer un code d\'essai par e-mail', 'smbb-wpcodetool'); ?></button>
                <button type="submit" class="button" name="smbb_security_action" value="test_sms" <?php disabled(!TwoFactor::smsPossible($user)); ?>><?php esc_html_e('… par SMS', 'smbb-wpcodetool'); ?></button>
            </p>

            <h4><?php esc_html_e('Codes de secours', 'smbb-wpcodetool'); ?></h4>
            <?php if (is_array($codes)) : ?>
                <div class="smbb-codetool-notice is-info"><p class="smbb-codetool-notice-message"><?php esc_html_e('Notez-les ou imprimez-les maintenant : ils ne seront plus jamais affichés. Chaque code ne sert qu\'une fois.', 'smbb-wpcodetool'); ?></p></div>
                <pre style="font-size:16px;line-height:1.8;background:var(--smbb-surface-2,#f6f7f7);padding:12px 16px;border-radius:8px;display:inline-block;columns:2;column-gap:32px;"><?php echo esc_html(implode("\n", $codes)); ?></pre>
            <?php else : ?>
                <p class="description"><?php
                $left = TwoFactor::backupCount($user->ID);
                echo esc_html($left > 0
                    ? sprintf(_n('Il vous reste %d code de secours.', 'Il vous reste %d codes de secours.', $left, 'smbb-wpcodetool'), $left)
                    : __('Vous n\'avez pas de code de secours. Sans eux, si le code n\'arrive pas, il faudra passer par wp-config.php.', 'smbb-wpcodetool'));
                ?></p>
            <?php endif; ?>
            <p><button type="submit" class="button" name="smbb_security_action" value="backup_generate" data-confirm="<?php esc_attr_e('Les anciens codes de secours ne marcheront plus. Continuer ?', 'smbb-wpcodetool'); ?>" onclick="return !this.dataset.confirm || confirm(this.dataset.confirm);"><?php echo esc_html(TwoFactor::backupCount($user->ID) > 0 ? __('Générer de nouveaux codes', 'smbb-wpcodetool') : __('Générer mes codes de secours', 'smbb-wpcodetool')); ?></button></p>

            <h4><?php esc_html_e('Mes adresses de confiance', 'smbb-wpcodetool'); ?></h4>
            <?php $trust = TwoFactor::trustList($user->ID); ?>
            <?php if (!$trust) : ?>
                <p class="description"><?php esc_html_e('Aucune pour le moment.', 'smbb-wpcodetool'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:560px;">
                    <tbody>
                        <?php foreach ($trust as $ip => $until) : ?>
                            <tr>
                                <td><?php echo $this->ipCell($ip, Security::ip()); // phpcs:ignore -- échappé dans ipCell(). ?></td>
                                <td><?php echo esc_html(sprintf(__('jusqu\'au %s', 'smbb-wpcodetool'), wp_date(get_option('date_format') . ' H:i', (int) $until))); ?></td>
                                <td style="text-align:right;"><button type="submit" class="button button-small" name="smbb_security_action" value="trust_revoke" onclick="this.form.ip.value='<?php echo esc_js($ip); ?>';"><?php esc_html_e('Oublier', 'smbb-wpcodetool'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <input type="hidden" name="ip" value="">
            <p style="margin-top:16px;"><button type="submit" class="button" name="smbb_security_action" value="trust_revoke_all" onclick="return confirm('<?php echo esc_js(__('Tous les comptes protégés devront saisir un code à leur prochaine connexion. Continuer ?', 'smbb-wpcodetool')); ?>');"><?php esc_html_e('Oublier les adresses de confiance de tous les comptes', 'smbb-wpcodetool'); ?></button></p>
            <?php $this->sectionEnd(); ?>
        </form>
        <?php
    }

    /**
     * Sous le choix du canal : les comptes du rôle sans numéro (montré seulement quand SMS est choisi).
     */
    private function phoneCheck($role, $visible)
    {
        $hidden = $visible ? '' : ' hidden';
        $check = TwoFactor::roleWithoutPhone($role);
        $admin = $role === 'administrator';

        if ($check['total'] === 0) {
            return;
        }

        if (!$check['missing']) {
            echo '<div class="smbb-phone-check"' . $hidden . ' style="margin-top:6px;font-size:12px;color:#008a20;"><span class="dashicons dashicons-yes-alt" aria-hidden="true" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;"></span> ' . esc_html(sprintf(_n('Le compte a un numéro.', 'Les %d comptes ont un numéro.', $check['total'], 'smbb-wpcodetool'), $check['total'])) . '</div>';

            return;
        }

        $count = count($check['missing']);
        ?>
        <details class="smbb-phone-check"<?php echo $hidden; // phpcs:ignore -- constante. ?> style="margin-top:6px;font-size:12px;color:<?php echo $admin ? '#b32d2e' : '#996800'; ?>;">
            <summary style="cursor:pointer;"><span class="dashicons dashicons-warning" aria-hidden="true" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;"></span> <?php
            echo esc_html(sprintf(_n('%1$d compte sur %2$d sans numéro : code par e-mail', '%1$d comptes sur %2$d sans numéro : code par e-mail', $count, 'smbb-wpcodetool'), $count, $check['total']));
            ?></summary>
            <ul style="margin:4px 0 0 18px;list-style:disc;">
                <?php foreach (array_slice($check['missing'], 0, 20) as $missing) : ?>
                    <li><a href="<?php echo esc_url(get_edit_user_link($missing->ID)); ?>"><?php echo esc_html($missing->display_name !== '' ? $missing->display_name : $missing->user_login); ?></a> <span style="color:#646970;"><?php echo esc_html($missing->user_email); ?></span></li>
                <?php endforeach; ?>
                <?php if ($count > 20) : ?>
                    <li><?php echo esc_html(sprintf(__('… et %d autres', 'smbb-wpcodetool'), $count - 20)); ?></li>
                <?php endif; ?>
            </ul>
        </details>
        <?php
    }

    /**
     * Sous le choix du canal : les comptes du rôle qui n'ont pas encore configuré l'application
     * (montré seulement quand « Application » est choisi).
     */
    private function appCheck($role, $visible)
    {
        $hidden = $visible ? '' : ' hidden';
        $check = TwoFactor::roleWithoutApp($role);

        if ($check['total'] === 0) {
            return;
        }

        if (!$check['missing']) {
            echo '<div class="smbb-app-check"' . $hidden . ' style="margin-top:6px;font-size:12px;color:#008a20;"><span class="dashicons dashicons-yes-alt" aria-hidden="true" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;"></span> ' . esc_html(sprintf(_n('Le compte a configuré son application.', 'Les %d comptes ont configuré leur application.', $check['total'], 'smbb-wpcodetool'), $check['total'])) . '</div>';

            return;
        }

        $count = count($check['missing']);
        ?>
        <details class="smbb-app-check"<?php echo $hidden; // phpcs:ignore -- constante. ?> style="margin-top:6px;font-size:12px;color:#996800;">
            <summary style="cursor:pointer;"><span class="dashicons dashicons-info" aria-hidden="true" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;"></span> <?php
            echo esc_html(sprintf(_n('%1$d compte sur %2$d sans application : code par e-mail en attendant', '%1$d comptes sur %2$d sans application : code par e-mail en attendant', $count, 'smbb-wpcodetool'), $count, $check['total']));
            ?></summary>
            <ul style="margin:4px 0 0 18px;list-style:disc;">
                <?php foreach (array_slice($check['missing'], 0, 20) as $missing) : ?>
                    <li><?php echo esc_html($missing->display_name !== '' ? $missing->display_name : $missing->user_login); ?> <span style="color:#646970;"><?php echo esc_html($missing->user_email); ?></span></li>
                <?php endforeach; ?>
                <?php if ($count > 20) : ?>
                    <li><?php echo esc_html(sprintf(__('… et %d autres', 'smbb-wpcodetool'), $count - 20)); ?></li>
                <?php endif; ?>
            </ul>
            <p style="margin:4px 0 0;color:#646970;"><?php esc_html_e('Ils voient un rappel dans l\'admin avec un bouton « Configurer maintenant ».', 'smbb-wpcodetool'); ?></p>
        </details>
        <?php
    }

    // ------------------------------------------------------------------ IP bloquées

    private function renderBlocked()
    {
        $l = Security::part('limit');
        $active = LoginLimiter::active();
        $reasons = array('login' => __('mot de passe', 'smbb-wpcodetool'), '2fa' => __('code de double auth.', 'smbb-wpcodetool'));
        $me = Security::ip();
        ?>
        <form method="post" action="<?php echo esc_url(self::url('blocked')); ?>">
            <?php wp_nonce_field(self::NONCE, 'smbb_security_nonce'); ?>
            <input type="hidden" name="ip" value="">
            <p><a class="button" href="<?php echo esc_url(self::url('login') . '#smbb-sec-lim'); ?>"><span class="dashicons dashicons-admin-generic" aria-hidden="true" style="font-size:16px;width:16px;height:16px;line-height:1;margin:0 4px 0 0;"></span><?php esc_html_e('Régler les seuils de blocage', 'smbb-wpcodetool'); ?></a></p>
            <?php $this->sectionStart('dashicons-dismiss', __('Blocages en cours', 'smbb-wpcodetool'), empty($l['enabled']) ? __('La limitation des tentatives est coupée (page Connexion) : plus rien n\'est bloqué automatiquement.', 'smbb-wpcodetool') : sprintf(__('Après %1$d échecs en %2$d minutes : %3$d minutes de blocage.', 'smbb-wpcodetool'), (int) $l['attempts'], (int) $l['window'], (int) $l['lockout'])); ?>
            <?php if (!$active) : ?>
                <p class="description"><?php esc_html_e('Aucune adresse bloquée en ce moment.', 'smbb-wpcodetool'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Adresse', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"><?php esc_html_e('Dernier identifiant essayé', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"><?php esc_html_e('Motif', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"><?php esc_html_e('Bloquée depuis', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"><?php esc_html_e('Jusqu\'au', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"><?php esc_html_e('Blocages de suite', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active as $ip => $lock) : ?>
                            <tr>
                                <td><?php echo $this->ipCell($ip, $me); // phpcs:ignore -- échappé dans ipCell(). ?></td>
                                <td><?php echo esc_html($lock['user'] !== '' ? $lock['user'] : '—'); ?></td>
                                <td><?php echo esc_html(isset($reasons[$lock['reason']]) ? $reasons[$lock['reason']] : $lock['reason']); ?></td>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $lock['at'])); ?></td>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $lock['until'])); ?></td>
                                <td><?php echo (int) $lock['count']; ?></td>
                                <td style="text-align:right;"><button type="submit" class="button button-small" name="smbb_security_action" value="unlock" onclick="this.form.ip.value='<?php echo esc_js($ip); ?>';"><?php esc_html_e('Débloquer', 'smbb-wpcodetool'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="submit" class="button" name="smbb_security_action" value="unlock_all"><?php esc_html_e('Tout débloquer', 'smbb-wpcodetool'); ?></button></p>
            <?php endif; ?>
            <?php $this->sectionEnd(); ?>

            <?php
            $watched = LoginLimiter::watched();
            $this->sectionStart('dashicons-visibility', __('En surveillance', 'smbb-wpcodetool'), sprintf(__('Adresses qui ont déjà raté des connexions dans les %1$d dernières minutes, sans être encore bloquées (blocage au %2$de échec). Remettre à zéro efface leur compteur, par exemple pour un collègue qui s\'est trompé de mot de passe.', 'smbb-wpcodetool'), (int) $l['window'], (int) $l['attempts']));
            ?>
            <?php if (!$watched) : ?>
                <p class="description"><?php esc_html_e('Aucune adresse en surveillance en ce moment.', 'smbb-wpcodetool'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Adresse', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"><?php esc_html_e('Dernier identifiant essayé', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"><?php esc_html_e('Motif', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"><?php esc_html_e('Échecs', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"><?php esc_html_e('Dernier échec', 'smbb-wpcodetool'); ?></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($watched as $ip => $entry) : ?>
                            <tr>
                                <td><?php echo $this->ipCell($ip, $me); // phpcs:ignore -- échappé dans ipCell(). ?></td>
                                <td><?php echo esc_html($entry['user'] !== '' ? $entry['user'] : '—'); ?></td>
                                <td><?php echo esc_html(isset($reasons[$entry['reason']]) ? $reasons[$entry['reason']] : $entry['reason']); ?></td>
                                <td><?php echo esc_html(sprintf('%d / %d', count($entry['times']), (int) $l['attempts'])); ?></td>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', max($entry['times']))); ?></td>
                                <td style="text-align:right;"><button type="submit" class="button button-small" name="smbb_security_action" value="reset_failures" onclick="this.form.ip.value='<?php echo esc_js($ip); ?>';"><?php esc_html_e('Remettre à zéro', 'smbb-wpcodetool'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="submit" class="button" name="smbb_security_action" value="reset_failures_all"><?php esc_html_e('Tout remettre à zéro', 'smbb-wpcodetool'); ?></button></p>
            <?php endif; ?>
            <?php $this->sectionEnd(); ?>
        </form>

        <form method="post" action="<?php echo esc_url(self::url('blocked')); ?>">
            <?php $this->fields('save_lists'); ?>
            <?php $this->sectionStart('dashicons-list-view', __('Listes d\'adresses', 'smbb-wpcodetool'), __('Une adresse par ligne. Plages acceptées (192.168.1.0/24, 2001:db8::/32). Ce qui suit # est un commentaire.', 'smbb-wpcodetool')); ?>
            <p class="smbb-security-field">
                <label for="smbb-sec-white"><strong><?php esc_html_e('Liste blanche : jamais bloquées', 'smbb-wpcodetool'); ?></strong></label><br>
                <textarea id="smbb-sec-white" name="lim_whitelist" rows="5" class="large-text code" style="max-width:520px;"><?php echo esc_textarea((string) $l['whitelist']); ?></textarea><br>
                <button type="button" class="button button-small" onclick="var t=document.getElementById('smbb-sec-white');t.value=(t.value.trim()?t.value.trim()+'\n':'')+'<?php echo esc_js($me); ?>';"><?php echo esc_html(sprintf(__('Ajouter mon adresse (%s)', 'smbb-wpcodetool'), $me)); ?></button>
                <span class="description"><?php esc_html_e('La double authentification s\'applique quand même.', 'smbb-wpcodetool'); ?></span>
            </p>
            <p class="smbb-security-field">
                <label for="smbb-sec-black"><strong><?php esc_html_e('Liste noire : jamais de connexion', 'smbb-wpcodetool'); ?></strong></label><br>
                <textarea id="smbb-sec-black" name="lim_blacklist" rows="5" class="large-text code" style="max-width:520px;"><?php echo esc_textarea((string) $l['blacklist']); ?></textarea>
            </p>
            <?php $this->sectionEnd(); ?>
            <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer les listes', 'smbb-wpcodetool'); ?></button></p>
        </form>
        <?php
    }
}
