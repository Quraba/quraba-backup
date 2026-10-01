<x-filament-panels::page>
    <div class="space-y-6">
        @if ($canRequest)
            <div class="rounded-xl border p-5">
                <h2 class="font-semibold">Request backup</h2>
                <p class="text-sm">The scheduler executes pending requests outside the web request.</p>
                <div class="mt-3 flex gap-2">
                    @foreach (['database', 'media', 'recovery'] as $profile)
                        <button type="button" wire:click="requestBackup('{{ $profile }}')" class="rounded border px-3 py-2 text-sm">{{ ucfirst($profile) }}</button>
                    @endforeach
                </div>
                @if ($requestMessage)<p class="mt-2 text-sm">{{ $requestMessage }}</p>@endif
            </div>
        @endif
        <div class="flex flex-wrap gap-3">
            <select wire:model.live="profileFilter" aria-label="Profile" class="rounded border"><option value="">All profiles</option>@foreach (['database', 'media', 'recovery'] as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
            <select wire:model.live="statusFilter" aria-label="Status" class="rounded border"><option value="">All statuses</option>@foreach (['pending', 'preflighting', 'running', 'verifying', 'completed', 'partial', 'failed', 'canceled', 'indeterminate'] as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
            <select wire:model.live="triggerFilter" aria-label="Trigger" class="rounded border"><option value="">All triggers</option>@foreach (['scheduled', 'manual', 'pre_restore', 'api'] as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
            <input type="date" wire:model.live="dateFilter" aria-label="Requested date" class="rounded border">
        </div>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="p-3">Run UUID</th><th class="p-3">Profile</th><th class="p-3">Trigger</th><th class="p-3">Consistency</th><th class="p-3">Status</th><th class="p-3">Requested UTC</th><th class="p-3">Artifacts</th><th class="p-3"></th></tr></thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr class="border-t"><td class="p-3 font-mono">{{ $run->uuid }}</td><td class="p-3">{{ $run->profile->value }}</td><td class="p-3">{{ $run->trigger->value }}</td><td class="p-3">{{ $run->consistency->value }}</td><td class="p-3">{{ $run->status->value }}</td><td class="p-3">{{ $run->requested_at?->toDateTimeString() }}</td><td class="p-3">{{ $run->artifacts->count() }}</td><td class="p-3"><button type="button" wire:click="selectRun('{{ $run->uuid }}')" class="underline">Details</button></td></tr>
                    @empty
                        <tr><td colspan="8" class="p-3">No runs match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $runs->links() }}
        @if ($selected)
            <div class="rounded-xl border p-5 text-sm">
                <h2 class="font-semibold">Run {{ $selected->uuid }}</h2>
                <p>Status {{ $selected->status->value }} · started {{ $selected->started_at?->toDateTimeString() ?? '—' }} · completed {{ $selected->completed_at?->toDateTimeString() ?? '—' }}</p>
                @if ($selected->failure_code)<p>Failure: {{ $selected->failure_stage }} / {{ $selected->failure_code }} — {{ $selected->failure_message }}</p>@endif
                <h3 class="mt-3 font-semibold">Artifacts</h3>
                <ul>@foreach ($selected->artifacts as $artifact)<li>{{ $artifact->kind->value }} — {{ $artifact->status->value }} @if ($artifact->snapshot_id) · snapshot {{ $artifact->snapshot_id }} @endif @if ($artifact->sha256) · SHA-256 {{ $artifact->sha256 }} @endif @if ($artifact->byte_size) · {{ $artifact->byte_size }} bytes @endif</li>@endforeach</ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
