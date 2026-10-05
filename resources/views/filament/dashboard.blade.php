<x-filament-panels::page>
    @include('quraba-backup::filament.partials.styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    <div class="qb-ui space-y-5">
        @if (! $operationsAvailable || ! $catalogAvailable || ! $maintenanceAvailable)
            <x-filament::section :heading="$ui::text('sections.panel_history')">
                <p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.migrations') }}</p>
            </x-filament::section>
        @endif
        <x-filament::section :heading="$ui::text('pages.dashboard.health')" :description="$ui::text('pages.dashboard.health_help')">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <x-filament::badge :color="match ($health['state'] ?? 'unknown') { 'healthy' => 'success', 'degraded' => 'warning', 'failed' => 'danger', default => 'gray' }">{{ $ui::value($health['state'] ?? null) }}</x-filament::badge>
                <span class="text-gray-600 dark:text-gray-400">{{ $ui::text('pages.dashboard.checked') }}: <bdi>{{ $health['checked_at'] ?? $ui::text('empty_states.not_checked') }}</bdi></span>
            </div>
            @if (($journals['unresolved'] ?? 0) > 0 || ($journals['unreadable'] ?? []) !== [])
                <p class="mt-3 text-sm text-danger-600 dark:text-danger-400">{{ $ui::text('pages.dashboard.restore_attention', ['unresolved' => $journals['unresolved'] ?? 0, 'unreadable' => count($journals['unreadable'] ?? [])]) }}</p>
            @endif
            @foreach (($health['checks'] ?? []) as $check)
                @if (in_array($check['status'] ?? '', ['warn', 'fail'], true))
                    <div class="mt-2 text-sm"><div class="flex flex-wrap items-center gap-2"><x-filament::badge :color="($check['status'] ?? '') === 'fail' ? 'danger' : 'warning'">{{ $ui::value($check['status']) }}</x-filament::badge><span>{{ $ui::checkLabel($check['id'] ?? '', $check['label'] ?? '') }}</span></div><details class="mt-1"><summary class="cursor-pointer text-gray-500 dark:text-gray-400">{{ $ui::text('technical_detail') }}</summary><p class="mt-1">{{ $check['message'] }}</p></details></div>
                @endif
            @endforeach
        </x-filament::section>
        <div class="grid gap-5 lg:grid-cols-2">
            <x-filament::section :heading="$ui::text('pages.dashboard.application')">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $ui::text('pages.dashboard.environment') }}</dt><dd class="font-medium"><bdi>{{ $environment }}</bdi></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $ui::text('pages.dashboard.backups') }}</dt><dd><x-filament::badge :color="$backupEnabled ? 'success' : 'gray'">{{ $ui::value($backupEnabled ? 'enabled' : 'disabled') }}</x-filament::badge></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $ui::text('pages.dashboard.secrets') }}</dt><dd><x-filament::badge :color="$secretsAcknowledged ? 'success' : 'warning'">{{ $ui::value($secretsAcknowledged ? 'acknowledged' : 'not_acknowledged') }}</x-filament::badge></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $ui::text('pages.dashboard.worker') }}</dt><dd><x-filament::badge :color="! $pendingEnabled ? 'gray' : ($workerRecent ? 'success' : 'warning')">{{ $ui::value(! $pendingEnabled ? 'disabled' : ($workerRecent ? 'recently_observed' : 'not_recently_observed')) }}</x-filament::badge>@if ($pendingEnabled)<p class="mt-1 text-gray-500 dark:text-gray-400">{{ $ui::text('pages.dashboard.last_seen') }}: <bdi>{{ $workerObserved ?? $ui::text('empty_states.never_run') }}</bdi></p>@endif</dd></div>
                </dl>
            </x-filament::section>
            <x-filament::section :heading="$ui::text('pages.dashboard.repository')">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $ui::text('pages.dashboard.repository_message') }}</dt><dd>@if ($repository)<x-filament::badge :color="match ($repository['status'] ?? null) { 'pass' => 'success', 'warn' => 'warning', 'fail' => 'danger', default => 'gray' }">{{ $ui::value($repository['status'] ?? null) }}</x-filament::badge><details class="mt-1"><summary class="cursor-pointer text-gray-500 dark:text-gray-400">{{ $ui::text('technical_detail') }}</summary><p class="mt-1">{{ $repository['message'] ?? '' }}</p></details>@else{{ $ui::text('empty_states.no_health') }}@endif</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $ui::text('pages.dashboard.integrity_checked') }}</dt><dd><bdi>{{ $resticCheck?->finished_at?->setTimezone(config('app.timezone', 'UTC'))->translatedFormat('j M Y H:i') ?? $ui::text('empty_states.no_integrity') }}</bdi></dd></div>
                </dl>
            </x-filament::section>
            <x-filament::section :heading="$ui::text('pages.dashboard.problems')">
                <div class="space-y-2 text-sm">@forelse ($warnings as $run)<div class="flex flex-wrap items-center gap-2"><bdi>{{ $run->requested_at?->setTimezone(config('app.timezone', 'UTC'))->translatedFormat('j M Y H:i') }}</bdi><span>{{ $ui::value($run->profile, 'profiles') }}</span><x-filament::badge :color="in_array($run->status->value, ['failed', 'indeterminate'], true) ? 'danger' : 'warning'">{{ $ui::value($run->status) }}</x-filament::badge></div>@empty<p class="text-success-600 dark:text-success-400">{{ $ui::text('empty_states.no_problems') }}</p>@endforelse</div>
            </x-filament::section>
            <x-filament::section :heading="$ui::text('pages.dashboard.schedules')" :description="$ui::text('pages.dashboard.schedules_help')">
                <div class="space-y-2 text-sm">@if ($scheduleError)<p class="text-danger-600 dark:text-danger-400">{{ $scheduleError }}</p>@endif @forelse ($schedules as $schedule)<div class="flex flex-wrap items-center justify-between gap-2"><span class="font-medium">{{ $ui::value($schedule->task, 'schedule_tasks') }}</span><bdi>{{ $ui::schedule($schedule) }}</bdi></div>@empty<p class="text-gray-500 dark:text-gray-400">{{ $ui::text('empty_states.no_schedules') }}</p>@endforelse</div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
