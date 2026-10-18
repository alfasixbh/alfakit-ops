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
use AlfaSix\AlfakitOps\Support\QueueMonitor;

/**
 * Pending, delayed and reserved jobs per queue, with the age of the oldest pending job
 * and a "Clear" action. Refreshes while open.
 */
class Queues extends Page
{
    use InteractsWithOps;

    protected static ?string $slug = 'queues';

    protected static ?string $cluster = Operations::class;

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'layers';

    protected static ?int $navigationSort = 30;

    public static function getOpsFeature(): string
    {
        return OpsPlugin::FEATURE_QUEUES;
    }

    protected static function getOpsTranslationKey(): string
    {
        return 'queues';
    }

    protected static function trans(string $key, array $replace = []): string
    {
        return (string) __('alfakit-ops::ops.queues.'.$key, $replace);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(static::trans('table.heading'))
            ->description(static::trans('table.description'))
            ->records(static fn (): array => QueueMonitor::rows())
            ->columns([
                TextColumn::make('queue')
                    ->label(static::trans('columns.queue'))
                    ->weight('medium')
                    ->description(static fn (array $record): ?string => $record['error'] !== null ? static::trans('unreachable', ['message' => $record['error']]) : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('connection')
                    ->label(static::trans('columns.connection'))
                    ->description(static fn (array $record): string => $record['driver'])
                    ->sortable(),
                TextColumn::make('pending')
                    ->label(static::trans('columns.pending'))
                    ->getStateUsing(static fn (array $record): ?int => $record['pending'] ?? $record['size'])
                    ->numeric()
                    ->badge()
                    ->color(static fn (?int $state): string => ($state ?? 0) > 0 ? 'warning' : 'gray')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('delayed')
                    ->label(static::trans('columns.delayed'))
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('reserved')
                    ->label(static::trans('columns.reserved'))
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('oldest')
                    ->label(static::trans('columns.oldest'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('connection')
                    ->label(static::trans('columns.connection'))
                    ->options(static function (): array {
                        $connections = array_keys(QueueMonitor::monitoredQueues());

                        return array_combine($connections, $connections);
                    }),
            ])
            ->recordActions([
                static::authorizeOpsAction(
                    Action::make('clear')
                        ->label(static::trans('actions.clear.label'))
                        ->icon('eraser')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(static fn (array $record): string => static::trans('actions.clear.modal_heading', ['queue' => $record['queue']]))
                        ->modalDescription(static fn (array $record): string => static::trans('actions.clear.modal_description', ['queue' => $record['queue'], 'connection' => $record['connection']]))
                        ->modalSubmitActionLabel(static::trans('actions.clear.submit'))
                        ->visible(static fn (array $record): bool => $record['clearable'] && $record['error'] === null)
                        ->action(static function (array $record): void {
                            $count = QueueMonitor::clear($record['connection'], $record['queue']);

                            Notification::make()
                                ->success()
                                ->title(trans_choice('alfakit-ops::ops.queues.notifications.cleared', $count, ['count' => $count, 'queue' => $record['queue']]))
                                ->send();
                        }),
                    OpsPlugin::ABILITY_CLEAR_QUEUES,
                    isDestructive: true,
                ),
            ])
            ->paginated(false)
            ->poll(static::getPlugin()->getPollingInterval())
            ->emptyStateIcon('layers')
            ->emptyStateHeading(static::trans('table.empty.heading'))
            ->emptyStateDescription(static::trans('table.empty.description'));
    }
}
