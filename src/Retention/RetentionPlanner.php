<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use Carbon\CarbonImmutable;
use Quraba\Backup\Exceptions\RetentionFailed;

/**
 * Pure, deterministic retention planning. It reads nothing and deletes
 * nothing: given the same candidates, policies, protections and time it
 * always produces the same plan.
 *
 * Per family (database, media, recovery), the keep rules are applied to the
 * family's COMPLETE backups only, so partial runs can never push a complete
 * backup out of its slot. A partial run is kept while it is newer than the
 * family's newest complete backup (it may hold the freshest copy of one
 * component) and expires once a newer complete backup exists.
 *
 * Protections always win over the policy:
 *  - the newest verified application archive and the newest verified media
 *    snapshot (of any family), the newest complete Recovery Point and the
 *    newest quiesced complete Recovery Point;
 *  - pinned runs, pre-restore safety backups (reserved: never expired yet)
 *    and runs referenced by unresolved restores (external protections).
 *
 * Runs that are not terminal (running, verifying, indeterminate …) are never
 * candidates, so their artifacts are never touched. The result is checked
 * again: a family that has candidates always keeps at least one.
 */
final class RetentionPlanner
{
    private const array PERIODS = [
        'keep_daily' => 'Y-m-d',
        'keep_weekly' => 'o-\WW',
        'keep_monthly' => 'Y-m',
        'keep_yearly' => 'Y',
    ];

    /**
     * @param  list<RetentionCandidate>  $candidates
     * @param  array<string, RetentionPolicy>  $policies  keyed by family
     * @param  array<string, string>  $externalProtections  run UUID => reason
     */
    public function plan(array $candidates, array $policies, array $externalProtections, CarbonImmutable $now): RetentionPlan
    {
        foreach (RetentionPolicy::FAMILIES as $family) {
            if (! isset($policies[$family])) {
                throw RetentionFailed::invalidPolicy(sprintf('no policy for the %s family', $family));
            }
        }

        usort($candidates, RetentionCandidate::newestFirst(...));

        /** @var array<string, list<string>> $reasons */
        $reasons = [];

        foreach ($candidates as $candidate) {
            $reasons[$candidate->runUuid] = [];
        }

        foreach (RetentionPolicy::FAMILIES as $family) {
            $this->applyPolicy(array_values(array_filter($candidates, static fn (RetentionCandidate $c): bool => $c->family === $family)), $policies[$family], $reasons);
        }

        $this->applyProtections($candidates, $externalProtections, $now, $reasons);

        $decisions = [];

        foreach (RetentionPolicy::FAMILIES as $family) {
            foreach ($candidates as $candidate) {
                if ($candidate->family === $family) {
                    $decisions[] = new RetentionDecision($candidate, $reasons[$candidate->runUuid] === [], array_values(array_unique($reasons[$candidate->runUuid])));
                }
            }
        }

        // Candidates of unknown families (none today) are always kept.
        foreach ($candidates as $candidate) {
            if (! in_array($candidate->family, RetentionPolicy::FAMILIES, true)) {
                $decisions[] = new RetentionDecision($candidate, false, ['protected:unknown_family']);
            }
        }

        $plan = new RetentionPlan($now->utc(), $decisions, array_map(static fn (RetentionPolicy $p): array => $p->toArray(), $policies));
        $this->assertInvariants($plan);

        return $plan;
    }

    /**
     * @param  list<RetentionCandidate>  $family  newest first
     * @param  array<string, list<string>>  $reasons
     */
    private function applyPolicy(array $family, RetentionPolicy $policy, array &$reasons): void
    {
        $complete = array_values(array_filter($family, static fn (RetentionCandidate $c): bool => $c->isComplete()));

        foreach (array_slice($complete, 0, $policy->count('keep_latest')) as $candidate) {
            $reasons[$candidate->runUuid][] = 'keep_latest';
        }

        foreach (self::PERIODS as $rule => $format) {
            $limit = $policy->count($rule);
            $seen = [];

            foreach ($complete as $candidate) {
                if (count($seen) >= $limit) {
                    break;
                }

                $period = $candidate->requestedAt->utc()->format($format);

                if (! isset($seen[$period])) {
                    $seen[$period] = true;
                    $reasons[$candidate->runUuid][] = $rule;
                }
            }
        }

        $newestComplete = $complete[0] ?? null;

        foreach ($family as $candidate) {
            if (! $candidate->isComplete() && ($newestComplete === null || RetentionCandidate::newestFirst($candidate, $newestComplete) < 0)) {
                $reasons[$candidate->runUuid][] = 'newer_than_last_complete';
            }
        }
    }

    /**
     * @param  list<RetentionCandidate>  $candidates  newest first
     * @param  array<string, string>  $external
     * @param  array<string, list<string>>  $reasons
     */
    private function applyProtections(array $candidates, array $external, CarbonImmutable $now, array &$reasons): void
    {
        $firsts = [
            'protected:newest_database_archive' => static fn (RetentionCandidate $c): bool => $c->archiveLocator !== null,
            'protected:newest_media_snapshot' => static fn (RetentionCandidate $c): bool => $c->snapshotId !== null,
            'protected:newest_recovery_point' => static fn (RetentionCandidate $c): bool => $c->isCompleteRecoveryPoint(),
            'protected:newest_quiesced_recovery_point' => static fn (RetentionCandidate $c): bool => $c->isCompleteRecoveryPoint() && $c->consistency === 'quiesced',
        ];

        foreach ($firsts as $reason => $matches) {
            foreach ($candidates as $candidate) {
                if ($matches($candidate)) {
                    $reasons[$candidate->runUuid][] = $reason;

                    break;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate->pinnedUntil !== null && $candidate->pinnedUntil->greaterThan($now)) {
                $reasons[$candidate->runUuid][] = 'protected:pinned';
            }

            if ($candidate->trigger === 'pre_restore') {
                $reasons[$candidate->runUuid][] = 'protected:pre_restore_safety';
            }

            if (isset($external[$candidate->runUuid])) {
                $reasons[$candidate->runUuid][] = 'protected:'.$external[$candidate->runUuid];
            }
        }
    }

    private function assertInvariants(RetentionPlan $plan): void
    {
        $byFamily = [];

        foreach ($plan->decisions as $decision) {
            $family = $decision->candidate->family;
            $byFamily[$family] ??= ['candidates' => 0, 'kept' => 0];
            $byFamily[$family]['candidates']++;
            $byFamily[$family]['kept'] += $decision->expire ? 0 : 1;
        }

        foreach ($byFamily as $family => $counts) {
            if ($counts['kept'] === 0) {
                throw RetentionFailed::invalidPolicy(sprintf('the plan would remove every %s backup; refusing', $family));
            }
        }
    }
}
