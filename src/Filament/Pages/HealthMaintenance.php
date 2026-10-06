<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use DateTimeZone;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Filament\OperatorStatus;
use Quraba\Backup\Filament\Ui;
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

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return Ui::text('navigation.health');
    }

    public static function getNavigationGroup(): string
    {
        return Ui::text('navigation.group');
    }

    public function getTitle(): string
    {
        return Ui::text('pages.health.title');
    }

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-dashboard');
    }

    protected function getHeaderActions(): array
    {
        $checks = [];
        foreach ([[PendingOperationType::Doctor, 'actions.doctor'], [PendingOperationType::ResticCheck, 'actions.check_repository'], [PendingOperationType::RetentionPlan, 'actions.plan_retention']] as [$type, $label]) {
            $checks[] = Action::make($type->value)->label(Ui::text($label))
                ->visible(fn (): bool => BackupPanelAccess::allows($type->ability()) && (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled') && app(PanelTableAvailability::class)->has('operations'))
                ->requiresConfirmation()
                ->action(fn () => $this->request($type));
        }
        $schedules = [];
        $schedules[] = Action::make('configure_schedule')->label(Ui::text('actions.edit_schedules'))
            ->visible(fn (): bool => BackupPanelAccess::allows('configure-schedule') && app(PanelTableAvailability::class)->has('settings'))
            ->fillForm(fn (): array => $this->scheduleFormValues())
            ->schema($this->scheduleFields())
            ->action(fn (array $data) => $this->saveSchedule($data));
        $schedules[] = Action::make('reset_schedule')->label(Ui::text('actions.reset_schedules'))->color('warning')
            ->visible(fn (): bool => BackupPanelAccess::allows('configure-schedule') && app(PanelTableAvailability::class)->has('settings'))
            ->requiresConfirmation()
            ->action(function (): void {
                BackupPanelAccess::authorize('configure-schedule');
                foreach (ScheduleSettings::keys() as $key) {
                    app(ScheduleSettings::class)->reset($key);
                }
                Notification::make()->title(Ui::text('pages.health.schedule_reset'))->success()->send();
            });

        return [
            Action::make('check_now')->label(Ui::text('actions.refresh_health'))
                ->visible(fn (): bool => BackupPanelAccess::allows(PendingOperationType::HealthRefresh->ability()) && (bool) config('quraba-backup.enabled') && (bool) config('quraba-backup.filament.pending_enabled') && app(PanelTableAvailability::class)->has('operations'))
                ->action(fn () => $this->request(PendingOperationType::HealthRefresh)),
            ActionGroup::make($checks)->label(Ui::text('pages.health.diagnostics'))->button()->color('gray'),
            ActionGroup::make($schedules)->label(Ui::text('actions.edit_schedules'))->button()->color('gray'),
        ];
    }

    public function table(Table $table): Table
    {
        BackupPanelAccess::authorize('view-dashboard');

        return $table->query(BackupMaintenanceRun::query()->latest('id'))
            ->columns([
                TextColumn::make('created_at')->label(Ui::text('labels.created'))->dateTime('j M Y g:i A')->sortable(),
                TextColumn::make('operation')->label(Ui::text('labels.operation'))->badge()->formatStateUsing(fn ($state): string => Ui::value($state, 'maintenance_operations')),
                TextColumn::make('status')->label(Ui::text('labels.status'))->badge()->formatStateUsing(fn ($state): string => Ui::value($state))->color(fn (BackupMaintenanceRun $record): string => match ($record->status->value) {
                    'completed' => 'success', 'failed', 'indeterminate' => 'danger', default => 'warning'
                }),
                TextColumn::make('dry_run')->label(Ui::text('labels.read_only'))->badge()->color('gray')
                    ->formatStateUsing(fn (bool $state): string => Ui::maintenanceMode($state)),
                TextColumn::make('uuid')->label(Ui::text('labels.uuid'))->toggleable(isToggledHiddenByDefault: true),
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
            $waitingRequests = $operationsAvailable ? PendingOperation::query()->whereIn('status', [PendingOperationStatus::Pending->value, PendingOperationStatus::Claimed->value])->count() : 0;
        } catch (Throwable) {
            $latest = [];
            $unresolved = collect();
            $waitingRequests = 0;
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
            'waitingRequests' => $waitingRequests,
            'pendingEnabled' => (bool) config('quraba-backup.filament.pending_enabled'),
            'operationsAvailable' => $operationsAvailable,
            'maintenanceAvailable' => $tables->has('maintenance'),
            'unresolved' => $unresolved,
            'secrets' => [
                Ui::text('configuration.b2_key_id') => filled(config('quraba-backup.storage.b2.key_id')),
                Ui::text('configuration.b2_application_key') => filled(config('quraba-backup.storage.b2.application_key')),
                Ui::text('configuration.archive_password') => filled(config('quraba-backup.archive.password')),
                Ui::text('configuration.restic_password_file') => filled(config('restic.password_file')),
            ],
        ];
    }

    private function request(PendingOperationType $type): void
    {
        BackupPanelAccess::authorize($type->ability());
        try {
            $actor = Filament::auth()->user();
            abort_unless($actor instanceof Authenticatable, 403);
            app(PendingOperationRequest::class)->submit($type, $actor);
            Notification::make()->title(Ui::text('pages.health.check_requested'))->body(Ui::text('pages.health.check_queued'))->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title(Ui::text('pages.health.request_refused'))->body(OperatorStatus::failure(null, $exception->getMessage()))->danger()->send();
        }
    }

    /** @return list<Component> */
    private function scheduleFields(): array
    {
        $fields = [
            Checkbox::make('enabled')->label(Ui::text('schedule.enable')),
            Select::make('timezone')->label(Ui::text('schedule.timezone'))->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))->searchable()->placeholder(Ui::text('schedule.application_timezone')),
        ];
        foreach (['database', 'media', 'recovery'] as $profile) {
            $fields[] = Section::make(Ui::text('schedule.'.$profile))->columns(2)->schema([
                Checkbox::make($profile.'_enabled')->label(Ui::text('statuses.enabled')),
                Select::make($profile.'_frequency')->label(Ui::text('schedule.frequency'))->options(['daily' => Ui::text('schedule.daily'), 'weekly' => Ui::text('schedule.weekly'), 'monthly' => Ui::text('schedule.monthly')])->required(),
                TextInput::make($profile.'_day')->label(Ui::text('schedule.day'))->numeric()->helperText(Ui::text('schedule.day_help')),
                TextInput::make($profile.'_time')->label(Ui::text('schedule.time'))->required()->helperText(Ui::text('schedule.time_help')),
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
                throw new \RuntimeException(Ui::text('form_errors.schedule_invalid'));
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
            Notification::make()->title(Ui::text('pages.health.schedule_updated'))->body(Ui::text('pages.health.schedule_updated_help'))->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title(Ui::text('pages.health.schedule_refused'))->body(app(SecretRedactor::class)->redact($exception->getMessage()))->danger()->send();
        }
    }
}
