<x-filament-panels::page>
    <div class="space-y-6">
        @if ($canDryRestore)
            <div class="rounded-xl border p-5">
                <h2 class="font-semibold">Restore dry run</h2>
                <p class="text-sm">Validates an exact backup without changing live data. Large archives may take time to inspect.</p>
                <div class="mt-3 flex flex-wrap gap-2"><input type="text" wire:model="restoreRunUuid" placeholder="Exact run UUID" aria-label="Run UUID" class="rounded border"><select wire:model="restoreProfile" aria-label="Restore profile" class="rounded border"><option value="full">Full</option><option value="database">Database</option><option value="media">Media</option></select><button type="button" wire:click="runDryRestore" class="rounded border px-3 py-2">Run dry restore</button></div>
                @if ($dryRunResult)
                    <p class="mt-3">Result: {{ ($dryRunResult['ok'] ?? false) ? 'PASS' : 'BLOCKED' }}</p>
                    @foreach (['warnings', 'blockers'] as $field)
                        <h3 class="mt-2 font-semibold">{{ ucfirst($field) }}</h3><ul>@foreach (($dryRunResult[$field] ?? []) as $entry)<li>{{ is_scalar($entry) ? $entry : json_encode($entry) }}</li>@endforeach</ul>
                    @endforeach
                    @if (isset($dryRunResult['error']))<p>{{ $dryRunResult['error'] }}</p>@endif
                @endif
                <p class="mt-3 text-sm">Live restore is CLI-only: <code>php artisan quraba:backup:restore --run=UUID --profile=full --force --confirm=RESTORE_APPLICATION</code></p>
            </div>
        @endif
        @if ($canPlanRetention)
            <div class="rounded-xl border p-5"><h2 class="font-semibold">Retention plan</h2><button type="button" wire:click="planRetention" class="mt-2 rounded border px-3 py-2">Run read-only plan</button>@if ($retentionResult)<p class="mt-2">{{ $retentionResult['status'] ?? $retentionResult['error'] ?? 'Plan recorded' }}</p><p>{{ count($retentionResult['plan']['decisions'] ?? []) }} decisions</p>@endif</div>
        @endif
        <div class="rounded-xl border p-5"><h2 class="font-semibold">Maintenance history</h2><ul class="mt-2 text-sm">@forelse ($maintenance as $run)<li>{{ $run->uuid }} · {{ $run->operation->value }} · {{ $run->status->value }} · {{ $run->started_at?->toDateTimeString() ?? '—' }}</li>@empty<li>No maintenance runs recorded.</li>@endforelse</ul></div>
        <div class="rounded-xl border p-5"><h2 class="font-semibold">Secret configuration</h2><ul class="mt-2 text-sm">@foreach ($secretState as $label => $configured)<li>{{ $label }}: {{ $configured ? 'configured' : 'missing' }}</li>@endforeach</ul><p class="mt-2 text-sm">Secret values are configured outside the catalog.</p></div>
    </div>
</x-filament-panels::page>
