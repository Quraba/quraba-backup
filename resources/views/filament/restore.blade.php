<x-filament-panels::page>
    @include('quraba-backup::filament.partials.styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    <div class="qb-ui space-y-5">
        @if (! $operationsAvailable || ! $restoresAvailable)
            <x-filament::section :heading="$ui::text('sections.panel_history')"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.migrations') }}</p></x-filament::section>
        @endif
        @if (! $pendingEnabled)
            <x-filament::section :heading="$ui::text('sections.panel_disabled')" icon="heroicon-o-exclamation-triangle" icon-color="warning"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.panel_disabled') }}</p></x-filament::section>
        @endif
        @if (! $liveEnabled)
            <x-filament::section :heading="$ui::text('sections.live_disabled')" icon="heroicon-o-information-circle" icon-color="info"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.live_disabled') }}</p></x-filament::section>
        @endif
        @if (($journals['unreadable'] ?? []) !== [])
            <x-filament::section :heading="$ui::text('sections.unreadable_journals')"><p class="text-sm text-danger-600 dark:text-danger-400">{{ $ui::text('pages.restore.journal_unreadable', ['count' => count($journals['unreadable'])]) }}</p></x-filament::section>
        @endif
        @if (($journals['unresolved'] ?? 0) > 0)
            <x-filament::section :heading="$ui::text('sections.unresolved_restore')"><p class="text-sm text-danger-600 dark:text-danger-400">{{ $ui::text('pages.restore.unresolved_help') }} <code>php artisan quraba:backup:restore-reconcile</code></p></x-filament::section>
        @endif

        <x-filament::section :heading="$ui::text('pages.restore.requests')" :description="$ui::text('pages.restore.requests_help')">
            <div class="space-y-3 text-sm">
                @forelse ($requests as $request)
                    <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0 dark:border-gray-800">
                        <div class="flex flex-wrap items-center gap-2"><bdi>{{ $request->requested_at?->translatedFormat('j M Y H:i') }} UTC</bdi><span>{{ $ui::value($request->type, 'operations') }}</span><x-filament::badge :color="match ($request->status->value) { 'completed' => 'success', 'failed', 'indeterminate', 'interrupted' => 'danger', default => 'warning' }">{{ $ui::value($request->status) }}</x-filament::badge><bdi class="text-gray-500 dark:text-gray-400">{{ $request->uuid }}</bdi></div>
                        @if ($request->type->value === 'dry_restore' && $request->result)
                            <div class="mt-2"><x-filament::section :heading="$ui::text('pages.restore.validation')" collapsible collapsed>
                                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                                    <div>{{ $ui::text('labels.archive_verified') }}: <x-filament::badge :color="($request->result['archive_verified'] ?? false) ? 'success' : 'danger'">{{ $ui::value(($request->result['archive_verified'] ?? false) ? 'yes' : 'no') }}</x-filament::badge></div>
                                    <div>APP_KEY: <span>{{ $ui::value($request->result['app_key_compatibility'] ?? null, 'validation') }}</span></div>
                                    <div>{{ $ui::text('labels.release') }}: <span>{{ $ui::value($request->result['release_compatibility'] ?? null, 'validation') }}</span></div>
                                    <div>{{ $ui::text('labels.database_validation') }}: <span>{{ $ui::value($request->result['db_validation_level'] ?? null, 'validation') }}</span></div>
                                    <div>{{ $ui::text('labels.repository') }}: <bdi>{{ $request->result['repository_id'] ?? $ui::value('unknown') }}</bdi></div>
                                    <div>{{ $ui::text('labels.snapshot') }}: <bdi>{{ $request->result['snapshot_id'] ?? $ui::value('unknown') }}</bdi></div>
                                    <div>{{ $ui::text('labels.atomic_rename') }}: <x-filament::badge :color="($request->result['atomic_rename'] ?? false) ? 'success' : 'warning'">{{ $ui::value(($request->result['atomic_rename'] ?? false) ? 'yes' : 'no') }}</x-filament::badge></div>
                                </dl>
                                @foreach (($request->result['blockers'] ?? []) as $blocker)<p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $ui::text('labels.blocker') }}: {{ $blocker }}</p>@endforeach
                                @foreach (($request->result['warnings'] ?? []) as $warning)<p class="mt-2 text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('labels.warning') }}: {{ $warning }}</p>@endforeach
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $ui::text('pages.restore.no_live_change') }}</p>
                            </x-filament::section></div>
                        @endif
                    </div>
                @empty
                    <p class="text-gray-500 dark:text-gray-400">{{ $ui::text('empty_states.no_requests') }}</p>
                @endforelse
            </div>
        </x-filament::section>
        <x-filament::section :heading="$ui::text('pages.restore.history')" :description="$ui::text('pages.restore.history_help')">@if ($restoresAvailable){{ $this->table }}@else<p class="text-sm text-gray-500 dark:text-gray-400">{{ $ui::text('notices.history_unavailable') }}</p>@endif</x-filament::section>

        <div class="grid gap-5 lg:grid-cols-2">
            <x-filament::section :heading="$ui::text('pages.restore.journals')">
                <div class="space-y-3 text-sm">@forelse (($journals['journals'] ?? []) as $journal)<div class="border-b border-gray-100 pb-3 last:border-0 dark:border-gray-800"><div class="flex flex-wrap items-center gap-2"><bdi>{{ $journal['restore_uuid'] }}</bdi><span>{{ $ui::value($journal['profile'] ?? null, 'profiles') }}</span><x-filament::badge :color="($journal['unresolved'] ?? false) ? 'danger' : 'success'">{{ ($journal['unresolved'] ?? false) ? $ui::value('unresolved') : $ui::value($journal['resolution'] ?? $journal['terminal'] ?? null) }}</x-filament::badge></div><p class="mt-1 text-gray-500 dark:text-gray-400">{{ $ui::text('labels.journal_state') }}: {{ $ui::value($journal['phase'] ?? null, 'journal_phases') }} · {{ $ui::text('labels.boundary') }}: {{ $ui::value(($journal['destructive_started_at'] ?? null) ? 'crossed' : 'not_crossed') }}</p><p class="text-gray-500 dark:text-gray-400">{{ $ui::text('labels.safety_backup') }}: <bdi>{{ $journal['safety_backup_run_uuid'] ?? $ui::text('empty_states.none') }}</bdi></p></div>@empty<p class="text-gray-500 dark:text-gray-400">{{ $ui::text('empty_states.no_journals') }}</p>@endforelse</div>
            </x-filament::section>
            <x-filament::section :heading="$ui::text('pages.restore.evidence')" :description="$ui::text('pages.restore.evidence_help')">
                <div class="space-y-3 text-sm">@forelse ($approvalHistory as $approval)<div class="border-b border-gray-100 pb-3 last:border-0 dark:border-gray-800"><p>{{ $ui::text('labels.request') }}: <bdi>{{ $approval['operation_uuid'] }}</bdi></p><p>{{ $ui::text('labels.restore') }}: <bdi>{{ $approval['restore_uuid'] ?? $ui::text('empty_states.not_started') }}</bdi></p><p>{{ $ui::text('labels.source') }}: <bdi>{{ $approval['source_run_uuid'] ?? $ui::value('unknown') }}</bdi></p><p>{{ $ui::text('labels.worker_outcome') }}: {{ $ui::value($approval['worker_outcome']['status'] ?? null) }}</p>@if (isset($approval['evidence_error']))<p class="text-danger-600 dark:text-danger-400">{{ $approval['evidence_error'] }}</p>@endif</div>@empty<p class="text-gray-500 dark:text-gray-400">{{ $ui::text('empty_states.no_approvals') }}</p>@endforelse</div>
            </x-filament::section>
        </div>
        <x-filament::section :heading="$ui::text('pages.restore.after')">
            <ol class="list-inside list-decimal space-y-1 text-sm">@foreach (__('quraba-backup::filament.pages.restore.after_steps') as $step)<li>{{ $step }}</li>@endforeach</ol>
        </x-filament::section>
    </div>
</x-filament-panels::page>
