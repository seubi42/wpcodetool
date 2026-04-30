<?php

namespace Smbb\WpCodeTool\Tests\Support;

use Smbb\WpCodeTool\Store\TableStoreInterface;

final class FakeTableStore implements TableStoreInterface
{
    private $rows = array();
    private $primary_key;
    private $next_id;
    private $last_error = '';

    /**
     * @param array<int|string,array<string,mixed>> $rows
     */
    public function __construct(array $rows = array(), $primary_key = 'id', $next_id = 1)
    {
        $this->primary_key = (string) $primary_key;
        $this->next_id = (int) $next_id;

        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($this->primary_key, $row)) {
                continue;
            }

            $this->rows[(string) $row[$this->primary_key]] = $row;
        }
    }

    public function list(array $args = array())
    {
        unset($args);

        return array_values($this->rows);
    }

    public function count(array $args = array())
    {
        unset($args);

        return count($this->rows);
    }

    public function find($id, array $args = array())
    {
        unset($args);

        $key = (string) $id;

        return isset($this->rows[$key]) ? $this->rows[$key] : null;
    }

    public function search(array $args = array())
    {
        return $this->list($args);
    }

    public function create(array $data)
    {
        if (!array_key_exists($this->primary_key, $data)) {
            $data[$this->primary_key] = $this->next_id++;
        }

        $this->rows[(string) $data[$this->primary_key]] = $data;

        return $data[$this->primary_key];
    }

    public function update($id, array $data)
    {
        $key = (string) $id;

        if (!isset($this->rows[$key])) {
            return false;
        }

        $current = $this->rows[$key];
        $data[$this->primary_key] = $current[$this->primary_key];
        $this->rows[$key] = array_replace($current, $data);

        return true;
    }

    public function delete($id)
    {
        $key = (string) $id;

        if (!isset($this->rows[$key])) {
            return false;
        }

        unset($this->rows[$key]);

        return true;
    }

    public function lastError()
    {
        return $this->last_error;
    }
}
