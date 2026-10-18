<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitOps\Clusters\Operations;
use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\Concerns\InteractsWithOps;
use AlfaSix\AlfakitOps\Support\BatchRepository;

/**
 * Job batches: progress, failures and status, with "Retry failed jobs" and "Cancel".
 */
class Batches extends Page
{
    use InteractsWithOps;

    protected static ?string $slug = 'batches';

    protected static ?string $cluster = Operations::class;

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'boxes';

    protected static ?int $navigationSort = 40;

    public static function getOpsFeature(): string
    {
        return OpsPlugin::FEATURE_BATCHES;
    }

    protected static function getOpsTranslationKey(): string
    {
        return 'batches';
    }

    protected static function trans(string $key, array $replace = []): string
    {
        return (string) __('alfakit-ops::ops.batches.'.$key, $replace);
    }

    public static function statusLabel(string $status): string
    {
        return static::trans('statuses.'.$status);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(static::trans('table.heading'))
            ->description(BatchRepository::isAvailable() ? null : static::trans('table.unavailable'))
            ->records(static fn (?string $search, array $filters, int $page, int|string $recordsPerPage) => BatchRepository::paginate(
                $search,
                $filters,
                $page,
                is_int($recordsPerPage) ? $recordsPerPage : 100,
            ))
            ->resolveRecordUsing(static fn (string $key): ?array => BatchRepository::find($key))
            ->columns([
                TextColumn::make('name')
                    ->label(static::trans('columns.name'))
                    ->weight('medium')
                    ->description(static fn (array $record): string => $record['id'])
                    ->placeholder(static::trans('unnamed'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(static::trans('columns.status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => static::statusLabel($state))
                    ->color(static fn (string $state): string => BatchRepository::statusColor($state))
                    ->icon(static fn (string $state): string => BatchRepository::statusIcon($state)),
                TextColumn::make('progress')
                    ->label(static::trans('columns.progress'))
                    ->formatStateUsing(static fn (int $state): string => static::trans('progress', ['progress' => $state]))
                    ->description(static fn (array $record): string => static::trans('processed', ['processed' => $record['processed'], 'total' => $record['total']])),
                TextColumn::make('pending')
                    ->label(static::trans('columns.pending'))
                    ->numeric(),
                TextColumn::make('failed')
                    ->label(static::trans('columns.failed'))
                    ->numeric()
                    ->color(static fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('created_at')
                    ->label(static::trans('columns.created_at'))
                    ->since()
                    ->dateTimeTooltip(),
                TextColumn::make('finished_at')
                    ->label(static::trans('columns.finished_at'))
                    ->getStateUsing(static fn (array $record): ?string => $record['finished_at'] ?? $record['cancelled_at'])
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(static::trans('columns.status'))
                    ->options(static fn (): array => [
                        BatchRepository::STATUS_RUNNING => static::statusLabel(BatchRepository::STATUS_RUNNING),
                        BatchRepository::STATUS_FINISHED => static::statusLabel(BatchRepository::STATUS_FINISHED),
                        BatchRepository::STATUS_FAILED => static::statusLabel(BatchRepository::STATUS_FAILED),
                        BatchRepository::STATUS_CANCELLED => static::statusLabel(BatchRepository::STATUS_CANCELLED),
                    ]),
            ])
            ->recordActions([
                static::authorizeOpsAction(
                    Action::make('retry')
                        ->label(static::trans('actions.retry.label'))
                        ->icon('rotate-ccw')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading(static::trans('actions.retry.modal_heading'))
                        ->modalDescription(static fn (array $record): string => trans_choice('alfakit-ops::ops.batches.actions.retry.modal_description', $record['failed'], ['count' => $record['failed']]))
                        ->modalSubmitActionLabel(static::trans('actions.retry.submit'))
                        ->visible(static fn (array $record): bool => $record['failed'] > 0 && $record['status'] !== BatchRepository::STATUS_CANCELLED)
                        ->action(static function (array $record): void {
                            $count = BatchRepository::retryFailedJobs($record['id']);

                            Notification::make()
                                ->success()
                                ->title(trans_choice('alfakit-ops::ops.batches.notifications.retried', $count, ['count' => $count]))
                                ->send();
                        }),
                    OpsPlugin::ABILITY_RETRY_BATCHES,
                ),
                static::authorizeOpsAction(
                    Action::make('cancel')
                        ->label(static::trans('actions.cancel.label'))
                        ->icon('ban')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(static::trans('actions.cancel.modal_heading'))
                        ->modalDescription(static fn (array $record): string => static::trans('actions.cancel.modal_description', ['name' => $record['name'] !== '' ? $record['name'] : $record['id']]))
                        ->modalSubmitActionLabel(static::trans('actions.cancel.submit'))
                        ->visible(static fn (array $record): bool => $record['status'] !== BatchRepository::STATUS_CANCELLED && $record['finished_at'] === null)
                        ->action(static function (array $record): void {
                            BatchRepository::cancel($record['id']);

                            Notification::make()
                                ->success()
                                ->title(static::trans('notifications.cancelled'))
                                ->send();
                        }),
                    OpsPlugin::ABILITY_CANCEL_BATCHES,
                    isDestructive: true,
                ),
            ])
            ->poll(static::getPlugin()->getPollingInterval())
            ->emptyStateIcon('boxes')
            ->emptyStateHeading(static::trans('table.empty.heading'))
            ->emptyStateDescription(static::trans('table.empty.description'));
    }
}
