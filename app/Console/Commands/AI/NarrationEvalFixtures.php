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
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Services\AI\Narrators\ProfileVoiceNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
use App\Services\Run\Metrics\PaceCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RuntimeException;

final readonly class NarrationEvalFixtures
{
    public const array KINDS = ['briefing_mascot_voice', 'run_insight', 'profile_voice'];

    public const string RETIRED_LOAD_NAMES = '\b(?:ATL|CTL|TSB|acute|chronic)\b';

    private const string EASY_AS_ASKED = 'stayed easy|stayed at the easy|kept (?:it )?easy|properly easy|did the job|as asked';

    private const float MIN_BASELINE_GAP = 0.03;

    private const int CANDIDATE_RUNS = 40;

    public function __construct(
        private BriefingMascotVoiceNarrator $briefing,
        private RunInsightNarrator $runInsight,
        private ProfileVoiceNarrator $profile,
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
                ];
            }),
        ];
    }

    /**
     * @param  array{required: list<string>, forbidden: list<string>}  $direction
     * @return ?array{generate: Closure():string, evidence: array<string, mixed>, direction: array{required: list<string>, forbidden: list<string>}}
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
     * @return array{generate: Closure():string, evidence: array<string, mixed>, direction: array{required: list<string>, forbidden: list<string>}}
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
