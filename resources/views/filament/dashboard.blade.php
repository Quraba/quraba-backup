<x-filament-panels::page>
    @include('quraba-backup::filament.partials.styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    <div class="qb-ui space-y-5" @if ($activeBackup || $activeOperation) wire:poll.20s @endif>
        @if (! $operationsAvailable || ! $catalogAvailable || ! $maintenanceAvailable)
            <x-filament::section :heading="$ui::text('sections.panel_history')">
                <p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.migrations') }}</p>
            </x-filament::section>
        @endif

        <x-filament::section :heading="$ui::text('pages.dashboard.health')">
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge :color="match ($health['state'] ?? 'unknown') { 'healthy' => 'success', 'degraded' => 'warning', 'failed' => 'danger', default => 'gray' }">{{ $ui::value($health['state'] ?? null) }}</x-filament::badge>
                <span class="text-sm text-gray-600 dark:text-gray-400">{{ ($health['state'] ?? null) === 'healthy' ? $ui::text('operator.all_ready') : $ui::text('operator.attention') }}</span>
            </div>
            @if (($journals['unresolved'] ?? 0) > 0 || ($journals['unreadable'] ?? []) !== [])
                <p class="mt-3 text-sm text-danger-600 dark:text-danger-400">{{ $ui::text('pages.dashboard.restore_attention', ['unresolved' => $journals['unresolved'] ?? 0, 'unreadable' => count($journals['unreadable'] ?? [])]) }}</p>
            @elseif (($health['state'] ?? null) === 'unknown')
                <p class="mt-3 text-sm">{{ $ui::text('operator.health_unknown') }}</p>
            @elseif (($health['state'] ?? null) !== 'healthy')
                @php($issue = collect($health['checks'] ?? [])->first(fn ($check) => in_array($check['status'] ?? null, ['fail', 'warn'], true)))
                @if ($issue)
                    <p class="mt-3 text-sm">{{ \Quraba\Backup\Filament\OperatorStatus::healthIssue($issue) }}</p>
                @else
                    <p class="mt-3 text-sm">{{ $ui::text('operator.health_unknown') }}</p>
                @endif
            @endif
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $ui::text('operator.last_check') }}: <bdi>{{ isset($health['checked_at']) ? $ui::dateTimeValue($health['checked_at']) : $ui::text('empty_states.not_checked') }}</bdi></p>
        </x-filament::section>

        <x-filament::section :heading="$ui::text('navigation.runs')">
            <div class="grid gap-4 md:grid-cols-3">
                @foreach (['full' => 'operator.latest_full', 'database' => 'operator.latest_database', 'media' => 'operator.latest_files'] as $kind => $label)
                    @php($run = $latestUsable[$kind])
                    <div class="min-w-0 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $ui::text($label) }}</p>
                        <p class="mt-2 font-medium">{{ $run ? $ui::dateTime($run->requested_at) : $ui::text('empty_states.no_backup') }}</p>
                        @if ($run && $run->consistency->value === 'best_effort' && $kind === 'full')
                            <p class="mt-2 text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('operator.best_effort') }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div class="grid gap-5 lg:grid-cols-2">
            <x-filament::section :heading="$ui::text('operator.current_activity')">
                @if ($activeBackup)
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span>{{ $ui::value($activeBackup->profile, 'backup_types') }}</span>
                        <x-filament::badge color="warning">{{ \Quraba\Backup\Filament\OperatorStatus::backupStage($activeBackup) }}</x-filament::badge>
                    </div>
                @elseif ($activeOperation)
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span>{{ $ui::value($activeOperation->type, 'operations') }}</span>
                        <x-filament::badge color="warning">{{ \Quraba\Backup\Filament\OperatorStatus::operationStage($activeOperation) }}</x-filament::badge>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $ui::text('operator.no_activity') }}</p>
                @endif
            </x-filament::section>
            <x-filament::section :heading="$ui::text('operator.next_backup')">
                @if ($nextBackup)
                    <p class="font-medium">{{ $ui::value($nextBackup['profile'], 'backup_types') }}</p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $ui::dateTime($nextBackup['at']) }}</p>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $ui::text('empty_states.no_schedules') }}</p>
                @endif
            </x-filament::section>
        </div>

        <x-filament::section :heading="$ui::text('technical_detail')" collapsible collapsed>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt>{{ $ui::text('pages.dashboard.environment') }}</dt><dd><bdi dir="ltr">{{ $environment }}</bdi></dd></div>
                <div><dt>{{ $ui::text('operator.background') }}</dt><dd>{{ $ui::value(! $pendingEnabled ? 'disabled' : ($workerRecent ? 'recently_observed' : 'not_recently_observed')) }}</dd></div>
                <div><dt>{{ $ui::text('operator.storage') }}</dt><dd>{{ $repository ? $ui::value($repository['status'] ?? null) : $ui::text('empty_states.no_health') }}</dd></div>
                <div><dt>{{ $ui::text('pages.dashboard.integrity_checked') }}</dt><dd>{{ $ui::dateTime($resticCheck?->finished_at) }}</dd></div>
            </dl>
            @if ($pendingEnabled && ! $workerRecent)
                <p class="mt-3 text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('operator.background_stale') }}</p>
            @endif
            @foreach ($warnings as $run)
                <p class="mt-2 text-sm">{{ $ui::dateTime($run->requested_at) }} · {{ $ui::value($run->profile, 'backup_types') }} · {{ $ui::value($run->status) }}</p>
            @endforeach
            @if ($scheduleError)<p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $scheduleError }}</p>@endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
