<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Api\ApiWriteSemantics;
use Smbb\WpCodeTool\Tests\Support\ResourceFactory;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class ApiWriteSemanticsTest extends TestCase
{
    public function testPatchKeepsCurrentFieldsByDefault()
    {
        $resource = ResourceFactory::table(array(
            'columns' => array(
                'status' => array(
                    'type' => 'varchar',
                ),
            ),
        ));
        $semantics = new ApiWriteSemantics();

        list($data, $errors) = $semantics->prepareTableUpdateData($resource, array(
            'title' => 'Alpha',
            'status' => 'draft',
        ), array(
            'title' => 'Beta',
        ), 'patch');

        $this->assertSame(array(), $errors);
        $this->assertSame('Beta', $data['title']);
        $this->assertSame('draft', $data['status']);
    }

    public function testPutRejectsMissingWritableFieldsWhenConfigured()
    {
        $resource = ResourceFactory::table(array(
            'columns' => array(
                'status' => array(
                    'type' => 'varchar',
                ),
            ),
        ));
        $semantics = new ApiWriteSemantics();

        list($data, $errors) = $semantics->prepareTableUpdateData($resource, array(
            'title' => 'Alpha',
            'status' => 'draft',
        ), array(
            'title' => 'Beta',
        ), 'put');

        $this->assertSame('Beta', $data['title']);
        $this->assertArrayHasKey('status', $errors);
    }

    public function testSetNullModeFillsMissingFieldsAndRejectsNullWhenConfigured()
    {
        $resource = ResourceFactory::table(array(
            'columns' => array(
                'status' => array(
                    'type' => 'varchar',
                ),
            ),
            'api' => array(
                'actions' => array(
                    'put' => array(
                        'enabled' => true,
                        'missingFields' => 'set_null',
                        'nullFields' => 'reject',
                    ),
                ),
            ),
        ));
        $semantics = new ApiWriteSemantics();

        list($data, $errors) = $semantics->prepareTableUpdateData($resource, array(
            'title' => 'Alpha',
            'status' => 'draft',
        ), array(
            'title' => null,
        ), 'put');

        $this->assertSame(null, $data['status']);
        $this->assertArrayHasKey('title', $errors);
    }

    public function testOptionMergeDecisionRespectsModeConfiguration()
    {
        $resource = ResourceFactory::option(array(
            'api' => array(
                'actions' => array(
                    'put' => array(
                        'enabled' => true,
                        'missingFields' => 'keep',
                    ),
                ),
            ),
        ));
        $semantics = new ApiWriteSemantics();

        $this->assertTrue($semantics->shouldMergeOptionCurrent($resource, 'patch'));
        $this->assertTrue($semantics->shouldMergeOptionCurrent($resource, 'put'));
        $this->assertTrue($semantics->shouldMergeOptionCurrent($resource, 'create'));
    }
}
