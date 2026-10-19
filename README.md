# AlfaKit Ops

<p><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-white.svg"><img src="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

> This is a read-only split of the [AlfaKit monorepo](https://github.com/alfasixbh/alfakit/tree/main/packages/plugins/ops). Please open issues and pull requests there.

An operations toolkit for [AlfaKit](https://alfakit.dev) panels: failed jobs (search, filters, a redacted payload viewer, retry, forget, flush), queues and job batches, the scheduler (frequencies in words, next run in the panel's timezone, last run, "Run now"), a log viewer that pages huge files from the end, cache and maintenance mode tasks, and system information. It renders in React, Vue and Svelte with AlfaKit's built-in components and needs no other Composer package.

## Installation

```bash
composer require alfasix/alfakit-ops
```

## Register

```php
use AlfaSix\AlfakitOps\OpsPlugin;

$panel->plugin(
    OpsPlugin::make()
        ->authorize(fn (User $user): bool => $user->is_admin)
        ->navigationGroup('System'),
);
```

**Deny by default:** without `authorize()`, only users the `viewAlfakitOps` gate allows can see the pages.

```php
Gate::define('viewAlfakitOps', fn (User $user): bool => $user->is_admin);
```

## Options

```php
OpsPlugin::make()
    ->authorizeActions(fn (string $ability, User $user): bool => $ability !== OpsPlugin::ABILITY_MAINTENANCE_MODE || $user->is_owner)
    ->readOnly(fn (): bool => app()->isProduction())
    ->requiresPasswordConfirmation()
    ->failedJobs()->queues()->batches()->scheduler()->logs()->maintenance(false)
    ->redactKeys(['iban'])
    ->monitorQueues(['redis' => ['default', 'emails']])
    ->runScheduledTasksOnQueue(queue: 'ops')
    ->logDirectory(storage_path('logs'))
    ->dashboardWidget()
    ->allowOnTenantPanels();
```

See the [documentation](https://alfakit.dev/docs/plugins/ops) for every option, the abilities and the security notes.

## Testing

```bash
vendor/bin/pest --test-directory=packages/plugins packages/plugins/ops
```

## License

MIT. See [LICENSE.md](LICENSE.md).
