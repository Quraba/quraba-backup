<x-filament-panels::page>
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    <div class="space-y-5">
        <div class="grid gap-5 lg:grid-cols-2">
            @if ($canDryRestore)
                <x-filament::section :heading="$ui::text('pages.operations.dry')" :description="$ui::text('pages.operations.dry_help')">
                    <div class="space-y-3 text-sm">
                        <div><label for="backup-run-uuid" class="mb-1 block font-medium">{{ $ui::text('labels.run_uuid') }}</label><x-filament::input.wrapper><x-filament::input id="backup-run-uuid" type="text" wire:model="restoreRunUuid" /></x-filament::input.wrapper></div>
                        <div><label for="backup-restore-profile" class="mb-1 block font-medium">{{ $ui::text('labels.restore_profile') }}</label><x-filament::input.wrapper><x-filament::input.select id="backup-restore-profile" wire:model="restoreProfile"><option value="full">{{ $ui::text('profiles.full') }}</option><option value="database">{{ $ui::text('profiles.database') }}</option><option value="media">{{ $ui::text('profiles.media') }}</option></x-filament::input.select></x-filament::input.wrapper></div>
                        <x-filament::button type="button" wire:click="runDryRestore">{{ $ui::text('pages.operations.run_dry') }}</x-filament::button>
                        @if ($dryRunResult)<p>{{ $ui::text('labels.result') }}: <x-filament::badge :color="($dryRunResult['ok'] ?? false) ? 'success' : 'danger'">{{ $ui::value(($dryRunResult['ok'] ?? false) ? 'pass' : 'blocked') }}</x-filament::badge></p>@foreach (['warnings', 'blockers'] as $field)@foreach (($dryRunResult[$field] ?? []) as $entry)<p>{{ $ui::text($field === 'warnings' ? 'labels.warning' : 'labels.blocker') }}: {{ is_scalar($entry) ? $entry : json_encode($entry) }}</p>@endforeach @endforeach @if (isset($dryRunResult['error']))<p class="text-danger-600 dark:text-danger-400">{{ $dryRunResult['error'] }}</p>@endif @endif
                        <p class="text-gray-500 dark:text-gray-400">{{ $ui::text('pages.operations.cli_only') }}</p>
                    </div>
                </x-filament::section>
            @endif
            @if ($canPlanRetention)
                <x-filament::section :heading="$ui::text('pages.operations.retention')"><div class="space-y-3 text-sm"><x-filament::button type="button" wire:click="planRetention">{{ $ui::text('pages.operations.run_plan') }}</x-filament::button>@if ($retentionResult)<p>{{ $retentionResult['status'] ?? $retentionResult['error'] ?? $ui::text('empty_states.not_recorded') }}</p><p>{{ count($retentionResult['plan']['decisions'] ?? []) }} {{ $ui::text('labels.decisions') }}</p>@endif</div></x-filament::section>
            @endif
        </div>
        <div class="grid gap-5 lg:grid-cols-2">
            <x-filament::section :heading="$ui::text('pages.operations.maintenance')"><div class="space-y-2 text-sm">@forelse ($maintenance as $run)<div class="flex flex-wrap items-center gap-2"><bdi>{{ $run->uuid }}</bdi><span>{{ $ui::value($run->operation, 'maintenance_operations') }}</span><x-filament::badge :color="$run->status->value === 'completed' ? 'success' : 'danger'">{{ $ui::value($run->status) }}</x-filament::badge><bdi>{{ $run->started_at?->toDateTimeString() ?? '—' }}</bdi></div>@empty<p class="text-gray-500 dark:text-gray-400">{{ $ui::text('empty_states.no_maintenance') }}</p>@endforelse</div></x-filament::section>
            <x-filament::section :heading="$ui::text('pages.operations.secrets')" :description="$ui::text('pages.operations.secrets_help')"><div class="grid gap-3 text-sm">@foreach ($secretState as $label => $configured)<div class="flex items-center justify-between gap-2"><span>{{ $label }}</span><x-filament::badge :color="$configured ? 'success' : 'warning'">{{ $ui::value($configured ? 'configured' : 'missing') }}</x-filament::badge></div>@endforeach</div></x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
