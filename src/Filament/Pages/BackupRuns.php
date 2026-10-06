<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
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
use Quraba\Backup\Filament\OperatorStatus;
use Quraba\Backup\Filament\Ui;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Operations\PanelTableAvailability;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

final class BackupRuns extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected string $view = 'quraba-backup::filament.runs';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return Ui::text('navigation.runs');
    }

    public static function getNavigationGroup(): string
    {
        return Ui::text('navigation.group');
    }

    public function getTitle(): string
    {
        return Ui::text('pages.runs.title');
    }

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
                TextColumn::make('requested_at')->label(Ui::text('labels.created'))->state(fn (BackupRun $record): string => Ui::dateTime($record->requested_at))->sortable(),
                TextColumn::make('profile')->label(Ui::text('labels.profile'))->badge()->formatStateUsing(fn ($state): string => Ui::value($state, 'backup_types')),
                TextColumn::make('status')->label(Ui::text('labels.status'))->badge()->formatStateUsing(fn ($state, BackupRun $record): string => OperatorStatus::backupStage($record))->color(fn (BackupRun $record): string => match ($record->status) {
                    BackupStatus::Completed => 'success',
                    BackupStatus::Failed, BackupStatus::Indeterminate => 'danger',
                    BackupStatus::Partial => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('availability')->label(Ui::text('operator.restore_availability'))->badge()->state(fn (BackupRun $record): string => $this->availability($record))->formatStateUsing(fn (string $state): string => $state === 'unavailable' ? Ui::text('operator.unavailable') : Ui::value($state, 'profiles'))->color(fn (string $state): string => $state === 'unavailable' ? 'gray' : 'success'),
                TextColumn::make('uuid')->label(Ui::text('labels.uuid'))->searchable(isIndividual: true)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('profile')->label(Ui::text('labels.profile'))->options(array_combine(array_column(BackupProfile::cases(), 'value'), array_map(fn (BackupProfile $p): string => Ui::value($p, 'profiles'), BackupProfile::cases()))),
                SelectFilter::make('status')->label(Ui::text('labels.status'))->options(array_combine(array_column(BackupStatus::cases(), 'value'), array_map(fn (BackupStatus $s): string => Ui::value($s), BackupStatus::cases()))),
                SelectFilter::make('trigger')->label(Ui::text('labels.trigger'))->options(array_combine(array_column(BackupTrigger::cases(), 'value'), array_map(fn (BackupTrigger $t): string => Ui::value($t, 'triggers'), BackupTrigger::cases()))),
                SelectFilter::make('consistency')->label(Ui::text('labels.consistency'))->options(array_combine(array_column(ConsistencyLevel::cases(), 'value'), array_map(fn (ConsistencyLevel $c): string => Ui::value($c, 'consistency'), ConsistencyLevel::cases()))),
                Filter::make('requested_at')->label(Ui::text('labels.created'))->schema([DatePicker::make('from')->label(Ui::text('labels.from')), DatePicker::make('until')->label(Ui::text('labels.until'))])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('requested_at', '>=', is_string($date) ? $date : null))
                    ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('requested_at', '<=', is_string($date) ? $date : null))),
                Filter::make('exact_uuid')->label(Ui::text('pages.runs.exact_uuid'))->schema([TextInput::make('uuid')->label(Ui::text('labels.uuid'))->uuid()])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['uuid'] ?? null, fn (Builder $q, $uuid): Builder => $q->where('uuid', '=', $uuid))),
            ])
            ->headerActions([Action::make('create_backup')->label(Ui::text('operator.create_backup'))
                ->schema([Radio::make('profile')->label(Ui::text('operator.backup_type'))->options([
                    BackupProfile::Recovery->value => Ui::text('backup_choices.recovery'),
                    BackupProfile::Database->value => Ui::text('backup_choices.database'),
                    BackupProfile::Media->value => Ui::text('backup_choices.media'),
                ])->descriptions([
                    BackupProfile::Recovery->value => Ui::text('backup_choices.recovery_help'),
                    BackupProfile::Database->value => Ui::text('backup_choices.database_help'),
                    BackupProfile::Media->value => Ui::text('backup_choices.media_help'),
                ])->default(BackupProfile::Recovery->value)->required()])
                ->modalDescription(Ui::text('pages.runs.request_help'))
                ->modalSubmitActionLabel(Ui::text('operator.create_backup'))
                ->visible(fn (): bool => BackupPanelAccess::allows('run-backup') && (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled'))
                ->action(fn (array $data) => $this->requestBackupChoice($data))])
            ->recordActions([
                Action::make('details')->label(Ui::text('actions.details'))->schema([
                    Section::make(Ui::text('pages.runs.details'))->columns(2)->schema([
                        TextEntry::make('profile')->label(Ui::text('labels.profile'))->state(fn (BackupRun $record): string => Ui::value($record->profile, 'backup_types')),
                        TextEntry::make('status')->label(Ui::text('labels.status'))->state(fn (BackupRun $record): string => OperatorStatus::backupStage($record))->badge(),
                        TextEntry::make('availability')->label(Ui::text('operator.restore_availability'))->state(fn (BackupRun $record): string => $this->availability($record) === 'unavailable' ? Ui::text('operator.unavailable') : Ui::value($this->availability($record), 'profiles')),
                        TextEntry::make('created')->label(Ui::text('labels.created'))->state(fn (BackupRun $record): string => Ui::dateTime($record->requested_at)),
                        TextEntry::make('completed')->label(Ui::text('labels.completed_at'))->state(fn (BackupRun $record): string => Ui::dateTime($record->completed_at)),
                        TextEntry::make('warning')->label(Ui::text('labels.warning'))->state(fn (BackupRun $record): string => $record->consistency === ConsistencyLevel::BestEffort ? Ui::text('operator.best_effort') : OperatorStatus::failure($record->failure_code, $record->failure_message))->visible(fn (BackupRun $record): bool => $record->consistency === ConsistencyLevel::BestEffort || $record->failure_code !== null),
                    ]),
                    Section::make(Ui::text('pages.runs.technical'))->collapsible()->collapsed()->schema([
                        TextEntry::make('uuid')->label(Ui::text('labels.uuid')),
                        TextEntry::make('archive_status')->label(Ui::text('labels.archive'))->state(fn (BackupRun $record): string => Ui::value($this->componentStatus($record, ArtifactKind::ApplicationArchive))),
                        TextEntry::make('snapshot_status')->label(Ui::text('labels.snapshot'))->state(fn (BackupRun $record): string => Ui::value($this->componentStatus($record, ArtifactKind::ResticSnapshot))),
                        TextEntry::make('consistency')->label(Ui::text('labels.consistency'))->state(fn (BackupRun $record): string => Ui::value($record->consistency, 'consistency')),
                        TextEntry::make('trigger')->label(Ui::text('labels.trigger'))->state(fn (BackupRun $record): string => Ui::value($record->trigger, 'triggers')),
                        TextEntry::make('archive_locator')->label(Ui::text('labels.archive'))->state(fn (BackupRun $record): string => (string) $record->artifacts->firstWhere('kind', ArtifactKind::ApplicationArchive)?->locator),
                        TextEntry::make('archive_sha256')->label(Ui::text('labels.archive_sha256'))->state(fn (BackupRun $record): string => (string) $record->artifacts->firstWhere('kind', ArtifactKind::ApplicationArchive)?->sha256),
                        TextEntry::make('archive_size')->label(Ui::text('labels.archive_size'))->state(fn (BackupRun $record): string => Ui::text('units.bytes', ['count' => $record->artifacts->firstWhere('kind', ArtifactKind::ApplicationArchive)?->byte_size])),
                        TextEntry::make('snapshot_id')->label(Ui::text('labels.snapshot_id'))->state(fn (BackupRun $record): string => (string) $record->artifacts->firstWhere('kind', ArtifactKind::ResticSnapshot)?->snapshot_id),
                        TextEntry::make('repository_id')->label(Ui::text('labels.repository_id'))->state(fn (BackupRun $record): string => $this->repositoryId($record)),
                        TextEntry::make('manifest')->label(Ui::text('labels.manifest'))->state(fn (BackupRun $record): string => Ui::value($record->artifacts->firstWhere('kind', ArtifactKind::RemoteManifest)?->status)),
                        TextEntry::make('failure')->label(Ui::text('labels.failure'))->state(fn (BackupRun $record): string => trim((string) $record->failure_stage.' '.(string) $record->failure_code)),
                        TextEntry::make('failure_message')->label(Ui::text('operator.technical_failure'))->state(fn (BackupRun $record): string => app(SecretRedactor::class)->redact((string) $record->failure_message)),
                        TextEntry::make('retention')->label(Ui::text('labels.retention'))->state(fn (BackupRun $record): string => $record->isPinned() ? Ui::text('statuses.protected_until', ['time' => Ui::dateTime($record->pinned_until)]) : Ui::text('statuses.not_pinned')),
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
            return 'not_applicable';
        }

        return $this->verified($run, $kind) ? 'verified' : 'missing_failed';
    }

    private function availability(BackupRun $run): string
    {
        if ($run->status !== BackupStatus::Completed) {
            return 'unavailable';
        }

        $database = $this->verified($run, ArtifactKind::ApplicationArchive);
        $files = $this->verified($run, ArtifactKind::ResticSnapshot);

        return match (true) {
            $run->profile === BackupProfile::Recovery && $database && $files => 'full',
            $database => 'database',
            $files => 'media',
            default => 'unavailable',
        };
    }

    private function verified(BackupRun $run, ArtifactKind $kind): bool
    {
        return $run->artifacts->contains(fn ($artifact): bool => $artifact->kind === $kind && $artifact->status === ArtifactStatus::Verified);
    }

    /** @param array<array-key, mixed> $data */
    private function requestBackupChoice(array $data): void
    {
        $profile = $data['profile'] ?? null;
        if (! is_string($profile) || BackupProfile::tryFrom($profile) === null) {
            Notification::make()->title(Ui::text('pages.runs.request_refused'))->danger()->send();

            return;
        }

        $this->requestBackup(BackupProfile::from($profile));
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
            app(PendingBackupRequest::class)->request($profile);
            Notification::make()->title(Ui::text('pages.runs.requested'))->body(Ui::text('pages.runs.request_pending'))->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title(Ui::text('pages.runs.request_refused'))->body(app(SecretRedactor::class)->redact($exception->getMessage()))->danger()->send();
        }
    }
}
