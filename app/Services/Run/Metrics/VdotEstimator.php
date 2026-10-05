<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Actions\Run\Metrics\ResolveDistanceRecordsAction;
use App\Actions\Run\Metrics\ResolveHardEffortsAction;
use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Enums\IntentVerdict;
use App\Enums\RaceSupport;
use App\Enums\SessionType;
use App\Models\FitnessAnchor;
use App\Models\PerformanceEvidence;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Daniels' VDOT formula (1998 tables):
 *   v     = distance_m / time_min                                          (m/min)
 *   VO2   = -4.60 + 0.182258·v + 0.000104·v²                              (ml/kg/min)
 *   pmax  = 0.80 + 0.1894393·e^(-0.012778·t) + 0.2989558·e^(-0.1932605·t) (fraction of VO2max sustainable for t min)
 *   VDOT  = VO2 / pmax
 * Skipping pmax underestimates marathon VDOT by ~10 points.
 *
 * The supported VDOT is read at the race distance D (the active race, or 10K)
 * from the athlete's recent whole-run hard efforts, projected with their own
 * fall-off between distances. See docs/decisions/supported-race-time-from-recent-efforts.md.
 *
 * @phpstan-type VdotEstimate array{vdot: float, quality_vdot: float, source_activity_id?: int|null, source_value_sec?: float|null, source_category: string, set_at: Carbon, stale: bool, quality_source: array{source_category: string, set_at: Carbon, source_activity_id?: int|null, source_value_sec?: float|null, evidence_kind?: string, distance_m?: int}|null, confidence: string, evidence_id: int|null, evidence_kind?: string|null, distance_m?: int|null, corroborating_quality_count: int, race_distance_m?: float, longest_source_m?: int|null, k?: float, k_fitted?: bool}
 * @phpstan-import-type TrainingRun from ResolveHardEffortsAction
 * @phpstan-type Effort array{date: Carbon, known_on: Carbon, distance_m: float, time_sec: float, activity_id: int|null, evidence: PerformanceEvidence|null, basis: string}
 * @phpstan-type Projection array{vdot: float, time_sec: float, k: float, k_fitted: bool, sources: non-empty-list<Effort>, dominant: Effort, stale: bool, confirmed_only: bool, floor: TrainingRun|null}
 */
class VdotEstimator
{
    /** @var array<int, array<string, VdotEstimate|null>> */
    private array $estimates = [];

    /** @var array<int, array<string, Projection|null>> */
    private array $projections = [];

    /** @var array<int, Collection<int, PerformanceEvidence>> */
    private array $evidenceByUser = [];

    /** @var array<int, FitnessAnchor|null> */
    private array $anchorsByUser = [];

    /** @var array<int, Collection<int, RaceGoal>> */
    private array $racesByUser = [];

    /** @var array<int, Collection<int, PlannedSession>> */
    private array $qualitySessionsByUser = [];

    public function __construct(
        private readonly ResolveDistanceRecordsAction $distanceRecords,
        private readonly ResolveHardEffortsAction $hardEfforts,
        private readonly ResolveActiveRaceAction $activeRace,
    ) {
    }

    // Coefficients of the VO2 = c + b·v + a·v² relationship (v in m/min),
    // shared with {@see \App\Services\Run\Metrics\TrainingPaceCalculator}, which
    // solves this same quadratic for v given a target VO2.
    public const float VO2_COEFFICIENT_A = 0.000104;

    public const float VO2_COEFFICIENT_B = 0.182258;

    public const float VO2_COEFFICIENT_C = -4.60;

    public const int LEVEL_WEEKS = 16;

    public const int SHAPE_MONTHS = 12;

    public const float DEFAULT_RACE_METERS = 10_000.0;

    public const float RISE_CAP_VDOT_PER_WEEK = 1.0;

    /**
     * A record is a floor on what the athlete could do on its own date, never a
     * ceiling on what they can do now. Quality work therefore reads a second
     * anchor, restricted to recent short-distance evidence.
     */
    public const int QUALITY_MONTHS = 3;

    public const float QUALITY_MAX_METERS = 10_000.0;

    /**
     * A record shorter than this is a few minutes of work, and is often a
     * closing surge inside an easy run rather than an effort. It may still
     * refine the quality anchor, since the minimum keeps whichever evidence is
     * most conservative, but it cannot establish one on its own.
     */
    public const float QUALITY_MIN_METERS = 3_000.0;

    private const int MAX_EVIDENCE_METERS = 42_195;

    private const float CONFIDENCE_CONFLICT_PERCENT = 10.0;

    private const float COMPARABLE_DISTANCE_PERCENT = 10.0;

    private const string TRAINING_RUN_CATEGORY = 'training_run';

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
        unset($this->projections[$user->id]);
        unset($this->evidenceByUser[$user->id]);
        unset($this->anchorsByUser[$user->id]);
        unset($this->racesByUser[$user->id]);
        unset($this->qualitySessionsByUser[$user->id]);
        $this->hardEfforts->forget($user->id);
    }

    public function captureProvisionalAnchor(User $user, ?Carbon $capturedAt = null): void
    {
        if (FitnessAnchor::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        $capturedAt ??= Carbon::now();
        $estimate = $this->estimateAsOf($user, $capturedAt);
        if ($estimate === null) {
            return;
        }

        FitnessAnchor::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'vdot' => $estimate['vdot'],
                'quality_vdot' => $estimate['quality_vdot'],
                'source_activity_id' => $estimate['source_activity_id'] ?? null,
                'source_value_sec' => $estimate['source_value_sec'] ?? 0.0,
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

    /** @return VdotEstimate|null */
    private function estimateAsOf(User $user, Carbon $now): ?array
    {
        $distance = $this->raceDistance($user, $now);
        $projection = $this->projection($user, $now, $distance);
        if ($projection === null) {
            return null;
        }

        $vdot = $this->riseCapped($user, $now, $distance, $projection);
        $floor = $projection['floor'];
        $floorBinds = $floor !== null;

        $dominant = $projection['dominant'];
        $evidence = $dominant['evidence'];
        $source = $floorBinds
            ? [
                'source_activity_id' => $floor['activity_id'],
                'source_value_sec' => $floor['time_sec'],
                'source_category' => self::TRAINING_RUN_CATEGORY,
                'set_at' => $floor['date'],
                'evidence_kind' => null,
                'distance_m' => (int) round($floor['distance_m']),
                'evidence_id' => null,
            ]
            : [
                'source_activity_id' => $dominant['activity_id'],
                'source_value_sec' => $dominant['time_sec'],
                'source_category' => $evidence === null ? $dominant['basis'] : 'confirmed_'.$evidence->kind->value,
                'set_at' => $dominant['date'],
                'evidence_kind' => $evidence?->kind->value,
                'distance_m' => (int) round($dominant['distance_m']),
                'evidence_id' => $evidence?->id,
            ];
        $confirmedOnly = ! $floorBinds && $projection['confirmed_only'];

        $quality = $this->quality($user, $now);
        $qualityVdot = $quality === null ? $vdot : max($vdot, $quality['vdot']);

        return [
            ...$source,
            'vdot' => $vdot,
            'quality_vdot' => $qualityVdot,
            'quality_source' => $quality !== null && $qualityVdot > $vdot ? $quality['source'] : null,
            'stale' => $projection['stale'],
            'confidence' => match (true) {
                $projection['stale'] => 'stale',
                ! $confirmedOnly => 'provisional',
                $this->confirmedConflict($user, $now) => 'conflicting',
                default => 'confirmed',
            },
            'corroborating_quality_count' => $this->corroboratingQualityCount($user, $now),
            'race_distance_m' => $distance,
            'longest_source_m' => (int) round(max(array_column($projection['sources'], 'distance_m'))),
            'k' => $projection['k'],
            'k_fitted' => $projection['k_fitted'],
        ];
    }

    /**
     * The supported VDOT with unconfirmed rises replayed from the anchor's
     * capture: each step lifts it by at most {@see self::RISE_CAP_VDOT_PER_WEEK}
     * per started week of the rise, while confirmed efforts and drops apply at once.
     *
     * @param Projection $projection
     */
    private function riseCapped(User $user, Carbon $now, float $distance, array $projection): float
    {
        $anchor = $this->anchorForUser($user);
        if ($anchor === null || $anchor->captured_at->gt($now->copy()->endOfDay())) {
            return $projection['vdot'];
        }

        $capture = $anchor->captured_at->copy()->startOfDay();
        $today = $now->copy()->startOfDay();
        $eventDates = [];
        foreach ($this->efforts($user, $now) as $effort) {
            if ($effort['known_on']->gt($capture) && $effort['known_on']->lt($today)) {
                $eventDates[$effort['known_on']->toDateString()] = $effort['known_on'];
            }
        }
        foreach (($this->hardEfforts)($user->id)['runs'] as $run) {
            $day = $run['date']->copy()->startOfDay();
            if ($run['distance_m'] >= $distance && $day->gt($capture) && $day->lt($today)) {
                $eventDates[$day->toDateString()] = $day;
            }
        }
        ksort($eventDates);

        $capped = null;
        $riseStart = null;
        $riseBase = 0.0;
        $steps = [$capture, ...array_values($eventDates), $today];
        foreach ($steps as $step) {
            $target = $step->equalTo($today) ? $projection : $this->projection($user, $step, $distance);
            if ($target === null) {
                continue;
            }
            if ($capped === null || $target['vdot'] <= $capped || $target['confirmed_only']) {
                $capped = $target['vdot'];
                $riseStart = null;

                continue;
            }
            if ($riseStart === null) {
                $riseStart = $step;
                $riseBase = $capped;
            }
            $weeks = intdiv((int) $riseStart->diffInDays($step), 7);
            $capped = min($target['vdot'], round($riseBase + self::RISE_CAP_VDOT_PER_WEEK * ($weeks + 1), 1));
            if ($capped >= $target['vdot']) {
                $riseStart = null;
            }
        }

        return $capped ?? $projection['vdot'];
    }

    /** @return Projection|null */
    private function projection(User $user, Carbon $now, float $distance): ?array
    {
        $key = $now->toDateString().'@'.$distance;
        if (array_key_exists($key, $this->projections[$user->id] ?? [])) {
            return $this->projections[$user->id][$key];
        }

        $projection = $this->project($this->efforts($user, $now), $now, $distance);
        $floor = $projection === null ? null : $this->trainingFloor($user, $now, $distance, $projection['k']);
        if ($projection !== null && $floor !== null && $floor['vdot'] > $projection['vdot']) {
            $projection = [...$projection, 'vdot' => $floor['vdot'], 'floor' => $floor['run'], 'confirmed_only' => false];
        }

        return $this->projections[$user->id][$key] = $projection;
    }

    /**
     * @param list<Effort> $efforts newest first
     * @return Projection|null
     */
    private function project(array $efforts, Carbon $now, float $distance): ?array
    {
        if ($efforts === []) {
            return null;
        }

        $levelFrom = $now->copy()->startOfDay()->subWeeks(self::LEVEL_WEEKS);
        $level = array_values(array_filter($efforts, static fn (array $effort): bool => $effort['date']->gte($levelFrom)));
        $stale = $level === [];
        $pool = $level === [] ? [$efforts[0]] : $this->newestPerBand($level);

        $shapeFrom = $now->copy()->startOfDay()->subMonths(self::SHAPE_MONTHS);
        $fittedK = FallOffExponent::fit(
            array_values(array_filter($efforts, static fn (array $effort): bool => $effort['date']->gte($shapeFrom))),
        );
        $k = $fittedK ?? FallOffExponent::default($distance);

        $weighted = $this->select($pool, $distance);
        $logTime = 0.0;
        foreach ($weighted as $source) {
            $logTime += $source['weight'] * log($this->projectedTime($source['effort'], $distance, $k));
        }
        $time = exp($logTime);
        $vdot = $this->vdotFromTimeAndDistance($time, $distance);
        if ($vdot === null) {
            return null;
        }
        $sources = array_map(static fn (array $source): array => $source['effort'], $weighted);

        return [
            'vdot' => round($vdot, 1),
            'time_sec' => $time,
            'k' => $k,
            'k_fitted' => $fittedK !== null,
            'sources' => $sources,
            'dominant' => $weighted[0]['effort'],
            'stale' => $stale,
            'confirmed_only' => ! array_any($sources, static fn (array $effort): bool => $effort['evidence'] === null),
            'floor' => null,
        ];
    }

    /**
     * The two efforts bracketing the race distance, weighted by how close each
     * sits to it in log distance, or the single closest effort; the heavier first.
     *
     * @param non-empty-list<Effort> $pool
     * @return non-empty-list<array{effort: Effort, weight: float}>
     */
    private function select(array $pool, float $distance): array
    {
        $below = null;
        $above = null;
        $closest = $pool[0];
        foreach ($pool as $effort) {
            if (abs(log($effort['distance_m'] / $distance)) < abs(log($closest['distance_m'] / $distance))) {
                $closest = $effort;
            }
            if ($effort['distance_m'] < $distance && ($below === null || $effort['distance_m'] > $below['distance_m'])) {
                $below = $effort;
            }
            if ($effort['distance_m'] > $distance && ($above === null || $effort['distance_m'] < $above['distance_m'])) {
                $above = $effort;
            }
        }

        if ($below === null || $above === null || $closest['distance_m'] === $distance) {
            return [['effort' => $closest, 'weight' => 1.0]];
        }

        $belowGap = log($distance / $below['distance_m']);
        $aboveGap = log($above['distance_m'] / $distance);
        $belowWeight = $aboveGap / ($belowGap + $aboveGap);
        $pair = [['effort' => $below, 'weight' => $belowWeight], ['effort' => $above, 'weight' => 1 - $belowWeight]];

        return $belowWeight >= 0.5 ? $pair : array_reverse($pair);
    }

    /** @param Effort $effort */
    private function projectedTime(array $effort, float $distance, float $k): float
    {
        $powerLaw = $effort['time_sec'] * ($distance / $effort['distance_m']) ** $k;
        $vdot = $this->vdotFromTimeAndDistance($effort['time_sec'], $effort['distance_m']);
        $equivalent = $vdot === null ? null : $this->raceTimeForVdot($vdot, $distance);

        return max($powerLaw, $equivalent ?? $powerLaw);
    }

    /**
     * The fastest recent run of at least the race distance, scaled to it: a
     * floor under the supported VDOT, never a source that sets it on its own.
     *
     * @return array{vdot: float, run: TrainingRun}|null
     */
    private function trainingFloor(User $user, Carbon $now, float $distance, float $k): ?array
    {
        $from = $now->copy()->startOfDay()->subWeeks(self::LEVEL_WEEKS);
        $until = $now->copy()->endOfDay();
        $confirmed = $this->evidenceForUser($user)->pluck('activity_id')->filter()->flip()->all();
        $best = null;
        $bestTime = null;
        foreach (($this->hardEfforts)($user->id)['runs'] as $run) {
            if ($run['distance_m'] < $distance || isset($confirmed[$run['activity_id']]) || ! $run['date']->betweenIncluded($from, $until)) {
                continue;
            }
            $time = $run['time_sec'] * ($distance / $run['distance_m']) ** $k;
            if ($bestTime === null || $time < $bestTime) {
                $bestTime = $time;
                $best = $run;
            }
        }

        $vdot = $bestTime === null ? null : $this->vdotFromTimeAndDistance($bestTime, $distance);

        return $vdot === null || $best === null ? null : ['vdot' => round($vdot, 1), 'run' => $best];
    }

    /**
     * Confirmed evidence and unconfirmed hard efforts known by the given day, newest first.
     *
     * @return list<Effort>
     */
    private function efforts(User $user, Carbon $now): array
    {
        $endOfDay = $now->copy()->endOfDay();
        $efforts = [];
        $confirmedActivities = [];
        foreach ($this->evidenceForUser($user) as $row) {
            if ($row->performed_on->gt($endOfDay) || $row->confirmed_at->gt($endOfDay)
                || $row->distance_m < ResolveHardEffortsAction::MIN_METERS || $row->distance_m > self::MAX_EVIDENCE_METERS) {
                continue;
            }
            if ($row->activity_id !== null) {
                $confirmedActivities[$row->activity_id] = true;
            }
            $efforts[] = [
                'date' => $row->performed_on,
                'known_on' => $row->confirmed_at->copy()->startOfDay()->max($row->performed_on),
                'distance_m' => (float) $row->distance_m,
                'time_sec' => (float) $row->elapsed_time_sec,
                'activity_id' => $row->activity_id,
                'evidence' => $row,
                'basis' => 'confirmed_'.$row->kind->value,
            ];
        }

        foreach (($this->hardEfforts)($user->id)['efforts'] as $effort) {
            if ($effort['date']->gt($endOfDay) || isset($confirmedActivities[$effort['activity_id']])) {
                continue;
            }
            $efforts[] = [
                'date' => $effort['date'],
                'known_on' => $effort['date']->copy()->startOfDay(),
                'distance_m' => $effort['distance_m'],
                'time_sec' => $effort['time_sec'],
                'activity_id' => $effort['activity_id'],
                'evidence' => null,
                'basis' => $effort['basis'],
            ];
        }

        usort($efforts, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $efforts;
    }

    /**
     * @param list<Effort> $efforts newest first
     * @return ($efforts is non-empty-list ? non-empty-list<Effort> : list<Effort>)
     */
    private function newestPerBand(array $efforts): array
    {
        $kept = [];
        foreach ($efforts as $effort) {
            if (! array_any($kept, fn (array $other): bool => $this->comparable($effort['distance_m'], $other['distance_m']))) {
                $kept[] = $effort;
            }
        }

        return $kept;
    }

    private function comparable(float $a, float $b): bool
    {
        return abs($a - $b) / min($a, $b) <= self::COMPARABLE_DISTANCE_PERCENT / 100;
    }

    private function raceDistance(User $user, Carbon $now): float
    {
        $endOfDay = $now->copy()->endOfDay();
        $race = $now->gte(Carbon::today())
            ? ($this->activeRace)($user->id)
            : $this->racesForUser($user)->first(
                static fn (RaceGoal $race): bool => $race->created_at !== null && $race->created_at->lte($endOfDay)
                    && ($race->completed_at === null || $race->completed_at->gt($endOfDay)),
            );

        return $race !== null && RaceSupport::forDistance((float) $race->distance_m)->dedicatedPreparation()
            ? (float) $race->distance_m
            : self::DEFAULT_RACE_METERS;
    }

    private function confirmedConflict(User $user, Carbon $now): bool
    {
        $levelFrom = $now->copy()->startOfDay()->subWeeks(self::LEVEL_WEEKS);
        $confirmed = array_values(array_filter(
            $this->efforts($user, $now),
            static fn (array $effort): bool => $effort['evidence'] !== null && $effort['date']->gte($levelFrom),
        ));
        $vdots = array_filter(array_map(
            fn (array $effort): ?float => $this->vdotFromTimeAndDistance($effort['time_sec'], $effort['distance_m']),
            $this->newestPerBand($confirmed),
        ));

        return count($vdots) > 1 && (max($vdots) - min($vdots)) / min($vdots) >= self::CONFIDENCE_CONFLICT_PERCENT / 100;
    }

    /**
     * The recent short-distance anchor quality work reads: confirmed evidence
     * when the athlete has any, otherwise their distance records.
     *
     * @return array{vdot: float, source: array{source_category: string, set_at: Carbon, source_activity_id?: int|null, source_value_sec?: float|null, evidence_kind?: string, distance_m?: int}}|null
     */
    private function quality(User $user, Carbon $now): ?array
    {
        $confirmed = $this->evidenceForUser($user)->filter(
            static fn (PerformanceEvidence $row): bool => $row->performed_on->lessThanOrEqualTo($now->toDateString())
                && $row->confirmed_at->lessThanOrEqualTo($now->copy()->endOfDay())
                && $row->distance_m >= 1_000
                && $row->distance_m <= self::MAX_EVIDENCE_METERS,
        );
        if ($confirmed->contains(static fn (PerformanceEvidence $row): bool => $row->distance_m >= self::QUALITY_MIN_METERS)) {
            return $this->confirmedQuality($confirmed, $now);
        }

        return $this->recordQuality($user, $now);
    }

    /**
     * @param Collection<int, PerformanceEvidence> $evidence
     * @return array{vdot: float, source: array{source_category: string, set_at: Carbon, evidence_kind: string, distance_m: int}}|null
     */
    private function confirmedQuality(Collection $evidence, Carbon $now): ?array
    {
        $cutoff = $now->copy()->subMonths(self::QUALITY_MONTHS);
        $recent = [];
        foreach ($evidence as $row) {
            if ($row->performed_on->lt($cutoff) || $row->distance_m > self::QUALITY_MAX_METERS) {
                continue;
            }
            $vdot = $this->vdotFromTimeAndDistance($row->elapsed_time_sec, $row->distance_m);
            if ($vdot !== null && ! array_any($recent, fn (array $kept): bool => $this->comparable($row->distance_m, $kept['row']->distance_m))) {
                $recent[] = ['vdot' => round($vdot, 1), 'row' => $row];
            }
        }
        if (! array_any($recent, static fn (array $kept): bool => $kept['row']->distance_m >= self::QUALITY_MIN_METERS)) {
            return null;
        }

        usort($recent, static fn (array $a, array $b): int => $a['vdot'] <=> $b['vdot']);
        $lowest = $recent[0]['row'];

        return [
            'vdot' => $recent[0]['vdot'],
            'source' => [
                'source_category' => 'confirmed_'.$lowest->kind->value,
                'set_at' => $lowest->performed_on,
                'evidence_kind' => $lowest->kind->value,
                'distance_m' => $lowest->distance_m,
            ],
        ];
    }

    /** @return array{vdot: float, source: array{source_category: string, set_at: Carbon, source_activity_id: int|null, source_value_sec: float}}|null */
    private function recordQuality(User $user, Carbon $now): ?array
    {
        $cutoff = $now->copy()->subMonths(self::QUALITY_MONTHS);
        $until = $now->copy()->endOfDay();
        $recent = ($this->distanceRecords)($user->id)->filter(
            static fn (PersonalRecord $pr): bool => $pr->set_at->betweenIncluded($cutoff, $until)
                && $pr->category->distanceMeters() !== null
                && $pr->category->distanceMeters() <= self::QUALITY_MAX_METERS,
        );
        if (! $recent->contains(static fn (PersonalRecord $pr): bool => ($pr->category->distanceMeters() ?? 0) >= self::QUALITY_MIN_METERS)) {
            return null;
        }

        $lowest = $this->lowestVdot($recent);

        return $lowest === null ? null : [
            'vdot' => $lowest['vdot'],
            'source' => [
                'source_category' => $lowest['source_category'],
                'set_at' => $lowest['set_at'],
                'source_activity_id' => $lowest['source_activity_id'],
                'source_value_sec' => $lowest['source_value_sec'],
            ],
        ];
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

    /** @return Collection<int, RaceGoal> */
    private function racesForUser(User $user): Collection
    {
        return $this->racesByUser[$user->id] ??= RaceGoal::query()->where('user_id', $user->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'user_id', 'distance_m', 'created_at', 'completed_at']);
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
            if ($vdot !== null && ($bestVdot === null || $vdot < $bestVdot)) {
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

        return $vo2 > 0 ? $vo2 / self::sustainableVo2Fraction($timeMin) : null;
    }

    /**
     * The share of VDOT an all-out effort of this many minutes runs at. It is
     * always above 0.8 (both exponential terms are positive), so dividing by it is safe.
     */
    public static function sustainableVo2Fraction(float $minutes): float
    {
        return 0.80
            + 0.1894393 * exp(-0.012778 * $minutes)
            + 0.2989558 * exp(-0.1932605 * $minutes);
    }

    public function raceTimeForVdot(float $vdot, float $distanceMeters): ?float
    {
        if ($vdot <= 0 || $distanceMeters <= 0) {
            return null;
        }

        $fastest = 60.0;
        $slowest = 604_800.0;
        for ($i = 0; $i < 60; $i++) {
            $middle = ($fastest + $slowest) / 2;
            $middleVdot = $this->vdotFromTimeAndDistance($middle, $distanceMeters);
            if ($middleVdot !== null && $middleVdot > $vdot) {
                $fastest = $middle;
            } else {
                $slowest = $middle;
            }
        }

        return ($fastest + $slowest) / 2;
    }
}
