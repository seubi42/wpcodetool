<?php

namespace Smbb\WpCodeTool\Route;

defined('ABSPATH') || exit;

/**
 * Mini DSL inspire de TypeRocket pour declarer une route publique.
 */
final class PublicRouteBuilder
{
    private $registry;
    private $method = 'GET';

    public function __construct(PublicRouteRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function get()
    {
        return $this->method('GET');
    }

    public function post()
    {
        return $this->method('POST');
    }

    public function put()
    {
        return $this->method('PUT');
    }

    public function patch()
    {
        return $this->method('PATCH');
    }

    public function delete()
    {
        return $this->method('DELETE');
    }

    public function method($method)
    {
        $method = strtoupper((string) $method);
        $this->method = in_array($method, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'), true) ? $method : 'GET';

        return $this;
    }

    public function on($pattern, $callback)
    {
        return $this->registry->add($this->method, $pattern, $callback);
    }
}
