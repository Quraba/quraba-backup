<x-filament-panels::page>
    @include('quraba-backup::filament.partials.reference-styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    @php($latestJournal = collect($journals['journals'] ?? [])->first())
    @php($latestState = $latestJournal['resolution'] ?? $latestJournal['terminal'] ?? null)
    @php($sourceRun = $latestJournal ? $sourceRuns->get($latestJournal['source_run_uuid'] ?? '') : null)
    @php($latestApproval = $latestJournal ? collect($approvalHistory)->first(fn ($approval) => ($approval['restore_uuid'] ?? null) === ($latestJournal['restore_uuid'] ?? null)) : null)
    @php($recentChecks = $requests->filter(fn ($request) => $request->type === \Quraba\Backup\Enums\PendingOperationType::DryRestore)->take(3))
    <div class="qb-canvas" @if ($journalActive || $check?->status?->isOpen() || $requests->contains(fn ($request) => $request->status->isOpen())) wire:poll.20s @endif>
        @if (! $operationsAvailable || ! $restoresAvailable)<div class="qb-card qb-section qb-warn-text">{{ $ui::text('notices.migrations') }}</div>@endif
        @if (! $pendingEnabled)<div class="qb-card qb-section qb-warn-text">{{ $ui::text('notices.panel_disabled') }}</div>@endif
        @if (($journals['unreadable'] ?? []) !== [] || ($journals['unresolved'] ?? 0) > 0)<div class="qb-card qb-section qb-danger-text">{{ $ui::text('operator.restore_indeterminate') }} · {{ $ui::text('pages.restore.unresolved_help') }}</div>@endif

        <nav class="qb-card qb-stepper" aria-label="{{ $ui::text('pages.restore.title') }}">
            @foreach (['scope' => 'heroicon-o-folder', 'source' => 'heroicon-o-circle-stack', 'check' => 'heroicon-o-magnifying-glass', 'execute' => 'heroicon-o-play'] as $step => $icon)
                @php($stepState = match ($step) { 'scope' => 'is-complete', 'source' => $restoreSourceUuid ? 'is-complete' : 'is-current', 'check' => $check ? ($canRestore ? 'is-complete' : 'is-current') : '', 'execute' => $canRestore ? 'is-current' : '' })
                <div class="qb-step {{ $stepState }}"><span class="qb-step-icon"><x-filament::icon :icon="$icon" /></span><strong>{{ $ui::text('visual.restore_step_'.$step) }}</strong><small>{{ $ui::text('visual.restore_step_'.$step.'_help') }}</small></div>
            @endforeach
        </nav>

        <section class="qb-card qb-section" aria-labelledby="qb-restore-check-title"><div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-magnifying-glass" /></span><div><h2 id="qb-restore-check-title">{{ $ui::text('visual.restore_check_title') }}</h2><p>{{ $ui::text('visual.restore_check_help') }}</p></div></div>
            <form wire:submit="checkSelectedBackup">
                <div class="qb-select-grid">
                    <div class="qb-field"><label for="qb-restore-scope"><x-filament::icon icon="heroicon-o-computer-desktop" />{{ $ui::text('pages.restore.scope') }}</label><select id="qb-restore-scope" wire:model.live="restoreScope" @disabled(! $canCheckBackup)><option value="full">{{ $ui::text('backup_choices.recovery') }}</option><option value="database">{{ $ui::text('backup_choices.database') }}</option><option value="media">{{ $ui::text('backup_choices.media') }}</option></select><small>{{ $ui::text('visual.restore_scope_help') }}</small></div>
                    <div class="qb-field"><label for="qb-restore-source"><x-filament::icon icon="heroicon-o-circle-stack" />{{ $ui::text('operator.source') }}</label><select id="qb-restore-source" wire:model.live="restoreSourceUuid" @disabled(! $canCheckBackup)><option value="">{{ $ui::text('operator.source') }}</option>@foreach ($sourceOptions as $uuid => $label)<option value="{{ $uuid }}">{{ $label }}</option>@endforeach</select><small>{{ $ui::text('visual.restore_source_help') }}</small></div>
                </div>
                <div class="qb-form-actions"><button type="submit" class="qb-primary-button" @disabled(! $canCheckBackup || ! $restoreSourceUuid)>{{ $ui::text('operator.check_backup') }}</button><span class="qb-form-status">{{ $ui::text('operator.check_waiting') }}</span></div>
            </form>
            @if ($check)
                @php($warnings = is_array($check->result['warnings'] ?? null) ? $check->result['warnings'] : [])
                @php($blockers = is_array($check->result['blockers'] ?? null) ? $check->result['blockers'] : [])
                <div class="qb-issue-row" style="margin-top:14px"><span class="qb-icon {{ $canRestore ? 'qb-icon--green' : 'qb-icon--amber' }}"><x-filament::icon icon="heroicon-o-shield-check" /></span><div class="qb-issue-text"><strong>{{ $ui::text('operator.check_backup') }} · {{ $ui::value($check->restore_profile, 'profiles') }}</strong><small>{{ $check->status->isOpen() ? $ui::text('progress.checking') : ($canRestore ? $ui::text('operator.check_passed') : ($check->finished_at && $check->finished_at->lessThan(now('UTC')->subDay()) ? $ui::text('operator.check_expired') : $ui::text('operator.check_failed'))) }}</small></div><span class="qb-pill qb-pill--{{ $canRestore ? 'green' : 'amber' }}">{{ \Quraba\Backup\Filament\OperatorStatus::operationStage($check) }}</span></div>
                @if ($warnings !== [] || $blockers !== [])<p class="qb-form-status">{{ $ui::text('operator.warnings') }}: {{ count($warnings) }} · {{ $ui::text('operator.blockers') }}: {{ count($blockers) }}</p>@endif
            @endif
        </section>

        <section class="qb-card qb-hero qb-restore-result {{ $latestState === 'completed' ? 'qb-hero--green' : 'qb-hero--amber' }}" aria-labelledby="qb-latest-restore-title">
            <div class="qb-hero-art" aria-hidden="true"><x-filament::icon :icon="$latestState === 'completed' ? 'heroicon-o-shield-check' : 'heroicon-o-shield-exclamation'" /></div>
            <div class="qb-hero-main"><div class="qb-hero-heading"><span class="qb-icon {{ $latestState === 'completed' ? 'qb-icon--green' : 'qb-icon--amber' }}"><x-filament::icon icon="heroicon-o-arrow-uturn-left" /></span><h2 id="qb-latest-restore-title">{{ $ui::text('visual.restore_latest') }}</h2>@if ($latestJournal)<span class="qb-pill qb-pill--{{ $latestState === 'completed' ? 'green' : (($latestJournal['unresolved'] ?? false) ? 'red' : 'amber') }}">{{ \Quraba\Backup\Filament\OperatorStatus::restoreStage($latestJournal) }}</span>@endif</div>
                @if ($latestJournal)
                    <p style="color:var(--qb-text);font-weight:700">{{ $latestState === 'completed' ? $ui::text('visual.restore_success', ['scope' => $ui::value($latestJournal['profile'] ?? null, 'profiles')]) : (($latestJournal['unresolved'] ?? false) ? $ui::text('operator.restore_indeterminate') : $ui::text('operator.restore_failed_safe')) }}</p>
                    <p><bdi>{{ $ui::dateTimeValue($latestJournal['updated_at'] ?? null) }}</bdi></p>
                    @if ($latestState === 'completed')<div class="qb-success-notice"><strong>{{ $ui::text('operator.restore_after') }}</strong><small>{{ $ui::text('pages.restore.after_steps.0') }}</small></div>@endif
                @else<p>{{ $ui::text('visual.restore_latest_empty') }}</p>@endif
            </div>
            @if ($latestJournal)
                <div class="qb-info-wrap"><p class="qb-info-title">{{ $ui::text('visual.restore_information') }}</p><div class="qb-info-grid">
                    @foreach ([['pages.restore.scope', $ui::value($latestJournal['profile'] ?? null, 'profiles')], ['operator.reference', $latestJournal['source_run_uuid'] ?? '—'], ['operator.restore_record', $latestJournal['restore_uuid'] ?? '—'], ['labels.request', $latestApproval['operation_uuid'] ?? '—'], ['labels.safety_backup', $latestJournal['safety_backup_run_uuid'] ?? '—']] as [$label, $value])
                        <div class="qb-info-tile"><span>{{ $ui::text($label) }}</span><bdi dir="auto" title="{{ $value }}">{{ $value }}</bdi></div>
                    @endforeach
                </div></div>
            @endif
        </section>

        <div class="qb-columns">
            <section class="qb-card qb-section" aria-labelledby="qb-guidance-title"><div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-list-bullet" /></span><div><h2 id="qb-guidance-title">{{ $ui::text('operator.advanced_recovery') }}</h2><p>{{ $ui::text('visual.restore_guidance_help') }}</p></div></div><div class="qb-guidance">@foreach (__('quraba-backup::filament.pages.restore.after_steps') as $index => $step)<div class="qb-guidance-item"><span class="qb-guidance-number">{{ $index + 1 }}</span><strong>{{ $step }}</strong><span class="qb-icon qb-icon--muted"><x-filament::icon icon="heroicon-o-document-text" /></span></div>@endforeach</div></section>
            <section class="qb-card qb-section" aria-labelledby="qb-timeline-title"><div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-cog-6-tooth" /></span><div><h2 id="qb-timeline-title">{{ $ui::text('visual.restore_timeline') }}</h2><p>{{ $ui::text('visual.restore_timeline_help') }}</p></div></div>
                @if ($recentChecks->isEmpty())<div class="qb-empty"><x-filament::icon icon="heroicon-o-clock" /><strong>{{ $ui::text('empty_states.no_requests') }}</strong></div>
                @else<div class="qb-timeline">@foreach ($recentChecks as $request)<div class="qb-timeline-item {{ $request->status->value === 'completed' ? 'is-success' : (in_array($request->status->value, ['failed','interrupted','indeterminate'], true) ? 'is-failed' : '') }}"><div class="qb-timeline-main"><strong>{{ $ui::value($request->type, 'operations') }} · {{ $ui::value($request->restore_profile, 'profiles') }}</strong><span class="qb-pill qb-pill--{{ $request->status->value === 'completed' ? 'green' : (in_array($request->status->value, ['failed','interrupted','indeterminate'], true) ? 'red' : 'amber') }}">{{ \Quraba\Backup\Filament\OperatorStatus::operationStage($request) }}</span><small>{{ $request->status->value === 'completed' ? $ui::text('operator.check_passed') : ($request->status->isOpen() ? $ui::text('progress.checking') : $ui::text('operator.check_failed')) }}</small><button type="button" class="qb-detail-link" wire:click="resumeCheck('{{ $request->uuid }}')">{{ $ui::text('visual.view_details') }}</button></div><span class="qb-timeline-line" aria-hidden="true"></span><bdi class="qb-timeline-time">{{ $ui::dateTime($request->requested_at) }}</bdi></div>@endforeach</div>@endif
            </section>
        </div>

        <details class="qb-card qb-disclosure"><summary><span class="qb-icon"><x-filament::icon icon="heroicon-o-adjustments-horizontal" /></span><span><span class="qb-technical-title">{{ $ui::text('technical_detail') }}</span><span class="qb-technical-help">{{ $ui::text('visual.restore_technical_help') }}</span></span><x-filament::icon icon="heroicon-o-chevron-down" class="qb-chevron" /></summary><div class="qb-disclosure-body qb-accordion-content">
            @if ($latestJournal)<p>{{ $ui::text('operator.restore_record') }}: <bdi dir="ltr">{{ $latestJournal['restore_uuid'] ?? '—' }}</bdi></p><p>{{ $ui::text('operator.reference') }}: <bdi dir="ltr">{{ $latestJournal['source_run_uuid'] ?? '—' }}</bdi></p><p>{{ $ui::text('labels.journal_state') }}: {{ $ui::value($latestJournal['phase'] ?? null, 'journal_phases') }}</p><p>{{ $ui::text('labels.boundary') }}: {{ $ui::value(empty($latestJournal['destructive_started_at']) ? 'not_crossed' : 'crossed') }}</p><p>{{ $ui::text('labels.safety_backup') }}: <bdi dir="ltr">{{ $latestJournal['safety_backup_run_uuid'] ?? '—' }}</bdi></p>@if ($sourceRun)<p>{{ $ui::text('operator.source_type') }}: {{ $ui::dateTime($sourceRun->requested_at) }} · {{ $ui::value($sourceRun->profile, 'backup_types') }}</p>@endif @endif
            @if ($check)<p>{{ $ui::text('operator.check_backup') }}: <bdi dir="ltr">{{ $check->uuid }}</bdi> · {{ \Quraba\Backup\Filament\OperatorStatus::operationStage($check) }}</p>@foreach (['archive_verified', 'app_key_compatibility', 'release_compatibility', 'db_validation_level', 'repository_id', 'snapshot_id'] as $key)<p>{{ str_replace('_', ' ', $key) }}: <bdi dir="ltr">{{ is_scalar($check->result[$key] ?? null) ? (string) $check->result[$key] : '—' }}</bdi></p>@endforeach @foreach (is_array($check->result['warnings'] ?? null) ? $check->result['warnings'] : [] as $warning)<p>{{ $ui::text('operator.warnings') }}: {{ \Quraba\Backup\Filament\OperatorStatus::finding((string) $warning) }}</p>@endforeach @foreach (is_array($check->result['blockers'] ?? null) ? $check->result['blockers'] : [] as $blocker)<p>{{ $ui::text('operator.blockers') }}: {{ \Quraba\Backup\Filament\OperatorStatus::finding((string) $blocker) }}</p>@endforeach @endif
            @foreach ($approvalHistory as $approval)<p>{{ $ui::text('labels.request') }}: <bdi dir="ltr">{{ $approval['operation_uuid'] }}</bdi> · {{ $ui::text('labels.restore') }}: <bdi dir="ltr">{{ $approval['restore_uuid'] ?? '—' }}</bdi></p>@endforeach
        </div></details>
    </div>
</x-filament-panels::page>
