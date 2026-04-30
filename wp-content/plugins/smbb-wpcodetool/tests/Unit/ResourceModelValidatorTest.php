<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Resource\ResourceModelValidator;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class ResourceModelValidatorTest extends TestCase
{
    public function testRejectsInvalidCustomTableDefinition()
    {
        $validator = new ResourceModelValidator();
        $errors = $validator->validate(array(
            'name' => 'orders',
            'storage' => array(
                'type' => 'custom_table',
                'primaryKey' => 'order_id',
            ),
            'columns' => array(
                'status' => array(
                    'type' => 'made_up',
                ),
            ),
        ), '', 'orders.json');

        $this->assertCount(2, $errors);
        $this->assertSame('orders.json', $errors[0]['file']);
        $this->assertTrue(strpos($errors[0]['message'], 'type de colonne') !== false || strpos($errors[1]['message'], 'type de colonne') !== false);
        $this->assertTrue(strpos($errors[0]['message'], 'cle primaire') !== false || strpos($errors[1]['message'], 'cle primaire') !== false);
    }

    public function testRejectsInvalidCustomApiRouteShape()
    {
        $validator = new ResourceModelValidator();
        $errors = $validator->validate(array(
            'name' => 'exports',
            'storage' => array(
                'type' => 'none',
            ),
            'api' => array(
                'enabled' => true,
                'custom' => array(
                    'download' => array(
                        'method' => 'TRACE',
                        'path' => 'exports/download',
                        'class' => '',
                        'callback' => '',
                    ),
                ),
            ),
        ), '', 'exports.json');

        $this->assertCount(4, $errors);
    }

    public function testAcceptsSimpleOptionModel()
    {
        $validator = new ResourceModelValidator();
        $errors = $validator->validate(array(
            'name' => 'settings',
            'storage' => array(
                'type' => 'option',
                'optionName' => 'sample_settings',
                'default' => array(
                    'enabled' => true,
                ),
            ),
            'admin' => array(
                'type' => 'settings_page',
            ),
        ), '', 'settings.json');

        $this->assertSame(array(), $errors);
    }
}
