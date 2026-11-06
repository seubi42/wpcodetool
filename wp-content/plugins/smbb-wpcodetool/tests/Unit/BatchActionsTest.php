<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Resource\ResourceRuntime;
use Smbb\WpCodeTool\Tests\Support\ResourceFactory;
use Smbb\WpCodeTool\Tests\Support\TestCase;

final class BatchActionsTestHooks
{
    public $calls = array();

    public function batchActions(array $context)
    {
        return array(
            'publish' => 'Publier',
            'archive' => array('label' => 'Archiver', 'confirm' => 'Archiver ?', 'danger' => true),
            'secret' => array('label' => 'Réservé', 'capability' => 'do_not_allow'),
            'duplicate' => false,
            'delete' => array('label' => 'Remplacer la suppression'),
            '' => 'Sans clé',
        );
    }

    public function handleBatch($action, array $ids, array $context)
    {
        $this->calls[] = array($action, $ids, $context['resource']);

        if ($action === 'archive') {
            return new \WP_Error('nope', 'Archivage impossible.');
        }

        return array('success' => true, 'message' => count($ids) . ' publiés.');
    }

    public function markDone(array $ids, array $context)
    {
        $this->calls[] = array('markDone', $ids, $context['batch_action']);

        return count($ids);
    }
}

final class BatchActionsTest extends TestCase
{
    public function testBuiltinActionsWithoutHooks()
    {
        $runtime = new ResourceRuntime();
        $actions = $runtime->batchActions(ResourceFactory::table());

        $this->assertSame(array('duplicate', 'delete'), array_keys($actions));
        $this->assertTrue($actions['delete']['builtin']);
        $this->assertTrue($actions['delete']['confirm'] !== '', 'La suppression demande confirmation.');
    }

    public function testHooksAddActionsFirstAndCanRemoveBuiltins()
    {
        $runtime = new ResourceRuntime();
        $resource = ResourceFactory::table(array(
            'name' => 'batch_hooks',
            'hooks' => array('class' => BatchActionsTestHooks::class),
        ));
        $actions = $runtime->batchActions($resource);

        $this->assertSame(array('publish', 'archive', 'delete'), array_keys($actions));
        $this->assertSame('Publier', $actions['publish']['label']);
        $this->assertFalse($actions['publish']['builtin']);
        $this->assertSame('Archiver ?', $actions['archive']['confirm']);
        $this->assertTrue($actions['archive']['danger']);
        $this->assertSame('Supprimer', $actions['delete']['label'], 'Une action intégrée ne peut pas être redéfinie.');
    }

    public function testCallBatchHookNormalizesResults()
    {
        $runtime = new ResourceRuntime();
        $resource = ResourceFactory::table(array(
            'name' => 'batch_hooks_call',
            'hooks' => array('class' => BatchActionsTestHooks::class),
        ));

        $ok = $runtime->callBatchHook($resource, 'publish', array('3', '4'));
        $this->assertTrue($ok['success']);
        $this->assertSame(2, $ok['count']);
        $this->assertSame('2 publiés.', $ok['message']);

        $error = $runtime->callBatchHook($resource, 'archive', array('3'));
        $this->assertFalse($error['success']);
        $this->assertSame('Archivage impossible.', $error['message']);
    }

    public function testJsonActionsComeFirstAndUseTheirMethod()
    {
        $runtime = new ResourceRuntime();
        $resource = ResourceFactory::table(array(
            'name' => 'batch_json',
            'hooks' => array('class' => BatchActionsTestHooks::class),
            'admin' => array('list' => array('batchActions' => array(
                'done' => array('label' => 'Terminer', 'method' => 'markDone', 'confirm' => 'Terminer ?'),
                'publish' => 'Publier depuis le JSON',
                'delete' => false,
            ))),
        ));
        $actions = $runtime->batchActions($resource);

        $this->assertSame(array('done', 'publish', 'archive'), array_keys($actions));
        $this->assertSame('Publier depuis le JSON', $actions['publish']['label'], 'Le JSON l\'emporte sur les hooks.');
        $this->assertSame('markDone', $actions['done']['method']);

        $done = $runtime->callBatchHook($resource, 'done', array('1', '2', '3'));
        $this->assertTrue($done['success']);
        $this->assertSame(3, $done['count']);

        $publish = $runtime->callBatchHook($resource, 'publish', array('1'));
        $this->assertSame('1 publiés.', $publish['message'], 'Sans method, handleBatch reçoit l\'action.');
    }

    public function testUnknownMethodFailsClearly()
    {
        $runtime = new ResourceRuntime();
        $resource = ResourceFactory::table(array(
            'name' => 'batch_json_missing',
            'hooks' => array('class' => BatchActionsTestHooks::class),
            'admin' => array('list' => array('batchActions' => array(
                'ghost' => array('label' => 'Fantôme', 'method' => 'nope'),
            ))),
        ));
        $result = $runtime->callBatchHook($resource, 'ghost', array('1'));

        $this->assertFalse($result['success']);
        $this->assertTrue(strpos($result['message'], 'nope') !== false);
    }

    public function testValidatorChecksBatchActions()
    {
        $validator = new \Smbb\WpCodeTool\Resource\ResourceModelValidator();
        $base = array(
            'name' => 'items',
            'storage' => array('type' => 'none'),
        );

        $ok = $validator->validate($base + array('admin' => array('list' => array('batchActions' => array(
            'publish' => array('label' => 'Publier', 'method' => 'publishItems', 'danger' => true),
            'archive' => 'Archiver',
            'duplicate' => false,
        )))), '', 'items.json');
        $this->assertCount(0, $ok);

        $bad = $validator->validate($base + array('admin' => array('list' => array('batchActions' => array(
            'Publish' => 'Majuscule',
            'archive' => array('method' => 'bad-name', 'color' => 'red'),
            'delete' => array('label' => 'Supprimer autrement'),
            'other' => false,
        )))), '', 'items.json');
        $this->assertCount(6, $bad);
    }

    public function testCallBatchHookWithoutHandler()
    {
        $runtime = new ResourceRuntime();
        $result = $runtime->callBatchHook(ResourceFactory::table(), 'publish', array('1'));

        $this->assertFalse($result['success']);
    }
}
