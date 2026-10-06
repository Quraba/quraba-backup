<x-filament-panels::page>
    @include('quraba-backup::filament.partials.reference-styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    @php($healthState = $health['state'] ?? 'unknown')
    @php($healthIssue = collect($health['checks'] ?? [])->first(fn ($check) => in_array($check['status'] ?? null, ['fail', 'warn'], true)))
    <div class="qb-canvas" @if ($activeBackup || $activeOperation) wire:poll.20s @endif>
        @if (! $operationsAvailable || ! $catalogAvailable || ! $maintenanceAvailable)
            <div class="qb-card qb-section qb-warn-text">{{ $ui::text('notices.migrations') }}</div>
        @endif
        <section class="qb-card qb-hero qb-hero--amber" aria-labelledby="qb-recovery-title">
            <div class="qb-hero-art" aria-hidden="true"><x-filament::icon icon="heroicon-o-shield-exclamation" /></div>
            <div class="qb-hero-main">
                <div class="qb-hero-heading"><span class="qb-icon qb-icon--amber"><x-filament::icon icon="heroicon-o-heart" /></span><h2 id="qb-recovery-title">{{ $ui::text('pages.dashboard.health') }}</h2><span class="qb-pill qb-pill--{{ match ($healthState) { 'healthy' => 'green', 'failed' => 'red', default => 'amber' } }}">{{ $ui::value($healthState) }}</span></div>
                <p>@if (($journals['unresolved'] ?? 0) > 0 || ($journals['unreadable'] ?? []) !== []){{ $ui::text('pages.dashboard.restore_attention', ['unresolved' => $journals['unresolved'] ?? 0, 'unreadable' => count($journals['unreadable'] ?? [])]) }}@elseif ($healthIssue){{ \Quraba\Backup\Filament\OperatorStatus::healthIssue($healthIssue) }}@elseif ($healthState === 'healthy'){{ $ui::text('visual.health_ready') }}@else{{ $ui::text('operator.health_unknown') }}@endif</p>
                <div class="qb-hero-foot"><x-filament::icon icon="heroicon-o-clock" /><span>{{ $ui::text('operator.last_check') }}: <bdi>{{ $ui::dateTimeValue($health['checked_at'] ?? null) }}</bdi></span></div>
            </div>
        </section>
        <section class="qb-card qb-section" aria-labelledby="qb-backups-title">
            <div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-circle-stack" /></span><div><h2 id="qb-backups-title">{{ $ui::text('navigation.runs') }}</h2><p>{{ $ui::text('visual.backup_summary_help') }}</p></div></div>
            <div class="qb-summary-grid">
                @foreach (['full' => ['operator.latest_full', 'heroicon-o-computer-desktop', ''], 'database' => ['operator.latest_database', 'heroicon-o-circle-stack', 'qb-icon--purple'], 'media' => ['operator.latest_files', 'heroicon-o-document', 'qb-icon--green']] as $kind => [$label, $icon, $tone])
                    @php($run = $latestUsable[$kind])
                    <div class="qb-summary-item"><span class="qb-icon {{ $tone }}"><x-filament::icon :icon="$icon" /></span><div><h3>{{ $ui::text($label) }}</h3><p><bdi>{{ $run ? $ui::dateTime($run->requested_at) : $ui::text('empty_states.no_backup') }}</bdi></p>@if ($run && $kind === 'full' && $run->consistency->value === 'best_effort')<p class="qb-warn-text">{{ $ui::text('operator.best_effort') }}</p>@endif</div></div>
                @endforeach
            </div>
        </section>
        <div class="qb-columns">
            <section class="qb-card qb-section" aria-labelledby="qb-next-title"><div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-clock" /></span><div><h2 id="qb-next-title">{{ $ui::text('operator.next_backup') }}</h2><p>{{ $ui::text('visual.next_backup_help') }}</p></div></div>
                @if ($nextBackup)<div class="qb-next"><span class="qb-icon qb-icon--purple"><x-filament::icon icon="heroicon-o-circle-stack" /></span><div class="qb-next-main"><strong>{{ $ui::value($nextBackup['profile'], 'backup_types') }}</strong><span><bdi>{{ $ui::dateTime($nextBackup['at']) }}</bdi></span></div><span class="qb-pill qb-pill--gray">{{ $ui::text('triggers.scheduled') }}</span></div>
                @else<div class="qb-empty"><x-filament::icon icon="heroicon-o-calendar-days" /><strong>{{ $ui::text('empty_states.no_schedules') }}</strong></div>@endif
            </section>
            <section class="qb-card qb-section" aria-labelledby="qb-current-title"><div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-cog-6-tooth" /></span><div><h2 id="qb-current-title">{{ $ui::text('operator.current_activity') }}</h2><p>{{ $ui::text('visual.current_help') }}</p></div></div>
                @if ($activeBackup || $activeOperation)<div class="qb-next"><span class="qb-icon qb-icon--amber"><x-filament::icon icon="heroicon-o-arrow-path" /></span><div class="qb-next-main"><strong>{{ $activeBackup ? $ui::value($activeBackup->profile, 'backup_types') : $ui::value($activeOperation->type, 'operations') }}</strong><span>{{ $activeBackup ? \Quraba\Backup\Filament\OperatorStatus::backupStage($activeBackup) : \Quraba\Backup\Filament\OperatorStatus::operationStage($activeOperation) }}</span></div></div>
                @else<div class="qb-empty"><x-filament::icon icon="heroicon-o-document-text" /><strong>{{ $ui::text('visual.no_current') }}</strong><span>{{ $ui::text('visual.all_complete') }}</span></div>@endif
            </section>
        </div>
        <details class="qb-card qb-disclosure" open><summary><span class="qb-icon"><x-filament::icon icon="heroicon-o-adjustments-horizontal" /></span><span><span class="qb-technical-title">{{ $ui::text('technical_detail') }}</span><span class="qb-technical-help">{{ $ui::text('visual.technical_help') }}</span></span><x-filament::icon icon="heroicon-o-chevron-down" class="qb-chevron" /></summary>
            <div class="qb-disclosure-body"><dl class="qb-meta">
                <div class="qb-meta-item"><x-filament::icon icon="heroicon-o-server-stack" /><div><dt>{{ $ui::text('pages.dashboard.environment') }}</dt><dd><bdi dir="ltr">{{ $environment }}</bdi></dd></div></div>
                <div class="qb-meta-item"><x-filament::icon icon="heroicon-o-server" /><div><dt>{{ $ui::text('operator.storage') }}</dt><dd>{{ $repository ? $ui::value($repository['status'] ?? null) : $ui::text('empty_states.no_health') }}</dd></div></div>
                <div class="qb-meta-item"><x-filament::icon icon="heroicon-o-cog-6-tooth" /><div><dt>{{ $ui::text('visual.last_worker') }}</dt><dd><bdi>{{ $ui::dateTimeValue($workerObserved) }}</bdi></dd></div></div>
                <div class="qb-meta-item"><x-filament::icon icon="heroicon-o-shield-check" /><div><dt>{{ $ui::text('pages.dashboard.integrity_checked') }}</dt><dd><bdi>{{ $ui::dateTime($resticCheck?->finished_at) }}</bdi></dd></div></div>
            </dl>@if ($scheduleError)<p class="qb-danger-text">{{ $scheduleError }}</p>@endif</div>
        </details>
    </div>
</x-filament-panels::page>
