<?php

require_once __DIR__ . '/bootstrap.php';

$tests = array(
    new \Smbb\WpCodeTool\Tests\Unit\ResourceModelValidatorTest(),
    new \Smbb\WpCodeTool\Tests\Unit\ResourceMutationServiceTest(),
    new \Smbb\WpCodeTool\Tests\Unit\ApiWriteSemanticsTest(),
    new \Smbb\WpCodeTool\Tests\Unit\ApiArgsBuilderTest(),
    new \Smbb\WpCodeTool\Tests\Unit\ApiResourceRequestReaderTest(),
    new \Smbb\WpCodeTool\Tests\Unit\ApiScopeAuthorizerTest(),
    new \Smbb\WpCodeTool\Tests\Unit\OpenApiBuilderTest(),
    new \Smbb\WpCodeTool\Tests\Unit\PublicRouteRegistryTest(),
    new \Smbb\WpCodeTool\Tests\Unit\RequestInputTest(),
    new \Smbb\WpCodeTool\Tests\Unit\SchemaSynchronizerTest(),
);

$failures = array();
$passes = 0;

foreach ($tests as $test) {
    foreach ($test->run() as $result) {
        if ($result['success']) {
            $passes++;
            echo '[PASS] ' . $result['label'] . PHP_EOL;
            continue;
        }

        $failures[] = $result;
        echo '[FAIL] ' . $result['label'] . PHP_EOL;
        echo '       ' . $result['message'] . PHP_EOL;
    }
}

echo PHP_EOL . 'Passed: ' . $passes . PHP_EOL;
echo 'Failed: ' . count($failures) . PHP_EOL;

exit($failures ? 1 : 0);
