<?php

namespace Smbb\WpCodeTool\Cron;

defined('ABSPATH') || exit;

/**
 * Orchestrateur cron CodeTool.
 *
 * Il fait trois choses :
 * - expose un point de declaration pour les plugins SMBB ;
 * - programme les evenements WP-Cron si besoin ;
 * - affiche un recap admin avec un bouton "lancer maintenant".
 */
final class CronManager
{
    private $registry;
    private $collected = false;
    private $state_option = 'smbb_wpcodetool_cron_task_state';

    public function hooks()
    {
        add_filter('cron_schedules', array($this, 'cronSchedules'));
        add_action('init', array($this, 'registerTasks'));
        add_action('admin_menu', array($this, 'registerAdminMenu'), 40);
        add_action('admin_post_smbb_wpcodetool_run_cron_task', array($this, 'handleRunNow'));
    }

    public function cronSchedules(array $schedules)
    {
        if (!isset($schedules['smbb_every_15_minutes'])) {
            $schedules['smbb_every_15_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 minutes (SMBB)', 'smbb-wpcodetool'),
            );
        }

        return $schedules;
    }

    public function registerTasks()
    {
        foreach ($this->tasks() as $task) {
            if ($task->isRunnable()) {
                add_action($task->hook(), function () use ($task) {
                    $this->runTask($task);
                });
            }

            if (!wp_next_scheduled($task->hook())) {
                wp_schedule_event(time() + 60, $task->schedule(), $task->hook());
            }
        }
    }

    public function registerAdminMenu()
    {
        add_submenu_page(
            'smbb-wpcodetool',
            __('Cron tasks', 'smbb-wpcodetool'),
            __('Cron tasks', 'smbb-wpcodetool'),
            'manage_options',
            'smbb-wpcodetool-cron-tasks',
            array($this, 'renderAdminPage')
        );
    }

    public function handleRunNow()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run cron tasks.', 'smbb-wpcodetool'));
        }

        $hook = isset($_POST['hook']) ? sanitize_key((string) wp_unslash($_POST['hook'])) : '';
        check_admin_referer('smbb_wpcodetool_run_cron_task_' . $hook);

        $task = $this->registry()->get($hook);
        $notice_type = 'error';
        $notice_message = __('Cron task not found.', 'smbb-wpcodetool');

        if ($task) {
            try {
                $result = $this->runTask($task);
                $notice_type = 'success';
                $notice_message = sprintf(
                    /* translators: %s: cron task label. */
                    __('Cron task "%s" has been executed.', 'smbb-wpcodetool'),
                    $task->label()
                );

                if ($this->resultSummary($result) !== '') {
                    $notice_message .= ' ' . $this->resultSummary($result);
                }
            } catch (\Throwable $exception) {
                $notice_message = $exception->getMessage();
            }
        }

        set_transient($this->noticeKey(), array(
            'type' => $notice_type,
            'message' => $notice_message,
        ), 60);

        wp_safe_redirect(admin_url('admin.php?page=smbb-wpcodetool-cron-tasks'));
        exit;
    }

    public function renderAdminPage()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view cron tasks.', 'smbb-wpcodetool'));
        }

        $tasks = $this->tasks();
        $state = $this->state();
        $schedules = wp_get_schedules();
        $notice = $this->pullNotice();
        ?>
        <div class="wrap smbb-codetool">
            <div class="smbb-codetool-page-intro">
                <div class="smbb-codetool-page-header">
                    <div class="smbb-codetool-page-header-main">
                        <span class="smbb-codetool-page-icon" aria-hidden="true">
                            <span class="dashicons dashicons-clock"></span>
                        </span>
                        <div class="smbb-codetool-page-heading">
                            <h1 class="smbb-codetool-page-title"><?php esc_html_e('Cron tasks', 'smbb-wpcodetool'); ?></h1>
                            <p class="smbb-codetool-page-subtitle"><?php esc_html_e('Recap des taches declarees par les plugins SMBB.', 'smbb-wpcodetool'); ?></p>
                        </div>
                    </div>
                </div>

                <?php if ($notice) : ?>
                    <div class="smbb-codetool-notices">
                        <div class="smbb-codetool-notice is-<?php echo esc_attr($notice['type'] === 'success' ? 'success' : 'error'); ?>">
                            <div class="smbb-codetool-notice-body">
                                <p class="smbb-codetool-notice-message"><?php echo esc_html((string) $notice['message']); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <table class="widefat striped smbb-codetool-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Tache', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Cron', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Derniere execution', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Prochaine execution', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Etat', 'smbb-wpcodetool'); ?></th>
                        <th><?php esc_html_e('Lancer maintenant', 'smbb-wpcodetool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$tasks) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e('Aucune tache cron SMBB declaree.', 'smbb-wpcodetool'); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($tasks as $task) : ?>
                        <?php
                        $task_state = isset($state[$task->hook()]) && is_array($state[$task->hook()]) ? $state[$task->hook()] : array();
                        $next = wp_next_scheduled($task->hook());
                        $schedule = isset($schedules[$task->schedule()]) ? $schedules[$task->schedule()] : null;
                        $last_finished = isset($task_state['last_finished']) ? (string) $task_state['last_finished'] : '';
                        $last_status = isset($task_state['last_status']) ? (string) $task_state['last_status'] : '';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($task->label()); ?></strong>
                                <br><code><?php echo esc_html($task->hook()); ?></code>
                                <?php if ($task->description() !== '') : ?>
                                    <p class="description"><?php echo esc_html($task->description()); ?></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?php echo esc_html($task->schedule()); ?></code>
                                <?php if ($schedule && isset($schedule['display'])) : ?>
                                    <br><span class="description"><?php echo esc_html((string) $schedule['display']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($last_finished !== '' ? $this->formatDate($last_finished) : '-'); ?></td>
                            <td><?php echo esc_html($next ? $this->formatTimestamp((int) $next) : '-'); ?></td>
                            <td>
                                <?php if (!$task->isRunnable()) : ?>
                                    <span class="smbb-codetool-status is-error"><?php esc_html_e('Callback absent', 'smbb-wpcodetool'); ?></span>
                                <?php elseif (!$next) : ?>
                                    <span class="smbb-codetool-status is-warning"><?php esc_html_e('Non planifie', 'smbb-wpcodetool'); ?></span>
                                <?php elseif ($last_status === 'error') : ?>
                                    <span class="smbb-codetool-status is-error"><?php esc_html_e('Derniere execution en erreur', 'smbb-wpcodetool'); ?></span>
                                <?php else : ?>
                                    <span class="smbb-codetool-status is-success"><?php esc_html_e('Actif', 'smbb-wpcodetool'); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($task_state['last_error'])) : ?>
                                    <p class="description"><?php echo esc_html((string) $task_state['last_error']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="smbb_wpcodetool_run_cron_task">
                                    <input type="hidden" name="hook" value="<?php echo esc_attr($task->hook()); ?>">
                                    <?php wp_nonce_field('smbb_wpcodetool_run_cron_task_' . $task->hook()); ?>
                                    <button type="submit" class="button button-primary" <?php disabled(!$task->isRunnable()); ?>>
                                        <?php esc_html_e('Lancer', 'smbb-wpcodetool'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function runTask(CronTask $task)
    {
        $this->markStarted($task);

        try {
            if (!$task->isRunnable()) {
                throw new \RuntimeException(__('Cron callback is not callable.', 'smbb-wpcodetool'));
            }

            $result = call_user_func($task->callback());
            $this->markFinished($task, 'success', $result, '');

            return $result;
        } catch (\Throwable $exception) {
            $this->markFinished($task, 'error', null, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @return array<string,CronTask>
     */
    private function tasks()
    {
        return $this->registry()->all();
    }

    private function registry()
    {
        if ($this->collected && $this->registry) {
            return $this->registry;
        }

        $this->registry = new CronRegistry();

        /**
         * Point d'extension public : chaque plugin SMBB peut declarer ici ses crons.
         *
         * @param CronRegistry $registry
         */
        do_action('smbb_wpcodetool_register_cron_tasks', $this->registry);

        $this->collected = true;

        return $this->registry;
    }

    private function markStarted(CronTask $task)
    {
        $state = $this->state();
        $state[$task->hook()] = array_merge(
            isset($state[$task->hook()]) && is_array($state[$task->hook()]) ? $state[$task->hook()] : array(),
            array(
                'last_started' => current_time('mysql'),
                'last_status' => 'running',
                'last_error' => '',
            )
        );

        update_option($this->state_option, $state, false);
    }

    private function markFinished(CronTask $task, $status, $result, $error)
    {
        $state = $this->state();
        $state[$task->hook()] = array_merge(
            isset($state[$task->hook()]) && is_array($state[$task->hook()]) ? $state[$task->hook()] : array(),
            array(
                'last_finished' => current_time('mysql'),
                'last_status' => $status,
                'last_result' => $this->resultSummary($result),
                'last_error' => (string) $error,
            )
        );

        update_option($this->state_option, $state, false);
    }

    private function state()
    {
        $state = get_option($this->state_option, array());

        return is_array($state) ? $state : array();
    }

    private function resultSummary($result)
    {
        if (is_array($result)) {
            $parts = array();

            foreach ($result as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $parts[] = $key . ': ' . (string) $value;
                }
            }

            return implode(', ', $parts);
        }

        if (is_scalar($result)) {
            return (string) $result;
        }

        return '';
    }

    private function formatTimestamp($timestamp)
    {
        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s', (int) $timestamp);
        }

        return date_i18n('Y-m-d H:i:s', (int) $timestamp);
    }

    private function formatDate($date)
    {
        $timestamp = strtotime((string) $date);

        if (!$timestamp) {
            return (string) $date;
        }

        return $this->formatTimestamp($timestamp);
    }

    private function noticeKey()
    {
        return 'smbb_wpcodetool_cron_notice_' . get_current_user_id();
    }

    private function pullNotice()
    {
        $notice = get_transient($this->noticeKey());

        if ($notice !== false) {
            delete_transient($this->noticeKey());
        }

        return is_array($notice) ? $notice : null;
    }
}
