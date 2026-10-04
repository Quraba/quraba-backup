<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Quraba\Backup\Backup\PendingBackupRequest;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Operations\PanelTableAvailability;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

final class BackupRuns extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected string $view = 'quraba-backup::filament.runs';

    protected static ?string $navigationLabel = 'Recovery Points';

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-details');
    }

    protected function getViewData(): array
    {
        $tables = app(PanelTableAvailability::class);

        return [
            'pendingEnabled' => (bool) config('quraba-backup.filament.pending_enabled'),
            'catalogAvailable' => $tables->has('runs') && $tables->has('artifacts'),
        ];
    }

    public function table(Table $table): Table
    {
        BackupPanelAccess::authorize('view-details');

        return $table
            ->query(BackupRun::query()->with('artifacts')->latest('requested_at'))
            ->columns([
                TextColumn::make('requested_at')->label('Created')->dateTime()->sortable(),
                TextColumn::make('profile')->badge()->formatStateUsing(fn ($state): string => $state instanceof BackupProfile ? ucfirst($state->value) : (is_string($state) ? ucfirst($state) : 'Unknown')),
                TextColumn::make('status')->badge()->color(fn (BackupRun $record): string => match ($record->status) {
                    BackupStatus::Completed => 'success',
                    BackupStatus::Failed, BackupStatus::Indeterminate => 'danger',
                    BackupStatus::Partial => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('consistency')->badge()->toggleable(),
                TextColumn::make('trigger')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('archive')->label('Archive')->badge()->state(fn (BackupRun $record): string => $this->componentStatus($record, ArtifactKind::ApplicationArchive))->color(fn (string $state): string => $this->componentColor($state)),
                TextColumn::make('snapshot')->label('Snapshot')->badge()->state(fn (BackupRun $record): string => $this->componentStatus($record, ArtifactKind::ResticSnapshot))->color(fn (string $state): string => $this->componentColor($state)),
                TextColumn::make('complete')->label('Recovery Point')->badge()->state(fn (BackupRun $record): string => $this->complete($record) ? 'Complete' : 'Incomplete')->color(fn (string $state): string => $state === 'Complete' ? 'success' : 'gray'),
                TextColumn::make('uuid')->searchable(isIndividual: true)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('profile')->options(array_combine(array_column(BackupProfile::cases(), 'value'), array_map(fn (BackupProfile $p): string => ucfirst($p->value), BackupProfile::cases()))),
                SelectFilter::make('status')->options(array_combine(array_column(BackupStatus::cases(), 'value'), array_column(BackupStatus::cases(), 'value'))),
                SelectFilter::make('trigger')->options(array_combine(array_column(BackupTrigger::cases(), 'value'), array_column(BackupTrigger::cases(), 'value'))),
                SelectFilter::make('consistency')->options(array_combine(array_column(ConsistencyLevel::cases(), 'value'), array_column(ConsistencyLevel::cases(), 'value'))),
                Filter::make('requested_at')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('requested_at', '>=', is_string($date) ? $date : null))
                    ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('requested_at', '<=', is_string($date) ? $date : null))),
                Filter::make('exact_uuid')->label('Exact Run UUID')->schema([TextInput::make('uuid')->uuid()])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['uuid'] ?? null, fn (Builder $q, $uuid): Builder => $q->where('uuid', '=', $uuid))),
            ])
            ->headerActions(array_map(fn (BackupProfile $profile): Action => Action::make('request_'.$profile->value)
                ->label(match ($profile) {
                    BackupProfile::Database => 'Database Backup', BackupProfile::Media => 'Media Backup', BackupProfile::Recovery => 'Full Recovery Point'
                })
                ->requiresConfirmation()
                ->modalDescription('The scheduler will process this request outside your browser session.')
                ->visible(fn (): bool => BackupPanelAccess::allows('run-backup') && (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled'))
                ->action(fn () => $this->requestBackup($profile)), BackupProfile::cases()))
            ->recordActions([
                Action::make('details')->label('Details')->schema([
                    Section::make('Backup')->columns(2)->schema([
                        TextEntry::make('profile')->state(fn (BackupRun $record): string => $record->profile->value),
                        TextEntry::make('status')->state(fn (BackupRun $record): string => $record->status->value)->badge(),
                        TextEntry::make('consistency')->state(fn (BackupRun $record): string => $record->consistency->value),
                        TextEntry::make('trigger')->state(fn (BackupRun $record): string => $record->trigger->value),
                        TextEntry::make('created')->state(fn (BackupRun $record): string => $record->requested_at?->toDateTimeString().' UTC'),
                        TextEntry::make('completed')->state(fn (BackupRun $record): string => $record->completed_at?->toDateTimeString() ?? '—'),
                        TextEntry::make('complete_recovery_point')->state(fn (BackupRun $record): string => $this->complete($record) ? 'Yes' : 'No'),
                    ]),
                    Section::make('Technical details')->collapsible()->collapsed()->schema([
                        TextEntry::make('uuid'),
                        TextEntry::make('archive_sha256')->state(fn (BackupRun $record): string => (string) $record->artifacts->firstWhere('kind', ArtifactKind::ApplicationArchive)?->sha256),
                        TextEntry::make('archive_size')->state(fn (BackupRun $record): string => (string) $record->artifacts->firstWhere('kind', ArtifactKind::ApplicationArchive)?->byte_size.' bytes'),
                        TextEntry::make('snapshot_id')->state(fn (BackupRun $record): string => (string) $record->artifacts->firstWhere('kind', ArtifactKind::ResticSnapshot)?->snapshot_id),
                        TextEntry::make('repository_id')->state(fn (BackupRun $record): string => $this->repositoryId($record)),
                        TextEntry::make('manifest')->state(fn (BackupRun $record): string => (string) $record->artifacts->firstWhere('kind', ArtifactKind::RemoteManifest)?->status?->value),
                        TextEntry::make('failure')->state(fn (BackupRun $record): string => trim((string) $record->failure_stage.' '.(string) $record->failure_code)),
                        TextEntry::make('retention')->state(fn (BackupRun $record): string => $record->isPinned() ? 'Protected until '.$record->pinned_until?->toDateTimeString().' UTC' : 'Not pinned'),
                    ]),
                ])->modalSubmitAction(false),
            ])
            ->defaultPaginationPageOption(20)
            ->poll(fn (): ?string => app(PanelTableAvailability::class)->has('runs') && BackupRun::query()->whereIn('status', ['pending', 'preflighting', 'running', 'verifying'])->exists() ? '20s' : null);
    }

    private function componentStatus(BackupRun $run, ArtifactKind $kind): string
    {
        if (($kind === ArtifactKind::ApplicationArchive && $run->profile === BackupProfile::Media)
            || ($kind === ArtifactKind::ResticSnapshot && $run->profile === BackupProfile::Database)) {
            return 'N/A';
        }

        return $this->verified($run, $kind) ? 'Verified' : 'Missing / failed';
    }

    private function componentColor(string $status): string
    {
        return match ($status) {
            'Verified' => 'success', 'N/A' => 'gray', default => 'danger',
        };
    }

    private function verified(BackupRun $run, ArtifactKind $kind): bool
    {
        return $run->artifacts->contains(fn ($artifact): bool => $artifact->kind === $kind && $artifact->status === ArtifactStatus::Verified);
    }

    private function complete(BackupRun $run): bool
    {
        return $run->profile === BackupProfile::Recovery && $run->status === BackupStatus::Completed
            && $this->verified($run, ArtifactKind::ApplicationArchive)
            && $this->verified($run, ArtifactKind::ResticSnapshot);
    }

    private function repositoryId(BackupRun $run): string
    {
        $metadata = $run->artifacts->firstWhere('kind', ArtifactKind::ResticSnapshot)?->metadata;
        $value = is_array($metadata) ? ($metadata['repository_id'] ?? null) : null;

        return is_string($value) ? $value : '—';
    }

    private function requestBackup(BackupProfile $profile): void
    {
        BackupPanelAccess::authorize('run-backup');
        try {
            $run = app(PendingBackupRequest::class)->request($profile);
            Notification::make()->title('Backup requested')->body('Request '.$run->uuid.' is pending.')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Backup request refused')->body(app(SecretRedactor::class)->redact($exception->getMessage()))->danger()->send();
        }
    }
}
