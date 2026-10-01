<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use Filament\Pages\Page;
use Livewire\WithPagination;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Models\BackupRun;

final class BackupRuns extends Page
{
    use WithPagination;

    protected string $view = 'quraba-backup::filament.runs';

    protected static ?string $navigationLabel = 'Backup runs';

    public string $profileFilter = '';

    public string $statusFilter = '';

    public string $triggerFilter = '';

    public string $dateFilter = '';

    public ?string $selectedUuid = null;

    public ?string $requestMessage = null;

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-details');
    }

    public function updatedProfileFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTriggerFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function selectRun(string $uuid): void
    {
        BackupPanelAccess::authorize('view-details');
        $this->selectedUuid = $uuid;
    }

    public function requestBackup(string $profile): void
    {
        BackupPanelAccess::authorize('run-backup');
        abort_unless(config('quraba-backup.enabled') && config('quraba-backup.filament.pending_enabled'), 403);
        $value = BackupProfile::tryFrom($profile);
        abort_if($value === null, 422);
        abort_if(BackupRun::query()->where('trigger', BackupTrigger::Api->value)->where('status', 'pending')->count() >= 10, 429);

        $run = BackupRun::request($value, BackupTrigger::Api);
        $this->requestMessage = 'Pending backup request '.$run->uuid.'; the scheduler will process it.';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        BackupPanelAccess::authorize('view-details');

        $query = BackupRun::query()->with('artifacts')->latest('id');
        if (in_array($this->profileFilter, array_column(BackupProfile::cases(), 'value'), true)) {
            $query->where('profile', $this->profileFilter);
        }
        if (in_array($this->statusFilter, ['pending', 'preflighting', 'running', 'verifying', 'completed', 'partial', 'failed', 'canceled', 'indeterminate'], true)) {
            $query->where('status', $this->statusFilter);
        }
        if (in_array($this->triggerFilter, array_column(BackupTrigger::cases(), 'value'), true)) {
            $query->where('trigger', $this->triggerFilter);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->dateFilter) === 1) {
            $query->whereDate('requested_at', $this->dateFilter);
        }

        return [
            'runs' => $query->paginate(20),
            'selected' => $this->selectedUuid !== null ? BackupRun::query()->with('artifacts')->where('uuid', $this->selectedUuid)->first() : null,
            'canRequest' => BackupPanelAccess::allows('run-backup') && (bool) config('quraba-backup.filament.pending_enabled'),
        ];
    }
}
