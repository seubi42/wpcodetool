<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Route\PublicRouteRegistry;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class PublicRouteRegistryTest extends TestCase
{
    public function testWildcardPatternPassesCapturedSegments()
    {
        $registry = new PublicRouteRegistry('/tmp/plugin', '/tmp/plugin/codetool/routes/public.php');
        $registry->route()->get()->on('api/extractdocument/*/*', function ($or, $version) {
            return array($or, $version);
        });

        $routes = $registry->routes();
        $params = array();

        $this->assertTrue(
            count($routes) === 1
            && $routes[0]->matches('GET', '/api/extractdocument/ABC/2', $params)
            && $params === array('ABC', '2'),
            'Public route wildcard patterns capture URL segments.'
        );
    }

    public function testMethodMustMatch()
    {
        $registry = new PublicRouteRegistry();
        $registry->route()->post()->on('api/login', function () {
            return true;
        });

        $routes = $registry->routes();
        $params = array();

        $this->assertFalse(
            $routes[0]->matches('GET', 'api/login', $params),
            'Public routes only match the declared HTTP method.'
        );
    }

    public function testWildcardInsideSegmentCapturesOnlyVariablePart()
    {
        $registry = new PublicRouteRegistry();
        $registry->route()->get()->on('preview-images/*.jpg', function ($id) {
            return $id;
        });

        $routes = $registry->routes();
        $params = array();

        $this->assertTrue($routes[0]->matches('GET', 'preview-images/front-42.jpg', $params));
        $this->assertSame(array('front-42'), $params);
    }

    public function testInvalidCallbackIsIgnored()
    {
        $registry = new PublicRouteRegistry();
        $registry->route()->get()->on('api/broken', 'missing_function_name');

        $this->assertSame(
            0,
            count($registry->routes()),
            'Public routes ignore non-callable callbacks.'
        );
    }
}
