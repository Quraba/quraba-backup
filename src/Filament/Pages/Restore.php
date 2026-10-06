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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Filament\OperatorStatus;
use Quraba\Backup\Filament\RestoreSources;
use Quraba\Backup\Filament\Ui;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Operations\LiveApprovalStore;
use Quraba\Backup\Operations\PanelTableAvailability;
use Quraba\Backup\Operations\PendingOperationRequest;
use Quraba\Backup\Restore\Live\LiveRestoreAuthorization;
use Quraba\Backup\Restore\Live\RestoreReconciler;
use Throwable;

final class Restore extends Page
{
    protected string $view = 'quraba-backup::filament.restore';

    protected static ?int $navigationSort = 3;

    public ?string $checkUuid = null;

    public string $restoreScope = 'full';

    public ?string $restoreSourceUuid = null;

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

    public function getSubheading(): string
    {
        return Ui::text('visual.restore_subtitle');
    }

    public function getPageClasses(): array
    {
        return ['qb-design', 'qb-restore'];
    }

    public function updatedRestoreScope(): void
    {
        $this->restoreSourceUuid = null;
    }

    public function checkSelectedBackup(): void
    {
        $this->submitCheck(['restore_profile' => $this->restoreScope, 'source_run_uuid' => $this->restoreSourceUuid]);
    }

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-recovery');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start_restoration')->label(Ui::text('operator.start_restore'))->icon('heroicon-o-play')
                ->visible(fn (): bool => BackupPanelAccess::allows('dry-restore') && $this->requestsAvailable())
                ->schema([
                    Wizard::make([
                        Step::make(Ui::text('operator.scope'))->schema([
                            Select::make('restore_profile')->label(Ui::text('operator.scope'))
                                ->options(['full' => Ui::text('backup_choices.recovery'), 'database' => Ui::text('backup_choices.database'), 'media' => Ui::text('backup_choices.media')])
                                ->default('full')->required()->live()
                                ->helperText(fn (Get $get): string => $this->impact($get('restore_profile'))),
                        ]),
                        Step::make(Ui::text('operator.source'))->schema([$this->sourceField()]),
                    ]),
                ])->modalWidth('3xl')->modalSubmitActionLabel(Ui::text('operator.check_backup'))
                ->action(fn (array $data) => $this->submitCheck($data)),
            Action::make('restore_now')->label(Ui::text('operator.restore_now'))->color('danger')
                ->visible(fn (): bool => BackupPanelAccess::allows('live-restore') && (bool) config('quraba-backup.filament.live_restore_enabled') && $this->canRestoreNow())
                ->schema([
                    Placeholder::make('impact')->label(Ui::text('pages.restore.impact_step'))
                        ->content(fn (): string => $this->impact($this->currentCheck()?->restore_profile?->value)),
                    Placeholder::make('check_result')->label(Ui::text('operator.check_backup'))->content(Ui::text('operator.check_passed')),
                    Checkbox::make('acknowledge_replacement')->label(Ui::text('operator.ack_replacement'))->required()->accepted(),
                    Checkbox::make('acknowledge_maintenance')->label(Ui::text('operator.ack_maintenance'))->required()->accepted(),
                    TextInput::make('confirmation')->label(Ui::text('operator.confirm_phrase', ['phrase' => LiveRestoreAuthorization::phrase(app(Repository::class))]))->required()->autocomplete('off'),
                ])->modalWidth('2xl')->requiresConfirmation()
                ->action(fn (array $data) => $this->submitLive($data)),
        ];
    }

    private function sourceField(): Select
    {
        return Select::make('source_run_uuid')->label(Ui::text('operator.source'))->required()->searchable()->preload()
            ->helperText(Ui::text('operator.source_help'))
            ->options(fn (Get $get): array => $this->sourceOptions($get('restore_profile')))
            ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->sourceOptions($get('restore_profile'), $search))
            ->getOptionLabelUsing(fn (string $value, Get $get): ?string => $this->sourceOptions($get('restore_profile'), $value)[$value] ?? null);
    }

    /** @return array<string, string> */
    private function sourceOptions(mixed $scope, ?string $search = null): array
    {
        $profile = is_string($scope) ? RestoreProfile::tryFrom($scope) : null;
        if ($profile === null || ! $this->catalogAvailable()) {
            return [];
        }
        $query = app(RestoreSources::class)->eligible($profile);
        if ($search !== null && $search !== '') {
            $query->where('uuid', 'like', '%'.$search.'%');
        }

        $options = [];
        foreach ($query->latest('requested_at')->limit(30)->get() as $run) {
            $options[$run->uuid] = app(RestoreSources::class)->label($run);
        }

        return $options;
    }

    private function impact(mixed $scope): string
    {
        return Ui::text(match ($scope) {
            RestoreProfile::Database->value => 'operator.impact_database',
            RestoreProfile::Media->value => 'operator.impact_media',
            default => 'operator.impact_full',
        });
    }

    /** @param array<array-key, mixed> $data */
    private function submitCheck(array $data): void
    {
        BackupPanelAccess::authorize('dry-restore');
        try {
            $scopeValue = $data['restore_profile'] ?? null;
            if (! is_string($scopeValue) || RestoreProfile::tryFrom($scopeValue) === null) {
                throw new \InvalidArgumentException(Ui::text('form_errors.source_scope_required'));
            }
            $scope = RestoreProfile::from($scopeValue);
            $source = $data['source_run_uuid'] ?? null;
            if (! is_string($source) || ! $this->catalogAvailable() || ! app(RestoreSources::class)->contains($source, $scope)) {
                throw new \DomainException(Ui::text('operator.source_changed'));
            }
            $actor = Filament::auth()->user();
            abort_unless($actor instanceof Authenticatable, 403);
            $operation = app(PendingOperationRequest::class)->submit(PendingOperationType::DryRestore, $actor, $source, $scope);
            $this->checkUuid = $operation->uuid;
            Notification::make()->title(Ui::text('operator.checking_requested'))->success()->send();
        } catch (Throwable $exception) {
            $this->failureNotice($exception);
        }
    }

    /** @param array<array-key, mixed> $data */
    private function submitLive(array $data): void
    {
        BackupPanelAccess::authorize('live-restore');
        try {
            $check = $this->currentCheck();
            if (! $this->canRestoreNow() || $check === null || ! is_string($check->source_run_uuid) || $check->restore_profile === null) {
                throw new \DomainException(Ui::text('operator.approval_unavailable'));
            }
            if (($data['acknowledge_replacement'] ?? false) !== true || ($data['acknowledge_maintenance'] ?? false) !== true) {
                throw new \DomainException(Ui::text('form_errors.acknowledgements'));
            }
            $actor = Filament::auth()->user();
            abort_unless($actor instanceof Authenticatable, 403);
            app(PendingOperationRequest::class)->submit(PendingOperationType::LiveRestore, $actor, $check->source_run_uuid, $check->restore_profile, is_string($data['confirmation'] ?? null) ? $data['confirmation'] : null);
            Notification::make()->title(Ui::text('operator.restore_requested'))->success()->send();
        } catch (Throwable $exception) {
            $this->failureNotice($exception);
        } finally {
            unset($data['confirmation']);
        }
    }

    private function failureNotice(Throwable $exception): void
    {
        Notification::make()->title(Ui::text('pages.restore.request_refused'))
            ->body(OperatorStatus::failure(null, $exception->getMessage()))->danger()->send();
    }

    public function resumeCheck(string $uuid): void
    {
        BackupPanelAccess::authorize('view-recovery');
        if ($this->requestsAvailable() && PendingOperation::query()->where('uuid', $uuid)->where('type', PendingOperationType::DryRestore->value)->exists()) {
            $this->checkUuid = $uuid;
        }
    }

    private function currentCheck(): ?PendingOperation
    {
        if ($this->checkUuid === null || ! $this->requestsAvailable()) {
            return null;
        }

        return PendingOperation::query()->where('uuid', $this->checkUuid)->where('type', PendingOperationType::DryRestore->value)->first();
    }

    private function canRestoreNow(): bool
    {
        $check = $this->currentCheck();

        if ($check === null || ! is_string($check->source_run_uuid) || $check->restore_profile === null) {
            return false;
        }
        $latest = PendingOperation::query()->where('type', PendingOperationType::DryRestore->value)
            ->where('source_run_uuid', $check->source_run_uuid)->where('restore_profile', $check->restore_profile->value)->latest('id')->first();

        return $latest?->id === $check->id && $check->status === PendingOperationStatus::Completed
            && $check->finished_at !== null && ! $check->finished_at->lessThan(now('UTC')->subDay())
            && ($check->result['ok'] ?? false) === true && ($check->result['blockers'] ?? []) === []
            && $this->catalogAvailable() && app(RestoreSources::class)->contains($check->source_run_uuid, $check->restore_profile);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        BackupPanelAccess::authorize('view-recovery');
        try {
            $journals = app(RestoreReconciler::class)->overview();
        } catch (Throwable) {
            $journals = ['journals' => [], 'unreadable' => ['unavailable'], 'unresolved' => 0];
        }
        $tables = app(PanelTableAvailability::class);
        $operationsAvailable = $tables->has('operations');
        $sourceRuns = collect();
        if ($this->catalogAvailable()) {
            try {
                $sourceUuids = collect($journals['journals'])->pluck('source_run_uuid')->filter(is_string(...))->all();
                $sourceRuns = BackupRun::query()->whereIn('uuid', $sourceUuids)->get()->keyBy('uuid');
            } catch (Throwable) {
                // The external journal still renders when a restored catalog is unavailable.
            }
        }
        try {
            $requests = $operationsAvailable ? PendingOperation::query()->whereIn('type', [PendingOperationType::DryRestore->value, PendingOperationType::LiveRestore->value])->latest('id')->limit(15)->get() : collect();
        } catch (Throwable) {
            $requests = collect();
            $operationsAvailable = false;
        }

        return [
            'journals' => $journals,
            'journalActive' => collect($journals['journals'])->contains(fn (array $journal): bool => ($journal['terminal'] ?? null) === null && ($journal['resolution'] ?? null) === null),
            'sourceRuns' => $sourceRuns,
            'requests' => $requests,
            'check' => $this->currentCheck(),
            'canRestore' => $this->canRestoreNow(),
            'approvalHistory' => app(LiveApprovalStore::class)->history(),
            'liveEnabled' => (bool) config('quraba-backup.filament.live_restore_enabled'),
            'pendingEnabled' => (bool) config('quraba-backup.filament.pending_enabled'),
            'operationsAvailable' => $operationsAvailable,
            'restoresAvailable' => $tables->has('restores'),
            'sourceOptions' => $this->sourceOptions($this->restoreScope),
            'canCheckBackup' => BackupPanelAccess::allows('dry-restore') && $this->requestsAvailable(),
        ];
    }

    private function requestsAvailable(): bool
    {
        return (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled') && app(PanelTableAvailability::class)->has('operations');
    }

    private function catalogAvailable(): bool
    {
        $tables = app(PanelTableAvailability::class);

        return $tables->has('runs') && $tables->has('artifacts');
    }
}
