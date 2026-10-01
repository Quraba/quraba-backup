<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Recovery health: {{ strtoupper($health['state']) }}</h2>
            <p class="text-sm">Physical sampling of the latest backup artifacts and Restic repository.</p>
            <p class="mt-1 text-sm">Repository: {{ $repository['details']['state'] ?? $repository['status'] ?? 'unknown' }} @if (isset($repository['details']['repository_id'])) · {{ $repository['details']['repository_id'] }} @endif</p>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($health['checks'] as $check)
                    @if (in_array($check['status'], ['warn', 'fail'], true))
                        <li><strong>{{ strtoupper($check['status']) }}</strong> {{ $check['label'] }}: {{ $check['message'] }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (['Latest database backup' => $database, 'Latest media backup' => $media, 'Latest Recovery Point' => $recovery, 'Latest quiesced Recovery Point' => $quiesced] as $label => $run)
                <div class="rounded-xl border p-5">
                    <h3 class="font-semibold">{{ $label }}</h3>
                    <p class="font-mono text-sm">{{ $run?->uuid ?? 'None' }}</p>
                    <p class="text-sm">{{ $run?->requested_at?->toDateTimeString() ?? 'No backup recorded' }} UTC</p>
                </div>
            @endforeach
        </div>
        <div class="rounded-xl border p-5">
            <h3 class="font-semibold">Recent non-complete backups</h3>
            <ul class="mt-2 text-sm">
                @forelse ($warnings as $run)
                    <li>{{ $run->uuid }} — {{ $run->status->value }} @if ($run->failure_code) ({{ $run->failure_code }}) @endif</li>
                @empty
                    <li>None in the recent catalog.</li>
                @endforelse
            </ul>
        </div>
        <div class="rounded-xl border p-5">
            <h3 class="font-semibold">Configured schedules</h3>
            <ul class="mt-2 text-sm">
                @forelse ($schedules as $schedule)
                    <li>{{ $schedule->task }} — {{ $schedule->describe() }}</li>
                @empty
                    <li>None enabled.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-filament-panels::page>
