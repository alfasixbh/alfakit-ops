<?php

declare(strict_types=1);

use AlfaSix\AlfakitOps\Pages\Batches;
use AlfaSix\AlfakitOps\Pages\Queues;
use AlfaSix\AlfakitOps\Support\BatchRepository;
use AlfaSix\AlfakitOps\Support\QueueMonitor;
use AlfaSix\AlfakitOps\Tests\Fixtures\Jobs\SendInvoice;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function AlfaSix\AlfakitOps\Tests\contentBlock;
use function AlfaSix\AlfakitOps\Tests\failJob;
use function AlfaSix\AlfakitOps\Tests\opsPlugin;
use function AlfaSix\AlfakitOps\Tests\opsUser;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitOps\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('ops'));
    actingAs(opsUser());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function batch(array $attributes = []): string
{
    $id = (string) Str::orderedUuid();

    DB::table('job_batches')->insert([
        'id' => $id,
        'name' => 'Import customers',
        'total_jobs' => 10,
        'pending_jobs' => 4,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => serialize([]),
        'created_at' => now()->subMinutes(5)->getTimestamp(),
        'cancelled_at' => null,
        'finished_at' => null,
        ...$attributes,
    ]);

    return $id;
}

it('counts pending, delayed and reserved jobs per queue', function (): void {
    $queue = Queue::connection('database');
    $queue->push(new SendInvoice, '', 'emails');
    $queue->push(new SendInvoice, '', 'emails');
    $queue->later(now()->addHour(), new SendInvoice, '', 'emails');
    $queue->push(new SendInvoice, '', 'payments');
    DB::table('jobs')->where('queue', 'payments')->update(['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);

    $rows = collect(QueueMonitor::rows())->keyBy('queue');

    expect($rows->keys()->all())->toBe(['default', 'emails', 'payments'])
        ->and($rows['emails'])->toMatchArray(['connection' => 'database', 'driver' => 'database', 'pending' => 2, 'delayed' => 1, 'reserved' => 0, 'error' => null, 'clearable' => true])
        ->and($rows['emails']['oldest'])->not->toBeNull()
        ->and($rows['payments'])->toMatchArray(['pending' => 0, 'reserved' => 1])
        ->and($rows['default']['pending'])->toBe(0)
        ->and(QueueMonitor::totalPending())->toBe(2);

    $table = contentBlock('/ops-panel/ops/queues');
    $cells = collect($table['records'])->firstWhere('cells.queue.state', 'emails')['cells'];

    expect($cells['pending']['state'])->toBe('2')
        ->and($table['pollingInterval'])->toBe('10s');
});

it('lists only the configured queues and survives unreachable connections', function (): void {
    config()->set('queue.connections.broken', ['driver' => 'database', 'connection' => 'missing', 'table' => 'jobs', 'queue' => 'default']);
    config()->set('queue.connections.sync', ['driver' => 'sync']);
    opsPlugin()->monitorQueues(['database' => ['emails'], 'broken' => 'default', 'sync' => ['default']]);

    $rows = array_values(QueueMonitor::rows());

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['connection' => 'database', 'queue' => 'emails', 'error' => null])
        ->and($rows[1]['connection'])->toBe('broken')
        ->and($rows[1]['error'])->not->toBeNull();

    alfakit()->testPage(Queues::class, panel: 'ops')
        ->assertTableActionHidden('clear', $rows[1]['id'])
        ->assertTableActionVisible('clear', $rows[0]['id']);
});

it('only asks Redis when a client is installed', function (): void {
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);

    expect(QueueMonitor::isMonitorable('redis'))->toBe(extension_loaded('redis') || class_exists(Predis\Client::class))
        ->and(QueueMonitor::isMonitorable('sync'))->toBeFalse()
        ->and(QueueMonitor::isMonitorable('unknown'))->toBeFalse();
});

it('clears a queue', function (): void {
    $queue = Queue::connection('database');
    $queue->push(new SendInvoice, '', 'emails');
    $queue->push(new SendInvoice, '', 'emails');
    $queue->push(new SendInvoice, '', 'payments');

    alfakit()->testPage(Queues::class, panel: 'ops')
        ->callTableAction('clear', QueueMonitor::key('database', 'emails'))
        ->assertNotified('2 jobs deleted from emails');

    expect(DB::table('jobs')->pluck('queue')->all())->toBe(['payments']);
});

it('denies clearing a queue without the ability', function (): void {
    opsPlugin()->authorizeActions(static fn (string $ability): bool => $ability !== AlfaSix\AlfakitOps\OpsPlugin::ABILITY_CLEAR_QUEUES);
    Queue::connection('database')->push(new SendInvoice, '', 'emails');

    alfakit()->testPage(Queues::class, panel: 'ops')
        ->assertTableActionHidden('clear', QueueMonitor::key('database', 'emails'))
        ->callTableAction('clear', QueueMonitor::key('database', 'emails'));

    expect(DB::table('jobs')->count())->toBe(1);
});

it('lists batches with their progress and status', function (): void {
    $running = batch();
    $finished = batch(['name' => 'Send newsletter', 'pending_jobs' => 0, 'finished_at' => now()->getTimestamp(), 'created_at' => now()->subMinutes(10)->getTimestamp()]);
    $failed = batch(['name' => 'Resize images', 'failed_jobs' => 2, 'created_at' => now()->subMinutes(20)->getTimestamp()]);
    $cancelled = batch(['name' => 'Old', 'cancelled_at' => now()->getTimestamp(), 'created_at' => now()->subMinutes(30)->getTimestamp()]);

    expect(BatchRepository::find($running))->toMatchArray(['progress' => 60, 'processed' => 6, 'status' => 'running'])
        ->and(BatchRepository::find($finished)['status'] ?? null)->toBe('finished')
        ->and(BatchRepository::find($failed)['status'] ?? null)->toBe('failed')
        ->and(BatchRepository::find($cancelled)['status'] ?? null)->toBe('cancelled')
        ->and(BatchRepository::runningCount())->toBe(2);

    $table = contentBlock('/ops-panel/ops/batches');
    $records = array_column($table['records'], null, 'key');

    expect(array_keys($records))->toBe([$running, $finished, $failed, $cancelled])
        ->and($records[$running]['cells']['progress'])->toBe(['state' => '60%', 'description' => '6 of 10 processed'])
        ->and($records[$failed]['cells']['status']['state'])->toBe('Has failures');

    alfakit()->testPage(Batches::class, panel: 'ops')
        ->filterTable('status', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCountTableRecords(1)
        ->assertTableActionVisible('retry', $failed);

    alfakit()->testPage(Batches::class, panel: 'ops')
        ->assertTableActionHidden('cancel', $cancelled)
        ->assertTableActionHidden('cancel', $finished)
        ->assertTableActionVisible('cancel', $running)
        ->assertTableActionHidden('retry', $running);
});

it('cancels a batch', function (): void {
    $id = batch();

    alfakit()->testPage(Batches::class, panel: 'ops')
        ->callTableAction('cancel', $id)
        ->assertNotified('Batch cancelled');

    expect(Bus::findBatch($id)?->cancelled())->toBeTrue();
});

it('retries the failed jobs of a batch', function (): void {
    $uuid = failJob('imports');
    $id = batch(['failed_jobs' => 1, 'failed_job_ids' => json_encode([$uuid])]);

    alfakit()->testPage(Batches::class, panel: 'ops')
        ->callTableAction('retry', $id)
        ->assertNotified('1 job pushed back onto its queue');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'imports')->count())->toBe(1);
});

it('explains when batching has no table', function (): void {
    config()->set('queue.batching.table', 'missing_batches');

    expect(contentBlock('/ops-panel/ops/batches')['description'])->toContain('Job batching');
});
