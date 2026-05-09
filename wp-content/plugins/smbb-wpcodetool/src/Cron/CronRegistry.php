<?php

namespace Smbb\WpCodeTool\Cron;

defined('ABSPATH') || exit;

/**
 * Registre partage : les plugins metier y deposent leurs taches cron.
 *
 * Exemple cote plugin :
 * $registry->register('mon_hook_cron', array(
 *     'label' => 'Ma tache',
 *     'schedule' => 'smbb_every_15_minutes',
 *     'callback' => array($service, 'run'),
 * ));
 */
final class CronRegistry
{
    private $tasks = array();

    public function register($hook, array $definition)
    {
        $task = new CronTask($hook, $definition);
        $this->tasks[$task->hook()] = $task;

        return $task;
    }

    /**
     * @return array<string,CronTask>
     */
    public function all()
    {
        return $this->tasks;
    }

    public function get($hook)
    {
        $hook = strtolower((string) $hook);
        $hook = preg_replace('/[^a-z0-9_]/', '_', $hook);
        $hook = trim((string) $hook, '_');

        return isset($this->tasks[$hook]) ? $this->tasks[$hook] : null;
    }
}
