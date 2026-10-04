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
use Quraba\Backup\Enums\RestoreMode;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Filament\BackupPanelAccess;
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

    protected static ?string $navigationLabel = 'Restore';

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-recovery');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('dry_restore')->label('Prepare dry restore')->visible(fn (): bool => BackupPanelAccess::allows('dry-restore') && (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled') && app(PanelTableAvailability::class)->has('operations'))
                ->schema($this->sourceFields())
                ->action(fn (array $data) => $this->submit(PendingOperationType::DryRestore, $data)),
            Action::make('live_restore')->label('Request live restore')->color('danger')
                ->visible(fn (): bool => BackupPanelAccess::allows('live-restore') && (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled') && (bool) config('quraba-backup.filament.live_restore_enabled') && app(PanelTableAvailability::class)->has('operations'))
                ->schema([
                    Wizard::make([
                        Step::make('Exact source')->schema($this->sourceFields()),
                        Step::make('Impact')->schema([
                            Placeholder::make('impact')->content(fn (Get $get): string => $this->impactSummary($get)),
                            Placeholder::make('maintenance')->content('A verified safety backup is taken before replacement. The application remains in maintenance mode afterwards; use CLI to verify and bring it up.'),
                        ]),
                        Step::make('Dry restore guidance')->schema([
                            Placeholder::make('dry_guidance')->content(fn (Get $get): string => $this->dryGuidance($get)),
                        ]),
                        Step::make('Warnings and blockers')->schema([
                            Placeholder::make('warnings')->content(fn (Get $get): string => $this->warningsSummary($get)),
                        ]),
                        Step::make('Acknowledgements')->schema([
                            Checkbox::make('acknowledge_replacement')->label('I understand that live data will be replaced')->required()->accepted(),
                            Checkbox::make('acknowledge_maintenance')->label('I will verify the restored application and complete CLI follow-up before bringing it up')->required()->accepted(),
                        ]),
                        Step::make('Exact confirmation')->schema([
                            TextInput::make('confirmation')->label('Type '.LiveRestoreAuthorization::phrase(app(Repository::class)).' exactly')->required()->autocomplete('off'),
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
            Select::make('known_source_run_uuid')->label('Known recovery point')->searchable()->preload()->required(fn (Get $get): bool => blank($get('manual_source_run_uuid')))
                ->options(fn (): array => $this->catalogAvailable() ? $this->eligibleSourceQuery()->latest('requested_at')->limit(30)->get()->mapWithKeys(fn (BackupRun $run): array => [$run->uuid => $this->sourceLabel($run)])->all() : [])
                ->getSearchResultsUsing(fn (string $search): array => $this->catalogAvailable() ? $this->eligibleSourceQuery()->where('uuid', 'like', '%'.$search.'%')->latest('requested_at')->limit(30)->get()->mapWithKeys(fn (BackupRun $run): array => [$run->uuid => $this->sourceLabel($run)])->all() : [])
                ->getOptionLabelUsing(function (string $value): ?string {
                    $run = $this->catalogAvailable() ? $this->eligibleSourceQuery()->where('uuid', $value)->first() : null;

                    return $run instanceof BackupRun ? $this->sourceLabel($run) : null;
                })
                ->live()->helperText('Browse recent verified full Recovery Points or search by UUID. No source is selected automatically.'),
            TextInput::make('manual_source_run_uuid')->label('Manual exact UUID (advanced)')->uuid()->required(fn (Get $get): bool => blank($get('known_source_run_uuid')))->live(onBlur: true)->helperText('Use only when the local catalog is missing a recoverable immutable remote manifest. Leave the selector empty.'),
            Select::make('restore_profile')->label('Restore scope')->options(['database' => 'Database', 'media' => 'Media', 'full' => 'Full'])->required()->default('full')->live(),
        ];
    }

    public function table(Table $table): Table
    {
        BackupPanelAccess::authorize('view-recovery');

        return $table->query(RestoreRun::query()->latest('id'))
            ->columns([
                TextColumn::make('created_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('mode')->badge()->formatStateUsing(fn ($state): string => $state instanceof RestoreMode ? ($state === RestoreMode::DryRun ? 'Dry restore' : 'Live restore') : (is_string($state) ? $state : 'Unknown')),
                TextColumn::make('profile')->badge(),
                TextColumn::make('status')->badge()->color(fn (RestoreRun $record): string => match ($record->status->value) {
                    'completed' => 'success', 'failed', 'indeterminate', 'abandoned' => 'danger', default => 'warning'
                }),
                TextColumn::make('source_run_uuid')->label('Source')->copyable()->toggleable(),
                TextColumn::make('completed_at')->label('Completed')->dateTime()->toggleable(),
                TextColumn::make('destructive_started_at')->label('Boundary crossed')->formatStateUsing(fn ($state): string => $state === null ? 'No' : 'Yes'),
                TextColumn::make('pre_change_run_uuid')->label('Safety backup')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('journal_phase')->label('Journal state')->state(function (RestoreRun $record): string {
                    $journal = $record->metadata['journal'] ?? null;
                    $phase = is_array($journal) ? ($journal['phase'] ?? null) : null;

                    return is_string($phase) ? $phase : 'Not mirrored';
                })->toggleable(),
                TextColumn::make('uuid')->label('Restore UUID')->copyable()->toggleable(isToggledHiddenByDefault: true),
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
                throw new \DomainException('Both live restore acknowledgements are required.');
            }
            $profileValue = $data['restore_profile'] ?? null;
            $known = $data['known_source_run_uuid'] ?? null;
            $manual = $data['manual_source_run_uuid'] ?? null;
            if (filled($known) && filled($manual)) {
                throw new \InvalidArgumentException('Choose either a known recovery point or a manual exact UUID.');
            }
            $source = filled($known) ? $known : $manual;
            if (filled($known) && (! is_string($known) || ! $this->eligibleSourceQuery()->where('uuid', $known)->exists())) {
                throw new \InvalidArgumentException('The selected recovery point is no longer eligible.');
            }
            $actor = Filament::auth()->user();
            abort_unless($actor instanceof Authenticatable, 403);
            if (! is_string($profileValue) || ! is_string($source)) {
                throw new \InvalidArgumentException('An exact source and restore scope are required.');
            }
            $profile = RestoreProfile::from($profileValue);
            $operation = app(PendingOperationRequest::class)->submit($type, $actor, $source, $profile, is_string($phrase) ? $phrase : null);
            Notification::make()->title('Restore request queued')->body('Operation '.$operation->uuid.' will run through the scheduler.')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Restore request refused')->body(app(SecretRedactor::class)->redact($exception->getMessage()))->danger()->send();
        } finally {
            unset($phrase, $data['confirmation']);
        }
    }

    private function impactSummary(Get $get): string
    {
        $uuid = $this->selectedSource($get);
        if (! is_string($uuid) || $uuid === '') {
            return 'Enter an exact source UUID to review its recorded components.';
        }
        $run = app(PanelTableAvailability::class)->has('runs') && app(PanelTableAvailability::class)->has('artifacts') ? BackupRun::query()->with('artifacts')->where('uuid', $uuid)->first() : null;
        if ($run === null) {
            return 'This source is absent from the local catalog. The worker will resolve the immutable remote manifest before any change.';
        }
        $archive = $run->artifacts->firstWhere('kind', ArtifactKind::ApplicationArchive);
        $snapshot = $run->artifacts->firstWhere('kind', ArtifactKind::ResticSnapshot);
        $repository = is_array($snapshot?->metadata) ? ($snapshot->metadata['repository_id'] ?? null) : null;
        $scope = $get('restore_profile');

        return sprintf('Source %s · %s · consistency %s · scope %s. Archive %s (SHA %s). Snapshot %s. Repository %s. Live targets: %s. A verified safety backup is required before replacement.',
            $run->uuid, $run->status->value, $run->consistency->value, is_string($scope) ? $scope : 'full',
            $archive === null ? 'missing' : $archive->status->value, $archive === null ? 'unknown' : ($archive->sha256 ?? 'unknown'), $snapshot === null ? 'missing' : ($snapshot->snapshot_id ?? 'missing'), is_string($repository) ? $repository : 'unknown', $this->impactTargets(is_string($scope) ? $scope : 'full'));
    }

    private function impactTargets(string $scope): string
    {
        $targets = [];
        try {
            if ($scope !== 'media') {
                $database = app(DatabaseReplacement::class)->target();
                $targets[] = 'database '.$database->database.' on connection '.$database->connection;
            }
            if ($scope !== 'database') {
                foreach (app(MediaRootResolver::class)->destinations() as $destination) {
                    $targets[] = 'media '.$destination->name.' at '.$destination->path;
                }
            }
        } catch (Throwable $exception) {
            $targets[] = 'target inspection unavailable: '.app(SecretRedactor::class)->redact($exception->getMessage());
        }

        return implode('; ', $targets);
    }

    private function dryGuidance(Get $get): string
    {
        $uuid = $this->selectedSource($get);
        $profile = $get('restore_profile');
        if (! is_string($uuid) || ! is_string($profile)) {
            return 'A completed dry restore of the exact source and scope is required.';
        }
        if (! app(PanelTableAvailability::class)->has('operations')) {
            return 'Panel operation history is unavailable until package migrations are run after restore verification.';
        }
        $dry = PendingOperation::query()->where('type', PendingOperationType::DryRestore->value)
            ->where('source_run_uuid', $uuid)->where('restore_profile', $profile)
            ->latest('id')->first();
        if ($dry === null) {
            return 'No matching dry restore is recorded. Prepare one first.';
        }
        $result = $dry->result ?? [];
        $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];
        $blockers = is_array($result['blockers'] ?? null) ? $result['blockers'] : [];

        return sprintf('Dry restore %s at %s UTC. %d warning(s), %d blocker(s). A completed result from the last 24 hours is required. The live worker repeats every safety check.',
            $dry->status->value, $dry->finished_at?->toDateTimeString() ?? 'unknown', count($warnings), count($blockers));
    }

    private function warningsSummary(Get $get): string
    {
        $uuid = $this->selectedSource($get);
        $profile = $get('restore_profile');
        if (! is_string($uuid) || ! is_string($profile)) {
            return 'Choose an exact source and scope to review the recorded dry restore findings.';
        }
        if (! app(PanelTableAvailability::class)->has('operations')) {
            return 'Panel operation history is unavailable until package migrations are run after restore verification.';
        }
        $dry = PendingOperation::query()->where('type', PendingOperationType::DryRestore->value)
            ->where('source_run_uuid', $uuid)->where('restore_profile', $profile)->latest('id')->first();
        if ($dry === null || ! is_array($dry->result)) {
            return 'No completed dry restore findings are available. Submit a dry restore first.';
        }
        $warnings = is_array($dry->result['warnings'] ?? null) ? $dry->result['warnings'] : [];
        $blockers = is_array($dry->result['blockers'] ?? null) ? $dry->result['blockers'] : [];

        return sprintf('Warnings: %s. Blockers: %s. Any blocker prevents submission. New findings discovered by the live worker also stop the restore.',
            $warnings === [] ? 'none recorded' : implode('; ', array_filter($warnings, 'is_string')),
            $blockers === [] ? 'none recorded' : implode('; ', array_filter($blockers, 'is_string')));
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
        return sprintf('%s UTC · Recovery · %s · %s · %s', $run->requested_at?->format('M j, Y H:i') ?? 'Unknown time', $run->status->value, $run->consistency->value, $run->uuid);
    }
}
