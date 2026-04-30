<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Schema\SchemaSynchronizer;
use Smbb\WpCodeTool\Tests\Support\FakeWpdb;
use Smbb\WpCodeTool\Tests\Support\ResourceFactory;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class SchemaSynchronizerTest extends TestCase
{
    protected function setUp()
    {
        global $wpdb;

        $wpdb = new FakeWpdb();
    }

    public function testStatusReportsMissingWhenTableDoesNotExist()
    {
        global $wpdb;

        $resource = ResourceFactory::table();
        $wpdb->setTableExists($resource->tableName(), false);
        $synchronizer = new SchemaSynchronizer();

        $status = $synchronizer->status($resource);

        $this->assertSame('missing', $status['state']);
        $this->assertSame($resource->tableName(), $status['table']);
    }

    public function testStatusReportsNeedsUpdateWhenStoredHashDiffers()
    {
        global $wpdb;

        $resource = ResourceFactory::table();
        $wpdb->setTableExists($resource->tableName(), true);
        $sql = (new \Smbb\WpCodeTool\Schema\SchemaBuilder())->createTableSql($resource);
        $GLOBALS['smbb_test_options'][SchemaSynchronizer::OPTION_HASHES] = array(
            $resource->name() => array(
                'hash' => md5($sql . '--old'),
            ),
        );
        $synchronizer = new SchemaSynchronizer();

        $status = $synchronizer->status($resource);

        $this->assertSame('needs_update', $status['state']);
    }

    public function testApplyMarksSchemaAsAppliedOnSuccess()
    {
        global $wpdb;

        $resource = ResourceFactory::table();
        $wpdb->setTableExists($resource->tableName(), false);
        $GLOBALS['smbb_test_dbdelta_result'] = array(
            'Created table ' . $resource->tableName(),
        );
        $synchronizer = new SchemaSynchronizer();

        $result = $synchronizer->apply($resource);

        $this->assertTrue(!empty($result['success']));
        $this->assertCount(1, $GLOBALS['smbb_test_dbdelta_calls']);
        $this->assertArrayHasKey(SchemaSynchronizer::OPTION_HASHES, $GLOBALS['smbb_test_options']);
        $this->assertArrayHasKey($resource->name(), $GLOBALS['smbb_test_options'][SchemaSynchronizer::OPTION_HASHES]);
    }
}
