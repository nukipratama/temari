<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\PersonalRecord;
use App\Models\PerformanceEvidence;
use App\Models\PlannedSession;
use App\Enums\IntentVerdict;
use App\Enums\SessionType;
use App\Models\FitnessAnchor;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Actions\Run\Metrics\ResolveDistanceRecordsAction;

/**
 * Daniels' VDOT formula (1998 tables):
 *   v     = distance_m / time_min                                          (m/min)
 *   VO2   = -4.60 + 0.182258·v + 0.000104·v²                              (ml/kg/min)
 *   pmax  = 0.80 + 0.1894393·e^(-0.012778·t) + 0.2989558·e^(-0.1932605·t) (fraction of VO2max sustainable for t min)
 *   VDOT  = VO2 / pmax
 * Skipping pmax underestimates marathon VDOT by ~10 points.
 *
 * @phpstan-type VdotEstimate array{vdot: float, quality_vdot: float, source_activity_id?: int|null, source_value_sec?: float|null, source_category: string, set_at: Carbon, stale: bool, quality_source: array{source_category: string, set_at: Carbon, source_activity_id?: int|null, source_value_sec?: float|null, evidence_kind?: string, distance_m?: int}|null, confidence: string, evidence_id: int|null, evidence_kind?: string|null, distance_m?: int|null, corroborating_quality_count: int}
 */
class VdotEstimator
{
    /** @var array<int, array<string, VdotEstimate|null>> */
    private array $estimates = [];

    /** @var array<int, Collection<int, PerformanceEvidence>> */
    private array $evidenceByUser = [];

    /** @var array<int, FitnessAnchor|null> */
    private array $anchorsByUser = [];

    /** @var array<int, Collection<int, PlannedSession>> */
    private array $qualitySessionsByUser = [];

    public function __construct(
        private readonly ResolveDistanceRecordsAction $distanceRecords,
    ) {
    }

    // Coefficients of the VO2 = c + b·v + a·v² relationship (v in m/min),
    // shared with {@see \App\Services\Run\Metrics\TrainingPaceCalculator}, which
    // solves this same quadratic for v given a target VO2.
    public const float VO2_COEFFICIENT_A = 0.000104;

    public const float VO2_COEFFICIENT_B = 0.182258;

    public const float VO2_COEFFICIENT_C = -4.60;

    /**
     * A personal record is only ever replaced by a faster one, so it improves but
     * never ages out on its own. Unbounded, one hard effort from years ago keeps
     * a permanent veto over every prescribed pace.
     */
    public const int RECENT_MONTHS = 12;

    /**
     * A record is a floor on what the athlete could do on its own date, never a
     * ceiling on what they can do now. Taking one minimum across every distance
     * and every date conflates the two: a hard long effort from months ago
     * outvotes a recent short one and holds quality paces below what the athlete
     * demonstrably runs in training. Quality work therefore reads a second
     * anchor, restricted to recent short-distance evidence.
     */
    public const int QUALITY_MONTHS = 3;

    public const float QUALITY_MAX_METERS = 10_000.0;

    /**
     * A record shorter than this is a few minutes of work, and is often a
     * closing surge inside an easy run rather than an effort. It may still
     * refine the quality anchor, since the minimum keeps whichever evidence is
     * most conservative, but it cannot establish one on its own: an athlete
     * whose only recent short record is a sprint would otherwise have 30-minute
     * tempo work prescribed from three minutes of running.
     */
    public const float QUALITY_MIN_METERS = 3_000.0;

    private const float CONFIDENCE_CONFLICT_PERCENT = 10.0;

    private const float COMPARABLE_DISTANCE_PERCENT = 10.0;

    /** @return VdotEstimate|null */
    public function estimate(User $user, ?Carbon $asOf = null): ?array
    {
        $now = ($asOf ?? Carbon::now())->copy();
        $date = $now->toDateString();
        if (array_key_exists($date, $this->estimates[$user->id] ?? [])) {
            return $this->estimates[$user->id][$date];
        }

        return $this->estimates[$user->id][$date] = $this->estimateAsOf($user, $now);
    }

    public function forget(User $user): void
    {
        unset($this->estimates[$user->id]);
        unset($this->evidenceByUser[$user->id]);
        unset($this->anchorsByUser[$user->id]);
        unset($this->qualitySessionsByUser[$user->id]);
    }

    /** @return VdotEstimate|null */
    private function estimateAsOf(User $user, Carbon $now): ?array
    {
        $confirmed = $this->confirmedEstimate($user, $now);
        if ($confirmed !== null) {
            return $confirmed;
        }

        $anchor = $this->anchorForUser($user);
        if ($anchor !== null && $anchor->captured_at->lte($now->copy()->endOfDay())) {
            $stale = $anchor->set_at->lt($now->copy()->subMonths(self::RECENT_MONTHS));

            return [
                'vdot' => $anchor->vdot,
                'quality_vdot' => $anchor->quality_vdot,
                'source_activity_id' => $anchor->source_activity_id,
                'source_value_sec' => $anchor->source_value_sec,
                'source_category' => $anchor->source_category,
                'set_at' => $anchor->set_at,
                'evidence_kind' => null,
                'distance_m' => null,
                'stale' => $stale,
                'quality_source' => $anchor->quality_source_category === null || $anchor->quality_set_at === null
                    ? null
                    : [
                        'source_category' => $anchor->quality_source_category,
                        'set_at' => $anchor->quality_set_at,
                        'source_activity_id' => $anchor->quality_source_activity_id,
                        'source_value_sec' => $anchor->quality_source_value_sec,
                    ],
                'confidence' => $stale ? 'stale' : 'provisional',
                'evidence_id' => null,
                'corroborating_quality_count' => $this->corroboratingQualityCount($user, $now),
            ];
        }

        return $this->provisionalEstimate($user, $now);
    }

    public function captureProvisionalAnchor(User $user, ?Carbon $capturedAt = null): void
    {
        if (FitnessAnchor::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        $capturedAt ??= Carbon::now();
        $estimate = $this->provisionalEstimate($user, $capturedAt);
        if ($estimate === null) {
            return;
        }

        FitnessAnchor::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'vdot' => $estimate['vdot'],
                'quality_vdot' => $estimate['quality_vdot'],
                'source_activity_id' => $estimate['source_activity_id'],
                'source_value_sec' => $estimate['source_value_sec'],
                'source_category' => $estimate['source_category'],
                'set_at' => $estimate['set_at']->toDateString(),
                'quality_source_activity_id' => $estimate['quality_source']['source_activity_id'] ?? null,
                'quality_source_category' => $estimate['quality_source']['source_category'] ?? null,
                'quality_source_value_sec' => $estimate['quality_source']['source_value_sec'] ?? null,
                'quality_set_at' => $estimate['quality_source']['set_at'] ?? null,
                'captured_at' => $capturedAt,
            ],
        );
        $this->forget($user);
    }

    /** @return array{vdot: float, quality_vdot: float, source_activity_id: int|null, source_value_sec: float, source_category: string, set_at: Carbon, stale: bool, quality_source: array{source_category: string, set_at: Carbon, source_activity_id: int|null, source_value_sec: float}|null, confidence: string, evidence_id: null, corroborating_quality_count: int}|null */
    private function provisionalEstimate(User $user, Carbon $now): ?array
    {
        $prs = ($this->distanceRecords)($user->id);
        $cutoff = $now->copy()->subMonths(self::RECENT_MONTHS);
        $qualityCutoff = $now->copy()->subMonths(self::QUALITY_MONTHS);
        $sustainedPrs = $prs->filter(
            static fn (PersonalRecord $pr): bool => ($pr->category->distanceMeters() ?? 0) >= self::QUALITY_MIN_METERS,
        );
        $asOfPrs = $prs->filter(
            static fn (PersonalRecord $pr): bool => $pr->set_at->lessThanOrEqualTo($now->copy()->endOfDay()),
        );
        $asOfSustainedPrs = $sustainedPrs->filter(
            static fn (PersonalRecord $pr): bool => $pr->set_at->lessThanOrEqualTo($now->copy()->endOfDay()),
        );

        if ($asOfSustainedPrs->isEmpty()) {
            return null;
        }

        $result = $this->lowestVdot($asOfSustainedPrs->filter(
            static fn (PersonalRecord $pr): bool => $pr->set_at->greaterThanOrEqualTo($cutoff),
        ));
        $stale = $result === null;
        $result ??= $this->lowestVdot($asOfSustainedPrs);

        if ($result === null) {
            return null;
        }

        $qualityEvidence = $asOfPrs->filter(
            static fn (PersonalRecord $pr): bool => $pr->set_at->greaterThanOrEqualTo($qualityCutoff)
                && $pr->category->distanceMeters() !== null
                && $pr->category->distanceMeters() <= self::QUALITY_MAX_METERS,
        );
        $hasSustainedQualityEvidence = $qualityEvidence->contains(
            static fn (PersonalRecord $pr): bool => ($pr->category->distanceMeters() ?? 0) >= self::QUALITY_MIN_METERS,
        );
        $quality = $hasSustainedQualityEvidence ? $this->lowestVdot($qualityEvidence) : null;
        $qualityVdot = $quality === null ? $result['vdot'] : max($result['vdot'], $quality['vdot']);

        $corroboratingQualityCount = $this->corroboratingQualityCount($user, $now);

        return [
            ...$result,
            'quality_vdot' => $qualityVdot,
            'quality_source' => $quality !== null && $qualityVdot > $result['vdot']
                ? [
                    'source_category' => $quality['source_category'],
                    'set_at' => $quality['set_at'],
                    'source_activity_id' => $quality['source_activity_id'],
                    'source_value_sec' => $quality['source_value_sec'],
                ]
                : null,
            'stale' => $stale,
            'confidence' => $stale ? 'stale' : 'provisional',
            'evidence_id' => null,
            'corroborating_quality_count' => $corroboratingQualityCount,
        ];
    }

    /** @return array{vdot: float, quality_vdot: float, source_category: string, set_at: Carbon, stale: bool, quality_source: array{source_category: string, set_at: Carbon}|null, confidence: string, evidence_id: int, corroborating_quality_count: int}|null */
    private function confirmedEstimate(User $user, Carbon $now): ?array
    {
        $evidence = $this->evidenceForUser($user)->filter(
            static fn (PerformanceEvidence $row): bool => $row->performed_on->lessThanOrEqualTo($now->toDateString())
                && $row->confirmed_at->lessThanOrEqualTo($now->copy()->endOfDay())
                && $row->distance_m >= 1_000
                && $row->distance_m <= 42_195,
        );
        $candidates = [];
        foreach ($evidence as $row) {
            $vdot = $this->vdotFromTimeAndDistance($row->elapsed_time_sec, $row->distance_m);
            if ($vdot !== null) {
                $candidates[] = ['vdot' => round($vdot, 1), 'evidence' => $row];
            }
        }

        $sustainedCandidates = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['evidence']->distance_m >= self::QUALITY_MIN_METERS,
        ));
        if ($sustainedCandidates === []) {
            return null;
        }

        $recentCutoff = $now->copy()->subMonths(self::RECENT_MONTHS);
        $qualityCutoff = $now->copy()->subMonths(self::QUALITY_MONTHS);
        $recentEvidence = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['evidence']->performed_on->gte($recentCutoff),
        ));
        $recentSustainedEvidence = array_values(array_filter(
            $recentEvidence,
            static fn (array $candidate): bool => $candidate['evidence']->distance_m >= self::QUALITY_MIN_METERS,
        ));
        $anchorPool = $recentSustainedEvidence === [] ? $sustainedCandidates : $recentSustainedEvidence;
        $anchor = $this->lowestConfirmedCandidate($this->newestComparableCandidates($anchorPool));
        if ($anchor === null) {
            return null;
        }

        $recentQualityEvidence = array_values(array_filter(
            $recentEvidence,
            static fn (array $candidate): bool => $candidate['evidence']->performed_on->gte($qualityCutoff)
                && $candidate['evidence']->distance_m <= self::QUALITY_MAX_METERS,
        ));
        $hasSustainedQualityEvidence = array_any(
            $recentQualityEvidence,
            static fn (array $candidate): bool => $candidate['evidence']->distance_m >= self::QUALITY_MIN_METERS,
        );
        $quality = $hasSustainedQualityEvidence
            ? $this->lowestConfirmedCandidate($this->newestComparableCandidates($recentQualityEvidence))
            : null;
        $qualityVdot = $quality === null ? $anchor['vdot'] : max($anchor['vdot'], $quality['vdot']);
        $distinctRecentEvidence = $this->newestComparableCandidates($recentSustainedEvidence);
        $vdots = array_column($distinctRecentEvidence, 'vdot');
        $conflicting = count($vdots) > 1
            && (max($vdots) - min($vdots)) / min($vdots) >= self::CONFIDENCE_CONFLICT_PERCENT / 100;
        $qualitySource = null;
        if ($quality !== null && $qualityVdot > $anchor['vdot']) {
            $qualitySource = [
                'source_category' => 'confirmed_'.$quality['evidence']->kind->value,
                'set_at' => $quality['evidence']->performed_on,
                'evidence_kind' => $quality['evidence']->kind->value,
                'distance_m' => $quality['evidence']->distance_m,
            ];
        }

        return [
            'vdot' => $anchor['vdot'],
            'quality_vdot' => $qualityVdot,
            'source_category' => 'confirmed_'.$anchor['evidence']->kind->value,
            'set_at' => $anchor['evidence']->performed_on,
            'evidence_kind' => $anchor['evidence']->kind->value,
            'distance_m' => $anchor['evidence']->distance_m,
            'stale' => $recentSustainedEvidence === [],
            'confidence' => $recentSustainedEvidence === [] ? 'stale' : ($conflicting ? 'conflicting' : 'confirmed'),
            'evidence_id' => $anchor['evidence']->id,
            'corroborating_quality_count' => $this->corroboratingQualityCount($user, $now),
            'quality_source' => $qualitySource,
        ];
    }

    /**
     * @param list<array{vdot: float, evidence: PerformanceEvidence}> $candidates
     * @return array{vdot: float, evidence: PerformanceEvidence}|null
     */
    private function lowestConfirmedCandidate(array $candidates): ?array
    {
        $lowest = null;
        foreach ($candidates as $candidate) {
            if ($lowest === null || $candidate['vdot'] < $lowest['vdot']) {
                $lowest = $candidate;
            }
        }

        return $lowest;
    }

    /** @param list<array{vdot: float, evidence: PerformanceEvidence}> $candidates
     * @return list<array{vdot: float, evidence: PerformanceEvidence}>
     */
    private function newestComparableCandidates(array $candidates): array
    {
        $groups = [];
        foreach ($candidates as $candidate) {
            $distance = $candidate['evidence']->distance_m;
            $matched = false;
            foreach ($groups as &$group) {
                foreach ($group['members'] as $member) {
                    $memberDistance = $member['evidence']->distance_m;
                    if (abs($distance - $memberDistance) / min($distance, $memberDistance)
                        <= self::COMPARABLE_DISTANCE_PERCENT / 100) {
                        $group['members'][] = $candidate;
                        $matched = true;
                        break;
                    }
                }
                unset($member);
                if ($matched) {
                    break;
                }
            }
            unset($group);

            if (! $matched) {
                $groups[] = ['representative' => $candidate, 'members' => [$candidate]];
            }
        }

        return array_map(static fn (array $group): array => $group['representative'], $groups);
    }

    private function corroboratingQualityCount(User $user, Carbon $asOf): int
    {
        $cutoff = $asOf->copy()->subMonths(self::QUALITY_MONTHS);

        return $this->qualitySessionsForUser($user)->filter(
            static fn (PlannedSession $session): bool => $session->date->greaterThanOrEqualTo($cutoff)
                && $session->date->lessThanOrEqualTo($asOf->toDateString()),
        )->count();
    }

    /** @return Collection<int, PerformanceEvidence> */
    private function evidenceForUser(User $user): Collection
    {
        return $this->evidenceByUser[$user->id] ??= PerformanceEvidence::query()->where('user_id', $user->id)
            ->orderByDesc('performed_on')->orderByDesc('id')->get();
    }

    private function anchorForUser(User $user): ?FitnessAnchor
    {
        if (! array_key_exists($user->id, $this->anchorsByUser)) {
            $this->anchorsByUser[$user->id] = FitnessAnchor::query()->where('user_id', $user->id)->first();
        }

        return $this->anchorsByUser[$user->id];
    }

    /** @return Collection<int, PlannedSession> */
    private function qualitySessionsForUser(User $user): Collection
    {
        return $this->qualitySessionsByUser[$user->id] ??= PlannedSession::query()->where('user_id', $user->id)
            ->whereIn('session_type', [SessionType::Tempo, SessionType::Interval])
            ->where('intent_verdict', IntentVerdict::Hit)
            ->where('prescribed_hard_minutes', '>', 0)
            ->whereNull('clamped_km')
            ->whereNull('rest_clamped_at')
            ->where('intent_evidence->advice_history', 'shown')
            ->orderBy('date')->get(['id', 'date']);
    }

    /** @param Collection<int, PersonalRecord> $prs
     * @return array{vdot: float, source_activity_id: int|null, source_value_sec: float, source_category: string, set_at: Carbon}|null
     */
    private function lowestVdot(Collection $prs): ?array
    {
        $best = null;
        $bestVdot = null;

        foreach ($prs as $pr) {
            $distance = $pr->category->distanceMeters();
            if ($distance === null) {
                continue;
            }
            $vdot = $this->vdotFromTimeAndDistance($pr->value_sec, $distance);
            if ($vdot === null) {
                continue;
            }
            // Daniels' formula is distance-normalized in theory, but a runner
            // who is disproportionately fast over a short distance (anaerobic
            // speed, not aerobic endurance) makes a max-across-distances VDOT
            // prescribe paces faster than any real PR at longer distances —
            // e.g. a marathon "target" pace quicker than the athlete's actual
            // marathon PR. Same asymmetric-caution stance as {@see Readiness}:
            // take the MINIMUM VDOT across categories, so every prescribed
            // pace stays within what at least one genuine PR has proven
            // reachable, never faster than the athlete's slowest relative PR.
            if ($bestVdot === null || $vdot < $bestVdot) {
                $bestVdot = $vdot;
                $best = $pr;
            }
        }

        if ($bestVdot === null || $best === null) {
            return null;
        }

        return [
            'vdot' => round($bestVdot, 1),
            'source_activity_id' => $best->activity_id,
            'source_value_sec' => $best->value_sec,
            'source_category' => $best->category->value,
            'set_at' => $best->set_at,
        ];
    }

    /**
     * @param VdotEstimate $estimate
     * @return array{category: string, set_at: string, stale: bool, confidence: string, evidence_id: int|null, evidence_kind: string|null, distance_m: int|null, corroborating_quality_count: int, quality_category: string|null, quality_set_at: string|null, quality_evidence_kind: string|null, quality_distance_m: int|null}
     */
    public static function sourceSummary(array $estimate): array
    {
        return [
            'category' => $estimate['source_category'],
            'set_at' => $estimate['set_at']->toDateString(),
            'stale' => $estimate['stale'],
            'confidence' => $estimate['confidence'],
            'evidence_id' => $estimate['evidence_id'],
            'evidence_kind' => $estimate['evidence_kind'] ?? null,
            'distance_m' => $estimate['distance_m'] ?? null,
            'corroborating_quality_count' => $estimate['corroborating_quality_count'],
            'quality_category' => $estimate['quality_source']['source_category'] ?? null,
            'quality_set_at' => isset($estimate['quality_source'])
                ? $estimate['quality_source']['set_at']->toDateString()
                : null,
            'quality_evidence_kind' => $estimate['quality_source']['evidence_kind'] ?? null,
            'quality_distance_m' => $estimate['quality_source']['distance_m'] ?? null,
        ];
    }

    public function vdotFromTimeAndDistance(float $elapsedSec, float $distanceMeters): ?float
    {
        if ($elapsedSec <= 0 || $distanceMeters <= 0) {
            return null;
        }
        $timeMin = $elapsedSec / 60.0;
        $velocity = $distanceMeters / $timeMin; // m/min

        $vo2 = self::VO2_COEFFICIENT_C + self::VO2_COEFFICIENT_B * $velocity + self::VO2_COEFFICIENT_A * $velocity * $velocity;

        // pmax is mathematically always > 0.8 (both exponential terms are positive),
        // so no defensive divide-by-zero check is needed here.
        $pmax = 0.80
            + 0.1894393 * exp(-0.012778 * $timeMin)
            + 0.2989558 * exp(-0.1932605 * $timeMin);

        return $vo2 > 0 ? $vo2 / $pmax : null;
    }
}
