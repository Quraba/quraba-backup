<x-filament-panels::page>
    <div class="space-y-6">
        @if (! $operationsAvailable || ! $catalogAvailable || ! $maintenanceAvailable)
            <x-filament::section heading="Panel history needs package migrations">
                <p>Some backup panel tables are unavailable in this database. After verifying a restored application, run the host application's normal <code>php artisan migrate</code> process to restore package operation history.</p>
            </x-filament::section>
        @endif
        <x-filament::section heading="Recovery health" description="Can this application be recovered from its recorded backups?">
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge :color="match ($health['state'] ?? 'unknown') { 'healthy' => 'success', 'degraded' => 'warning', 'failed' => 'danger', default => 'gray' }">
                    {{ ucfirst($health['state'] ?? 'unknown') }}
                </x-filament::badge>
                <span>Checked {{ $health['checked_at'] ?? 'never' }}</span>
            </div>
            @if (($journals['unresolved'] ?? 0) > 0 || ($journals['unreadable'] ?? []) !== [])
                <p class="mt-3 font-semibold">Restore attention required: {{ $journals['unresolved'] ?? 0 }} unresolved; {{ count($journals['unreadable'] ?? []) }} unreadable journal(s).</p>
            @endif
            @foreach (($health['checks'] ?? []) as $check)
                @if (in_array($check['status'] ?? '', ['warn', 'fail'], true))
                    <p class="mt-2"><x-filament::badge :color="($check['status'] ?? '') === 'fail' ? 'danger' : 'warning'">{{ strtoupper($check['status']) }}</x-filament::badge> {{ $check['label'] }}: {{ $check['message'] }}</p>
                @endif
            @endforeach
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-2">
            <x-filament::section heading="Repository & integrity">
                <p>Repository: {{ $repository['message'] ?? 'No recent health result' }}</p>
                <p class="mt-2">Last successful integrity check: {{ $resticCheck?->finished_at?->setTimezone(config('app.timezone', 'UTC'))->format('M j, Y H:i') ?? 'None recorded' }}</p>
            </x-filament::section>
            <x-filament::section heading="Application & worker">
                <p>Environment: {{ $environment }}</p>
                <p>Backups: {{ $backupEnabled ? 'Enabled' : 'Disabled' }}</p>
                <p>Recovery secrets: {{ $secretsAcknowledged ? 'Acknowledged' : 'Not acknowledged' }}</p>
                <p>Panel operation worker: {{ ! $pendingEnabled ? 'Disabled' : ($workerRecent ? 'Recently observed' : 'Not recently observed') }}@if ($pendingEnabled) · Last seen {{ $workerObserved ?? 'never' }}@endif</p>
            </x-filament::section>
        </div>

        <x-filament::section heading="Recent backup problems">
            @forelse ($warnings as $run)
                <p>{{ $run->requested_at?->setTimezone(config('app.timezone', 'UTC'))->format('M j, Y H:i') }} — {{ ucfirst($run->profile->value) }}: <x-filament::badge :color="$run->status->value === 'failed' || $run->status->value === 'indeterminate' ? 'danger' : 'warning'">{{ $run->status->value }}</x-filament::badge></p>
            @empty
                <p>No recent failed, partial or indeterminate backups.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="Configured schedules" description="Planned times require a working Laravel scheduler. The panel operation worker observation does not measure general cron health.">
            @if ($scheduleError)<p>{{ $scheduleError }}</p>@endif
            @forelse ($schedules as $schedule)
                <p>{{ ucfirst(str_replace('_', ' ', $schedule->task)) }}: {{ $schedule->describe() }}</p>
            @empty
                <p>No schedule is currently enabled.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
