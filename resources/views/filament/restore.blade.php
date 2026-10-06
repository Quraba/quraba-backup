<x-filament-panels::page>
    @include('quraba-backup::filament.partials.styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    <div class="qb-ui space-y-6" @if ($journalActive || $check?->status?->isOpen() || $requests->contains(fn ($request) => $request->status->isOpen())) wire:poll.20s @endif>
        @if (! $operationsAvailable || ! $restoresAvailable)
            <x-filament::section :heading="$ui::text('sections.panel_history')"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.migrations') }}</p></x-filament::section>
        @endif
        @if (! $pendingEnabled)
            <x-filament::section :heading="$ui::text('sections.panel_disabled')"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.panel_disabled') }}</p></x-filament::section>
        @endif
        @if (($journals['unreadable'] ?? []) !== [] || ($journals['unresolved'] ?? 0) > 0)
            <x-filament::section :heading="$ui::text('operator.attention')" icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="text-sm text-danger-600 dark:text-danger-400">{{ $ui::text('operator.restore_indeterminate') }}</p>
                <x-filament::section :heading="$ui::text('operator.advanced_recovery')" collapsible collapsed>
                    <p class="text-sm">{{ $ui::text('pages.restore.unresolved_help') }}</p>
                </x-filament::section>
            </x-filament::section>
        @endif

        <x-filament::section :heading="$ui::text('operator.check_backup')" :description="$ui::text('operator.source_help')">
            @if ($check)
                @php($warnings = is_array($check->result['warnings'] ?? null) ? $check->result['warnings'] : [])
                @php($blockers = is_array($check->result['blockers'] ?? null) ? $check->result['blockers'] : [])
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span>{{ $ui::value($check->restore_profile, 'profiles') }}</span>
                    <x-filament::badge :color="match ($check->status->value) { 'completed' => ($check->result['ok'] ?? false) && $blockers === [] ? 'success' : 'danger', 'failed', 'indeterminate', 'interrupted' => 'danger', default => 'warning' }">{{ \Quraba\Backup\Filament\OperatorStatus::operationStage($check) }}</x-filament::badge>
                </div>
                @if ($check->status->isOpen())
                    <p class="mt-3 text-sm">{{ $ui::text('progress.checking') }}</p>
                @elseif ($canRestore)
                    <p class="mt-3 text-sm text-success-600 dark:text-success-400">{{ $ui::text('operator.check_passed') }}</p>
                @elseif ($check->finished_at && $check->finished_at->lessThan(now('UTC')->subDay()))
                    <p class="mt-3 text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('operator.check_expired') }}</p>
                @else
                    <p class="mt-3 text-sm text-danger-600 dark:text-danger-400">{{ $ui::text('operator.check_failed') }}</p>
                @endif
                @if ($warnings !== [] || $blockers !== [])
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div><strong class="text-sm">{{ $ui::text('operator.warnings') }} ({{ count($warnings) }})</strong>@foreach ($warnings as $warning)<p class="mt-1 text-sm">{{ \Quraba\Backup\Filament\OperatorStatus::finding((string) $warning) }}</p>@endforeach</div>
                        <div><strong class="text-sm">{{ $ui::text('operator.blockers') }} ({{ count($blockers) }})</strong>@foreach ($blockers as $blocker)<p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ \Quraba\Backup\Filament\OperatorStatus::finding((string) $blocker) }}</p>@endforeach</div>
                    </div>
                @endif
                <div class="mt-4"><x-filament::section :heading="$ui::text('technical_detail')" collapsible collapsed>
                    <dl class="grid gap-2 text-sm sm:grid-cols-2">
                        <div>{{ $ui::text('operator.reference') }}: <bdi class="break-all font-mono" dir="ltr">{{ $check->source_run_uuid }}</bdi></div>
                        <div>{{ $ui::text('labels.request') }}: <bdi class="break-all font-mono" dir="ltr">{{ $check->uuid }}</bdi></div>
                        <div>{{ $ui::text('labels.completed_at') }}: <bdi>{{ $ui::dateTime($check->finished_at) }}</bdi></div>
                        <div>{{ $ui::text('operator.failure_code') }}: <bdi dir="ltr">{{ $check->failure_code ?? '—' }}</bdi></div>
                        <div class="sm:col-span-2">{{ $ui::text('operator.technical_failure') }}: {{ app(\Quraba\Backup\Security\SecretRedactor::class)->redact((string) $check->failure_message) }}</div>
                        @foreach (['archive_verified', 'app_key_compatibility', 'release_compatibility', 'db_validation_level', 'repository_id', 'snapshot_id'] as $key)
                            <div>{{ str_replace('_', ' ', $key) }}: <bdi class="break-all font-mono" dir="ltr">{{ is_scalar($check->result[$key] ?? null) ? (string) $check->result[$key] : '—' }}</bdi></div>
                        @endforeach
                        @foreach ($warnings as $warning)<div class="sm:col-span-2">{{ $ui::text('operator.warnings') }}: {{ app(\Quraba\Backup\Security\SecretRedactor::class)->redact((string) $warning) }}</div>@endforeach
                        @foreach ($blockers as $blocker)<div class="sm:col-span-2">{{ $ui::text('operator.blockers') }}: {{ app(\Quraba\Backup\Security\SecretRedactor::class)->redact((string) $blocker) }}</div>@endforeach
                    </dl>
                </x-filament::section></div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $ui::text('operator.check_waiting') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section :heading="$ui::text('operator.restore_history')">
            <div class="space-y-4 text-sm">
                @forelse (($journals['journals'] ?? []) as $journal)
                    @php($state = $journal['resolution'] ?? $journal['terminal'] ?? null)
                    @php($sourceRun = $sourceRuns->get($journal['source_run_uuid'] ?? ''))
                    <div class="border-b border-gray-200 pb-4 last:border-0 last:pb-0 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <bdi>{{ $ui::dateTimeValue($journal['updated_at'] ?? null) }}</bdi>
                            <span>{{ $ui::value($journal['profile'] ?? null, 'profiles') }}</span>
                            <x-filament::badge :color="($journal['unresolved'] ?? false) ? 'danger' : ($state === 'completed' ? 'success' : 'warning')">{{ \Quraba\Backup\Filament\OperatorStatus::restoreStage($journal) }}</x-filament::badge>
                        </div>
                        @if ($sourceRun)<p class="mt-1 text-gray-500 dark:text-gray-400">{{ $ui::text('operator.source_type') }}: {{ $ui::dateTime($sourceRun->requested_at) }} · {{ $ui::value($sourceRun->profile, 'backup_types') }}</p>@endif
                        @if ($state === 'completed')<p class="mt-2 text-success-600 dark:text-success-400">{{ $ui::text('operator.restore_after') }}</p>
                        @elseif ($journal['unresolved'] ?? false)<p class="mt-2 text-danger-600 dark:text-danger-400">{{ $ui::text('operator.restore_indeterminate') }}</p>
                        @elseif ($state === 'failed' && empty($journal['destructive_started_at']))<p class="mt-2 text-warning-600 dark:text-warning-400">{{ $ui::text('operator.restore_failed_safe') }}</p>@endif
                        <div class="mt-3"><x-filament::section :heading="$ui::text('technical_detail')" collapsible collapsed>
                            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                                <div>{{ $ui::text('operator.restore_record') }}: <bdi class="break-all font-mono" dir="ltr">{{ $journal['restore_uuid'] }}</bdi></div>
                                <div>{{ $ui::text('operator.reference') }}: <bdi class="break-all font-mono" dir="ltr">{{ $journal['source_run_uuid'] }}</bdi></div>
                                <div>{{ $ui::text('labels.journal_state') }}: {{ $ui::value($journal['phase'] ?? null, 'journal_phases') }}</div>
                                <div><bdi dir="ltr">{{ $journal['updated_at'] ?? '—' }}</bdi></div>
                                <div>{{ $ui::text('labels.boundary') }}: {{ $ui::value(empty($journal['destructive_started_at']) ? 'not_crossed' : 'crossed') }}</div>
                                <div>{{ $ui::text('labels.safety_backup') }}: <bdi class="break-all font-mono" dir="ltr">{{ $journal['safety_backup_run_uuid'] ?? '—' }}</bdi></div>
                                @foreach ($approvalHistory as $approval)@if (($approval['restore_uuid'] ?? null) === $journal['restore_uuid'])<div>{{ $ui::text('labels.request') }}: <bdi class="break-all font-mono" dir="ltr">{{ $approval['operation_uuid'] }}</bdi></div>@endif @endforeach
                            </dl>
                        </x-filament::section></div>
                    </div>
                @empty
                    <p class="text-gray-500 dark:text-gray-400">{{ $ui::text('empty_states.no_journals') }}</p>
                @endforelse
            </div>
            @if ($requests->isNotEmpty())
                <div class="mt-6"><x-filament::section :heading="$ui::text('operator.background')" collapsible collapsed>
                    <div class="space-y-2 text-sm">@foreach ($requests as $request)
                        <div class="flex flex-wrap items-center gap-2">
                            <bdi>{{ $ui::dateTime($request->requested_at) }}</bdi>
                            <span>{{ $ui::value($request->type, 'operations') }} · {{ $ui::value($request->restore_profile, 'profiles') }}</span>
                            <x-filament::badge>{{ \Quraba\Backup\Filament\OperatorStatus::operationStage($request) }}</x-filament::badge>
                            @if ($request->type === \Quraba\Backup\Enums\PendingOperationType::DryRestore)<x-filament::button size="xs" color="gray" wire:click="resumeCheck('{{ $request->uuid }}')">{{ $ui::text('actions.details') }}</x-filament::button>@endif
                        </div>
                    @endforeach</div>
                </x-filament::section></div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="$ui::text('operator.advanced_recovery')" collapsible collapsed>
            <ol class="list-inside list-decimal space-y-1 text-sm">@foreach (__('quraba-backup::filament.pages.restore.after_steps') as $step)<li>{{ $step }}</li>@endforeach</ol>
            <x-filament::section :heading="$ui::text('technical_detail')" collapsible collapsed>
                <div class="space-y-2 text-sm">@foreach ($approvalHistory as $approval)
                    <p>{{ $ui::text('labels.request') }}: <bdi class="break-all font-mono" dir="ltr">{{ $approval['operation_uuid'] }}</bdi> · {{ $ui::text('labels.restore') }}: <bdi class="break-all font-mono" dir="ltr">{{ $approval['restore_uuid'] ?? '—' }}</bdi></p>
                @endforeach</div>
            </x-filament::section>
        </x-filament::section>
    </div>
</x-filament-panels::page>
