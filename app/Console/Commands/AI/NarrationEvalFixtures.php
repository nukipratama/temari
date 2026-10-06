<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use Closure;
use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Enums\IngestState;
use App\Enums\IntentVerdict;
use App\Enums\PlannedSessionStatus;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanDayTool;
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Services\AI\Narrators\PlanDayVoiceNarrator;
use App\Services\AI\Narrators\ProfileVoiceNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\SessionMatcher;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RuntimeException;

final readonly class NarrationEvalFixtures
{
    public const array KINDS = ['plan_day_voice', 'briefing_mascot_voice', 'run_insight', 'profile_voice'];

    public const string RETIRED_LOAD_NAMES = '\b(?:ATL|CTL|TSB|acute|chronic)\b';

    private const string EASY_AS_ASKED = 'stayed easy|stayed at the easy|kept (?:it )?easy|properly easy|did the job|as asked';

    private const string PRAISE = 'nice work|well done|great (?:job|work|run)|good call|smart';

    private const int FIRST_PLAN_DAY_OFFSET = 400;

    private const float MIN_BASELINE_GAP = 0.03;

    private const int CANDIDATE_RUNS = 40;

    public function __construct(
        private PlanDayVoiceNarrator $planDay,
        private BriefingMascotVoiceNarrator $briefing,
        private RunInsightNarrator $runInsight,
        private ProfileVoiceNarrator $profile,
        private SessionMatcher $sessionMatcher,
        private TrainingBaseline $trainingBaseline,
        private VdotEstimator $vdotEstimator,
        private TrainingPaceCalculator $paceCalculator,
        private ResolveRunBaselineAction $runBaseline,
    ) {
    }

    /**
     * @param  list<string>  $kinds  Empty means every kind.
     * @return list<NarrationEvalFixture>
     */
    public function for(User $demo, array $kinds): array
    {
        $fixtures = [
            ...$this->planDayFixtures($demo),
            ...$this->briefingFixtures($demo),
            ...$this->runInsightFixtures($demo),
            ...$this->profileFixtures($demo),
        ];

        return $kinds === []
            ? $fixtures
            : array_values(array_filter($fixtures, static fn (NarrationEvalFixture $fixture): bool => in_array($fixture->kind, $kinds, true)));
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{required: list<string>, forbidden: list<string>}
     */
    public static function loadDirection(array $evidence): array
    {
        $forbidden = [self::RETIRED_LOAD_NAMES];
        $balance = $evidence['get_training_load']['training_load']['load_balance'] ?? null;

        if ($balance === 'heavy') {
            array_push($forbidden, '\bfresh\b', '\brested\b');
        }
        if ($balance === 'fresh') {
            array_push($forbidden, '\bheavy\b', '\boverloaded\b');
        }

        return ['required' => [], 'forbidden' => $forbidden];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function evidenceOf(array $context, AgentToolbox $toolbox): array
    {
        $evidence = ['context' => $context];
        foreach ($toolbox->definitions() as $definition) {
            $evidence[$definition['name']] = json_decode($toolbox->invoke($definition['name'], '{}'), true) ?? [];
        }

        return $evidence;
    }

    /**
     * @param  array{required: list<string>, forbidden: list<string>}  $first
     * @param  array{required: list<string>, forbidden: list<string>}  $second
     * @return array{required: list<string>, forbidden: list<string>}
     */
    private static function merge(array $first, array $second): array
    {
        return [
            'required' => [...$first['required'], ...$second['required']],
            'forbidden' => [...$first['forbidden'], ...$second['forbidden']],
        ];
    }

    /** @return list<NarrationEvalFixture> */
    private function planDayFixtures(User $demo): array
    {
        $steady = static fn (int $paceSec, array $extra = []): array => ['pace_sec' => $paceSec, 'ceiling_pace_sec' => 480, ...$extra];
        $easedTempo = static fn (array $extra = []): array => [
            'eased_from' => 'tempo',
            'original_completed' => true,
            'stimulus_family' => 'tempo',
            'stimulus_minutes' => 18,
            'stimulus_source' => 'pace',
            'effective_type' => 'easy',
            ...$extra,
        ];
        $eased = ['session_type' => SessionType::Tempo, 'clamped_km' => 5.0];

        $days = [
            'hit_easy' => [
                [SessionType::Easy, PlannedSessionStatus::Done, IntentVerdict::Hit, $steady(450), []], 8.0, 450,
                ['required' => ['easy|held|stayed|kept'], 'forbidden' => ['too hard|harder than|quicker than|missed|never showed|over the (?:cap|limit)']],
            ],
            'too_hard_easy' => [
                [SessionType::Easy, PlannedSessionStatus::Overreached, IntentVerdict::TooHard, $steady(405), []], 8.0, 405,
                ['required' => ['harder|quicker|faster|too hard|past|over|above|hot'], 'forbidden' => [self::EASY_AS_ASKED]],
            ],
            'capped_day' => [
                [SessionType::Tempo, PlannedSessionStatus::Done, IntentVerdict::Hit, $steady(460, ['effective_type' => 'easy']), $eased], 5.0, 460,
                ['required' => [], 'forbidden' => ['too hard|harder than|missed|never showed']],
            ],
            'eased_original' => [
                [SessionType::Tempo, PlannedSessionStatus::Overreached, IntentVerdict::TooHard, $easedTempo(), $eased], 8.0, 300,
                ['required' => ['exceed|beyond|past|against|ignor|despite|anyway|advice|eased|recovery'], 'forbidden' => [self::EASY_AS_ASKED, self::PRAISE]],
            ],
            'strong_concern' => [
                [SessionType::Tempo, PlannedSessionStatus::Overreached, IntentVerdict::TooHard, $easedTempo(['concern' => 'strong']), $eased], 8.0, 300,
                ['required' => ['advice|rest|against|despite|pain|illness|fatigue'], 'forbidden' => [self::EASY_AS_ASKED, self::PRAISE]],
            ],
            'excessive' => [
                [SessionType::Tempo, PlannedSessionStatus::Overreached, IntentVerdict::TooHard, $easedTempo(['original_completed' => 'excessive']), $eased], 8.0, 270,
                ['required' => ['past|well|beyond|exceed|over'], 'forbidden' => [self::EASY_AS_ASKED, self::PRAISE]],
            ],
            'unplanned_hard' => [
                [SessionType::Easy, PlannedSessionStatus::Overreached, IntentVerdict::TooHard, $steady(380, ['stimulus_family' => 'hard', 'stimulus_minutes' => 12, 'stimulus_source' => 'pace']), []], 8.0, 380,
                ['required' => ['harder|hard effort|unplanned|quicker|faster'], 'forbidden' => [self::EASY_AS_ASKED]],
            ],
        ];

        $fixtures = [];
        $slot = 0;
        foreach ($days as $name => [$session, $km, $paceSec, $direction]) {
            $date = Carbon::today()->subDays(self::FIRST_PLAN_DAY_OFFSET + $slot++);
            $fixtures[] = new NarrationEvalFixture('plan_day_voice', $name, function () use ($demo, $date, $session, $km, $paceSec, $direction): array {
                $planned = $this->plannedSession($demo, $date, $session, $km);
                $this->logRun($demo, $date->copy()->setTime(7, 0), $km, $paceSec);

                $tool = new PlanDayTool(
                    $planned,
                    $this->trainingBaseline,
                    $this->vdotEstimator,
                    $this->paceCalculator,
                    $this->sessionMatcher->creditedKmFor($planned),
                    $this->sessionMatcher->ranPaceSecPerKmFor($planned),
                );

                return [
                    'generate' => fn (): string => $this->planDay->generate($planned),
                    'evidence' => ['get_day_plan' => $tool->handle([])],
                    'direction' => $direction,
                    'plain_text' => true,
                ];
            });
        }

        return $fixtures;
    }

    /** @return list<NarrationEvalFixture> */
    private function briefingFixtures(User $demo): array
    {
        $today = [
            'hit_easy_today' => [
                [SessionType::Easy, PlannedSessionStatus::Done, IntentVerdict::Hit, ['pace_sec' => 450, 'ceiling_pace_sec' => 480], []], 450,
                ['required' => [], 'forbidden' => ['too hard|harder than the easy|ran too fast|over the (?:cap|limit)|missed']],
            ],
            'too_hard_easy_today' => [
                [SessionType::Easy, PlannedSessionStatus::Overreached, IntentVerdict::TooHard, ['pace_sec' => 405, 'ceiling_pace_sec' => 480], []], 405,
                ['required' => ['harder|quicker|faster|past|over|hot|above'], 'forbidden' => [self::EASY_AS_ASKED]],
            ],
        ];

        $fixtures = [];
        foreach ($today as $name => [$session, $paceSec, $direction]) {
            $fixtures[] = new NarrationEvalFixture('briefing_mascot_voice', $name, function () use ($demo, $session, $paceSec, $direction): array {
                $day = Carbon::today();
                $this->plannedSession($demo, $day, $session, 8.0);
                $this->logRun($demo, $day->copy()->setTime(6, 0), 8.0, $paceSec);

                $evidence = self::evidenceOf($this->briefing->context($demo, $day), $this->briefing->toolbox($demo, $day));

                return [
                    'generate' => fn (): string => $this->briefing->generate($demo, $day),
                    'evidence' => $evidence,
                    'direction' => self::merge($direction, self::loadDirection($evidence)),
                    'plain_text' => false,
                ];
            });
        }

        return $fixtures;
    }

    /** @return list<NarrationEvalFixture> */
    private function runInsightFixtures(User $demo): array
    {
        $heartRate = '\b(?:heart rate|HR|bpm|zones?|Z[1-5]|decoupling|drift|cardiac)\b';

        return [
            new NarrationEvalFixture('run_insight', 'faster_than_past_you', fn (): ?array => $this->againstBaseline(
                $demo,
                fastest: true,
                direction: [
                    'required' => ['faster|quicker|ahead|under|below|beat|sharper'],
                    'forbidden' => ['slower than (?:your|the|usual|normal)|behind (?:your|the)|off (?:your|the|usual)'],
                ],
            )),
            new NarrationEvalFixture('run_insight', 'slower_than_past_you', fn (): ?array => $this->againstBaseline(
                $demo,
                fastest: false,
                direction: [
                    'required' => ['slower|behind|off|back|easier|dragg|down on'],
                    'forbidden' => ['(?:faster|quicker) than (?:your|the|usual|normal)|ahead of (?:your|the)'],
                ],
            )),
            new NarrationEvalFixture('run_insight', 'no_heart_rate', function () use ($demo, $heartRate): ?array {
                $detail = $this->latestRuns($demo)->first();
                if ($detail === null) {
                    return null;
                }

                $this->withoutHeartRate($detail);

                return $this->runInsightCase($detail->activity, $detail, ['required' => [], 'forbidden' => [$heartRate]]);
            }),
        ];
    }

    /** @return list<NarrationEvalFixture> */
    private function profileFixtures(User $demo): array
    {
        return [
            new NarrationEvalFixture('profile_voice', 'progression_direction', function () use ($demo): array {
                $evidence = self::evidenceOf($this->profile->context($demo), $this->profile->toolbox($demo));
                $relation = $evidence['get_progression_signal']['progression_signal']['relation'] ?? null;
                $direction = match ($relation) {
                    'faster' => ['required' => [], 'forbidden' => ['\b(?:slower|slipp\w*|regress\w*|declin\w*|worse|backwards)\b']],
                    'slower' => ['required' => [], 'forbidden' => ['\b(?:improv\w*|faster|quicker|gain\w*)\b']],
                    default => ['required' => [], 'forbidden' => []],
                };

                return [
                    'generate' => fn (): string => $this->profile->generate($demo),
                    'evidence' => $evidence,
                    'direction' => self::merge($direction, self::loadDirection($evidence)),
                    'plain_text' => false,
                ];
            }),
        ];
    }

    /**
     * @param  array{required: list<string>, forbidden: list<string>}  $direction
     * @return ?array{generate: Closure():string, evidence: array<string, mixed>, direction: array{required: list<string>, forbidden: list<string>}, plain_text: bool}
     */
    private function againstBaseline(User $demo, bool $fastest, array $direction): ?array
    {
        $candidates = $this->candidateRuns($demo);
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $fastest ? $a['ratio'] <=> $b['ratio'] : $b['ratio'] <=> $a['ratio']);
        $pick = $candidates[0];
        $offBaseline = $fastest ? $pick['ratio'] <= 1 - self::MIN_BASELINE_GAP : $pick['ratio'] >= 1 + self::MIN_BASELINE_GAP;

        return $offBaseline ? $this->runInsightCase($pick['detail']->activity, $pick['detail'], $direction) : null;
    }

    /**
     * @param  array{required: list<string>, forbidden: list<string>}  $direction
     * @return array{generate: Closure():string, evidence: array<string, mixed>, direction: array{required: list<string>, forbidden: list<string>}, plain_text: bool}
     */
    private function runInsightCase(Activity $activity, ActivityDetail $detail, array $direction): array
    {
        $evidence = self::evidenceOf($this->runInsight->context($activity, $detail), $this->runInsight->toolbox($activity, $detail));

        return [
            'generate' => function () use ($activity, $detail): string {
                $claims = $this->runInsight->generate($activity, $detail)['claims'];
                if ($claims === []) {
                    throw new RuntimeException('no claim survived the anchor check');
                }

                return implode(' ', array_map(
                    static fn (array $claim): string => trim("{$claim['text']} {$claim['value']} {$claim['delta']}"),
                    $claims,
                ));
            },
            'evidence' => $evidence,
            'direction' => self::merge($direction, self::loadDirection($evidence)),
            'plain_text' => false,
        ];
    }

    /**
     * The athlete's latest detailed runs with the pace of each against its own 28-day baseline.
     *
     * @return list<array{detail: ActivityDetail, ratio: float}>
     */
    private function candidateRuns(User $demo): array
    {
        $candidates = [];
        foreach ($this->latestRuns($demo) as $detail) {
            $startedAt = $detail->start_date_local;
            $pace = PaceCalculator::secPerKm($detail->distance, $detail->elapsed_time);
            $baseline = $startedAt === null ? null : ($this->runBaseline)($demo->id, $startedAt, $detail->activity_id)['avg_pace_sec_per_km'] ?? null;
            if ($baseline === null || $pace === null) {
                continue;
            }
            $candidates[] = ['detail' => $detail, 'ratio' => $pace / $baseline];
        }

        return $candidates;
    }

    /** @return Collection<int, ActivityDetail> */
    private function latestRuns(User $demo): Collection
    {
        return ActivityDetail::query()
            ->forUser($demo->id)
            ->with('activity')
            ->whereNotNull('start_date_local')
            ->orderByDesc('start_date_local')
            ->limit(self::CANDIDATE_RUNS)
            ->get();
    }

    private function withoutHeartRate(ActivityDetail $detail): void
    {
        $detail->update([
            'has_heartrate' => false,
            'average_heartrate' => null,
            'max_heartrate' => null,
            'trimp_edwards' => null,
            'stream_summary' => self::withoutHeartRateKeys($detail->streamSummary()),
        ]);

        $stream = $detail->activity->stream;
        if ($stream !== null) {
            $stream->update(['data' => array_diff_key($stream->data ?? [], ['heartrate' => true])]);
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private static function withoutHeartRateKeys(array $summary): array
    {
        $kept = [];
        foreach ($summary as $key => $value) {
            if (preg_match('/(?:^|_)(?:hr|heartrate|heart)(?:_|$)|zone|decoupling|easy_cap/i', (string) $key) === 1) {
                continue;
            }
            $kept[$key] = is_array($value) ? self::withoutHeartRateKeys($value) : $value;
        }

        return $kept;
    }

    /**
     * @param  array{0: SessionType, 1: PlannedSessionStatus, 2: IntentVerdict, 3: array<string, mixed>, 4?: array<string, mixed>}  $session
     */
    private function plannedSession(User $demo, Carbon $date, array $session, float $km): PlannedSession
    {
        [$type, $status, $verdict, $evidence] = $session;

        return PlannedSession::query()->updateOrCreate(
            ['user_id' => $demo->id, 'date' => $date->toDateString()],
            [
                'phase' => PlanPhase::Base,
                'session_type' => $type,
                'pinned' => false,
                'skipped' => false,
                'status' => $status,
                'ran_anyway' => false,
                'prescribed_km' => $km,
                'intent_verdict' => $verdict,
                'intent_evidence' => $evidence,
                ...($session[4] ?? []),
            ],
        );
    }

    private function logRun(User $demo, Carbon $startsAt, float $km, int $paceSec): void
    {
        $seconds = (int) round($km * $paceSec);

        $activity = Activity::query()->create([
            'user_id' => $demo->id,
            'strava_external_id' => random_int(8_000_000_000, 8_999_999_999),
            'ingest_state' => IngestState::Detailed,
            'fetched_at' => $startsAt,
            'analyzed_at' => $startsAt,
            'detail_fail_count' => 0,
        ]);

        ActivityDetail::query()->create([
            'activity_id' => $activity->id,
            'name' => 'Eval run',
            'start_date_local' => $startsAt,
            'distance' => $km * 1000,
            'moving_time' => $seconds,
            'elapsed_time' => $seconds,
            'average_speed' => $km * 1000 / $seconds,
            'total_elevation_gain' => 0,
            'has_heartrate' => true,
            'average_heartrate' => 140,
            'max_heartrate' => 158,
        ]);
    }
}
