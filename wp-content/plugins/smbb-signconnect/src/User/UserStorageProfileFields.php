<?php

namespace Smbb\SignConnect\User;

use Smbb\SignConnect\Repository\StorageRepository;
use Smbb\SignConnect\Repository\StampRepository;

defined('ABSPATH') || exit;

final class UserStorageProfileFields
{
    const META_KEY = 'smbb_signconnect_storage_id';
    const STAMP_META_KEY = 'smbb_signconnect_stamp_id';

    private $storages;
    private $stamps;

    public function __construct(StorageRepository $storages = null, StampRepository $stamps = null)
    {
        $this->storages = $storages ?: new StorageRepository();
        $this->stamps = $stamps ?: new StampRepository();
    }

    public function hooks()
    {
        add_action('show_user_profile', array($this, 'render'));
        add_action('edit_user_profile', array($this, 'render'));
        add_action('personal_options_update', array($this, 'save'));
        add_action('edit_user_profile_update', array($this, 'save'));
    }

    public function render($user)
    {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $selected = (int) get_user_meta($user->ID, self::META_KEY, true);
        $selected_stamp = (int) get_user_meta($user->ID, self::STAMP_META_KEY, true);
        $storages = $this->storages->allActive();
        $stamps = $this->stamps->allActive();
        ?>
        <h2><?php esc_html_e('SignConnect', 'smbb-signconnect'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="smbb-signconnect-storage-id"><?php esc_html_e('Default storage', 'smbb-signconnect'); ?></label></th>
                <td>
                    <?php wp_nonce_field('smbb_signconnect_user_storage_' . $user->ID, 'smbb_signconnect_user_storage_nonce'); ?>
                    <select name="smbb_signconnect_storage_id" id="smbb-signconnect-storage-id">
                        <option value="0"><?php esc_html_e('No storage selected', 'smbb-signconnect'); ?></option>
                        <?php foreach ($storages as $storage) : ?>
                            <option value="<?php echo esc_attr((string) $storage['id']); ?>"<?php selected($selected, (int) $storage['id']); ?>>
                                <?php echo esc_html((string) $storage['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Storage used by the [signconnect_post] form for this user.', 'smbb-signconnect'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smbb-signconnect-stamp-id"><?php esc_html_e('Sender stamp', 'smbb-signconnect'); ?></label></th>
                <td>
                    <select name="smbb_signconnect_stamp_id" id="smbb-signconnect-stamp-id">
                        <option value="0"><?php esc_html_e('No stamp selected', 'smbb-signconnect'); ?></option>
                        <?php foreach ($stamps as $stamp) : ?>
                            <option value="<?php echo esc_attr((string) $stamp['id']); ?>"<?php selected($selected_stamp, (int) $stamp['id']); ?>>
                                <?php echo esc_html((string) $stamp['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Stamp applied when a document contains a sender stamp field.', 'smbb-signconnect'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save($user_id)
    {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        $nonce = isset($_POST['smbb_signconnect_user_storage_nonce']) ? (string) $_POST['smbb_signconnect_user_storage_nonce'] : '';

        if (!wp_verify_nonce($nonce, 'smbb_signconnect_user_storage_' . $user_id)) {
            return;
        }

        $storage_id = isset($_POST['smbb_signconnect_storage_id']) ? absint($_POST['smbb_signconnect_storage_id']) : 0;
        $stamp_id = isset($_POST['smbb_signconnect_stamp_id']) ? absint($_POST['smbb_signconnect_stamp_id']) : 0;

        if ($storage_id > 0) {
            update_user_meta($user_id, self::META_KEY, $storage_id);
        } else {
            delete_user_meta($user_id, self::META_KEY);
        }

        if ($stamp_id > 0) {
            update_user_meta($user_id, self::STAMP_META_KEY, $stamp_id);
        } else {
            delete_user_meta($user_id, self::STAMP_META_KEY);
        }
    }
}
