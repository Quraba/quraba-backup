<x-filament-panels::page>
    <div class="space-y-6">
        @if (! $pendingEnabled)
            <x-filament::section heading="Panel requests are disabled">
                The host must enable the pending-operation feature before Dry Restore or Live Restore can be requested here.
            </x-filament::section>
        @endif
        @if (! $liveEnabled)
            <x-filament::section heading="Live restore is disabled">
                The host must enable the explicit Filament live restore feature flag before this action is available. CLI recovery remains available.
            </x-filament::section>
        @endif
        @if (($journals['unreadable'] ?? []) !== [])
            <x-filament::section heading="Unreadable restore journals">
                {{ count($journals['unreadable']) }} journal(s) cannot be read. Inspect them with CLI before another live restore.
            </x-filament::section>
        @endif
        @if (($journals['unresolved'] ?? 0) > 0)
            <x-filament::section heading="Unresolved live restore">
                A restore may have changed this application. Keep it in maintenance mode and use <code>php artisan quraba:backup:restore-reconcile</code> to inspect physical evidence.
            </x-filament::section>
        @endif
        <x-filament::section heading="Background restore requests" description="A dry restore changes no live data. A live restore performs fresh checks and may put this application into maintenance mode.">
            @forelse ($requests as $request)
                <p>{{ $request->requested_at?->format('M j, Y H:i') }} UTC · {{ str_replace('_', ' ', $request->type->value) }} · <x-filament::badge :color="match ($request->status->value) { 'completed' => 'success', 'failed', 'indeterminate', 'interrupted' => 'danger', default => 'warning' }">{{ $request->status->value }}</x-filament::badge> · {{ $request->uuid }}</p>
                @if ($request->type->value === 'dry_restore' && $request->result)
                    <details class="mt-1 mb-3"><summary>Validation result</summary><p>Archive verified: {{ ($request->result['archive_verified'] ?? false) ? 'Yes' : 'No' }} · APP_KEY: {{ $request->result['app_key_compatibility'] ?? 'unknown' }} · Release: {{ $request->result['release_compatibility'] ?? 'unknown' }} · Database validation: {{ $request->result['db_validation_level'] ?? 'unknown' }}</p><p>Repository: {{ $request->result['repository_id'] ?? 'unknown' }} · Snapshot: {{ $request->result['snapshot_id'] ?? 'unknown' }} · Atomic media rename: {{ ($request->result['atomic_rename'] ?? false) ? 'Yes' : 'No' }}</p>@foreach (($request->result['blockers'] ?? []) as $blocker)<p>Blocker: {{ $blocker }}</p>@endforeach @foreach (($request->result['warnings'] ?? []) as $warning)<p>Warning: {{ $warning }}</p>@endforeach <p>Nothing was changed in the live application.</p></details>
                @endif
            @empty
                <p>No background restore requests yet.</p>
            @endforelse
        </x-filament::section>
        <x-filament::section heading="Restore history" description="The external journal is authoritative for live restore state; the database table below is an audit mirror.">
            {{ $this->table }}
        </x-filament::section>
        <x-filament::section heading="Live restore journals">
            @forelse (($journals['journals'] ?? []) as $journal)
                <p>{{ $journal['restore_uuid'] }} · {{ $journal['profile'] }} · {{ $journal['phase'] }} · <x-filament::badge :color="($journal['unresolved'] ?? false) ? 'danger' : 'success'">{{ ($journal['unresolved'] ?? false) ? 'Unresolved' : ($journal['resolution'] ?? $journal['terminal'] ?? 'Unknown') }}</x-filament::badge> · Boundary: {{ $journal['destructive_started_at'] ? 'crossed' : 'not crossed' }} · Safety backup: {{ $journal['safety_backup_run_uuid'] ?? 'none' }}</p>
            @empty
                <p>No live restore journal exists on this host.</p>
            @endforelse
        </x-filament::section>
        <x-filament::section heading="Queued live request evidence" description="Private request evidence links a panel request to the authoritative restore journal, even if the application database was replaced.">
            @forelse ($approvalHistory as $approval)
                <p>Request {{ $approval['operation_uuid'] }} · Restore {{ $approval['restore_uuid'] ?? 'not started' }} · Source {{ $approval['source_run_uuid'] ?? 'unknown' }}</p>
            @empty
                <p>No consumed panel live-restore approvals on this host.</p>
            @endforelse
        </x-filament::section>
        <x-filament::section heading="After a completed live restore">
            <p>Verify the restored application, reconcile the catalog, rebuild remote history when required, reconcile the restore journal, review parked media, and use CLI to clean parked media and bring the application out of maintenance mode. Do not remove recovery evidence before verification.</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
