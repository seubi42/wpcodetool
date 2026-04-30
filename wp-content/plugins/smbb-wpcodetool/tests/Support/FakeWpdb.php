<?php

namespace Smbb\WpCodeTool\Tests\Support;

final class FakeWpdb
{
    public $prefix = 'wp_';
    public $last_error = '';
    private $tables = array();

    public function setTableExists($table_name, $exists)
    {
        $this->tables[(string) $table_name] = (bool) $exists;
    }

    public function get_charset_collate()
    {
        return 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%');
    }

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . addslashes((string) $value) . "'", $query);
    }

    public function get_var($sql)
    {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", (string) $sql, $matches) !== 1) {
            return null;
        }

        $table = stripslashes($matches[1]);
        $table = str_replace(array('\_', '\%'), array('_', '%'), $table);

        return !empty($this->tables[$table]) ? $table : null;
    }
}
