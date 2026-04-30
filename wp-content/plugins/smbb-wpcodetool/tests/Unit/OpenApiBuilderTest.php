<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Api\OpenApiBuilder;
use Smbb\WpCodeTool\Tests\Support\ResourceFactory;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class OpenApiBuilderTest extends TestCase
{
    public function testBuildIncludesResourceSchemasAndTokenPath()
    {
        $resource = ResourceFactory::table(array(
            'name' => 'orders',
            'label' => 'Order',
            'api' => array(
                'enabled' => true,
                'namespace' => 'partner/v1',
                'actions' => array(
                    'list' => array('enabled' => true),
                    'get' => array('enabled' => true),
                    'create' => array('enabled' => true),
                    'patch' => array('enabled' => true),
                    'put' => array('enabled' => true),
                    'delete' => array('enabled' => true),
                ),
            ),
        ));
        $builder = new OpenApiBuilder();

        $document = $builder->build('partner/v1', array($resource));

        $this->assertSame('3.0.3', $document['openapi']);
        $this->assertArrayHasKey('/orders', $document['paths']);
        $this->assertArrayHasKey('/orders/{id}', $document['paths']);
        $this->assertArrayHasKey('/token', $document['paths']);
        $this->assertArrayHasKey('OrdersItem', $document['components']['schemas']);
        $this->assertArrayHasKey('OrdersWrite', $document['components']['schemas']);
    }
}
