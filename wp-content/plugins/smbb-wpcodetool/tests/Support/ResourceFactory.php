<?php

namespace Smbb\WpCodeTool\Tests\Support;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

final class ResourceFactory
{
    public static function table(array $overrides = array())
    {
        $base = array(
            'name' => 'tests',
            'label' => 'Test',
            'storage' => array(
                'type' => 'custom_table',
                'table' => 'tests',
                'primaryKey' => 'id',
            ),
            'columns' => array(
                'id' => array(
                    'type' => 'bigint',
                    'primary' => true,
                    'autoIncrement' => true,
                ),
                'title' => array(
                    'type' => 'varchar',
                ),
            ),
            'api' => array(
                'enabled' => true,
                'actions' => array(
                    'patch' => array(
                        'enabled' => true,
                        'missingFields' => 'keep',
                        'nullFields' => 'set_null',
                    ),
                    'put' => array(
                        'enabled' => true,
                        'missingFields' => 'reject',
                        'nullFields' => 'set_null',
                    ),
                ),
            ),
        );

        return self::make(array_replace_recursive($base, $overrides));
    }

    public static function option(array $overrides = array())
    {
        $base = array(
            'name' => 'settings',
            'label' => 'Settings',
            'storage' => array(
                'type' => 'option',
                'optionName' => 'test_settings',
                'default' => array(
                    'enabled' => true,
                ),
            ),
            'api' => array(
                'enabled' => true,
                'actions' => array(
                    'patch' => array(
                        'enabled' => true,
                        'missingFields' => 'keep',
                    ),
                    'put' => array(
                        'enabled' => true,
                        'missingFields' => 'reject',
                    ),
                ),
            ),
        );

        return self::make(array_replace_recursive($base, $overrides));
    }

    public static function make(array $data)
    {
        $plugin_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'plugin';
        $model_file = $plugin_dir . DIRECTORY_SEPARATOR . 'codetool' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . (isset($data['name']) ? $data['name'] : 'resource') . '.json';

        return new ResourceDefinition($data, $plugin_dir, $model_file);
    }
}
