<?php

declare(strict_types=1);

use AlfaSix\AlfakitOps\Pages\FailedJobs;
use AlfaSix\AlfakitOps\Support\FailedJobRepository;
use AlfaSix\AlfakitOps\Support\Redactor;
use AlfaSix\AlfakitOps\Support\SerializedValue;
use AlfaSix\AlfakitOps\Tests\Fixtures\Jobs\SendInvoice;
use Illuminate\Support\Facades\DB;

use function AlfaSix\AlfakitOps\Tests\contentBlock;
use function AlfaSix\AlfakitOps\Tests\failJob;
use function AlfaSix\AlfakitOps\Tests\opsUser;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

uses(AlfaSix\AlfakitOps\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('ops'));
    actingAs(opsUser());
});

function failedJobsPage(): AlfaSix\Alfakit\Testing\PageTester
{
    return alfakit()->testPage(FailedJobs::class, panel: 'ops');
}

it('lists failed jobs newest first with their job, queue and exception', function (): void {
    travelTo(now()->subHour());
    $old = failJob('emails', message: 'SMTP down');
    travelTo(now()->addHour());
    $new = failJob('payments');

    $table = contentBlock('/ops-panel/ops/failed-jobs');
    $records = array_column($table['records'], null, 'key');

    expect(array_keys($records))->toBe([$new, $old])
        ->and($records[$new]['cells']['job'])->toBe(['state' => SendInvoice::class, 'description' => 'RuntimeException: Payment gateway timed out'])
        ->and($records[$new]['cells']['queue']['state'])->toBe('payments')
        ->and($table['pollingInterval'])->toBe('10s');
});

it('shows the failed job count in the navigation', function (): void {
    expect(FailedJobs::getNavigationBadge())->toBeNull()
        ->and(FailedJobs::getNavigationBadgeColor())->toBeNull();

    failJob();
    failJob();

    expect(FailedJobs::getNavigationBadge())->toBe('2')
        ->and(FailedJobs::getNavigationBadgeColor())->toBe('danger');
});

it('searches and filters by queue, connection, job and date', function (): void {
    $email = failJob('emails', message: 'SMTP down');
    $payment = failJob('payments', new SendInvoice(7));
    DB::table('failed_jobs')->where('uuid', $payment)->update(['failed_at' => now()->subDays(10)]);

    failedJobsPage()->searchTable('SMTP')->assertCanSeeTableRecords([$email])->assertCountTableRecords(1);
    failedJobsPage()->filterTable('queue', 'payments')->assertCanSeeTableRecords([$payment])->assertCountTableRecords(1);
    failedJobsPage()->filterTable('connection', 'database')->assertCountTableRecords(2);
    failedJobsPage()->filterTable('job', SendInvoice::class)->assertCountTableRecords(2);
    failedJobsPage()->filterTable('job', 'App\\Jobs\\Other')->assertCountTableRecords(0);
    failedJobsPage()->filterTable('failed_at', ['from' => now()->subDays(2)->toDateString()])->assertCanSeeTableRecords([$email])->assertCountTableRecords(1);
    failedJobsPage()->filterTable('failed_at', ['until' => now()->subDays(5)->toDateString()])->assertCanSeeTableRecords([$payment])->assertCountTableRecords(1);

    expect(FailedJobRepository::queueOptions())->toBe(['emails' => 'emails', 'payments' => 'payments'])
        ->and(FailedJobRepository::jobOptions())->toBe([SendInvoice::class => SendInvoice::class]);
});

it('shows the exception and the payload with sensitive values redacted', function (): void {
    $uuid = failJob();

    $modal = failedJobsPage()->mountTableAction('view', $uuid)->getMountedActionModal();
    $content = json_encode($modal['content']);

    expect($modal['heading'])->toBe(SendInvoice::class)
        ->and($content)->toContain('Payment gateway timed out')
        ->and($content)->toContain('[redacted]')
        ->and($content)->toContain('\"invoice\": 42')
        ->and($content)->not->toContain('s3cr3t')
        ->and($content)->not->toContain('tok_live_123')
        ->and($content)->not->toContain('hunter2')
        // `redactKeys(['card_holder'])` on the panel's plugin.
        ->and($content)->not->toContain('A. Lovelace');
});

it('parses serialized commands without unserializing them', function (): void {
    $value = SerializedValue::parse(serialize(new SendInvoice(9)));

    expect($value)->toMatchArray([
        SerializedValue::CLASS_KEY => SendInvoice::class,
        'invoice' => 9,
        'apiToken' => 'tok_live_123',
        'password' => 's3cr3t',
    ])
        ->and(SerializedValue::parse('O:8:"stdClass":1:{s:1:"a";'))->toBeNull()
        ->and(SerializedValue::parse('a:1:{i:0;b:1;}'))->toBe([true])
        ->and(SerializedValue::parse('s:3:"abc";trailing'))->toBeNull();
});

it('redacts keys word by word', function (): void {
    $redactor = new Redactor(['password', 'token', 'api_key']);

    expect($redactor->isSensitive('password'))->toBeTrue()
        ->and($redactor->isSensitive('password_confirmation'))->toBeTrue()
        ->and($redactor->isSensitive('apiToken'))->toBeTrue()
        ->and($redactor->isSensitive('X-API-KEY'))->toBeTrue()
        ->and($redactor->isSensitive('tokenizer'))->toBeFalse()
        ->and($redactor->isSensitive('className'))->toBeFalse()
        ->and($redactor->redact(['user' => ['name' => 'Ada', 'password' => 'x'], 'tokens' => 1]))->toBe(['user' => ['name' => 'Ada', 'password' => '[redacted]'], 'tokens' => 1]);
});

it('hides a command it cannot read when it mentions a sensitive key', function (): void {
    $payload = json_encode(['displayName' => 'X', 'data' => ['command' => 'O:1:"X":1:{s:8:"password";s:3:"abc"']]);

    expect(FailedJobRepository::presentPayload((string) $payload))->toContain('[redacted]')->not->toContain('abc');
});

it('retries one failed job', function (): void {
    $uuid = failJob('emails');
    $other = failJob('emails');

    failedJobsPage()
        ->callTableAction('retry', $uuid)
        ->assertNotified('1 job pushed back onto its queue');

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe([$other])
        ->and(DB::table('jobs')->where('queue', 'emails')->count())->toBe(1);
});

it('retries and forgets selected failed jobs', function (): void {
    $a = failJob();
    $b = failJob();
    $c = failJob();

    failedJobsPage()->callTableBulkAction('retrySelected', [$a, $b])->assertNotified('2 jobs pushed back onto their queues');

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe([$c])
        ->and(DB::table('jobs')->count())->toBe(2);

    failedJobsPage()->callTableBulkAction('forgetSelected', [$c])->assertNotified('1 failed job forgotten');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(2);
});

it('retries every failed job', function (): void {
    failJob('a');
    failJob('b');

    failedJobsPage()->callAction('retryAll')->assertNotified('2 jobs pushed back onto their queues');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(2);
});

it('forgets one failed job and flushes them all', function (): void {
    $uuid = failJob();
    failJob();
    failJob();

    failedJobsPage()->callTableAction('forget', $uuid)->assertNotified('1 failed job forgotten');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->count())->toBe(2);

    failedJobsPage()->callAction('flush')->assertNotified('2 failed jobs forgotten');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('hides the retry all and flush actions without failed jobs', function (): void {
    failedJobsPage()->assertActionHidden('retryAll')->assertActionHidden('flush');
});

it('selects every matching failed job across pages for bulk actions', function (): void {
    foreach (range(1, 12) as $i) {
        failJob($i % 2 === 0 ? 'even' : 'odd');
    }

    $keys = FailedJobRepository::keys(null, ['queue' => ['value' => 'even']]);

    expect($keys)->toHaveCount(6)
        ->and(FailedJobRepository::paginate(null, [], null, null, 2, 5)->items())->toHaveCount(5)
        ->and(FailedJobRepository::paginate(null, [], null, null, 3, 5)->total())->toBe(12);
});

it('reads other failers through all()', function (): void {
    config()->set('queue.failed', ['driver' => 'file', 'path' => storage_path('framework/testing/failed-jobs.json')]);
    app()->forgetInstance('queue.failer');
    @unlink(storage_path('framework/testing/failed-jobs.json'));

    $uuid = failJob('files');
    failJob('other');

    expect(FailedJobRepository::usesDatabase())->toBeFalse()
        ->and(FailedJobRepository::count())->toBe(2)
        ->and(array_keys(FailedJobRepository::paginate(null, ['queue' => ['value' => 'files']], null, null, 1, 10)->items()))->toBe([$uuid])
        ->and(FailedJobRepository::paginate('Payment', [], null, null, 1, 10)->total())->toBe(2)
        ->and(FailedJobRepository::find($uuid)['queue'] ?? null)->toBe('files');

    FailedJobRepository::forget([$uuid]);

    expect(FailedJobRepository::count())->toBe(1);

    @unlink(storage_path('framework/testing/failed-jobs.json'));
});

it('explains when there is no failed job table', function (): void {
    config()->set('queue.failed.table', 'missing_failed_jobs');

    $table = contentBlock('/ops-panel/ops/failed-jobs');

    expect($table['description'])->toContain('No failed job store')
        ->and($table['records'])->toBe([]);
});
