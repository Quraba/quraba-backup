<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Operations\PanelTableAvailability;
use Quraba\Backup\Operations\PendingOperationRequest;
use Quraba\Backup\Operations\WorkerHeartbeat;
use Quraba\Backup\Scheduling\ScheduleSettings;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

final class HealthMaintenance extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected string $view = 'quraba-backup::filament.health-maintenance';

    protected static ?string $navigationLabel = 'Health & Maintenance';

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-dashboard');
    }

    protected function getHeaderActions(): array
    {
        $requests = [];
        foreach ([[PendingOperationType::HealthRefresh, 'Refresh health'], [PendingOperationType::Doctor, 'Run Doctor'], [PendingOperationType::ResticCheck, 'Check repository'], [PendingOperationType::RetentionPlan, 'Plan retention']] as [$type, $label]) {
            $requests[] = Action::make($type->value)->label($label)
                ->visible(fn (): bool => BackupPanelAccess::allows($type->ability()) && (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled') && app(PanelTableAvailability::class)->has('operations'))
                ->requiresConfirmation()
                ->action(fn () => $this->request($type));
        }
        $requests[] = Action::make('configure_schedule')->label('Edit schedules')
            ->visible(fn (): bool => BackupPanelAccess::allows('configure-schedule') && app(PanelTableAvailability::class)->has('settings'))
            ->fillForm(fn (): array => $this->scheduleFormValues())
            ->schema($this->scheduleFields())
            ->action(fn (array $data) => $this->saveSchedule($data));
        $requests[] = Action::make('reset_schedule')->label('Reset schedule defaults')->color('warning')
            ->visible(fn (): bool => BackupPanelAccess::allows('configure-schedule') && app(PanelTableAvailability::class)->has('settings'))
            ->requiresConfirmation()
            ->action(function (): void {
                BackupPanelAccess::authorize('configure-schedule');
                foreach (ScheduleSettings::keys() as $key) {
                    app(ScheduleSettings::class)->reset($key);
                }
                Notification::make()->title('Schedule overrides removed')->success()->send();
            });

        return $requests;
    }

    public function table(Table $table): Table
    {
        BackupPanelAccess::authorize('view-dashboard');

        return $table->query(BackupMaintenanceRun::query()->latest('id'))
            ->columns([
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
                TextColumn::make('operation')->badge(),
                TextColumn::make('status')->badge()->color(fn (BackupMaintenanceRun $record): string => match ($record->status->value) {
                    'completed' => 'success', 'failed', 'indeterminate' => 'danger', default => 'warning'
                }),
                IconColumn::make('dry_run')->label('Read only')->boolean(),
                TextColumn::make('uuid')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultPaginationPageOption(20);
    }

    protected function getViewData(): array
    {
        BackupPanelAccess::authorize('view-dashboard');
        $tables = app(PanelTableAvailability::class);
        $operationsAvailable = $tables->has('operations');
        $latest = [];
        try {
            foreach (PendingOperationType::cases() as $type) {
                if ($type === PendingOperationType::LiveRestore || $type === PendingOperationType::DryRestore) {
                    continue;
                }
                $latest[$type->value] = $operationsAvailable ? PendingOperation::query()->where('type', $type->value)->latest('id')->first() : null;
            }
            $unresolved = $operationsAvailable ? PendingOperation::query()->whereIn('status', [PendingOperationStatus::Interrupted->value, PendingOperationStatus::Indeterminate->value])->latest('id')->limit(10)->get() : collect();
        } catch (Throwable) {
            $latest = [];
            $unresolved = collect();
            $operationsAvailable = false;
        }
        try {
            $schedule = app(ScheduleSettings::class)->all();
            $scheduleError = null;
        } catch (Throwable $exception) {
            $schedule = [];
            $scheduleError = app(SecretRedactor::class)->redact($exception->getMessage());
        }

        return [
            'latest' => $latest,
            'schedule' => $schedule,
            'scheduleError' => $scheduleError,
            'workerObserved' => app(WorkerHeartbeat::class)->observedAt(),
            'workerRecent' => app(WorkerHeartbeat::class)->recentlyObserved(),
            'pendingEnabled' => (bool) config('quraba-backup.filament.pending_enabled'),
            'operationsAvailable' => $operationsAvailable,
            'maintenanceAvailable' => $tables->has('maintenance'),
            'unresolved' => $unresolved,
            'secrets' => [
                'B2 key ID' => filled(config('quraba-backup.storage.b2.key_id')),
                'B2 application key' => filled(config('quraba-backup.storage.b2.application_key')),
                'Archive password' => filled(config('quraba-backup.archive.password')),
                'Restic password file' => filled(config('restic.password_file')),
            ],
        ];
    }

    private function request(PendingOperationType $type): void
    {
        BackupPanelAccess::authorize($type->ability());
        try {
            $actor = Filament::auth()->user();
            abort_unless($actor instanceof Authenticatable, 403);
            $operation = app(PendingOperationRequest::class)->submit($type, $actor);
            Notification::make()->title('Check requested')->body('Operation '.$operation->uuid.' is queued.')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Request refused')->body(app(SecretRedactor::class)->redact($exception->getMessage()))->danger()->send();
        }
    }

    /** @return list<Component> */
    private function scheduleFields(): array
    {
        $fields = [
            Checkbox::make('enabled')->label('Enable backup schedules'),
            Select::make('timezone')->label('Schedule timezone')->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))->searchable()->placeholder('Application timezone'),
        ];
        foreach (['database', 'media', 'recovery'] as $profile) {
            $fields[] = Section::make(ucfirst($profile).' backup')->columns(2)->schema([
                Checkbox::make($profile.'_enabled')->label('Enabled'),
                Select::make($profile.'_frequency')->label('Frequency')->options(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'])->required(),
                TextInput::make($profile.'_day')->label('Day')->numeric()->helperText('Weekly: 0=Sunday to 6=Saturday. Monthly: 1–28. Leave blank for daily.'),
                TextInput::make($profile.'_time')->label('Time (HH:MM)')->required()->helperText('Use a 5-minute boundary, such as 02:05.'),
            ]);
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function scheduleFormValues(): array
    {
        $values = app(ScheduleSettings::class)->all();
        $form = ['enabled' => $values['enabled']['value'], 'timezone' => $values['timezone']['value']];
        foreach (['database', 'media', 'recovery'] as $profile) {
            $setting = $values[$profile]['value'];
            if (! is_array($setting)) {
                throw new \RuntimeException('The schedule profile is invalid.');
            }
            foreach (['enabled', 'frequency', 'day', 'time'] as $field) {
                $form[$profile.'_'.$field] = $setting[$field] ?? null;
            }
        }

        return $form;
    }

    /** @param array<array-key, mixed> $data */
    private function saveSchedule(array $data): void
    {
        BackupPanelAccess::authorize('configure-schedule');
        try {
            $values = ['enabled' => (bool) ($data['enabled'] ?? false), 'timezone' => ($data['timezone'] ?? null) ?: null];
            foreach (['database', 'media', 'recovery'] as $profile) {
                $values[$profile] = [
                    'enabled' => (bool) ($data[$profile.'_enabled'] ?? false),
                    'frequency' => $data[$profile.'_frequency'] ?? '',
                    'day' => is_numeric($data[$profile.'_day'] ?? null) ? (int) $data[$profile.'_day'] : null,
                    'time' => $data[$profile.'_time'] ?? '',
                ];
            }
            app(ScheduleSettings::class)->saveAll($values);
            Notification::make()->title('Schedules updated')->body('The next scheduler invocation will use these overrides.')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Schedule update refused')->body(app(SecretRedactor::class)->redact($exception->getMessage()))->danger()->send();
        }
    }
}
