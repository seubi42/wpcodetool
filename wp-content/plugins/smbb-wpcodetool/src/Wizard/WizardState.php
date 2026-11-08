<?php

namespace Smbb\WpCodeTool\Wizard;

defined('ABSPATH') || exit;

/**
 * Avancement d'un assistant, dans l'option smbb_codetool_wizard_<nom> :
 * étapes faites et passées (avec la date), données gardées par étape, fin, bandeau masqué.
 */
final class WizardState
{
    const PREFIX = 'smbb_codetool_wizard_';

    /** @var string */
    private $name;

    /** @var array<string,mixed> */
    private $value;

    public function __construct($name)
    {
        $this->name = sanitize_key((string) $name);
        $stored = get_option(self::PREFIX . $this->name, array());
        $this->value = array_merge(array(
            'done' => array(),
            'skipped' => array(),
            'data' => array(),
            'current' => '',
            'started_at' => 0,
            'finished_at' => 0,
            'hidden_until' => 0,
        ), is_array($stored) ? $stored : array());

        foreach (array('done', 'skipped', 'data') as $key) {
            if (!is_array($this->value[$key])) {
                $this->value[$key] = array();
            }
        }
    }

    public function isDone($key)
    {
        return isset($this->value['done'][$key]);
    }

    public function isSkipped($key)
    {
        return isset($this->value['skipped'][$key]);
    }

    public function markDone($key)
    {
        $this->value['done'][$key] = time();
        unset($this->value['skipped'][$key]);
        $this->start();
    }

    public function markSkipped($key)
    {
        if (!$this->isDone($key)) {
            $this->value['skipped'][$key] = time();
        }

        $this->start();
    }

    public function data($key, $default = null)
    {
        return array_key_exists($key, $this->value['data']) ? $this->value['data'][$key] : $default;
    }

    public function setData($key, $value)
    {
        $this->value['data'][$key] = $value;
    }

    public function current()
    {
        return (string) $this->value['current'];
    }

    public function setCurrent($key)
    {
        $this->value['current'] = (string) $key;
    }

    public function started()
    {
        return (int) $this->value['started_at'] > 0;
    }

    private function start()
    {
        if (!$this->started()) {
            $this->value['started_at'] = time();
        }
    }

    public function finished()
    {
        return (int) $this->value['finished_at'] > 0;
    }

    public function finish()
    {
        $this->value['finished_at'] = time();
        $this->start();
    }

    /**
     * Reprendre depuis le début : les étapes restent cochées, l'assistant n'est plus « fini ».
     */
    public function reopen()
    {
        $this->value['finished_at'] = 0;
        $this->value['hidden_until'] = 0;
        $this->value['current'] = '';
    }

    public function hidden()
    {
        return (int) $this->value['hidden_until'] > time();
    }

    public function hideFor($days)
    {
        $this->value['hidden_until'] = time() + max(1, (int) $days) * DAY_IN_SECONDS;
    }

    public function save()
    {
        update_option(self::PREFIX . $this->name, $this->value, false);
    }

    /**
     * Efface tout l'avancement.
     */
    public static function reset($name)
    {
        delete_option(self::PREFIX . sanitize_key((string) $name));
    }
}
