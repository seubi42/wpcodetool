<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Support\RequestInput;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class RequestInputTest extends TestCase
{
    private $server = array();

    protected function setUp()
    {
        $this->server = $_SERVER;
    }

    protected function tearDown()
    {
        $_SERVER = $this->server;
    }

    public function testClientIpUsesFirstValidForwardedIp()
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'bad-value, 203.0.113.42, 10.0.0.2';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $input = new RequestInput();

        $this->assertSame('203.0.113.42', $input->get_client_ip());
    }

    public function testClientIpFallsBackToRemoteAddr()
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $input = new RequestInput();

        $this->assertSame('192.0.2.10', $input->get_client_ip());
    }

    public function testBearerTokenReadsAuthorizationHeader()
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token-123';
        $input = new RequestInput();

        $this->assertSame('test-token-123', $input->get_bearer_token());
    }

    public function testBearerTokenIgnoresOtherSchemes()
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc123';
        $input = new RequestInput();

        $this->assertSame('', $input->get_bearer_token());
    }
}
