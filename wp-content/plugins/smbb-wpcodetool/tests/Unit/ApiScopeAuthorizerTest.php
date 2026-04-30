<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Api\ApiScopeAuthorizer;
use Smbb\WpCodeTool\Tests\Support\ResourceFactory;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class ApiScopeAuthorizerTest extends TestCase
{
    public function testNormalizeScopeListAcceptsTextareaAndJson()
    {
        $authorizer = new ApiScopeAuthorizer();

        $this->assertSame(
            array(
                'resource:orders:read',
                'namespace:partner/v1:write',
            ),
            $authorizer->normalizeScopeList("resource:orders:read\nnamespace:partner/v1:write")
        );

        $this->assertSame(
            array(
                'resource:orders:read',
            ),
            $authorizer->normalizeScopeList('["resource:orders:read"]')
        );
    }

    public function testClientAllowsMatchesResourceAndNamespaceScopes()
    {
        $authorizer = new ApiScopeAuthorizer();
        $resource = ResourceFactory::table(array(
            'name' => 'orders',
            'api' => array(
                'enabled' => true,
                'namespace' => 'partner/v1',
            ),
        ));

        $this->assertTrue($authorizer->clientAllows(array('scopes' => array('*')), $resource, 'delete'));
        $this->assertTrue($authorizer->clientAllows(array('scopes' => array('resource:orders:read')), $resource, 'get'));
        $this->assertFalse($authorizer->clientAllows(array('scopes' => array('resource:orders:read')), $resource, 'patch'));
        $this->assertTrue($authorizer->clientAllows(array('scopes' => array('namespace:partner/v1:write')), $resource, 'POST'));
        $this->assertFalse($authorizer->clientAllows(array('scopes' => array('namespace:partner/v1:write')), $resource, 'delete'));
    }
}
