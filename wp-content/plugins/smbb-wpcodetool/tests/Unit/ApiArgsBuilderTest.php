<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Api\ApiArgsBuilder;
use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Tests\Support\ResourceFactory;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class ApiArgsBuilderTest extends TestCase
{
    public function testTableListArgsRestrictOrderAndOrderBy()
    {
        $resource = ResourceFactory::table(array(
            'columns' => array(
                'status' => array(
                    'type' => 'varchar',
                ),
            ),
        ));
        $builder = new ApiArgsBuilder(new ResourceRuntime());
        $args = $builder->tableListArgs($resource);

        $this->assertTrue(call_user_func($args['orderby']['validate_callback'], 'title'));
        $this->assertFalse(call_user_func($args['orderby']['validate_callback'], 'unknown'));
        $this->assertSame('desc', call_user_func($args['order']['sanitize_callback'], 'DESC'));
        $this->assertTrue(call_user_func($args['order']['validate_callback'], 'asc'));
        $this->assertFalse(call_user_func($args['order']['validate_callback'], 'sideways'));
    }

    public function testResourceIdArgFollowsPrimaryKeyType()
    {
        $numeric_resource = ResourceFactory::table();
        $string_resource = ResourceFactory::table(array(
            'storage' => array(
                'primaryKey' => 'code',
            ),
            'columns' => array(
                'id' => array(
                    'type' => 'bigint',
                ),
                'code' => array(
                    'type' => 'varchar',
                    'primary' => true,
                ),
            ),
        ));
        $builder = new ApiArgsBuilder(new ResourceRuntime());

        $numeric_arg = $builder->resourceIdArg($numeric_resource);
        $string_arg = $builder->resourceIdArg($string_resource);

        $this->assertTrue(call_user_func($numeric_arg['id']['validate_callback'], '12'));
        $this->assertFalse(call_user_func($numeric_arg['id']['validate_callback'], '0'));
        $this->assertSame('\\d+', $builder->resourceIdPattern($numeric_resource));
        $this->assertTrue(call_user_func($string_arg['code']['validate_callback'], 'alpha-01'));
        $this->assertFalse(call_user_func($string_arg['code']['validate_callback'], ''));
        $this->assertSame('[^\/]+', $builder->resourceIdPattern($string_resource));
    }

    public function testTableWriteArgsRequireWritableFieldsOnRejectPut()
    {
        $resource = ResourceFactory::table(array(
            'columns' => array(
                'status' => array(
                    'type' => 'varchar',
                ),
                'created_at' => array(
                    'type' => 'datetime',
                    'managed' => 'create_datetime',
                ),
            ),
        ));
        $builder = new ApiArgsBuilder(new ResourceRuntime());

        $patch_args = $builder->tableWriteArgs($resource, 'patch');
        $put_args = $builder->tableWriteArgs($resource, 'put');

        $this->assertFalse($patch_args['title']['required']);
        $this->assertFalse($patch_args['status']['required']);
        $this->assertTrue($put_args['id']['required']);
        $this->assertTrue($put_args['title']['required']);
        $this->assertTrue($put_args['status']['required']);
        $this->assertFalse(isset($put_args['created_at']));
        $this->assertTrue(call_user_func($put_args['status']['validate_callback'], 'draft'));
        $this->assertFalse(call_user_func($put_args['status']['validate_callback'], array('draft')));
    }

    public function testCustomRouteArgsMergePathAndSchemaRules()
    {
        $resource = ResourceFactory::table();
        $builder = new ApiArgsBuilder(new ResourceRuntime());
        $args = $builder->customRouteArgs($resource, array(
            'path' => '/exports/(?P<item_id>\\d+)/(?P<slug>[^\\/]+)',
            'args' => array(
                'mode' => array(
                    'type' => 'string',
                    'required' => true,
                    'enum' => array('full', 'compact'),
                    'sanitize' => 'key',
                    'default' => 'full',
                ),
                'page' => array(
                    'type' => 'integer',
                    'minimum' => 1,
                ),
                'email' => array(
                    'type' => 'string',
                    'sanitize' => 'email',
                    'pattern' => '.+@.+',
                ),
            ),
        ));

        $this->assertTrue(call_user_func($args['item_id']['validate_callback'], '0'));
        $this->assertFalse(call_user_func($args['item_id']['validate_callback'], 'abc'));
        $this->assertTrue(call_user_func($args['slug']['validate_callback'], 'hello-world'));
        $this->assertFalse(call_user_func($args['slug']['validate_callback'], ''));
        $this->assertSame('sanitize_key', $args['mode']['sanitize_callback']);
        $this->assertSame('full', $args['mode']['default']);
        $this->assertTrue(call_user_func($args['mode']['validate_callback'], 'compact'));
        $this->assertFalse(call_user_func($args['mode']['validate_callback'], 'verbose'));
        $this->assertTrue(call_user_func($args['page']['validate_callback'], '2'));
        $this->assertFalse(call_user_func($args['page']['validate_callback'], '0'));
        $this->assertSame('sanitize_email', $args['email']['sanitize_callback']);
        $this->assertTrue(call_user_func($args['email']['validate_callback'], 'dev@example.com'));
        $this->assertFalse(call_user_func($args['email']['validate_callback'], 'invalid'));
    }
}
