<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Api\ApiResourceRequestReader;
use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Tests\Support\ResourceFactory;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class ApiResourceRequestReaderTest extends TestCase
{
    public function testTableDataIgnoresManagedAndAutoIncrementColumns()
    {
        $resource = ResourceFactory::table(array(
            'columns' => array(
                'status' => array(
                    'type' => 'varchar',
                ),
                'updated_at' => array(
                    'type' => 'datetime',
                    'managed' => 'update_datetime',
                ),
            ),
        ));
        $reader = new ApiResourceRequestReader(new ResourceRuntime());
        $request = new ApiRequestReaderTestRequest(array(), array(
            'id' => 99,
            'title' => 'Hello',
            'status' => 'draft',
            'updated_at' => '2026-04-21 10:00:00',
        ));

        $data = $reader->tableData($resource, $request);

        $this->assertSame(array(
            'title' => 'Hello',
            'status' => 'draft',
        ), $data);
    }

    public function testQueryHelpersNormalizeRequestValues()
    {
        $resource = ResourceFactory::table(array(
            'name' => 'orders',
            'api' => array(
                'enabled' => true,
            ),
            'admin' => array(
                'list' => array(
                    'perPage' => 15,
                    'search' => array(
                        'enabled' => true,
                        'fields' => array('title', 'status'),
                    ),
                    'filters' => array(
                        'status' => array(
                            'operators' => array('eq', 'neq'),
                        ),
                    ),
                ),
            ),
            'columns' => array(
                'status' => array(
                    'type' => 'varchar',
                ),
            ),
        ));
        $reader = new ApiResourceRequestReader(new ResourceRuntime());
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token_123';
        $request = new ApiRequestReaderTestRequest(array(
            'search' => ' Alpha ',
            'filter' => array(
                'field' => 'status',
                'operator' => 'eq',
                'value' => 'draft',
            ),
            'per_page' => '120',
            'id' => '7',
        ));

        $this->assertSame('Alpha', $reader->tableSearchTerm($resource, $request));
        $this->assertSame(array(
            'field' => 'status',
            'operator' => 'eq',
            'value' => 'draft',
        ), $reader->tableFilterArgs($resource, $request));
        $this->assertSame(120, $reader->perPage($resource, $request));
        $this->assertSame(7, $reader->resourceId($resource, $request));
        $this->assertSame('token_123', $reader->bearerToken($request));
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
}

final class ApiRequestReaderTestRequest
{
    private $params;
    private $json;

    public function __construct(array $params = array(), array $json = array())
    {
        $this->params = $params;
        $this->json = $json;
    }

    public function get_param($key)
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : null;
    }

    public function get_json_params()
    {
        return $this->json;
    }

    public function get_body_params()
    {
        return array();
    }

    public function get_header($name)
    {
        unset($name);

        return '';
    }
}
