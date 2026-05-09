<?php

namespace Smbb\WpCodeTool\Cron;

defined('ABSPATH') || exit;

/**
 * Petite valeur objet pour decrire une tache cron declaree par un plugin SMBB.
 *
 * Le but est de garder le contrat public tres simple :
 * - un hook WordPress stable ;
 * - un libelle lisible dans l'admin ;
 * - une recurrence WordPress ;
 * - un callback metier a executer.
 */
final class CronTask
{
    private $hook;
    private $label;
    private $schedule;
    private $callback;
    private $description;

    public function __construct($hook, array $definition)
    {
        $this->hook = $this->normalizeHook($hook);
        $this->label = isset($definition['label']) ? (string) $definition['label'] : $this->hook;
        $this->schedule = isset($definition['schedule']) ? (string) $definition['schedule'] : 'hourly';
        $this->callback = isset($definition['callback']) ? $definition['callback'] : null;
        $this->description = isset($definition['description']) ? (string) $definition['description'] : '';
    }

    public function hook()
    {
        return $this->hook;
    }

    public function label()
    {
        return $this->label;
    }

    public function schedule()
    {
        return $this->schedule;
    }

    public function callback()
    {
        return $this->callback;
    }

    public function description()
    {
        return $this->description;
    }

    public function isRunnable()
    {
        return is_callable($this->callback);
    }

    private function normalizeHook($hook)
    {
        $hook = strtolower((string) $hook);
        $hook = preg_replace('/[^a-z0-9_]/', '_', $hook);
        $hook = trim((string) $hook, '_');

        return $hook !== '' ? $hook : 'smbb_cron_task';
    }
}
