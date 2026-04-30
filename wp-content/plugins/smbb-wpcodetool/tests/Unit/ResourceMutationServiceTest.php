<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Resource\ResourceMutationService;
use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Tests\Support\FakeOptionStore;
use Smbb\WpCodeTool\Tests\Support\FakeTableStore;
use Smbb\WpCodeTool\Tests\Support\ResourceFactory;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class ResourceMutationServiceTest extends TestCase
{
    public function testSaveTableAppliesManagedColumnsAndPersistsThroughInjectedStore()
    {
        $resource = ResourceFactory::table(array(
            'columns' => array(
                'created_at' => array(
                    'type' => 'datetime',
                    'managed' => 'create_datetime',
                ),
                'created_by' => array(
                    'type' => 'bigint',
                    'managed' => 'create_user',
                ),
            ),
        ));
        $store = new FakeTableStore(array(), 'id', 12);
        $service = new ResourceMutationService(new ResourceRuntime());

        $result = $service->saveTable($resource, array(
            'title' => 'Alpha',
        ), array(
            'store' => $store,
            'action' => 'test_create',
        ));

        $this->assertTrue(!empty($result['success']));
        $this->assertSame(12, $result['id']);
        $this->assertSame('Alpha', $result['payload']['title']);
        $this->assertSame('2026-04-20 10:30:00', $result['payload']['created_at']);
        $this->assertSame(7, $result['payload']['created_by']);
    }

    public function testSaveOptionCanMergeCurrentValues()
    {
        $resource = ResourceFactory::option(array(
            'storage' => array(
                'default' => array(
                    'profile' => array(
                        'name' => '',
                        'flags' => array(
                            'a' => false,
                            'b' => false,
                        ),
                    ),
                ),
            ),
        ));
        $store = new FakeOptionStore(array(
            'profile' => array(
                'name' => 'Alice',
                'flags' => array(
                    'a' => true,
                    'b' => false,
                ),
            ),
        ));
        $service = new ResourceMutationService(new ResourceRuntime());

        $result = $service->saveOption($resource, array(
            'profile' => array(
                'flags' => array(
                    'b' => true,
                ),
            ),
        ), array(
            'store' => $store,
            'merge_current' => true,
        ));

        $this->assertTrue(!empty($result['success']));
        $this->assertSame('Alice', $result['payload']['profile']['name']);
        $this->assertTrue($result['payload']['profile']['flags']['a']);
        $this->assertTrue($result['payload']['profile']['flags']['b']);
    }

    public function testDuplicateTableReturnsValidationFailureFromCallback()
    {
        $resource = ResourceFactory::table();
        $store = new FakeTableStore(array(
            array(
                'id' => 5,
                'title' => 'Alpha',
            ),
        ), 'id', 6);
        $service = new ResourceMutationService(new ResourceRuntime());

        $result = $service->duplicateTable($resource, 5, array(
            'store' => $store,
            'validation_callback' => function (array $data) {
                if ($data['title'] === 'Alpha') {
                    return array(
                        'title' => 'Already used in this test.',
                    );
                }

                return array();
            },
        ));

        $this->assertFalse(!empty($result['success']));
        $this->assertSame('validation', $result['reason']);
        $this->assertArrayHasKey('title', $result['errors']);
    }
}
