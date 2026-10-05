<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Quraba\Backup\Contracts\DatabaseReplacement;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Filament\Ui;
use Quraba\Backup\Media\MediaRootResolver;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Operations\LiveApprovalStore;
use Quraba\Backup\Operations\PanelTableAvailability;
use Quraba\Backup\Operations\PendingOperationRequest;
use Quraba\Backup\Restore\Live\LiveRestoreAuthorization;
use Quraba\Backup\Restore\Live\RestoreReconciler;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

final class Restore extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected string $view = 'quraba-backup::filament.restore';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return Ui::text('navigation.restore');
    }

    public static function getNavigationGroup(): string
    {
        return Ui::text('navigation.group');
    }

    public function getTitle(): string
    {
        return Ui::text('pages.restore.title');
    }

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-recovery');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('dry_restore')->label(Ui::text('pages.restore.dry_action'))->visible(fn (): bool => BackupPanelAccess::allows('dry-restore') && (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled') && app(PanelTableAvailability::class)->has('operations'))
                ->schema($this->sourceFields())
                ->action(fn (array $data) => $this->submit(PendingOperationType::DryRestore, $data)),
            Action::make('live_restore')->label(Ui::text('pages.restore.live_action'))->color('danger')
                ->visible(fn (): bool => BackupPanelAccess::allows('live-restore') && (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled') && (bool) config('quraba-backup.filament.live_restore_enabled') && app(PanelTableAvailability::class)->has('operations'))
                ->schema([
                    Wizard::make([
                        Step::make(Ui::text('pages.restore.source_step'))->schema($this->sourceFields()),
                        Step::make(Ui::text('pages.restore.impact_step'))->schema([
                            Placeholder::make('impact')->label(Ui::text('pages.restore.impact_step'))->content(fn (Get $get): string => $this->impactSummary($get)),
                            Placeholder::make('maintenance')->label(Ui::text('labels.safety_backup'))->content(Ui::text('pages.restore.safety_notice')),
                        ]),
                        Step::make(Ui::text('pages.restore.dry_step'))->schema([
                            Placeholder::make('dry_guidance')->label(Ui::text('pages.restore.dry_step'))->content(fn (Get $get): string => $this->dryGuidance($get)),
                        ]),
                        Step::make(Ui::text('pages.restore.warnings_step'))->schema([
                            Placeholder::make('warnings')->label(Ui::text('pages.restore.warnings_step'))->content(fn (Get $get): string => $this->warningsSummary($get)),
                        ]),
                        Step::make(Ui::text('pages.restore.ack_step'))->schema([
                            Checkbox::make('acknowledge_replacement')->label(Ui::text('pages.restore.ack_replacement'))->required()->accepted(),
                            Checkbox::make('acknowledge_maintenance')->label(Ui::text('pages.restore.ack_maintenance'))->required()->accepted(),
                        ]),
                        Step::make(Ui::text('pages.restore.confirm_step'))->schema([
                            TextInput::make('confirmation')->label(Ui::text('pages.restore.type_exactly', ['phrase' => LiveRestoreAuthorization::phrase(app(Repository::class))]))->required()->autocomplete('off'),
                        ]),
                    ]),
                ])
                ->modalWidth('4xl')
                ->action(fn (array $data) => $this->submit(PendingOperationType::LiveRestore, $data)),
        ];
    }

    /** @return list<Component> */
    private function sourceFields(): array
    {
        return [
            Select::make('known_source_run_uuid')->label(Ui::text('pages.restore.known_source'))->searchable()->preload()->required(fn (Get $get): bool => blank($get('manual_source_run_uuid')))
                ->options(fn (): array => $this->catalogAvailable() ? $this->eligibleSourceQuery()->latest('requested_at')->limit(30)->get()->mapWithKeys(fn (BackupRun $run): array => [$run->uuid => $this->sourceLabel($run)])->all() : [])
                ->getSearchResultsUsing(fn (string $search): array => $this->catalogAvailable() ? $this->eligibleSourceQuery()->where('uuid', 'like', '%'.$search.'%')->latest('requested_at')->limit(30)->get()->mapWithKeys(fn (BackupRun $run): array => [$run->uuid => $this->sourceLabel($run)])->all() : [])
                ->getOptionLabelUsing(function (string $value): ?string {
                    $run = $this->catalogAvailable() ? $this->eligibleSourceQuery()->where('uuid', $value)->first() : null;

                    return $run instanceof BackupRun ? $this->sourceLabel($run) : null;
                })
                ->live()->helperText(Ui::text('pages.restore.known_source_help')),
            TextInput::make('manual_source_run_uuid')->label(Ui::text('pages.restore.manual_source'))->uuid()->required(fn (Get $get): bool => blank($get('known_source_run_uuid')))->live(onBlur: true)->helperText(Ui::text('pages.restore.manual_source_help')),
            Select::make('restore_profile')->label(Ui::text('pages.restore.scope'))->options(['database' => Ui::text('profiles.database'), 'media' => Ui::text('profiles.media'), 'full' => Ui::text('profiles.full')])->required()->default('full')->live(),
        ];
    }

    public function table(Table $table): Table
    {
        BackupPanelAccess::authorize('view-recovery');

        return $table->query(RestoreRun::query()->latest('id'))
            ->columns([
                TextColumn::make('created_at')->label(Ui::text('labels.started'))->dateTime()->sortable(),
                TextColumn::make('mode')->label(Ui::text('labels.operation'))->badge()->formatStateUsing(fn ($state): string => Ui::value($state, 'modes')),
                TextColumn::make('profile')->label(Ui::text('labels.profile'))->badge()->formatStateUsing(fn ($state): string => Ui::value($state, 'profiles')),
                TextColumn::make('status')->label(Ui::text('labels.status'))->badge()->formatStateUsing(fn ($state): string => Ui::value($state))->color(fn (RestoreRun $record): string => match ($record->status->value) {
                    'completed' => 'success', 'failed', 'indeterminate', 'abandoned' => 'danger', default => 'warning'
                }),
                TextColumn::make('source_run_uuid')->label(Ui::text('labels.source'))->copyable()->toggleable(),
                TextColumn::make('completed_at')->label(Ui::text('labels.completed_at'))->dateTime()->toggleable(),
                TextColumn::make('destructive_started_at')->label(Ui::text('labels.boundary'))->badge()->state(fn (RestoreRun $record): string => $record->destructive_started_at === null ? 'not_crossed' : 'crossed')->formatStateUsing(fn (string $state): string => Ui::value($state))->color(fn (string $state): string => $state === 'crossed' ? 'warning' : 'gray'),
                TextColumn::make('pre_change_run_uuid')->label(Ui::text('labels.safety_backup'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('journal_phase')->label(Ui::text('labels.journal_state'))->state(function (RestoreRun $record): string {
                    $journal = $record->metadata['journal'] ?? null;
                    $phase = is_array($journal) ? ($journal['phase'] ?? null) : null;

                    return is_string($phase) ? $phase : 'not_mirrored';
                })->formatStateUsing(fn (string $state): string => Ui::value($state, 'journal_phases'))->toggleable(),
                TextColumn::make('uuid')->label(Ui::text('labels.restore_uuid'))->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultPaginationPageOption(20)
            ->poll(fn (): ?string => app(PanelTableAvailability::class)->has('operations') && PendingOperation::query()->whereIn('status', ['pending', 'claimed', 'running'])->exists() ? '20s' : null);
    }

    protected function getViewData(): array
    {
        BackupPanelAccess::authorize('view-recovery');
        try {
            $journals = app(RestoreReconciler::class)->overview();
        } catch (Throwable) {
            $journals = ['journals' => [], 'unreadable' => ['Journal directory unavailable'], 'unresolved' => 0];
        }

        $tables = app(PanelTableAvailability::class);
        $operationsAvailable = $tables->has('operations');
        try {
            $requests = $operationsAvailable ? PendingOperation::query()->whereIn('type', [PendingOperationType::DryRestore->value, PendingOperationType::LiveRestore->value])->latest('id')->limit(10)->get() : collect();
        } catch (Throwable) {
            $requests = collect();
            $operationsAvailable = false;
        }

        return [
            'journals' => $journals,
            'requests' => $requests,
            'approvalHistory' => app(LiveApprovalStore::class)->history(),
            'liveEnabled' => (bool) config('quraba-backup.filament.live_restore_enabled'),
            'pendingEnabled' => (bool) config('quraba-backup.filament.pending_enabled'),
            'operationsAvailable' => $operationsAvailable,
            'restoresAvailable' => $tables->has('restores'),
        ];
    }

    /** @param array<array-key, mixed> $data */
    private function submit(PendingOperationType $type, array $data): void
    {
        BackupPanelAccess::authorize($type->ability());
        $phrase = $data['confirmation'] ?? null;
        try {
            if ($type === PendingOperationType::LiveRestore && (($data['acknowledge_replacement'] ?? false) !== true || ($data['acknowledge_maintenance'] ?? false) !== true)) {
                throw new \DomainException(Ui::text('form_errors.acknowledgements'));
            }
            $profileValue = $data['restore_profile'] ?? null;
            $known = $data['known_source_run_uuid'] ?? null;
            $manual = $data['manual_source_run_uuid'] ?? null;
            if (filled($known) && filled($manual)) {
                throw new \InvalidArgumentException(Ui::text('form_errors.one_source'));
            }
            $source = filled($known) ? $known : $manual;
            if (filled($known) && (! is_string($known) || ! $this->eligibleSourceQuery()->where('uuid', $known)->exists())) {
                throw new \InvalidArgumentException(Ui::text('form_errors.source_ineligible'));
            }
            $actor = Filament::auth()->user();
            abort_unless($actor instanceof Authenticatable, 403);
            if (! is_string($profileValue) || ! is_string($source)) {
                throw new \InvalidArgumentException(Ui::text('form_errors.source_scope_required'));
            }
            $profile = RestoreProfile::from($profileValue);
            $operation = app(PendingOperationRequest::class)->submit($type, $actor, $source, $profile, is_string($phrase) ? $phrase : null);
            Notification::make()->title(Ui::text('pages.restore.request_queued'))->body(Ui::text('pages.restore.request_queue_body', ['uuid' => $operation->uuid]))->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title(Ui::text('pages.restore.request_refused'))->body(app(SecretRedactor::class)->redact($exception->getMessage()))->danger()->send();
        } finally {
            unset($phrase, $data['confirmation']);
        }
    }

    private function impactSummary(Get $get): string
    {
        $uuid = $this->selectedSource($get);
        if (! is_string($uuid) || $uuid === '') {
            return Ui::text('pages.restore.source_prompt');
        }
        $run = app(PanelTableAvailability::class)->has('runs') && app(PanelTableAvailability::class)->has('artifacts') ? BackupRun::query()->with('artifacts')->where('uuid', $uuid)->first() : null;
        if ($run === null) {
            return Ui::text('pages.restore.source_absent');
        }
        $archive = $run->artifacts->firstWhere('kind', ArtifactKind::ApplicationArchive);
        $snapshot = $run->artifacts->firstWhere('kind', ArtifactKind::ResticSnapshot);
        $repository = is_array($snapshot?->metadata) ? ($snapshot->metadata['repository_id'] ?? null) : null;
        $scope = $get('restore_profile');

        return Ui::text('pages.restore.impact_summary', [
            'uuid' => $run->uuid, 'status' => Ui::value($run->status), 'consistency' => Ui::value($run->consistency, 'consistency'),
            'scope' => Ui::value(is_string($scope) ? $scope : 'full', 'profiles'),
            'archive' => $archive === null ? Ui::value('missing') : Ui::value($archive->status),
            'sha' => $archive === null ? Ui::value('unknown') : ($archive->sha256 ?? Ui::value('unknown')),
            'snapshot' => $snapshot === null ? Ui::value('missing') : ($snapshot->snapshot_id ?? Ui::value('missing')),
            'repository' => is_string($repository) ? $repository : Ui::value('unknown'),
            'targets' => $this->impactTargets(is_string($scope) ? $scope : 'full'),
        ]);
    }

    private function impactTargets(string $scope): string
    {
        $targets = [];
        try {
            if ($scope !== 'media') {
                $database = app(DatabaseReplacement::class)->target();
                $targets[] = Ui::text('pages.restore.database_target', ['database' => $database->database, 'connection' => $database->connection]);
            }
            if ($scope !== 'database') {
                foreach (app(MediaRootResolver::class)->destinations() as $destination) {
                    $targets[] = Ui::text('pages.restore.media_target', ['name' => $destination->name, 'path' => $destination->path]);
                }
            }
        } catch (Throwable $exception) {
            $targets[] = Ui::text('pages.restore.inspection_unavailable', ['error' => app(SecretRedactor::class)->redact($exception->getMessage())]);
        }

        return implode('; ', $targets);
    }

    private function dryGuidance(Get $get): string
    {
        $uuid = $this->selectedSource($get);
        $profile = $get('restore_profile');
        if (! is_string($uuid) || ! is_string($profile)) {
            return Ui::text('pages.restore.dry_required');
        }
        if (! app(PanelTableAvailability::class)->has('operations')) {
            return Ui::text('pages.restore.history_unavailable');
        }
        $dry = PendingOperation::query()->where('type', PendingOperationType::DryRestore->value)
            ->where('source_run_uuid', $uuid)->where('restore_profile', $profile)
            ->latest('id')->first();
        if ($dry === null) {
            return Ui::text('pages.restore.dry_missing');
        }
        $result = $dry->result ?? [];
        $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];
        $blockers = is_array($result['blockers'] ?? null) ? $result['blockers'] : [];

        return Ui::text('pages.restore.dry_summary', [
            'status' => Ui::value($dry->status), 'time' => $dry->finished_at?->toDateTimeString() ?? Ui::value('unknown'),
            'warnings' => count($warnings), 'blockers' => count($blockers),
        ]);
    }

    private function warningsSummary(Get $get): string
    {
        $uuid = $this->selectedSource($get);
        $profile = $get('restore_profile');
        if (! is_string($uuid) || ! is_string($profile)) {
            return Ui::text('pages.restore.choose_source');
        }
        if (! app(PanelTableAvailability::class)->has('operations')) {
            return Ui::text('pages.restore.history_unavailable');
        }
        $dry = PendingOperation::query()->where('type', PendingOperationType::DryRestore->value)
            ->where('source_run_uuid', $uuid)->where('restore_profile', $profile)->latest('id')->first();
        if ($dry === null || ! is_array($dry->result)) {
            return Ui::text('pages.restore.findings_missing');
        }
        $warnings = is_array($dry->result['warnings'] ?? null) ? $dry->result['warnings'] : [];
        $blockers = is_array($dry->result['blockers'] ?? null) ? $dry->result['blockers'] : [];

        return Ui::text('pages.restore.findings_summary', [
            'warnings' => $warnings === [] ? Ui::value('none_recorded') : implode('; ', array_filter($warnings, 'is_string')),
            'blockers' => $blockers === [] ? Ui::value('none_recorded') : implode('; ', array_filter($blockers, 'is_string')),
        ]);
    }

    private function selectedSource(Get $get): ?string
    {
        $known = $get('known_source_run_uuid');
        $manual = $get('manual_source_run_uuid');

        return is_string($known) && $known !== '' ? $known : (is_string($manual) && $manual !== '' ? $manual : null);
    }

    private function catalogAvailable(): bool
    {
        $tables = app(PanelTableAvailability::class);

        return $tables->has('runs') && $tables->has('artifacts');
    }

    /** @return Builder<BackupRun> */
    private function eligibleSourceQuery(): Builder
    {
        return BackupRun::query()->where('profile', BackupProfile::Recovery->value)
            ->where('status', BackupStatus::Completed->value)
            ->whereHas('artifacts', fn (Builder $query): Builder => $query->where('kind', ArtifactKind::ApplicationArchive->value)->where('status', ArtifactStatus::Verified->value))
            ->whereHas('artifacts', fn (Builder $query): Builder => $query->where('kind', ArtifactKind::ResticSnapshot->value)->where('status', ArtifactStatus::Verified->value));
    }

    private function sourceLabel(BackupRun $run): string
    {
        return Ui::text('pages.restore.source_label', [
            'time' => $run->requested_at?->translatedFormat('j M Y H:i') ?? Ui::text('pages.restore.unknown_time'),
            'status' => Ui::value($run->status), 'consistency' => Ui::value($run->consistency, 'consistency'), 'uuid' => $run->uuid,
        ]);
    }
}
