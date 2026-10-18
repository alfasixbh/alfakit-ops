<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Tests\Fixtures\Jobs\SendInvoice;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function inertiaGet(string $url): TestResponse
{
    return \Pest\Laravel\get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
}

/**
 * @return array<string, mixed>
 */
function pageProps(string $url): array
{
    $response = inertiaGet($url);
    $response->assertOk();

    return $response->json('props.page');
}

/**
 * The first content block of a page with the given type.
 *
 * @return array<string, mixed>
 */
function contentBlock(string $url, string $type = 'Table'): array
{
    $block = collect(pageProps($url)['content'])->firstWhere('type', $type);

    return is_array($block) ? $block : throw new RuntimeException("No {$type} block on {$url}.");
}

function opsUser(string $name = 'Ada Admin'): TenantUser
{
    return TenantUser::query()->create(['name' => $name, 'email' => uniqid('user', true).'@example.com', 'password' => 'password']);
}

function opsPlugin(string $panel = 'ops'): OpsPlugin
{
    $plugin = alfakit()->getPanel($panel)->getPlugin(OpsPlugin::ID);

    return $plugin instanceof OpsPlugin ? $plugin : throw new RuntimeException('The ops plugin is not registered.');
}

/**
 * Log a failed job as the worker does (`queue.failer`), with a serialized command that holds
 * secrets. Returns its UUID (the `database-uuids` failer's key).
 */
function failJob(string $queue = 'default', ?object $command = null, string $message = 'Payment gateway timed out', string $connection = 'database'): string
{
    $command ??= new SendInvoice;
    $uuid = (string) Str::uuid();
    $payload = [
        'uuid' => $uuid,
        'displayName' => $command::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => null,
        'maxExceptions' => null,
        'failOnTimeout' => false,
        'backoff' => null,
        'timeout' => null,
        'retryUntil' => null,
        'data' => ['commandName' => $command::class, 'command' => serialize($command)],
        'attempts' => 3,
    ];

    app('queue.failer')->log($connection, $queue, json_encode($payload, JSON_UNESCAPED_UNICODE), new RuntimeException($message));

    return $uuid;
}

/**
 * Write a log file into the test log directory.
 */
function writeLog(string $name, string $contents): string
{
    $path = TestCase::logDirectory().'/'.$name;
    File::put($path, $contents);

    return $path;
}
