<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Admin\AdminManager;
use Smbb\WpCodeTool\Resource\ResourceDefinition;
use Smbb\WpCodeTool\Support\Secrets;

defined('ABSPATH') || exit;

/**
 * Page « Encryption » : état de la clé, génération d'une clé pour wp-config.php,
 * inventaire des colonnes secrètes et chiffrement / déchiffrement en masse.
 */
final class EncryptionPage extends AbstractAdminPage
{
    const SLUG = 'smbb-wpcodetool-encryption';
    const NONCE = 'smbb_codetool_secrets';
    const MIGRATED_OPTION = 'smbb_codetool_secrets_key';

    public function render()
    {
        $manager = $this->manager();
        $inventory = self::inventory($manager);
        $totals = array('plain' => 0, 'current' => 0, 'known' => 0, 'unknown' => 0, 'too_short' => 0);

        foreach ($inventory as $entry) {
            foreach ($totals as $state => $count) {
                $totals[$state] += $entry[$state];
            }
        }

        $source = Secrets::source();
        $generated = isset($_GET['generate']) && Secrets::available() ? Secrets::generateKey() : '';
        $page_url = admin_url('admin.php?page=' . self::SLUG);
        ?>
        <div class="wrap smbb-codetool">
            <?php
            echo $manager->pageIntroHtml(
                __('Chiffrement', 'smbb-wpcodetool'),
                __('Les clés d\'API et jetons enregistrés par les ressources CodeTool sont chiffrés en base de données.', 'smbb-wpcodetool'),
                'dashicons-lock',
                self::resultNotice()
            );
            ?>

            <section class="smbb-codetool-section smbb-codetool-encryption">
                <header class="smbb-codetool-section-header">
                    <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-admin-network"></span></span>
                    <div class="smbb-codetool-section-heading">
                        <h3><?php esc_html_e('Clé de chiffrement', 'smbb-wpcodetool'); ?></h3>
                        <p class="description"><?php esc_html_e('La clé n\'est jamais enregistrée en base : une copie de la base seule ne peut pas révéler vos clés d\'API.', 'smbb-wpcodetool'); ?></p>
                    </div>
                </header>
                <div class="smbb-codetool-section-body">
                    <?php if (!function_exists('sodium_crypto_secretbox')) : ?>
                        <p class="smbb-codetool-encryption-state is-bad"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><?php esc_html_e('Chiffrement indisponible : l\'extension PHP sodium est absente sur ce serveur.', 'smbb-wpcodetool'); ?></p>
                    <?php elseif ($source === Secrets::SOURCE_CONSTANT) : ?>
                        <p class="smbb-codetool-encryption-state is-good"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e('La constante SMBB_SECRET_KEY est définie dans wp-config.php. C\'est la configuration recommandée.', 'smbb-wpcodetool'); ?></p>
                    <?php elseif ($source === Secrets::SOURCE_SALTS) : ?>
                        <p class="smbb-codetool-encryption-state is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e('Le chiffrement utilise une clé dérivée des salts WordPress. Si ces salts sont régénérés (certains plugins de sécurité le font), les valeurs chiffrées devront être saisies à nouveau. Définissez SMBB_SECRET_KEY pour l\'éviter.', 'smbb-wpcodetool'); ?></p>
                    <?php else : ?>
                        <p class="smbb-codetool-encryption-state is-bad"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><?php esc_html_e('Aucune clé utilisable : définissez SMBB_SECRET_KEY dans wp-config.php.', 'smbb-wpcodetool'); ?></p>
                    <?php endif; ?>

                    <?php if (Secrets::available()) : ?>
                        <p class="description"><?php printf(esc_html__('Empreinte de la clé actuelle : %s', 'smbb-wpcodetool'), '<code>' . esc_html(Secrets::currentFingerprint()) . '</code>'); ?></p>
                    <?php endif; ?>

                    <?php if ($generated !== '') : ?>
                        <div class="smbb-codetool-encryption-key">
                            <p><strong><?php esc_html_e('Nouvelle clé, affichée une seule fois.', 'smbb-wpcodetool'); ?></strong> <?php esc_html_e('Ajoutez cette ligne dans wp-config.php, juste au-dessus de la ligne « C\'est tout, ne touchez pas à ce qui suit ! » (« That\'s all, stop editing! »), puis gardez-en une copie dans votre gestionnaire de mots de passe.', 'smbb-wpcodetool'); ?></p>
                            <div class="smbb-codetool-encryption-copy">
                                <input type="text" id="smbb-codetool-new-key" readonly value="<?php echo esc_attr("define( 'SMBB_SECRET_KEY', '" . $generated . "' );"); ?>" onfocus="this.select()">
                                <button type="button" class="button button-primary" data-smbb-copy="smbb-codetool-new-key" data-copied="<?php esc_attr_e('Copié', 'smbb-wpcodetool'); ?>"><?php esc_html_e('Copier', 'smbb-wpcodetool'); ?></button>
                            </div>
                            <p class="description"><?php esc_html_e('Les valeurs existantes sont rechiffrées automatiquement avec la nouvelle clé au prochain chargement d\'une page d\'admin. Si vous perdez cette clé, les clés d\'API devront être saisies à nouveau.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    <?php elseif (function_exists('sodium_crypto_secretbox')) : ?>
                        <p><a class="button" href="<?php echo esc_url(add_query_arg('generate', 1, $page_url)); ?>"><?php echo esc_html($source === Secrets::SOURCE_CONSTANT ? __('Générer une nouvelle clé', 'smbb-wpcodetool') : __('Générer une clé', 'smbb-wpcodetool')); ?></a></p>
                    <?php endif; ?>

                    <?php if ($source === Secrets::SOURCE_CONSTANT && $generated === '') : ?>
                        <p class="description"><?php esc_html_e('Pour changer de clé : déchiffrez toutes les valeurs ci-dessous, remplacez la constante dans wp-config.php, puis rechargez cette page.', 'smbb-wpcodetool'); ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="smbb-codetool-section smbb-codetool-encryption">
                <header class="smbb-codetool-section-header">
                    <span class="smbb-codetool-section-icon" aria-hidden="true"><span class="dashicons dashicons-database"></span></span>
                    <div class="smbb-codetool-section-heading">
                        <h3><?php esc_html_e('Secrets enregistrés', 'smbb-wpcodetool'); ?></h3>
                        <p class="description"><?php esc_html_e('Colonnes déclarées avec "secret": true dans les modèles de ressources, et réglages sensibles enregistrés en option (mot de passe SMTP, jeton Twilio…).', 'smbb-wpcodetool'); ?></p>
                    </div>
                </header>
                <div class="smbb-codetool-section-body">
                    <?php if (!$inventory) : ?>
                        <p><?php esc_html_e('Aucune ressource ne déclare encore de colonne secrète.', 'smbb-wpcodetool'); ?></p>
                    <?php else : ?>
                        <table class="widefat smbb-codetool-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Ressource', 'smbb-wpcodetool'); ?></th>
                                    <th><?php esc_html_e('Colonne', 'smbb-wpcodetool'); ?></th>
                                    <th><?php esc_html_e('Chiffré', 'smbb-wpcodetool'); ?></th>
                                    <th><?php esc_html_e('En clair', 'smbb-wpcodetool'); ?></th>
                                    <th><?php esc_html_e('Clé précédente', 'smbb-wpcodetool'); ?></th>
                                    <th><?php esc_html_e('Illisible', 'smbb-wpcodetool'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventory as $entry) : ?>
                                    <tr>
                                        <td><?php echo esc_html($entry['label']); ?></td>
                                        <td><code><?php echo esc_html($entry['column']); ?></code><?php echo isset($entry['option']) ? '<br><span class="description">' . esc_html(sprintf(__('option %s', 'smbb-wpcodetool'), $entry['option'])) . '</span>' : ''; ?><?php echo $entry['length'] && $entry['length'] < 400 ? ' <span class="smbb-codetool-badge is-warning" title="' . esc_attr__('Colonne trop courte pour des valeurs chiffrées : synchronisez le schéma.', 'smbb-wpcodetool') . '">' . esc_html(sprintf(__('%d caractères', 'smbb-wpcodetool'), $entry['length'])) . '</span>' : ''; ?></td>
                                        <td><?php echo self::count($entry['current'], 'is-success'); ?></td>
                                        <td><?php echo self::count($entry['plain'], 'is-warning'); ?></td>
                                        <td><?php echo self::count($entry['known'], 'is-warning'); ?></td>
                                        <td><?php echo self::count($entry['unknown'], 'is-error'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($totals['unknown'] > 0) : ?>
                            <p class="smbb-codetool-encryption-state is-bad"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><?php echo esc_html(sprintf(_n('%d valeur a été chiffrée avec une clé qui n\'est plus disponible : elle doit être saisie à nouveau dans son formulaire.', '%d valeurs ont été chiffrées avec une clé qui n\'est plus disponible : elles doivent être saisies à nouveau dans leurs formulaires.', $totals['unknown'], 'smbb-wpcodetool'), $totals['unknown'])); ?></p>
                        <?php endif; ?>

                        <form method="post" class="smbb-codetool-encryption-actions">
                            <?php wp_nonce_field(self::NONCE); ?>
                            <button type="submit" name="smbb_secrets_op" value="encrypt" class="button button-primary"<?php disabled(!Secrets::available() || ($totals['plain'] + $totals['known']) === 0); ?>><?php esc_html_e('Tout chiffrer', 'smbb-wpcodetool'); ?></button>
                            <button type="submit" name="smbb_secrets_op" value="decrypt" class="button" data-smbb-confirm-submit="<?php esc_attr_e('Enregistrer toutes ces clés en clair ? Ne le faites que pour changer la clé de chiffrement, puis rechiffrez.', 'smbb-wpcodetool'); ?>"<?php disabled(($totals['current'] + $totals['known']) === 0); ?>><?php esc_html_e('Tout déchiffrer', 'smbb-wpcodetool'); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <?php
    }

    /**
     * Traite les boutons Chiffrer / Déchiffrer (appelé sur admin_init).
     */
    public static function handle(AdminManager $manager)
    {
        if (!isset($_POST['smbb_secrets_op'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Vous n\'avez pas le droit de gérer le chiffrement.', 'smbb-wpcodetool'));
        }

        check_admin_referer(self::NONCE);
        $op = sanitize_key(wp_unslash($_POST['smbb_secrets_op']));
        $result = self::process($manager, $op === 'decrypt' ? 'decrypt' : 'encrypt');

        if ($op !== 'decrypt' && !$result['skipped']) {
            update_option(self::MIGRATED_OPTION, Secrets::currentFingerprint(), false);
        }

        wp_safe_redirect(add_query_arg(array(
            'page' => self::SLUG,
            'secrets_done' => $op === 'decrypt' ? 'decrypted' : 'encrypted',
            'secrets_count' => $result['changed'],
            'secrets_skipped' => $result['skipped'],
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Chiffrement automatique des valeurs en clair, une fois par clé : à la mise à jour
     * du plugin, puis après un changement de clé dans wp-config.php.
     */
    public static function autoMigrate(AdminManager $manager)
    {
        if (!Secrets::available() || get_option(self::MIGRATED_OPTION) === Secrets::currentFingerprint() || !current_user_can('manage_options') || get_transient('smbb_codetool_secrets_retry')) {
            return;
        }

        $result = self::process($manager, 'encrypt');

        if ($result['skipped']) {
            // Colonnes trop courtes : on réessaie dans une heure (après synchronisation du schéma).
            set_transient('smbb_codetool_secrets_retry', 1, HOUR_IN_SECONDS);
            return;
        }

        update_option(self::MIGRATED_OPTION, Secrets::currentFingerprint(), false);
    }

    /**
     * @return array{changed:int,skipped:int}
     */
    public static function process(AdminManager $manager, $op)
    {
        global $wpdb;

        $changed = 0;
        $skipped = 0;

        foreach (self::secretResources($manager) as $resource) {
            $table = $resource->tableName();
            $pk = $resource->primaryKey();
            $lengths = self::columnLengths($table);

            if ($lengths === null) {
                continue;
            }

            foreach ($resource->secretColumns() as $column) {
                if (!array_key_exists($column, $lengths)) {
                    continue;
                }

                $rows = $wpdb->get_results('SELECT `' . esc_sql($pk) . '` AS pk, `' . esc_sql($column) . '` AS v FROM `' . esc_sql($table) . "` WHERE `" . esc_sql($column) . "` IS NOT NULL AND `" . esc_sql($column) . "` <> ''", ARRAY_A);

                foreach (is_array($rows) ? $rows : array() as $row) {
                    $state = Secrets::state($row['v']);
                    $new = null;

                    if ($op === 'encrypt' && ($state === 'plain' || $state === 'known')) {
                        $new = Secrets::encrypt($row['v']);
                    } elseif ($op === 'decrypt' && ($state === 'current' || $state === 'known')) {
                        $new = Secrets::decrypt($row['v']);
                    }

                    if ($new === null || $new === $row['v']) {
                        continue;
                    }

                    if ($lengths[$column] > 0 && strlen($new) > $lengths[$column]) {
                        $skipped++;
                        continue;
                    }

                    $wpdb->update($table, array($column => $new), array($pk => $row['pk']));
                    $changed++;
                }
            }
        }

        // Secrets rangés dans des options (mot de passe SMTP, jeton Twilio…).
        $changed += \Smbb\WpCodeTool\Support\OptionSecrets::process($op);

        return array('changed' => $changed, 'skipped' => $skipped);
    }

    /**
     * Inventaire : une entrée par colonne secrète avec le compte de chaque état.
     */
    public static function inventory(AdminManager $manager)
    {
        global $wpdb;

        $inventory = array();

        foreach (self::secretResources($manager) as $resource) {
            $table = $resource->tableName();
            $lengths = self::columnLengths($table);

            foreach ($resource->secretColumns() as $column) {
                $entry = array(
                    'label' => $resource->pluralLabel(),
                    'column' => $column,
                    'length' => $lengths && isset($lengths[$column]) ? $lengths[$column] : 0,
                    'plain' => 0,
                    'current' => 0,
                    'known' => 0,
                    'unknown' => 0,
                    'too_short' => 0,
                );

                if ($lengths !== null && array_key_exists($column, $lengths)) {
                    $values = $wpdb->get_col('SELECT `' . esc_sql($column) . '` FROM `' . esc_sql($table) . "` WHERE `" . esc_sql($column) . "` IS NOT NULL AND `" . esc_sql($column) . "` <> ''");

                    foreach (is_array($values) ? $values : array() as $value) {
                        $state = Secrets::state($value);

                        if (isset($entry[$state])) {
                            $entry[$state]++;
                        }
                    }
                }

                $inventory[] = $entry;
            }
        }

        return array_merge($inventory, \Smbb\WpCodeTool\Support\OptionSecrets::inventory());
    }

    /**
     * @return ResourceDefinition[]
     */
    private static function secretResources(AdminManager $manager)
    {
        $resources = array();

        foreach ($manager->resources() as $resource) {
            if ($resource->storageType() === 'custom_table' && $resource->secretColumns()) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    /**
     * Longueur maximale des colonnes texte d'une table (0 = sans limite utile), null si la table n'existe pas.
     *
     * @return array<string,int>|null
     */
    private static function columnLengths($table)
    {
        global $wpdb;

        $columns = $wpdb->get_results('SHOW COLUMNS FROM `' . esc_sql($table) . '`', ARRAY_A);

        if (!is_array($columns) || !$columns) {
            return null;
        }

        $lengths = array();

        foreach ($columns as $column) {
            $lengths[$column['Field']] = preg_match('/^(?:var)?char\((\d+)\)/i', (string) $column['Type'], $match) ? (int) $match[1] : 0;
        }

        return $lengths;
    }

    private static function count($count, $tone)
    {
        return $count > 0 ? '<span class="smbb-codetool-badge ' . esc_attr($tone) . '">' . esc_html(number_format_i18n($count)) . '</span>' : '<span class="smbb-codetool-muted">0</span>';
    }

    private static function resultNotice()
    {
        if (empty($_GET['secrets_done'])) {
            return '';
        }

        $count = isset($_GET['secrets_count']) ? absint($_GET['secrets_count']) : 0;
        $skipped = isset($_GET['secrets_skipped']) ? absint($_GET['secrets_skipped']) : 0;
        $message = sanitize_key(wp_unslash($_GET['secrets_done'])) === 'decrypted'
            ? sprintf(_n('%d valeur déchiffrée.', '%d valeurs déchiffrées.', $count, 'smbb-wpcodetool'), $count)
            : sprintf(_n('%d valeur chiffrée.', '%d valeurs chiffrées.', $count, 'smbb-wpcodetool'), $count);
        $html = '<div class="smbb-codetool-notices"><div class="smbb-codetool-notice is-success"><div class="smbb-codetool-notice-body"><p class="smbb-codetool-notice-message">' . esc_html($message) . '</p></div></div>';

        if ($skipped > 0) {
            $html .= '<div class="smbb-codetool-notice is-error"><div class="smbb-codetool-notice-body"><p class="smbb-codetool-notice-message">'
                . esc_html(sprintf(_n('%d valeur ignorée : sa colonne est trop courte. Synchronisez le schéma puis réessayez.', '%d valeurs ignorées : leurs colonnes sont trop courtes. Synchronisez le schéma puis réessayez.', $skipped, 'smbb-wpcodetool'), $skipped))
                . '</p></div></div>';
        }

        return $html . '</div>';
    }
}
