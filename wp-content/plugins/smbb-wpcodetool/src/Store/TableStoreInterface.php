<?php

namespace Smbb\WpCodeTool\Store;

defined('ABSPATH') || exit;

/**
 * Contrat minimal d'un store de ressource base sur une table custom.
 */
interface TableStoreInterface
{
    /**
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    public function list(array $args = array());

    /**
     * @param array<string,mixed> $args
     */
    public function count(array $args = array());

    /**
     * @param mixed $id
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function find($id, array $args = array());

    /**
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    public function search(array $args = array());

    /**
     * @param array<string,mixed> $data
     * @return int|string|false
     */
    public function create(array $data);

    /**
     * @param mixed $id
     * @param array<string,mixed> $data
     */
    public function update($id, array $data);

    /**
     * @param mixed $id
     */
    public function delete($id);

    public function lastError();
}
