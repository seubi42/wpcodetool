<?php

namespace Smbb\WpCodeTool\Store;

defined('ABSPATH') || exit;

/**
 * Contrat minimal d'un store de ressource base sur les options WordPress.
 */
interface OptionStoreInterface
{
    /**
     * @return array<string,mixed>
     */
    public function get();

    /**
     * @param array<string,mixed> $data
     */
    public function replace(array $data);

    /**
     * @param array<string,mixed> $data
     */
    public function patch(array $data);

    public function delete();
}
