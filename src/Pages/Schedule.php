<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Infolists\Components\CodeEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitOps\Clusters\Operations;
use AlfaSix\AlfakitOps\Jobs\RunScheduledTaskJob;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Concerns\InteractsWithOps;
use AlfaSix\AlfakitOps\Support\ScheduledTasks;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The application's scheduled tasks: what runs, how often (in words), when it is due
 * next in the panel's timezone, how it is guarded, and its last run, with "Run now".
 *
 * @phpstan-import-type TaskRow from ScheduledTasks
 */
class Schedule extends Page
{
    use InteractsWithOps;

    protected static ?string $slug = 'schedule';

    protected static ?string $cluster = Operations::class;

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'calendar-clock';

    protected static ?int $navigationSort = 50;

    public static function getOpsFeature(): string
    {
        return OpsPlugin::FEATURE_SCHEDULER;
    }

    protected static function getOpsTranslationKey(): string
    {
        return 'schedule';
    }

    protected static function trans(string $key, array $replace = []): string
    {
        return (string) __('alfakit-ops::ops.schedule.'.$key, $replace);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            ScheduledTasks::STATUS_SUCCESS => 'success',
            ScheduledTasks::STATUS_FAILED => 'danger',
            ScheduledTasks::STATUS_SKIPPED => 'warning',
            default => 'gray',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(static::trans('table.heading'))
            ->description(static::trans('table.description', ['timezone' => alfakit()->getTimezone()]))
            ->records(static fn (): array => ScheduledTasks::rows())
            ->columns([
                TextColumn::make('command')
                    ->label(static::trans('columns.command'))
                    ->fontFamily('mono')
                    ->weight('medium')
                    ->description(static fn (array $record): ?string => $record['description'])
                    ->wrap()
                    ->searchable(),
                TextColumn::make('type')
                    ->label(static::trans('columns.type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => static::trans('types.'.$state))
                    ->toggleable(),
                TextColumn::make('frequency')
                    ->label(static::trans('columns.frequency'))
                    ->getStateUsing(static fn (array $record): string => $record['frequency'] ?? $record['expression'])
                    ->description(static fn (array $record): ?string => $record['frequency'] !== null ? $record['expression'] : null)
                    ->tooltip(static fn (array $record): string => static::trans('timezone', ['timezone' => $record['timezone']])),
                TextColumn::make('next_due')
                    ->label(static::trans('columns.next_due'))
                    ->dateTime()
                    ->description(static fn (array $record): ?string => static::relative($record['next_due']))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('timezone')
                    ->label(static::trans('columns.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('without_overlapping')
                    ->label(static::trans('columns.without_overlapping'))
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('on_one_server')
                    ->label(static::trans('columns.on_one_server'))
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('in_background')
                    ->label(static::trans('columns.in_background'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('environments')
                    ->label(static::trans('columns.environments'))
                    ->badge()
                    ->color('gray')
                    ->placeholder(static::trans('all_environments'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_run')
                    ->label(static::trans('columns.last_run'))
                    ->getStateUsing(static fn (array $record): ?string => is_string($record['last_run']['status'] ?? null) ? $record['last_run']['status'] : null)
                    ->badge()
                    ->formatStateUsing(static fn (?string $state): ?string => $state === null ? null : static::trans('statuses.'.$state))
                    ->color(static fn (?string $state): string => static::statusColor($state))
                    ->description(static fn (array $record): ?string => static::lastRunDescription($record))
                    ->placeholder(static::trans('never_ran')),
            ])
            ->recordActions([
                $this->getOutputAction(),
                static::authorizeOpsAction(
                    Action::make('run')
                        ->label(static::trans('actions.run.label'))
                        ->icon('play')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalIcon('play')
                        ->modalHeading(static::trans('actions.run.modal_heading'))
                        ->modalDescription(static fn (array $record): string => static::trans(
                            static::getPlugin()->runsScheduledTasksOnQueue() ? 'actions.run.modal_description_queued' : 'actions.run.modal_description',
                            ['command' => $record['command']],
                        ))
                        ->modalSubmitActionLabel(static::trans('actions.run.submit'))
                        ->action(static function (array $record): void {
                            static::runTask($record);
                        }),
                    OpsPlugin::ABILITY_RUN_SCHEDULED_TASKS,
                    isDestructive: true,
                ),
            ])
            ->defaultSort('next_due')
            ->paginated(false)
            ->emptyStateIcon('calendar-clock')
            ->emptyStateHeading(static::trans('table.empty.heading'))
            ->emptyStateDescription(static::trans('table.empty.description'));
    }

    /**
     * @param  TaskRow  $record
     */
    protected static function runTask(array $record): void
    {
        $plugin = static::getPlugin();
        $user = $plugin->getPanelUserName();

        if ($plugin->runsScheduledTasksOnQueue()) {
            RunScheduledTaskJob::dispatch($record['id'], $user)
                ->onQueue($plugin->getScheduledTaskQueue())
                ->onConnection($plugin->getScheduledTaskConnection());

            Notification::make()
                ->success()
                ->title(static::trans('notifications.queued', ['command' => $record['command']]))
                ->send();

            return;
        }

        $event = ScheduledTasks::find($record['id']);

        if ($event === null) {
            Notification::make()->danger()->title(static::trans('notifications.missing'))->send();

            return;
        }

        $run = ScheduledTasks::run($event, $user);
        $notification = Notification::make()
            ->title(static::trans('notifications.'.$run['status'], ['command' => $record['command'], 'code' => (string) ($run['exit_code'] ?? '—')]))
            ->body($run['output'] !== null ? mb_strimwidth(trim($run['output']), 0, 300, '…') : null);

        match ($run['status']) {
            ScheduledTasks::STATUS_SUCCESS => $notification->success(),
            ScheduledTasks::STATUS_SKIPPED => $notification->warning(),
            default => $notification->danger(),
        };

        $notification->send();
    }

    protected function getOutputAction(): Action
    {
        return Action::make('output')
            ->label(static::trans('actions.output.label'))
            ->icon('terminal')
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalHeading(static fn (array $record): string => $record['command'])
            ->modalDescription(static fn (array $record): ?string => $record['description'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(static::trans('actions.output.close'))
            ->modalContent(static fn (array $record): array => static::outputSchema($record));
    }

    /**
     * @param  TaskRow  $record
     * @return array<Component>
     */
    public static function outputSchema(array $record): array
    {
        $run = $record['last_run'];
        $event = ScheduledTasks::find($record['id']);
        $file = $event !== null ? ScheduledTasks::outputFile($event) : null;
        $output = is_string($run['output'] ?? null) ? $run['output'] : null;

        return array_values(array_filter([
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('status')
                    ->label(static::trans('columns.last_run'))
                    ->state(is_string($run['status'] ?? null) ? static::trans('statuses.'.$run['status']) : null)
                    ->badge()
                    ->color(static::statusColor(is_string($run['status'] ?? null) ? $run['status'] : null))
                    ->placeholder(static::trans('never_ran')),
                TextEntry::make('finished_at')
                    ->label(static::trans('columns.finished_at'))
                    ->state($run['finished_at'] ?? null)
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('exit_code')
                    ->label(static::trans('columns.exit_code'))
                    ->state(isset($run['exit_code']) && is_int($run['exit_code']) ? (string) $run['exit_code'] : null)
                    ->placeholder('—'),
                TextEntry::make('runtime')
                    ->label(static::trans('columns.runtime'))
                    ->state(is_numeric($run['runtime'] ?? null) ? static::trans('seconds', ['seconds' => (string) $run['runtime']]) : null)
                    ->placeholder('—'),
                TextEntry::make('source')
                    ->label(static::trans('columns.source'))
                    ->state(is_string($run['source'] ?? null) ? static::trans('sources.'.$run['source'], ['user' => is_string($run['user'] ?? null) ? $run['user'] : '']) : null)
                    ->placeholder('—'),
                TextEntry::make('next_due')
                    ->label(static::trans('columns.next_due'))
                    ->state($record['next_due'])
                    ->dateTime()
                    ->placeholder('—'),
            ]),
            CodeEntry::make('output')
                ->label(static::trans('details.output'))
                ->state($output ?? static::trans('details.no_output'))
                ->copyable(),
            $file !== null ? CodeEntry::make('file')
                ->label(static::trans('details.output_file'))
                ->state($file)
                ->copyable() : null,
        ]));
    }

    public static function relative(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->locale(app()->getLocale())->diffForHumans();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  TaskRow  $record
     */
    protected static function lastRunDescription(array $record): ?string
    {
        $run = $record['last_run'];

        if ($run === null) {
            return null;
        }

        $when = is_string($run['finished_at'] ?? null) ? static::relative($run['finished_at']) : null;

        return $when === null ? null : static::trans('last_run_description', [
            'time' => $when,
            'code' => isset($run['exit_code']) && is_int($run['exit_code']) ? (string) $run['exit_code'] : '—',
        ]);
    }
}
