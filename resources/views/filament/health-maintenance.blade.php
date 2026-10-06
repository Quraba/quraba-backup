<x-filament-panels::page>
    @include('quraba-backup::filament.partials.styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    @php($health = $latest['health_refresh'] ?? null)
    @php($checks = is_array($health?->result['checks'] ?? null) ? $health->result['checks'] : [])
    @php($issues = array_filter($checks, fn ($check) => in_array($check['status'] ?? '', ['fail', 'warn'], true)))
    @php($storage = collect($checks)->first(fn ($check) => ($check['id'] ?? '') === 'health.repository'))
    <div class="qb-ui space-y-6" @if ($health?->status?->isOpen()) wire:poll.20s @endif>
        @if (! $operationsAvailable || ! $maintenanceAvailable)
            <x-filament::section :heading="$ui::text('sections.panel_history')"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.migrations') }}</p></x-filament::section>
        @endif
        <x-filament::section :heading="$ui::text('navigation.health')" :description="$ui::text('pages.dashboard.health_help')">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-filament::badge :color="match ($health?->result['state'] ?? null) { 'healthy' => 'success', 'degraded' => 'warning', 'failed' => 'danger', default => 'gray' }">{{ $health ? $ui::value($health->result['state'] ?? 'unknown') : $ui::text('operator.health_unknown') }}</x-filament::badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $ui::text('operator.last_check') }}: <bdi>{{ $ui::dateTime($health?->finished_at) }}</bdi></span>
            </div>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="font-medium">{{ $ui::text('operator.storage') }}</dt><dd>{{ $storage ? $ui::value($storage['status'] ?? null) : $ui::text('empty_states.not_checked') }}</dd></div>
                <div><dt class="font-medium">{{ $ui::text('operator.background') }}</dt><dd>{{ $pendingEnabled ? ($workerRecent ? $ui::value('recently_observed') : $ui::text($waitingRequests > 0 ? 'operator.background_stale' : 'operator.background_unseen')) : $ui::value('disabled') }}</dd></div>
            </dl>
            @if ($issues !== [] || $unresolved->isNotEmpty())
                <div class="mt-5 space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    @foreach ($issues as $issue)
                        <div class="text-sm"><x-filament::badge :color="($issue['status'] ?? '') === 'fail' ? 'danger' : 'warning'">{{ $ui::value($issue['status'] ?? null) }}</x-filament::badge> <span class="font-medium">{{ $ui::checkLabel($issue['id'] ?? '', $issue['label'] ?? $ui::text('operator.attention')) }}</span><p class="mt-1 text-gray-600 dark:text-gray-300">{{ \Quraba\Backup\Filament\OperatorStatus::healthIssue($issue) }}</p></div>
                    @endforeach
                    @foreach ($unresolved as $operation)
                        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $ui::value($operation->type, 'operations') }}: {{ \Quraba\Backup\Filament\OperatorStatus::failure($operation->failure_code, $operation->failure_message) }}</p>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="$ui::text('pages.health.schedules_worker')" collapsible collapsed>
            <div class="space-y-3 text-sm">
                @if ($scheduleError)<p class="text-danger-600 dark:text-danger-400">{{ $scheduleError }}</p>@endif
                @foreach ($schedule as $key => $setting)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-2 dark:border-gray-800"><span>{{ $ui::value($key, 'schedule') }}</span><span><bdi>{{ is_array($setting['value']) ? $ui::scheduleSetting($setting['value']) : (is_bool($setting['value']) ? $ui::value($setting['value'] ? 'enabled' : 'disabled') : ($setting['value'] ?? $ui::text('schedule.application_default'))) }}</bdi> <x-filament::badge color="gray">{{ $ui::value($setting['source'], 'schedule.source') }}</x-filament::badge></span></div>
                @endforeach
            </div>
        </x-filament::section>
        <x-filament::section :heading="$ui::text('operator.advanced')" collapsible collapsed>
            <div class="space-y-4">
                @foreach (['health_refresh', 'doctor', 'restic_check', 'retention_plan'] as $type)
                    @php($operation = $latest[$type] ?? null)
                    <x-filament::section :heading="$ui::value($type, 'operations')" collapsible collapsed>
                        <p class="text-sm">{{ $operation ? \Quraba\Backup\Filament\OperatorStatus::operationStage($operation) : $ui::text('empty_states.never_run') }} · {{ $ui::dateTime($operation?->finished_at) }}</p>
                        @if ($operation?->failure_message)<p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ \Quraba\Backup\Filament\OperatorStatus::failure($operation->failure_code, $operation->failure_message) }}</p>@endif
                        <x-filament::section :heading="$ui::text('technical_detail')" collapsible collapsed>
                            <p class="text-sm">{{ $ui::text('operator.reference') }}: <bdi class="break-all font-mono" dir="ltr">{{ $operation?->uuid ?? '—' }}</bdi></p>
                            <p class="text-sm">{{ $ui::text('operator.technical_failure') }}: {{ app(\Quraba\Backup\Security\SecretRedactor::class)->redact((string) $operation?->failure_message) }}</p>
                            @if (is_array($operation?->result['checks'] ?? null))@foreach ($operation->result['checks'] as $check)<p class="text-sm">{{ $ui::checkLabel($check['id'] ?? '', $check['label'] ?? '') }} · {{ $ui::value($check['status'] ?? null) }}: {{ app(\Quraba\Backup\Security\SecretRedactor::class)->redact((string) ($check['message'] ?? '')) }}</p>@endforeach@endif
                        </x-filament::section>
                    </x-filament::section>
                @endforeach
                <x-filament::section :heading="$ui::text('pages.health.configuration')" collapsible collapsed><div class="grid gap-3 text-sm sm:grid-cols-2">@foreach ($secrets as $name => $configured)<div class="flex items-center justify-between gap-2"><span>{{ $name }}</span><x-filament::badge :color="$configured ? 'success' : 'warning'">{{ $ui::value($configured ? 'configured' : 'missing') }}</x-filament::badge></div>@endforeach</div></x-filament::section>
                @if ($maintenanceAvailable)<x-filament::section :heading="$ui::text('pages.health.history')" collapsible collapsed>{{ $this->table }}</x-filament::section>@endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
