<?php

namespace Smbb\WpCodeTool\Tests\Support;

use Smbb\WpCodeTool\Store\OptionStoreInterface;

final class FakeOptionStore implements OptionStoreInterface
{
    private $value;

    public function __construct(array $value = array())
    {
        $this->value = $value;
    }

    public function get()
    {
        return $this->value;
    }

    public function replace(array $data)
    {
        $this->value = $data;

        return true;
    }

    public function patch(array $data)
    {
        $this->value = array_replace_recursive($this->value, $data);

        return true;
    }

    public function delete()
    {
        $this->value = array();

        return true;
    }
}
