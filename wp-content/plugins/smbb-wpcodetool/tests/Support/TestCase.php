<?php

namespace Smbb\WpCodeTool\Tests\Support;

abstract class TestCase
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function run()
    {
        $results = array();

        foreach (get_class_methods($this) as $method) {
            if (strpos($method, 'test') !== 0) {
                continue;
            }

            smbb_wpcodetool_tests_reset_environment();
            $label = get_class($this) . '::' . $method;

            try {
                $this->setUp();
                $this->{$method}();
                $results[] = array(
                    'label' => $label,
                    'success' => true,
                    'message' => '',
                );
            } catch (\Throwable $throwable) {
                $results[] = array(
                    'label' => $label,
                    'success' => false,
                    'message' => $throwable->getMessage(),
                );
            } finally {
                $this->tearDown();
            }
        }

        return $results;
    }

    protected function setUp()
    {
    }

    protected function tearDown()
    {
    }

    protected function assertTrue($condition, $message = '')
    {
        if ($condition !== true) {
            throw new TestFailure($message !== '' ? $message : 'Expected true.');
        }
    }

    protected function assertFalse($condition, $message = '')
    {
        if ($condition !== false) {
            throw new TestFailure($message !== '' ? $message : 'Expected false.');
        }
    }

    protected function assertSame($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            throw new TestFailure($message !== '' ? $message : 'Expected ' . $this->export($expected) . ', got ' . $this->export($actual) . '.');
        }
    }

    protected function assertEquals($expected, $actual, $message = '')
    {
        if ($expected != $actual) {
            throw new TestFailure($message !== '' ? $message : 'Expected ' . $this->export($expected) . ', got ' . $this->export($actual) . '.');
        }
    }

    protected function assertArrayHasKey($key, array $array, $message = '')
    {
        if (!array_key_exists($key, $array)) {
            throw new TestFailure($message !== '' ? $message : 'Missing array key ' . $this->export($key) . '.');
        }
    }

    protected function assertCount($expected, array $array, $message = '')
    {
        $actual = count($array);

        if ($expected !== $actual) {
            throw new TestFailure($message !== '' ? $message : 'Expected count ' . $expected . ', got ' . $actual . '.');
        }
    }

    private function export($value)
    {
        return var_export($value, true);
    }
}
