<x-filament-panels::page>
    @include('quraba-backup::filament.partials.styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    <div class="qb-ui space-y-5">
        @if (! $operationsAvailable || ! $maintenanceAvailable)
            <x-filament::section :heading="$ui::text('sections.panel_history')"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.migrations') }}</p></x-filament::section>
        @endif
        @if (! $pendingEnabled)
            <x-filament::section :heading="$ui::text('sections.panel_disabled')" icon="heroicon-o-exclamation-triangle" icon-color="warning"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.panel_disabled') }}</p></x-filament::section>
        @endif

        <x-filament::section :heading="$ui::text('pages.health.diagnostics')" :description="$ui::text('pages.health.diagnostics_help')">
            <div class="grid gap-4 md:grid-cols-2">
                @foreach (['health_refresh', 'doctor', 'restic_check', 'retention_plan'] as $type)
                    @php($operation = $latest[$type] ?? null)
                    <x-filament::section secondary compact>
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm font-medium"><span>{{ $ui::value($type, 'operations') }}</span><x-filament::badge :color="match ($operation?->status?->value) { 'completed' => 'success', 'failed', 'interrupted', 'indeterminate' => 'danger', 'pending', 'claimed', 'running' => 'warning', default => 'gray' }">{{ $operation ? $ui::value($operation->status) : $ui::text('empty_states.never_run') }}</x-filament::badge></div>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $ui::text('pages.health.checked') }}: <bdi>{{ $operation?->finished_at?->toDateTimeString() ?? $ui::text('empty_states.not_checked') }}</bdi></p>
                        @if ($operation?->failure_message)<p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $operation->failure_message }}</p>@endif
                        @if ($type === 'retention_plan' && is_array($operation?->result['plan'] ?? null))
                            <p class="mt-2 text-sm">{{ $ui::text('pages.health.keep') }}: {{ $operation->result['plan']['keep'] ?? 0 }} · {{ $ui::text('pages.health.would_expire') }}: {{ $operation->result['plan']['expire'] ?? 0 }}</p>
                            <div class="mt-3 space-y-2">
                                <x-filament::section :heading="$ui::text('pages.health.retention_policy')" collapsible collapsed>@foreach (($operation->result['plan']['policies'] ?? []) as $family => $policy)<p class="text-sm">{{ $ui::value($family, 'profiles') }}: @foreach ($policy as $period => $count){{ $ui::value($period, 'retention_rules') }} {{ $count }}@if (! $loop->last), @endif @endforeach</p>@endforeach</x-filament::section>
                                <x-filament::section :heading="$ui::text('pages.health.retention_decisions')" collapsible collapsed>@foreach (($operation->result['plan']['decisions'] ?? []) as $decision)<p class="text-sm"><bdi>{{ $decision['run_uuid'] ?? '' }}</bdi> · {{ $ui::value($decision['decision'] ?? null) }} · @foreach (($decision['reasons'] ?? []) as $reason){{ $ui::value($reason, 'retention_reasons') }}@if (! $loop->last), @endif @endforeach</p>@endforeach</x-filament::section>
                            </div>
                        @elseif (is_array($operation?->result['checks'] ?? null))
                            <div class="mt-3 space-y-2">
                                @foreach (['fail', 'warn', 'pass', 'skip'] as $status)
                                    @php($checks = array_filter($operation->result['checks'], fn ($check) => ($check['status'] ?? '') === $status))
                                    @if ($checks)<x-filament::section :heading="$ui::value($status).' ('.count($checks).')'" collapsible :collapsed="! in_array($status, ['fail', 'warn'], true)">@foreach ($checks as $check)<div class="mb-2 text-sm"><span class="font-medium">{{ $ui::checkLabel($check['id'] ?? '', $check['label'] ?? $ui::text('empty_states.not_checked')) }}</span><details class="mt-1"><summary class="cursor-pointer text-gray-500 dark:text-gray-400">{{ $ui::text('technical_detail') }}</summary><p class="mt-1">{{ $check['message'] ?? '' }}</p></details></div>@endforeach</x-filament::section>@endif
                                @endforeach
                            </div>
                        @endif
                    </x-filament::section>
                @endforeach
            </div>
        </x-filament::section>

        <div class="grid gap-5 lg:grid-cols-2">
            <x-filament::section :heading="$ui::text('pages.health.schedules_worker')" :description="$ui::text('pages.health.schedules_help')">
                <div class="space-y-3 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2"><span>{{ $ui::text('pages.dashboard.worker') }}</span><x-filament::badge :color="! $pendingEnabled ? 'gray' : ($workerRecent ? 'success' : 'warning')">{{ $ui::value(! $pendingEnabled ? 'disabled' : ($workerRecent ? 'recently_observed' : 'not_recently_observed')) }}</x-filament::badge></div>
                    @if ($pendingEnabled)<p class="text-gray-500 dark:text-gray-400">{{ $ui::text('pages.dashboard.last_seen') }}: <bdi>{{ $workerObserved ?? $ui::text('empty_states.never_run') }}</bdi></p>@endif
                    @if ($scheduleError)<p class="text-danger-600 dark:text-danger-400">{{ $scheduleError }}</p>@endif
                    @foreach ($schedule as $key => $setting)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-2 dark:border-gray-800"><span>{{ $ui::value($key, 'schedule') }}</span><span><bdi>{{ is_array($setting['value']) ? $ui::scheduleSetting($setting['value']) : (is_bool($setting['value']) ? $ui::value($setting['value'] ? 'enabled' : 'disabled') : ($setting['value'] ?? $ui::text('schedule.application_default'))) }}</bdi> <x-filament::badge color="gray">{{ $ui::value($setting['source'], 'schedule.source') }}</x-filament::badge></span></div>
                    @endforeach
                </div>
            </x-filament::section>
            <x-filament::section :heading="$ui::text('pages.health.unresolved')">
                <div class="space-y-2 text-sm">@forelse ($unresolved as $operation)<div class="flex flex-wrap items-center gap-2"><bdi>{{ $operation->uuid }}</bdi><span>{{ $ui::value($operation->type, 'operations') }}</span><x-filament::badge color="danger">{{ $ui::value($operation->status) }}</x-filament::badge></div>@empty<p class="text-success-600 dark:text-success-400">{{ $ui::text('empty_states.no_operations') }}</p>@endforelse</div>
            </x-filament::section>
        </div>
        <x-filament::section :heading="$ui::text('pages.health.history')">@if ($maintenanceAvailable){{ $this->table }}@else<p class="text-sm text-gray-500 dark:text-gray-400">{{ $ui::text('notices.migrations') }}</p>@endif</x-filament::section>
        <x-filament::section :heading="$ui::text('pages.health.configuration')" :description="$ui::text('pages.health.configuration_help')">
            <div class="grid gap-3 text-sm sm:grid-cols-2">@foreach ($secrets as $name => $configured)<div class="flex items-center justify-between gap-2"><span>{{ $name }}</span><x-filament::badge :color="$configured ? 'success' : 'warning'">{{ $ui::value($configured ? 'configured' : 'missing') }}</x-filament::badge></div>@endforeach</div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
