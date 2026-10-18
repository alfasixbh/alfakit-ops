<?php

declare(strict_types=1);

use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\LogFile;
use AlfaSix\AlfakitOps\Pages\Logs;
use AlfaSix\AlfakitOps\Support\LogFiles;
use AlfaSix\AlfakitOps\Support\LogReader;
use AlfaSix\AlfakitOps\Tests\TestCase;

use function AlfaSix\AlfakitOps\Tests\contentBlock;
use function AlfaSix\AlfakitOps\Tests\inertiaGet;
use function AlfaSix\AlfakitOps\Tests\opsPlugin;
use function AlfaSix\AlfakitOps\Tests\opsUser;
use function AlfaSix\AlfakitOps\Tests\pageProps;
use function AlfaSix\AlfakitOps\Tests\writeLog;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travel;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('ops'));
    actingAs(opsUser());
});

function logLines(): string
{
    return implode("\n", [
        '[2026-10-14 09:00:00] local.INFO: User signed in {"user_id":1} []',
        '[2026-10-14 09:05:00] local.WARNING: Disk almost full',
        '[2026-10-14 09:10:00] production.ERROR: Payment failed {"exception":"[object] (RuntimeException(code: 0): Payment failed at /app/Pay.php:12)',
        '[stacktrace]',
        '#0 /app/Http/Controller.php(10): pay()',
        '#1 {main}',
        '"} ',
        '[2026-10-14T09:15:00.123456+00:00] local.DEBUG: Cache miss {"key":"users"} {"memory":"2 MB"}',
    ])."\n";
}

it('lists the log files of the directory, newest first', function (): void {
    writeLog('laravel.log', logLines());
    touch(writeLog('laravel-2026-10-13.log', 'x'), now()->subDay()->getTimestamp());
    writeLog('notes.txt', 'not a log');
    mkdir(TestCase::logDirectory().'/nested');
    writeLog('nested/deep.log', 'nested');

    $records = array_column(contentBlock('/ops-panel/ops/logs')['records'], null, 'key');

    expect(array_keys($records))->toBe(['laravel.log', 'laravel-2026-10-13.log'])
        ->and($records['laravel.log']['cells']['type']['state'])->toBe('Single')
        ->and($records['laravel-2026-10-13.log']['cells']['type']['state'])->toBe('Daily')
        ->and($records['laravel.log']['url'] ?? null)->toBe('http://localhost/ops-panel/ops/logs/laravel.log');
});

it('only resolves plain log file names inside the directory', function (string $name): void {
    writeLog('laravel.log', 'x');
    file_put_contents(dirname(TestCase::logDirectory()).'/outside.log', 'secret');

    expect(LogFiles::resolve($name))->toBeNull();

    inertiaGet('/ops-panel/ops/logs/'.rawurlencode($name))->assertNotFound();
})->with([
    'parent directory' => ['../outside.log'],
    'encoded parent' => ['..%2Foutside.log'],
    'absolute path' => ['/etc/passwd'],
    'dot file' => ['.env'],
    'other extension' => ['notes.txt'],
    'missing file' => ['missing.log'],
    'null byte' => ["laravel.log\0.txt"],
    'backslash' => ['..\\outside.log'],
]);

it('refuses symbolic links that leave the directory', function (): void {
    $outside = dirname(TestCase::logDirectory()).'/outside.log';
    file_put_contents($outside, 'secret');
    symlink($outside, TestCase::logDirectory().'/link.log');

    expect(LogFiles::resolve('link.log'))->toBeNull()
        ->and(LogFiles::all())->not->toHaveKey('link.log');

    inertiaGet('/ops-panel/ops/logs/link.log')->assertNotFound();
    @unlink($outside);
});

it('only lists the configured patterns of the configured directory', function (): void {
    writeLog('worker.txt', 'x');
    writeLog('laravel.log', 'x');
    opsPlugin()->logFiles(['*.txt', '../*']);

    expect(array_keys(LogFiles::all()))->toBe(['worker.txt'])
        ->and(opsPlugin()->getLogFilePatterns())->toBe(['*.txt']);
});

it('parses Monolog entries newest first', function (): void {
    $path = writeLog('laravel.log', logLines());

    $entries = array_values(LogReader::paginate($path, 1, 10)->items());

    expect($entries)->toHaveCount(4)
        ->and($entries[0])->toMatchArray(['level' => 'debug', 'channel' => 'local', 'date' => '2026-10-14T09:15:00.123456+00:00', 'message' => 'Cache miss'])
        ->and($entries[0]['context'])->toContain('"key": "users"')->toContain('"memory": "2 MB"')
        ->and($entries[1])->toMatchArray(['level' => 'error', 'channel' => 'production', 'message' => 'Payment failed'])
        ->and($entries[1]['context'])->toContain('[stacktrace]')->toContain('#1 {main}')
        ->and($entries[2])->toMatchArray(['level' => 'warning', 'message' => 'Disk almost full', 'context' => null])
        ->and($entries[3])->toMatchArray(['level' => 'info', 'message' => 'User signed in', 'offset' => 0])
        ->and(json_decode((string) $entries[3]['context'], true))->toBe(['user_id' => 1]);
});

it('filters by level and searches', function (): void {
    $path = writeLog('laravel.log', logLines());

    expect(array_column(LogReader::paginate($path, 1, 10, ['error', 'warning'])->items(), 'level'))->toBe(['error', 'warning'])
        ->and(array_column(LogReader::paginate($path, 1, 10, [], 'controller.php')->items(), 'message'))->toBe(['Payment failed'])
        ->and(LogReader::paginate($path, 1, 10, ['emergency'])->items())->toBe([]);

    alfakit()->testPage(LogFile::class, ['file' => 'laravel.log'], panel: 'ops')
        ->filterTable('level', ['values' => ['warning']])
        ->assertCountTableRecords(1);

    alfakit()->testPage(LogFile::class, ['file' => 'laravel.log'], panel: 'ops')
        ->searchTable('signed in')
        ->assertCountTableRecords(1);
});

it('pages through large files from the end without losing entries across chunks', function (): void {
    $lines = [];

    foreach (range(1, 3000) as $i) {
        $lines[] = sprintf('[2026-10-14 10:%02d:%02d] local.%s: Entry number %d %s', intdiv($i, 60) % 60, $i % 60, $i % 10 === 0 ? 'ERROR' : 'INFO', $i, str_repeat('·', $i % 97));

        if ($i % 10 === 0) {
            $lines[] = '#0 /app/trace.php('.$i.'): frame';
            $lines[] = '#1 {main}';
        }
    }

    $path = writeLog('big.log', implode("\n", $lines)."\n");

    expect(filesize($path))->toBeGreaterThan(LogReader::CHUNK_SIZE * 4);

    $page = LogReader::paginate($path, 1, 25);
    $messages = array_map(static fn (array $entry): string => strtok($entry['message'], ' ·').' '.explode(' ', $entry['message'])[2], array_values($page->items()));

    expect($page->hasMorePages())->toBeTrue()
        ->and(array_values($page->items())[0]['message'])->toStartWith('Entry number 3000')
        ->and(array_values($page->items())[0]['context'])->toBe("#0 /app/trace.php(3000): frame\n#1 {main}");

    $seen = [];

    for ($number = 1; ; $number++) {
        $page = LogReader::paginate($path, $number, 500);

        foreach ($page->items() as $entry) {
            $seen[] = (int) explode(' ', $entry['message'])[2];

            // Every entry can be read again on its own from its offset.
            if (count($seen) % 250 === 0) {
                expect(LogReader::entryAt($path, $entry['offset']))->toBe($entry);
            }
        }

        if (! $page->hasMorePages()) {
            break;
        }
    }

    expect($seen)->toBe(range(3000, 1))
        ->and(array_column(LogReader::paginate($path, 2, 10, ['error'])->items(), 'message'))->toHaveCount(10)
        ->and(LogReader::paginate($path, 2, 10, ['error'])->items()[array_key_first(LogReader::paginate($path, 2, 10, ['error'])->items())]['message'])->toStartWith('Entry number 2900');
});

it('keeps memory bounded on a huge single entry', function (): void {
    $path = writeLog('huge.log', "[2026-10-14 10:00:00] local.ERROR: Boom\n".str_repeat(str_repeat('t', 1000)."\n", 2000));

    $entry = array_values(LogReader::paginate($path, 1, 10)->items())[0];

    expect($entry['truncated'])->toBeTrue()
        ->and(strlen((string) $entry['context']))->toBeLessThanOrEqual(LogReader::MAX_ENTRY_SIZE)
        ->and($entry['message'])->toBe('Boom');
});

it('reads files that do not start with a header', function (): void {
    $path = writeLog('odd.log', "stray line\nanother\n[2026-10-14 10:00:00] local.INFO: Real entry\n");

    $entries = array_values(LogReader::paginate($path, 1, 10)->items());

    expect($entries)->toHaveCount(2)
        ->and($entries[1])->toMatchArray(['level' => null, 'message' => 'stray line', 'context' => 'another']);
});

it('opens a log file page and an entry', function (): void {
    writeLog('laravel.log', logLines());

    $props = pageProps('/ops-panel/ops/logs/laravel.log');
    $table = collect($props['content'])->firstWhere('type', 'Table');

    expect($props['title'])->toBe('laravel.log')
        ->and(json_encode($props['breadcrumbs']))->toContain('Logs')
        ->and($table['records'])->toHaveCount(4)
        ->and($table['pagination']['mode'] ?? null)->toBe('simple');

    $error = collect($table['records'])->firstWhere('cells.level.state', 'Error');

    $modal = alfakit()->testPage(LogFile::class, ['file' => 'laravel.log'], panel: 'ops')
        ->mountTableAction('view', $error['key'])
        ->getMountedActionModal();

    expect($modal['heading'])->toBe('Error')
        ->and(json_encode($modal['content']))->toContain('[stacktrace]');
});

it('downloads a log file through a signed link', function (): void {
    writeLog('laravel.log', logLines());

    $url = LogFile::getDownloadUrl('laravel.log');
    $traversal = LogFile::getDownloadUrl('../outside.log');

    expect($url)->toContain('/ops-panel/ops/logs/laravel.log/download?')->toContain('signature=');

    get($url)->assertOk()->assertDownload('laravel.log');

    get('/ops-panel/ops/logs/laravel.log/download')->assertForbidden();
    get($traversal)->assertNotFound();

    actingAs(opsUser('Eve Intruder'));
    get($url)->assertForbidden();

    actingAs(opsUser());
    opsPlugin()->authorizeActions(static fn (string $ability): bool => $ability !== OpsPlugin::ABILITY_DOWNLOAD_LOGS);
    get($url)->assertForbidden();

    opsPlugin()->authorizeActions(null);
    travel(10)->minutes();
    get($url)->assertForbidden();
});

it('empties and deletes log files', function (): void {
    $path = writeLog('laravel.log', logLines());
    $other = writeLog('worker.log', logLines());

    alfakit()->testPage(Logs::class, panel: 'ops')
        ->callTableAction('clear', 'laravel.log')
        ->assertNotified('laravel.log emptied');

    expect(filesize($path))->toBe(0);

    alfakit()->testPage(Logs::class, panel: 'ops')
        ->callTableAction('delete', 'laravel.log')
        ->assertNotified('laravel.log deleted');

    expect(file_exists($path))->toBeFalse();

    alfakit()->testPage(LogFile::class, ['file' => 'worker.log'], panel: 'ops')
        ->callAction('delete')
        ->assertNotified('worker.log deleted')
        ->assertRedirect('http://localhost/ops-panel/ops/logs');

    expect(file_exists($other))->toBeFalse();
});

it('does not delete logs without the ability', function (): void {
    $path = writeLog('laravel.log', logLines());
    opsPlugin()->authorizeActions(static fn (string $ability): bool => $ability !== OpsPlugin::ABILITY_DELETE_LOGS);

    alfakit()->testPage(Logs::class, panel: 'ops')
        ->assertTableActionHidden('delete', 'laravel.log')
        ->assertTableActionHidden('clear', 'laravel.log')
        ->assertTableActionVisible('download', 'laravel.log')
        ->callTableAction('delete', 'laravel.log');

    expect(file_exists($path))->toBeTrue();
});

it('explains a missing log directory', function (): void {
    opsPlugin()->logDirectory(TestCase::logDirectory().'/missing');

    $table = contentBlock('/ops-panel/ops/logs');

    expect($table['description'])->toContain('does not exist')
        ->and($table['records'])->toBe([]);
});
