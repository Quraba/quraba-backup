<x-filament-panels::page>
    @include('quraba-backup::filament.partials.reference-styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    @php($health = $latest['health_refresh'] ?? null)
    @php($checks = is_array($health?->result['checks'] ?? null) ? $health->result['checks'] : [])
    @php($issues = collect($checks)->filter(fn ($check) => in_array($check['status'] ?? null, ['fail', 'warn'], true)))
    @php($storage = collect($checks)->firstWhere('id', 'health.repository'))
    @php($healthState = $health?->result['state'] ?? 'unknown')
    <div class="qb-canvas" x-data @if ($health?->status?->isOpen()) wire:poll.20s @endif>
        @if (! $operationsAvailable || ! $maintenanceAvailable)<div class="qb-card qb-section qb-warn-text">{{ $ui::text('notices.migrations') }}</div>@endif
        <section class="qb-card qb-hero qb-hero--amber" aria-labelledby="qb-health-summary-title">
            <div class="qb-hero-art" aria-hidden="true"><x-filament::icon icon="heroicon-o-shield-exclamation" /></div>
            <div class="qb-hero-main"><div class="qb-hero-heading"><h2 id="qb-health-summary-title">{{ $ui::text(match ($healthState) { 'healthy' => 'visual.health_summary_ok', 'degraded', 'failed' => 'visual.health_summary', default => 'visual.health_summary_unknown' }) }}</h2></div>
                <p>{{ $issues->isNotEmpty() ? $ui::text('visual.health_warning') : ($healthState === 'healthy' ? $ui::text('visual.health_ready') : $ui::text('operator.health_unknown')) }}</p>
            </div>
            <div class="qb-health-rail"><div><x-filament::icon icon="heroicon-o-calendar-days" /><span>{{ $ui::text('operator.last_check') }}<bdi>{{ $ui::dateTime($health?->finished_at) }}</bdi></span></div><div><x-filament::icon icon="heroicon-o-cog-6-tooth" /><span>{{ $ui::text('operator.background') }}<small>{{ $pendingEnabled ? $ui::value($workerRecent ? 'recently_observed' : 'not_recently_observed') : $ui::value('disabled') }}</small></span></div></div>
        </section>

        <div class="qb-issue-grid">
            <section class="qb-card qb-section" aria-labelledby="qb-issues-title"><div class="qb-section-head"><span class="qb-icon qb-icon--amber"><x-filament::icon icon="heroicon-o-exclamation-triangle" /></span><div><h2 id="qb-issues-title">{{ $ui::text('visual.health_issues') }}</h2><p>{{ $ui::text('visual.health_issues_help') }}</p></div></div>
                <div class="qb-issue-list">
                    @forelse ($issues as $issue)
                        @php($issueId = $issue['id'] ?? '')
                        @php($issueIcon = str_contains($issueId, 'password') ? 'heroicon-o-lock-closed' : (str_contains($issueId, 'retention') ? 'heroicon-o-clock' : 'heroicon-o-circle-stack'))
                        <div class="qb-issue-row"><span class="qb-icon {{ str_contains($issueId, 'database') ? 'qb-icon--purple' : '' }}"><x-filament::icon :icon="$issueIcon" /></span><div class="qb-issue-text"><strong>{{ $ui::checkLabel($issueId, $issue['label'] ?? $ui::text('operator.attention')) }}</strong><small>{{ \Quraba\Backup\Filament\OperatorStatus::healthIssue($issue) }}</small></div><span class="qb-pill qb-pill--{{ ($issue['status'] ?? '') === 'fail' ? 'red' : 'amber' }}">{{ $ui::value($issue['status'] ?? null) }}</span></div>
                    @empty
                        <div class="qb-empty"><x-filament::icon icon="heroicon-o-shield-check" /><strong>{{ $health ? $ui::text('visual.health_ready') : $ui::text('operator.health_unknown') }}</strong></div>
                    @endforelse
                </div>
            </section>
            <section class="qb-card qb-section" aria-labelledby="qb-other-title"><div class="qb-section-head"><span class="qb-icon qb-icon--green"><x-filament::icon icon="heroicon-o-check-circle" /></span><div><h2 id="qb-other-title">{{ $ui::text('visual.health_other') }}</h2><p>{{ $ui::text('visual.health_other_help') }}</p></div></div>
                <div class="qb-issue-list">
                    <div class="qb-issue-row"><span class="qb-icon"><x-filament::icon icon="heroicon-o-server-stack" /></span><div class="qb-issue-text"><strong>{{ $ui::text('operator.storage') }}</strong><small>{{ $storage ? $ui::value($storage['status'] ?? null) : $ui::text('empty_states.not_checked') }}</small></div><span class="qb-pill qb-pill--{{ ($storage['status'] ?? null) === 'pass' ? 'green' : 'amber' }}">{{ $ui::value(($storage['status'] ?? null) === 'pass' ? 'pass' : 'unknown') }}</span></div>
                    <div class="qb-issue-row"><span class="qb-icon"><x-filament::icon icon="heroicon-o-cog-6-tooth" /></span><div class="qb-issue-text"><strong>{{ $ui::text('operator.background') }}</strong><small>{{ $pendingEnabled ? ($workerRecent ? $ui::value('recently_observed') : $ui::text($waitingRequests > 0 ? 'operator.background_stale' : 'operator.background_unseen')) : $ui::value('disabled') }}</small></div><span class="qb-pill qb-pill--{{ $workerRecent ? 'green' : 'amber' }}">{{ $ui::value($workerRecent ? 'pass' : 'unknown') }}</span></div>
                </div>
            </section>
        </div>

        <section class="qb-card qb-section" aria-labelledby="qb-main-checks-title"><div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-cube" /></span><div><h2 id="qb-main-checks-title">{{ $ui::text('visual.health_main') }}</h2><p>{{ $ui::text('visual.health_main_help') }}</p></div></div>
            <div class="qb-checks">
                @foreach (['health_refresh' => ['heroicon-o-circle-stack', 'qb-icon--purple'], 'doctor' => ['heroicon-o-shield-check', ''], 'restic_check' => ['heroicon-o-server-stack', ''], 'retention_plan' => ['heroicon-o-document-text', 'qb-icon--purple']] as $type => [$icon, $tone])
                    @php($operation = $latest[$type] ?? null)
                    @php($state = $operation?->result['state'] ?? ($operation?->status?->value === 'completed' ? 'healthy' : ($operation?->status?->value === 'failed' ? 'failed' : 'unknown')))
                    <div class="qb-check"><div class="qb-check-top"><span class="qb-icon {{ $tone }}"><x-filament::icon :icon="$icon" /></span><strong>{{ $ui::value($type, 'operations') }}</strong></div><span class="qb-pill qb-pill--{{ match ($state) { 'healthy' => 'green', 'failed' => 'red', 'degraded' => 'amber', default => 'gray' } }}">{{ $ui::value($state) }}</span><p>{{ $operation ? \Quraba\Backup\Filament\OperatorStatus::operationStage($operation) : $ui::text('empty_states.never_run') }}</p><button type="button" class="qb-detail-link" x-on:click="document.getElementById('qb-health-detail-{{ $type }}').open = true; document.getElementById('qb-health-detail-{{ $type }}').scrollIntoView({behavior:'smooth',block:'center'})">{{ $ui::text('visual.view_details') }} ←</button></div>
                @endforeach
            </div>
        </section>

        <div class="qb-columns">
            <section class="qb-card qb-section" aria-labelledby="qb-schedule-title"><div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-clock" /></span><div><h2 id="qb-schedule-title">{{ $ui::text('pages.health.schedules_worker') }}</h2><p>{{ $ui::text('pages.health.schedules_help') }}</p></div></div>
                <div class="qb-kv">
                    @foreach (['timezone' => 'heroicon-o-globe-alt', 'database' => 'heroicon-o-circle-stack', 'media' => 'heroicon-o-document', 'recovery' => 'heroicon-o-calendar-days'] as $key => $icon)
                        @php($setting = $schedule[$key] ?? null)
                        <div class="qb-kv-row"><span class="qb-kv-label"><x-filament::icon :icon="$icon" />{{ $ui::value($key, 'schedule') }}</span><bdi dir="{{ $key === 'timezone' ? 'ltr' : 'auto' }}">{{ $setting ? (is_array($setting['value']) ? $ui::scheduleSetting($setting['value']) : ($setting['value'] ?: $ui::text('schedule.application_default'))) : '—' }}</bdi></div>
                    @endforeach
                    <div class="qb-kv-row"><span class="qb-kv-label"><x-filament::icon icon="heroicon-o-cog-6-tooth" />{{ $ui::text('operator.background') }}</span><span class="qb-pill qb-pill--{{ $workerRecent ? 'green' : 'amber' }}">{{ $pendingEnabled ? $ui::value($workerRecent ? 'recently_observed' : 'not_recently_observed') : $ui::value('disabled') }}</span></div>
                </div>@if ($scheduleError)<p class="qb-danger-text">{{ $scheduleError }}</p>@endif
            </section>
            <section class="qb-card qb-section" aria-labelledby="qb-unresolved-title"><div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-clock" /></span><div><h2 id="qb-unresolved-title">{{ $ui::text('visual.health_unresolved') }}</h2><p>{{ $ui::text('visual.health_unresolved_help') }}</p></div></div>
                @if ($unresolved->isEmpty())<div class="qb-empty"><x-filament::icon icon="heroicon-o-document-text" /><strong>{{ $ui::text('empty_states.no_operations') }}</strong><span>{{ $ui::text('visual.all_complete') }}</span></div>
                @else<div class="qb-issue-list">@foreach ($unresolved as $operation)<div class="qb-issue-row"><span class="qb-icon qb-icon--amber"><x-filament::icon icon="heroicon-o-exclamation-triangle" /></span><div class="qb-issue-text"><strong>{{ $ui::value($operation->type, 'operations') }}</strong><small>{{ \Quraba\Backup\Filament\OperatorStatus::failure($operation->failure_code, $operation->failure_message) }}</small></div><span class="qb-pill qb-pill--amber">{{ $ui::value($operation->status) }}</span></div>@endforeach</div>@endif
            </section>
        </div>

        <section class="qb-card qb-section" aria-labelledby="qb-health-advanced-title"><div class="qb-section-head"><span class="qb-icon"><x-filament::icon icon="heroicon-o-adjustments-horizontal" /></span><div><h2 id="qb-health-advanced-title">{{ $ui::text('visual.health_advanced') }}</h2><p>{{ $ui::text('visual.health_advanced_help') }}</p></div></div>
            <div class="qb-accordion">
                @foreach (['health_refresh', 'doctor', 'restic_check', 'retention_plan'] as $type)
                    @php($operation = $latest[$type] ?? null)
                    <details id="qb-health-detail-{{ $type }}"><summary>{{ $ui::value($type, 'operations') }}<x-filament::icon icon="heroicon-o-chevron-down" /></summary><div class="qb-accordion-content"><p>{{ $operation ? \Quraba\Backup\Filament\OperatorStatus::operationStage($operation) : $ui::text('empty_states.never_run') }} · <bdi>{{ $ui::dateTime($operation?->finished_at) }}</bdi></p><p>{{ $ui::text('operator.reference') }}: <bdi dir="ltr">{{ $operation?->uuid ?? '—' }}</bdi></p>@if ($operation?->failure_message)<p>{{ \Quraba\Backup\Filament\OperatorStatus::failure($operation->failure_code, $operation->failure_message) }}</p>@endif @if (is_array($operation?->result['checks'] ?? null))@foreach ($operation->result['checks'] as $check)<p>{{ $ui::checkLabel($check['id'] ?? '', $check['label'] ?? '') }} · {{ $ui::value($check['status'] ?? null) }}: {{ app(\Quraba\Backup\Security\SecretRedactor::class)->redact((string) ($check['message'] ?? '')) }}</p>@endforeach @endif</div></details>
                @endforeach
                <details><summary>{{ $ui::text('pages.health.configuration') }}<x-filament::icon icon="heroicon-o-chevron-down" /></summary><div class="qb-accordion-content">@foreach ($secrets as $name => $configured)<p>{{ $name }}: {{ $ui::value($configured ? 'configured' : 'missing') }}</p>@endforeach</div></details>
                <details><summary>{{ $ui::text('pages.health.history') }}<x-filament::icon icon="heroicon-o-chevron-down" /></summary><div class="qb-accordion-content">@forelse ($maintenanceHistory as $run)<p><bdi>{{ $ui::dateTime($run->created_at) }}</bdi> · {{ $ui::value($run->operation, 'maintenance_operations') }} · {{ $ui::value($run->status) }} · {{ $ui::maintenanceMode((bool) $run->dry_run) }} · <bdi dir="ltr" title="{{ $run->uuid }}">{{ $run->uuid }}</bdi></p>@empty<p>{{ $ui::text('empty_states.no_maintenance') }}</p>@endforelse @if ($maintenanceHistory instanceof \Illuminate\Contracts\Pagination\Paginator)<div class="qb-history-pages">{{ $maintenanceHistory->links() }}</div>@endif</div></details>
            </div>
        </section>
    </div>
</x-filament-panels::page>
