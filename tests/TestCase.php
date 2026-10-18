<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitOps\AlfakitOpsServiceProvider;
use AlfaSix\AlfakitOps\Tests\Fixtures\Panels\OpsPanelProvider;
use AlfaSix\AlfakitOps\Tests\Fixtures\Panels\OpsTenantPanelProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

abstract class TestCase extends AlfakitTestCase
{
    /**
     * The running test (Pest binds test closures to it).
     */
    public static function current(): static
    {
        $test = \Pest\TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new RuntimeException('No '.static::class.' is running.');
        }

        return $test;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The default authorisation: only Ada may see the operations pages.
        Gate::define('viewAlfakitOps', static fn (Authenticatable $user): bool => $user->getAttribute('name') === 'Ada Admin');

        File::deleteDirectory(self::logDirectory());
        File::ensureDirectoryExists(self::logDirectory());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(self::logDirectory());

        if (app()->isDownForMaintenance()) {
            \Illuminate\Support\Facades\Artisan::call('up');
        }

        parent::tearDown();
    }

    public static function logDirectory(): string
    {
        return storage_path('framework/testing/ops-logs');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AlfakitOpsServiceProvider::class,
            OpsPanelProvider::class,
            OpsTenantPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', TenantUser::class);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs']);
        $app['config']->set('queue.batching', ['database' => 'testing', 'table' => 'job_batches']);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }
}
