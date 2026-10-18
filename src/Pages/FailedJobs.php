<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Infolists\Components\CodeEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Tabs;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitOps\Clusters\Operations;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Concerns\InteractsWithOps;
use AlfaSix\AlfakitOps\Support\FailedJobRepository;
use Illuminate\Support\Collection;

/**
 * Laravel's failed jobs: search and filter them, read the exception and the (redacted)
 * payload, retry them one by one, in bulk or all at once, forget them or flush the list.
 *
 * @phpstan-import-type FailedJobRow from FailedJobRepository
 */
class FailedJobs extends Page
{
    use InteractsWithOps;

    protected static ?string $slug = 'failed-jobs';

    protected static ?string $cluster = Operations::class;

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'circle-x';

    protected static ?int $navigationSort = 20;

    public static function getOpsFeature(): string
    {
        return OpsPlugin::FEATURE_FAILED_JOBS;
    }

    protected static function getOpsTranslationKey(): string
    {
        return 'failed_jobs';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = FailedJobRepository::count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getNavigationBadge() === null ? null : 'danger';
    }

    protected static function trans(string $key, array $replace = []): string
    {
        return (string) __('alfakit-ops::ops.failed_jobs.'.$key, $replace);
    }

    protected function getHeaderActions(): array
    {
        return [
            static::authorizeOpsAction(
                Action::make('retryAll')
                    ->label(static::trans('actions.retry_all.label'))
                    ->icon('rotate-ccw')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(static::trans('actions.retry_all.modal_heading'))
                    ->modalDescription(static::trans('actions.retry_all.modal_description'))
                    ->modalSubmitActionLabel(static::trans('actions.retry_all.submit'))
                    ->visible(static fn (): bool => FailedJobRepository::count() > 0)
                    ->action(static function (): void {
                        $count = FailedJobRepository::retryAll();

                        Notification::make()
                            ->success()
                            ->title(trans_choice('alfakit-ops::ops.failed_jobs.notifications.retried', $count, ['count' => $count]))
                            ->send();
                    }),
                OpsPlugin::ABILITY_RETRY_FAILED_JOBS,
            ),
            static::authorizeOpsAction(
                Action::make('flush')
                    ->label(static::trans('actions.flush.label'))
                    ->icon('trash-2')
                    ->color('danger')
                    ->outlined()
                    ->requiresConfirmation()
                    ->modalHeading(static::trans('actions.flush.modal_heading'))
                    ->modalDescription(static::trans('actions.flush.modal_description'))
                    ->modalSubmitActionLabel(static::trans('actions.flush.submit'))
                    ->visible(static fn (): bool => FailedJobRepository::count() > 0)
                    ->action(static function (): void {
                        $count = FailedJobRepository::flush();

                        Notification::make()
                            ->success()
                            ->title(trans_choice('alfakit-ops::ops.failed_jobs.notifications.forgotten', $count, ['count' => $count]))
                            ->send();
                    }),
                OpsPlugin::ABILITY_FLUSH_FAILED_JOBS,
                isDestructive: true,
            ),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(static::trans('table.heading'))
            ->description(FailedJobRepository::isAvailable() ? null : static::trans('table.unavailable'))
            ->records(static fn (?string $search, array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage) => FailedJobRepository::paginate(
                $search,
                $filters,
                $sortColumn,
                $sortDirection,
                $page,
                is_int($recordsPerPage) ? $recordsPerPage : 100,
            ))
            ->resolveRecordUsing(static fn (string $key): ?array => FailedJobRepository::find($key))
            ->resolveSelectedRecordsUsing(fn (array $keys, bool $isTrackingDeselectedKeys): array => FailedJobRepository::findMany(
                $isTrackingDeselectedKeys ? FailedJobRepository::keys($this->getTable()?->getState()->search, $this->getActiveFilterStates()) : $keys,
            ))
            ->columns([
                TextColumn::make('job')
                    ->label(static::trans('columns.job'))
                    ->weight('medium')
                    ->description(static fn (array $record): string => $record['exception_summary'])
                    ->wrap()
                    ->searchable(),
                TextColumn::make('queue')
                    ->label(static::trans('columns.queue'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('connection')
                    ->label(static::trans('columns.connection'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('attempts')
                    ->label(static::trans('columns.attempts'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failed_at')
                    ->label(static::trans('columns.failed_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('queue')
                    ->label(static::trans('filters.queue'))
                    ->options(static fn (): array => FailedJobRepository::queueOptions()),
                SelectFilter::make('connection')
                    ->label(static::trans('filters.connection'))
                    ->options(static fn (): array => FailedJobRepository::connectionOptions()),
                SelectFilter::make('job')
                    ->label(static::trans('filters.job'))
                    ->options(static fn (): array => FailedJobRepository::jobOptions())
                    ->searchable(),
                Filter::make('failed_at')
                    ->label(static::trans('filters.failed_at'))
                    ->form([
                        DatePicker::make('from')->label(static::trans('filters.from')),
                        DatePicker::make('until')->label(static::trans('filters.until')),
                    ])
                    ->indicateUsing(static fn (array $data): array => array_values(array_filter([
                        ($data['from'] ?? null) ? static::trans('filters.from_indicator', ['date' => (string) $data['from']]) : null,
                        ($data['until'] ?? null) ? static::trans('filters.until_indicator', ['date' => (string) $data['until']]) : null,
                    ]))),
            ])
            ->recordActions([
                $this->getViewAction(),
                static::authorizeOpsAction(
                    Action::make('retry')
                        ->label(static::trans('actions.retry.label'))
                        ->icon('rotate-ccw')
                        ->color('gray')
                        ->action(static function (array $record): void {
                            $count = FailedJobRepository::retry([$record['id']]);

                            Notification::make()
                                ->success()
                                ->title(trans_choice('alfakit-ops::ops.failed_jobs.notifications.retried', $count, ['count' => $count]))
                                ->send();
                        }),
                    OpsPlugin::ABILITY_RETRY_FAILED_JOBS,
                ),
                static::authorizeOpsAction(
                    Action::make('forget')
                        ->label(static::trans('actions.forget.label'))
                        ->icon('trash-2')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(static::trans('actions.forget.modal_heading'))
                        ->modalDescription(static::trans('actions.forget.modal_description'))
                        ->modalSubmitActionLabel(static::trans('actions.forget.submit'))
                        ->action(static function (array $record): void {
                            $count = FailedJobRepository::forget([$record['id']]);

                            Notification::make()
                                ->success()
                                ->title(trans_choice('alfakit-ops::ops.failed_jobs.notifications.forgotten', $count, ['count' => $count]))
                                ->send();
                        }),
                    OpsPlugin::ABILITY_FORGET_FAILED_JOBS,
                ),
            ])
            ->bulkActions([
                static::authorizeOpsAction(
                    BulkAction::make('retrySelected')
                        ->label(static::trans('actions.retry_selected.label'))
                        ->icon('rotate-ccw')
                        ->deselectRecordsAfterCompletion()
                        ->action(static function (Collection $records): void {
                            $count = FailedJobRepository::retry(static::keysOf($records));

                            Notification::make()
                                ->success()
                                ->title(trans_choice('alfakit-ops::ops.failed_jobs.notifications.retried', $count, ['count' => $count]))
                                ->send();
                        }),
                    OpsPlugin::ABILITY_RETRY_FAILED_JOBS,
                ),
                static::authorizeOpsAction(
                    BulkAction::make('forgetSelected')
                        ->label(static::trans('actions.forget_selected.label'))
                        ->icon('trash-2')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(static::trans('actions.forget_selected.modal_heading'))
                        ->modalDescription(static::trans('actions.forget_selected.modal_description'))
                        ->modalSubmitActionLabel(static::trans('actions.forget.submit'))
                        ->deselectRecordsAfterCompletion()
                        ->action(static function (Collection $records): void {
                            $count = FailedJobRepository::forget(static::keysOf($records));

                            Notification::make()
                                ->success()
                                ->title(trans_choice('alfakit-ops::ops.failed_jobs.notifications.forgotten', $count, ['count' => $count]))
                                ->send();
                        }),
                    OpsPlugin::ABILITY_FORGET_FAILED_JOBS,
                ),
            ])
            ->recordAction('view')
            ->defaultSort('failed_at', 'desc')
            ->poll(static::getPlugin()->getPollingInterval())
            ->emptyStateIcon('circle-check')
            ->emptyStateHeading(static::trans('table.empty.heading'))
            ->emptyStateDescription(static::trans('table.empty.description'));
    }

    protected function getViewAction(): Action
    {
        return Action::make('view')
            ->label(static::trans('actions.view.label'))
            ->icon('eye')
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::FourExtraLarge)
            ->modalHeading(static fn (array $record): string => $record['job'] !== '' ? $record['job'] : static::trans('actions.view.modal_heading'))
            ->modalDescription(static fn (array $record): string => $record['exception_summary'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(static::trans('actions.view.close'))
            ->modalContent(static fn (array $record): array => static::detailsSchema($record));
    }

    /**
     * The failed job's details: its metadata, the full exception and the redacted payload.
     *
     * @param  FailedJobRow  $record
     * @return array<Component>
     */
    public static function detailsSchema(array $record): array
    {
        return [
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('uuid')->label(static::trans('columns.uuid'))->state($record['uuid'])->copyable()->fontFamily('mono')->placeholder('—'),
                TextEntry::make('failed_at')->label(static::trans('columns.failed_at'))->state($record['failed_at'])->dateTime()->placeholder('—'),
                TextEntry::make('connection')->label(static::trans('columns.connection'))->state($record['connection']),
                TextEntry::make('queue')->label(static::trans('columns.queue'))->state($record['queue'])->badge()->color('gray'),
                TextEntry::make('attempts')->label(static::trans('columns.attempts'))->state($record['attempts'])->placeholder('—'),
                TextEntry::make('id')->label(static::trans('columns.id'))->state($record['id'])->fontFamily('mono')->copyable(),
            ]),
            Tabs::make(static::trans('details.tabs'))->tabs([
                Tab::make(static::trans('details.exception'))->icon('bug')->schema([
                    CodeEntry::make('exception')
                        ->hiddenLabel()
                        ->state($record['exception'])
                        ->copyable(),
                ]),
                Tab::make(static::trans('details.payload'))->icon('braces')->schema([
                    CodeEntry::make('payload')
                        ->hiddenLabel()
                        ->state(FailedJobRepository::presentPayload($record['payload']))
                        ->grammar('json')
                        ->helperText(static::trans('details.payload_redacted'))
                        ->copyable(),
                ]),
            ]),
        ];
    }

    /**
     * The active filters' states, by name (for "select all" across pages).
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getActiveFilterStates(): array
    {
        $states = [];

        foreach ($this->getTable()?->getFilters() ?? [] as $name => $filter) {
            $states[$name] = $filter->getState();
        }

        return $states;
    }

    /**
     * @param  Collection<array-key, mixed>  $records
     * @return list<string>
     */
    protected static function keysOf(Collection $records): array
    {
        return $records
            ->map(static fn (mixed $record): string => is_array($record) && is_scalar($record['id'] ?? null) ? (string) $record['id'] : '')
            ->filter()
            ->values()
            ->all();
    }
}
