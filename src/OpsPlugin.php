<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitOps\Clusters\Operations;
use AlfaSix\AlfakitOps\Pages\Batches;
use AlfaSix\AlfakitOps\Pages\FailedJobs;
use AlfaSix\AlfakitOps\Pages\LogFile;
use AlfaSix\AlfakitOps\Pages\Logs;
use AlfaSix\AlfakitOps\Pages\Maintenance;
use AlfaSix\AlfakitOps\Pages\Overview;
use AlfaSix\AlfakitOps\Pages\Queues;
use AlfaSix\AlfakitOps\Pages\Schedule;
use AlfaSix\AlfakitOps\Widgets\SystemInfoWidget;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * An operations toolkit for a panel: failed jobs, queues and batches, the scheduler, a log
 * viewer, cache and maintenance tasks and system information, grouped in an "Operations"
 * cluster.
 *
 * Nothing is visible until you allow it: pass `authorize()` or define the
 * `viewAlfakitOps` gate.
 *
 * ```php
 * $panel->plugin(
 *     OpsPlugin::make()
 *         ->authorize(fn (User $user): bool => $user->is_admin)
 *         ->navigationGroup('System'),
 * );
 * ```
 */
class OpsPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'ops';

    /** The gate checked when no `authorize()` callback is set. */
    public const GATE = 'viewAlfakitOps';

    // Features (`->failedJobs(false)`…).
    public const FEATURE_OVERVIEW = 'overview';

    public const FEATURE_FAILED_JOBS = 'failed-jobs';

    public const FEATURE_QUEUES = 'queues';

    public const FEATURE_BATCHES = 'batches';

    public const FEATURE_SCHEDULER = 'scheduler';

    public const FEATURE_LOGS = 'logs';

    public const FEATURE_MAINTENANCE = 'maintenance';

    // Abilities passed to `authorizeActions()`.
    public const ABILITY_RETRY_FAILED_JOBS = 'retry-failed-jobs';

    public const ABILITY_FORGET_FAILED_JOBS = 'forget-failed-jobs';

    public const ABILITY_FLUSH_FAILED_JOBS = 'flush-failed-jobs';

    public const ABILITY_CLEAR_QUEUES = 'clear-queues';

    public const ABILITY_CANCEL_BATCHES = 'cancel-batches';

    public const ABILITY_RETRY_BATCHES = 'retry-batches';

    public const ABILITY_RUN_SCHEDULED_TASKS = 'run-scheduled-tasks';

    public const ABILITY_DOWNLOAD_LOGS = 'download-logs';

    public const ABILITY_DELETE_LOGS = 'delete-logs';

    public const ABILITY_CLEAR_CACHES = 'clear-caches';

    public const ABILITY_OPTIMIZE = 'optimize';

    public const ABILITY_MAINTENANCE_MODE = 'maintenance-mode';

    /** Abilities a read-only plugin still allows. */
    public const READ_ONLY_ABILITIES = [self::ABILITY_DOWNLOAD_LOGS];

    /** @var list<string> */
    public const DEFAULT_REDACTED_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'access_key', 'private_key',
        'authorization', 'cookie', 'credit_card', 'card_number', 'cvv', 'cvc', 'iban', 'ssn', 'pin',
    ];

    protected bool|Closure|null $authorizeUsing = null;

    protected string $gate = self::GATE;

    protected ?Closure $authorizeActionsUsing = null;

    protected bool|Closure $isReadOnly = false;

    /** @var array<string, bool|Closure> */
    protected array $features = [
        self::FEATURE_OVERVIEW => true,
        self::FEATURE_FAILED_JOBS => true,
        self::FEATURE_QUEUES => true,
        self::FEATURE_BATCHES => true,
        self::FEATURE_SCHEDULER => true,
        self::FEATURE_LOGS => true,
        self::FEATURE_MAINTENANCE => true,
    ];

    protected bool $isAllowedOnTenantPanels = false;

    protected bool $hasDashboardWidget = false;

    /** @var list<string> */
    protected array $redactedKeys = self::DEFAULT_REDACTED_KEYS;

    /** @var array<string, list<string>>|Closure|null */
    protected array|Closure|null $monitoredQueues = null;

    protected string|Closure|null $logDirectory = null;

    /** @var list<string> */
    protected array $logFilePatterns = ['*.log'];

    protected bool $runsScheduledTasksOnQueue = false;

    protected ?string $scheduledTaskQueue = null;

    protected ?string $scheduledTaskConnection = null;

    protected bool|Closure $requiresPasswordConfirmation = false;

    protected ?string $pollingInterval = '10s';

    protected int $downloadLinkLifetime = 5;

    protected string|Closure|null $navigationLabel = null;

    protected string|Closure|null $navigationGroup = null;

    protected string|Closure|null $navigationIcon = 'server-cog';

    protected int|Closure|null $navigationSort = null;

    protected bool|Closure $shouldRegisterNavigation = true;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin registered on the current panel, or one with the default options (which
     * denies access unless the `viewAlfakitOps` gate allows it).
     */
    public static function get(): static
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        if ($panel->hasPlugin(self::ID)) {
            $plugin = $panel->getPlugin(self::ID);

            if ($plugin instanceof static) {
                return $plugin;
            }
        }

        return static::make();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $pages = [Operations::class];

        foreach ([
            self::FEATURE_OVERVIEW => [Overview::class],
            self::FEATURE_FAILED_JOBS => [FailedJobs::class],
            self::FEATURE_QUEUES => [Queues::class],
            self::FEATURE_BATCHES => [Batches::class],
            self::FEATURE_SCHEDULER => [Schedule::class],
            self::FEATURE_LOGS => [Logs::class, LogFile::class],
            self::FEATURE_MAINTENANCE => [Maintenance::class],
        ] as $feature => $classes) {
            // `false` removes the page (and its routes); a closure is checked per request.
            if ($this->features[$feature] !== false) {
                array_push($pages, ...$classes);
            }
        }

        $panel->pages($pages);

        if ($this->hasDashboardWidget) {
            $panel->widgets([SystemInfoWidget::class]);
        }
    }

    public function boot(Panel $panel): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'panel' => [alfakit()->getCurrentOrDefaultPanel()],
            'user' => [$this->getUser()],
            default => [],
        };
    }

    protected function getUser(): ?Authenticatable
    {
        return alfakit()->getCurrentOrDefaultPanel()->auth()->user();
    }

    /**
     * The signed-in user's display name, recorded with the actions they run.
     */
    public function getPanelUserName(): ?string
    {
        $user = $this->getUser();

        return $user === null ? null : alfakit()->getCurrentOrDefaultPanel()->getUserName($user);
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * Who may open the operations pages: `fn (User $user): bool`. Without it only users the
     * `viewAlfakitOps` gate (see `gate()`) allows may; guests never may.
     */
    public function authorize(bool|Closure|null $callback = true): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * The gate checked when no `authorize()` callback is set (default `viewAlfakitOps`).
     */
    public function gate(string $ability): static
    {
        $this->gate = $ability;

        return $this;
    }

    public function getGate(): string
    {
        return $this->gate;
    }

    /**
     * Whether the current user may see the operations pages on this panel.
     */
    public function isAuthorized(): bool
    {
        if (! $this->isAvailableOnPanel()) {
            return false;
        }

        $user = $this->getUser();

        if ($user === null) {
            return false;
        }

        if ($this->authorizeUsing !== null) {
            return (bool) $this->evaluate($this->authorizeUsing);
        }

        return Gate::forUser($user)->allows($this->gate);
    }

    /**
     * Decide per action what a user may do: `fn (string $ability, User $user): bool` with
     * an `OpsPlugin::ABILITY_*` value. It only narrows `authorize()`: users who can't see
     * the pages can't act either. Without it every authorised user may run every action.
     */
    public function authorizeActions(?Closure $callback): static
    {
        $this->authorizeActionsUsing = $callback;

        return $this;
    }

    /**
     * Show everything, change nothing: every action except downloading logs is denied.
     */
    public function readOnly(bool|Closure $condition = true): static
    {
        $this->isReadOnly = $condition;

        return $this;
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->evaluate($this->isReadOnly);
    }

    /**
     * Whether the current user may perform `$ability` (an `OpsPlugin::ABILITY_*` value).
     */
    public function can(string $ability): bool
    {
        if (! $this->isAuthorized()) {
            return false;
        }

        if ($this->isReadOnly() && ! in_array($ability, self::READ_ONLY_ABILITIES, true)) {
            return false;
        }

        if ($this->authorizeActionsUsing === null) {
            return true;
        }

        return (bool) $this->evaluate($this->authorizeActionsUsing, ['ability' => $ability]);
    }

    /**
     * Ask for the user's password before destructive actions (flushing failed jobs,
     * clearing queues, maintenance mode, deleting logs, running scheduled tasks).
     */
    public function requiresPasswordConfirmation(bool|Closure $condition = true): static
    {
        $this->requiresPasswordConfirmation = $condition;

        return $this;
    }

    public function shouldRequirePasswordConfirmation(): bool
    {
        return (bool) $this->evaluate($this->requiresPasswordConfirmation);
    }

    // ---------------------------------------------------------------- Tenancy

    /**
     * Operations are global (they are never scoped to a tenant), so the plugin hides itself
     * on panels with tenancy. Allow it there, e.g. on a panel whose every tenant is staff.
     */
    public function allowOnTenantPanels(bool $condition = true): static
    {
        $this->isAllowedOnTenantPanels = $condition;

        return $this;
    }

    public function isAllowedOnTenantPanels(): bool
    {
        return $this->isAllowedOnTenantPanels;
    }

    public function isAvailableOnPanel(): bool
    {
        return $this->isAllowedOnTenantPanels || ! alfakit()->getCurrentOrDefaultPanel()->hasTenancy();
    }

    // ---------------------------------------------------------------- Features

    public function overview(bool|Closure $condition = true): static
    {
        return $this->feature(self::FEATURE_OVERVIEW, $condition);
    }

    public function failedJobs(bool|Closure $condition = true): static
    {
        return $this->feature(self::FEATURE_FAILED_JOBS, $condition);
    }

    public function queues(bool|Closure $condition = true): static
    {
        return $this->feature(self::FEATURE_QUEUES, $condition);
    }

    public function batches(bool|Closure $condition = true): static
    {
        return $this->feature(self::FEATURE_BATCHES, $condition);
    }

    public function scheduler(bool|Closure $condition = true): static
    {
        return $this->feature(self::FEATURE_SCHEDULER, $condition);
    }

    public function logs(bool|Closure $condition = true): static
    {
        return $this->feature(self::FEATURE_LOGS, $condition);
    }

    public function maintenance(bool|Closure $condition = true): static
    {
        return $this->feature(self::FEATURE_MAINTENANCE, $condition);
    }

    public function feature(string $feature, bool|Closure $condition = true): static
    {
        $this->features[$feature] = $condition;

        return $this;
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) $this->evaluate($this->features[$feature] ?? false);
    }

    /**
     * Also show the system information card on the panel's dashboard.
     */
    public function dashboardWidget(bool $condition = true): static
    {
        $this->hasDashboardWidget = $condition;

        return $this;
    }

    public function hasDashboardWidget(): bool
    {
        return $this->hasDashboardWidget;
    }

    // ---------------------------------------------------------------- Failed jobs

    /**
     * Payload keys whose values are hidden in the failed job viewer. Keys are compared word
     * by word, case insensitively (`token` hides `api_token`, `apiToken` and `token`, but
     * not `tokenizer`). `$merge` keeps the defaults (password, secret, token, api_key,
     * authorization, cookie, card numbers…).
     *
     * @param  array<string>  $keys
     */
    public function redactKeys(array $keys, bool $merge = true): static
    {
        $keys = array_values(array_filter(array_map(static fn (string $key): string => strtolower(trim($key)), $keys), static fn (string $key): bool => $key !== ''));

        $this->redactedKeys = array_values(array_unique($merge ? [...$this->redactedKeys, ...$keys] : $keys));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRedactedKeys(): array
    {
        return $this->redactedKeys;
    }

    // ---------------------------------------------------------------- Queues

    /**
     * The queues listed per connection: `['redis' => ['default', 'emails']]`. Without it the
     * default connection's queue is listed, plus every queue that has jobs in the `jobs`
     * table of database connections.
     *
     * @param  array<string, string|array<string>>|Closure|null  $queues
     */
    public function monitorQueues(array|Closure|null $queues): static
    {
        if (is_array($queues)) {
            $normalised = [];

            foreach ($queues as $connection => $names) {
                $normalised[(string) $connection] = array_values(array_map(strval(...), (array) $names));
            }

            $queues = $normalised;
        }

        $this->monitoredQueues = $queues;

        return $this;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public function getMonitoredQueues(): ?array
    {
        $queues = $this->evaluate($this->monitoredQueues);

        if (! is_array($queues)) {
            return null;
        }

        $normalised = [];

        foreach ($queues as $connection => $names) {
            $normalised[(string) $connection] = array_values(array_map(static fn (mixed $name): string => is_scalar($name) ? (string) $name : '', is_array($names) ? $names : [$names]));
        }

        return $normalised;
    }

    /**
     * How often the queue, batch and failed job tables and the overview refresh (`'30s'`),
     * or null to turn polling off.
     */
    public function pollingInterval(?string $interval): static
    {
        $this->pollingInterval = $interval;

        return $this;
    }

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    // ---------------------------------------------------------------- Scheduler

    /**
     * Run "Run now" in a queued job instead of during the request. The result appears
     * as the task's last run once a worker ran it.
     */
    public function runScheduledTasksOnQueue(bool $condition = true, ?string $queue = null, ?string $connection = null): static
    {
        $this->runsScheduledTasksOnQueue = $condition;
        $this->scheduledTaskQueue = $queue;
        $this->scheduledTaskConnection = $connection;

        return $this;
    }

    public function runsScheduledTasksOnQueue(): bool
    {
        return $this->runsScheduledTasksOnQueue;
    }

    public function getScheduledTaskQueue(): ?string
    {
        return $this->scheduledTaskQueue;
    }

    public function getScheduledTaskConnection(): ?string
    {
        return $this->scheduledTaskConnection;
    }

    // ---------------------------------------------------------------- Logs

    /**
     * The directory the log viewer lists (default `storage_path('logs')`). Only files
     * directly inside it are listed, downloaded or deleted.
     */
    public function logDirectory(string|Closure|null $directory): static
    {
        $this->logDirectory = $directory;

        return $this;
    }

    public function getLogDirectory(): string
    {
        $directory = $this->evaluate($this->logDirectory);

        return is_string($directory) && $directory !== '' ? $directory : storage_path('logs');
    }

    /**
     * Glob patterns of the files listed (default `*.log`).
     *
     * @param  string|array<string>  $patterns
     */
    public function logFiles(string|array $patterns): static
    {
        $this->logFilePatterns = array_values(array_filter((array) $patterns, static fn (string $pattern): bool => $pattern !== '' && ! str_contains($pattern, '/') && ! str_contains($pattern, '\\')));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getLogFilePatterns(): array
    {
        return $this->logFilePatterns === [] ? ['*.log'] : $this->logFilePatterns;
    }

    /**
     * Minutes a signed log download link stays valid (default 5).
     */
    public function downloadLinkLifetime(int $minutes): static
    {
        $this->downloadLinkLifetime = max(1, $minutes);

        return $this;
    }

    public function getDownloadLinkLifetime(): int
    {
        return $this->downloadLinkLifetime;
    }

    // ---------------------------------------------------------------- Navigation

    public function navigationLabel(string|Closure|null $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function getNavigationLabel(): string
    {
        return (string) ($this->evaluate($this->navigationLabel) ?? __('alfakit-ops::ops.navigation.label'));
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) ? $group : null;
    }

    public function navigationIcon(string|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): ?string
    {
        $icon = $this->evaluate($this->navigationIcon);

        return is_string($icon) ? $icon : null;
    }

    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        $sort = $this->evaluate($this->navigationSort);

        return is_int($sort) ? $sort : null;
    }

    public function registerNavigation(bool|Closure $condition = true): static
    {
        $this->shouldRegisterNavigation = $condition;

        return $this;
    }

    public function shouldRegisterNavigation(): bool
    {
        return (bool) $this->evaluate($this->shouldRegisterNavigation);
    }
}
