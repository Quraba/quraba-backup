<x-filament-panels::page>
    <div class="space-y-6">
        @if (! $pendingEnabled)
            <x-filament::section heading="Panel requests are disabled">The host must enable the pending-operation feature before checks can be requested here.</x-filament::section>
        @endif
        <x-filament::section heading="Diagnostics" description="Checks run through the scheduler. Results below show when they were recorded.">
            @foreach (['health_refresh' => 'Backup health', 'doctor' => 'Doctor', 'restic_check' => 'Restic integrity', 'retention_plan' => 'Retention plan'] as $type => $label)
                @php $operation = $latest[$type] ?? null; @endphp
                <div class="mb-4">
                    <p class="font-semibold">{{ $label }} <x-filament::badge :color="match ($operation?->status?->value) { 'completed' => 'success', 'failed', 'interrupted', 'indeterminate' => 'danger', 'pending', 'claimed', 'running' => 'warning', default => 'gray' }">{{ $operation?->status?->value ?? 'Never run' }}</x-filament::badge></p>
                    <p>Checked: {{ $operation?->finished_at?->toDateTimeString() ?? 'Not yet' }} UTC</p>
                    @if ($operation?->failure_message)<p>{{ $operation->failure_message }}</p>@endif
                    @if ($type === 'retention_plan' && is_array($operation?->result['plan'] ?? null))
                        <p>Keep {{ $operation->result['plan']['keep'] ?? 0 }} · Would expire {{ $operation->result['plan']['expire'] ?? 0 }}</p>
                        <details><summary>Retention policy</summary>@foreach (($operation->result['plan']['policies'] ?? []) as $family => $policy)<p>{{ ucfirst($family) }}: @foreach ($policy as $period => $count){{ $period }} {{ $count }}@if (! $loop->last), @endif @endforeach</p>@endforeach</details>
                        <details><summary>Retention decisions</summary>@foreach (($operation->result['plan']['decisions'] ?? []) as $decision)<p>{{ $decision['run_uuid'] ?? '' }} · {{ $decision['decision'] ?? '' }} · {{ implode(', ', $decision['reasons'] ?? []) }}</p>@endforeach</details>
                    @elseif (is_array($operation?->result['checks'] ?? null))
                        @foreach (['fail' => 'Failed', 'warn' => 'Warning', 'pass' => 'Healthy', 'skip' => 'Skipped'] as $status => $heading)
                            @php $checks = array_filter($operation->result['checks'], fn ($check) => ($check['status'] ?? '') === $status); @endphp
                            @if ($checks)
                                <details @if (in_array($status, ['fail', 'warn'], true)) open @endif><summary>{{ $heading }} ({{ count($checks) }})</summary>@foreach ($checks as $check)<p>{{ $check['label'] ?? $check['id'] ?? 'Check' }}: {{ $check['message'] ?? '' }}</p>@endforeach</details>
                            @endif
                        @endforeach
                    @endif
                </div>
            @endforeach
        </x-filament::section>
        <x-filament::section heading="Schedules and worker" description="Database overrides take effect on the next scheduler invocation. A database restore can rewind them; deployment config remains the fallback.">
            <p>Worker: {{ $workerRecent ? 'Recently observed' : 'Not recently observed' }} · Last seen {{ $workerObserved ?? 'never' }}</p>
            @if ($scheduleError)<p>{{ $scheduleError }}</p>@endif
            @foreach ($schedule as $key => $setting)
                <p>{{ ucfirst(str_replace('_', ' ', $key)) }}: {{ is_array($setting['value']) ? (($setting['value']['enabled'] ?? false) ? ($setting['value']['frequency'] ?? '').' '.($setting['value']['time'] ?? '') : 'Disabled') : (is_bool($setting['value']) ? ($setting['value'] ? 'Enabled' : 'Disabled') : ($setting['value'] ?? 'Application default')) }} <x-filament::badge color="gray">{{ $setting['source'] }}</x-filament::badge></p>
            @endforeach
        </x-filament::section>
        <x-filament::section heading="Unresolved operations">
            @forelse ($unresolved as $operation)
                <p>{{ $operation->uuid }} · {{ $operation->type->value }} · <x-filament::badge color="danger">{{ $operation->status->value }}</x-filament::badge></p>
            @empty
                <p>No interrupted or indeterminate background operations.</p>
            @endforelse
        </x-filament::section>
        <x-filament::section heading="Maintenance history">
            {{ $this->table }}
        </x-filament::section>
        <x-filament::section heading="Recovery configuration" description="Secret values are managed on the server and are never shown here.">
            @foreach ($secrets as $name => $configured)
                <p>{{ $name }}: {{ $configured ? 'Configured' : 'Missing' }}</p>
            @endforeach
        </x-filament::section>
    </div>
</x-filament-panels::page>
