<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Geo\PolylineEncoder;
use App\Services\Run\Ingest\StreamAnalysis;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Story\RunCardFactory;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\Vibe;
use Closure;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function count;
use function is_array;

class DemoRunSeeder
{
    public const string DEMO_USER_EMAIL = 'demo@teman-lari.local';

    // Stagger between dispatched LLM jobs so Horizon doesn't slam Azure all at
    // once. Demo dispatches a few hundred jobs; 30s lets workers drip through.
    private const int DISPATCH_STAGGER_SECONDS = 30;

    // Senayan / SCBD, Jakarta — must agree with DEMO_LOCATION_NAME below.
    private const float DEMO_START_LAT = -6.2253;

    private const float DEMO_START_LNG = 106.8090;

    // Pre-resolved so seed skips the Nominatim call and the chip renders immediately.
    private const string DEMO_LOCATION_NAME = 'Senayan, Jakarta Pusat, DKI Jakarta, Indonesia';

    private const string DEMO_LOCATION_COUNTRY = 'ID';

    public function __construct(
        private readonly BlueprintLibrary $library,
        private readonly StreamSynthesizer $synthesizer,
        private readonly SplitsBuilder $splitsBuilder,
        private readonly StreamAnalysis $streamAnalysis,
        private readonly TrainingLoad $trainingLoad,
        private readonly PersonalRecords $personalRecords,
        private readonly RunCardFactory $cardFactory,
        private readonly Temari $temari,
        private readonly Vibe $vibe,
        private readonly WeeklyAggregator $weeklyAggregator,
        private readonly AnalysisService $analysisService,
        private readonly PolylineEncoder $polylineEncoder = new PolylineEncoder(),
    ) {
    }

    private function demoPolyline(int $distanceM, int $seed): string
    {
        $rng = new Randomizer(new Mt19937($seed));

        $lat = self::DEMO_START_LAT;
        $lng = self::DEMO_START_LNG;

        $radiusM = max(250, (int) round($distanceM / (2 * M_PI)));
        $latPerM = 1.0 / 111_320.0;
        $lngPerM = 1.0 / (111_320.0 * cos(deg2rad($lat)));

        $vertices = $rng->getInt(10, 16);
        $rotationRad = $rng->getInt(0, 359) * M_PI / 180;

        $points = [];
        for ($i = 0; $i <= $vertices; $i++) {
            $angle = ($i / $vertices) * 2 * M_PI + $rotationRad;
            $jitter = 0.75 + $rng->getInt(0, 50) / 100;
            $r = $radiusM * $jitter;
            $points[] = [
                $lat + sin($angle) * $r * $latPerM,
                $lng + cos($angle) * $r * $lngPerM,
            ];
        }

        return $this->polylineEncoder->encode($points);
    }

    /**
     * @param  bool  $fresh  truncate prior demo runs/cards/snapshots first
     * @param  Closure(string): void|null  $log  optional reporter (command::info etc.)
     */
    public function seed(bool $fresh = false, ?Closure $log = null): int
    {
        $log ??= static fn (string $_): null => null;

        // Demo seed dispatches hundreds of analyses; skip the queue and mark
        // them failed at the end so users retry via the UI on demand.
        $previousAutoDispatch = (bool) config('ai.auto_dispatch', true);
        config(['ai.auto_dispatch' => false]);

        try {
            if ($fresh) {
                $this->nukeDemoUser($log);
            }
            $user = $this->ensureDemoUser($log);

            $blueprints = $this->library->all();
            usort($blueprints, fn (RunBlueprint $a, RunBlueprint $b): int => $a->startsAt <=> $b->startsAt);

            $log(sprintf('Seeding %d runs for %s...', count($blueprints), $user->email));

            $count = 0;
            foreach ($blueprints as $blueprint) {
                $this->seedOne($user, $blueprint);
                $count++;
                if ($count % 20 === 0) {
                    $log(sprintf('  ...%d/%d runs materialised', $count, count($blueprints)));
                }
            }

            $log('Rebuilding weekly snapshots...');
            $weeks = $this->weeklyAggregator->rebuildFor($user);
            $log(sprintf('  %d weekly snapshots written', $weeks));

            $log("Generating today's Temari greeting...");
            $vibeState = $this->vibe->current($user);
            $this->temari->dailyGreeting($user, $vibeState);
            $log("  Today's vibe: {$vibeState}");

            $dispatched = $this->dispatchPendingAnalyses($user);
            $log(sprintf('  %d AI analyses dispatched to Horizon (narasi akan muncul saat job selesai).', $dispatched));

            return $count;
        } finally {
            config(['ai.auto_dispatch' => $previousAutoDispatch]);
        }
    }

    private function dispatchPendingAnalyses(User $user): int
    {
        $activityIds = Activity::query()->where('user_id', $user->id)->pluck('id')->all();
        $weeklyIds = WeeklySnapshot::query()->where('user_id', $user->id)->pluck('id')->all();
        $prIds = PersonalRecord::query()->where('user_id', $user->id)->pluck('id')->all();
        $cardIds = RunCard::query()->whereIn('activity_id', $activityIds)->pluck('id')->all();

        // 1) Re-dispatch any analysis that was created during seeding but
        // left Pending (e.g. PostRunSpeech via Temari).
        $pending = Analysis::query()
            ->where('status', AnalysisStatus::Pending)
            ->where(function ($q) use ($user, $activityIds, $weeklyIds, $prIds, $cardIds): void {
                $q->where(fn ($qq) => $qq->where('subject_type', Activity::class)->whereIn('subject_id', $activityIds))
                    ->orWhere(fn ($qq) => $qq->where('subject_type', WeeklySnapshot::class)->whereIn('subject_id', $weeklyIds))
                    ->orWhere(fn ($qq) => $qq->where('subject_type', PersonalRecord::class)->whereIn('subject_id', $prIds))
                    ->orWhere(fn ($qq) => $qq->where('subject_type', RunCard::class)->whereIn('subject_id', $cardIds))
                    ->orWhere(fn ($qq) => $qq->whereIn('subject_type', [
                        AnalysisType::BRIEFING_SUBJECT_TYPE,
                        AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
                        AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
                    ])->where('subject_id', $user->id));
            })
            ->get();

        $count = 0;

        foreach ($pending as $row) {
            $this->analysisService->request(
                subjectOrType: $row->subject_type,
                subjectId: $row->subject_id,
                type: $row->analysis_type,
                discriminator: $row->discriminator,
                force: true,
                delaySeconds: $count * self::DISPATCH_STAGGER_SECONDS,
            );
            $count++;
        }

        // 2) Pre-warm insights that are normally created lazily on the UI side
        // (RunInsight technical/splits/zones per activity, CardFlavor per card,
        // PrContext per PR). request() is idempotent — existing rows are skipped.
        $perActivityTypes = [
            [Activity::class, AnalysisType::RunInsightTechnical],
            [Activity::class, AnalysisType::RunInsightSplits],
            [Activity::class, AnalysisType::RunInsightZones],
        ];
        foreach ($activityIds as $activityId) {
            foreach ($perActivityTypes as [$subjectType, $type]) {
                $this->analysisService->request(
                    subjectOrType: $subjectType,
                    subjectId: $activityId,
                    type: $type,
                    force: true,
                    delaySeconds: $count * self::DISPATCH_STAGGER_SECONDS,
                );
                $count++;
            }
        }
        foreach ($cardIds as $cardId) {
            $this->analysisService->request(
                subjectOrType: RunCard::class,
                subjectId: $cardId,
                type: AnalysisType::CardFlavor,
                force: true,
                delaySeconds: $count * self::DISPATCH_STAGGER_SECONDS,
            );
            $count++;
        }
        foreach ($prIds as $prId) {
            $this->analysisService->request(
                subjectOrType: PersonalRecord::class,
                subjectId: $prId,
                type: AnalysisType::PrContext,
                force: true,
                delaySeconds: $count * self::DISPATCH_STAGGER_SECONDS,
            );
            $count++;
        }
        // WeeklyAggregator skips the current in-progress week to avoid LLM
        // churn on real ingest. For demo we want the Catatan page populated
        // end-to-end, so cover every snapshot explicitly.
        foreach ($weeklyIds as $weeklyId) {
            $this->analysisService->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: $weeklyId,
                type: AnalysisType::WeeklyRecap,
                force: true,
                delaySeconds: $count * self::DISPATCH_STAGGER_SECONDS,
            );
            $count++;
        }

        return $count;
    }

    private function ensureDemoUser(Closure $log): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::DEMO_USER_EMAIL],
            [
                'name' => 'Demo Runner',
                'avatar_url' => null,
            ],
        );

        StravaConnection::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'strava_athlete_id' => 99_999_999,
                'access_token' => 'demo-access-token',
                'refresh_token' => 'demo-refresh-token',
                'token_expires_at' => Carbon::now()->addYear(),
                'scopes' => 'read,activity:read',
            ],
        );

        $log("Demo user ready: {$user->email} (id={$user->id})");

        return $user;
    }

    private function nukeDemoUser(Closure $log): void
    {
        $user = User::query()->where('email', self::DEMO_USER_EMAIL)->first();
        if ($user === null) {
            return;
        }

        // ai_analyses is polymorphic (no FK), so cascade-on-delete from `users`
        // doesn't catch it — wipe it explicitly before the user goes away.
        $activityIds = Activity::query()->where('user_id', $user->id)->pluck('id');
        $weeklyIds = WeeklySnapshot::query()->where('user_id', $user->id)->pluck('id');
        $prIds = PersonalRecord::query()->where('user_id', $user->id)->pluck('id');
        $cardIds = RunCard::query()->whereIn('activity_id', $activityIds)->pluck('id');

        Analysis::query()
            ->where(function ($q) use ($user, $activityIds, $weeklyIds, $prIds, $cardIds): void {
                $q->where(fn ($qq) => $qq->where('subject_type', Activity::class)->whereIn('subject_id', $activityIds))
                    ->orWhere(fn ($qq) => $qq->where('subject_type', WeeklySnapshot::class)->whereIn('subject_id', $weeklyIds))
                    ->orWhere(fn ($qq) => $qq->where('subject_type', PersonalRecord::class)->whereIn('subject_id', $prIds))
                    ->orWhere(fn ($qq) => $qq->where('subject_type', RunCard::class)->whereIn('subject_id', $cardIds))
                    ->orWhere(fn ($qq) => $qq->whereIn('subject_type', [
                        AnalysisType::BRIEFING_SUBJECT_TYPE,
                        AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
                        AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
                    ])->where('subject_id', $user->id));
            })
            ->delete();

        $user->delete();
        $log("Nuked prior demo user (id={$user->id}) + all related analyses/activities/cards/story lines/PRs/snapshots");
    }

    private function seedOne(User $user, RunBlueprint $blueprint): void
    {
        $streams = $this->synthesizer->build($blueprint);
        $splits = $this->splitsBuilder->build($streams);

        $activity = Activity::query()->create([
            'user_id' => $user->id,
            'strava_external_id' => (int) ('9' . str_pad((string) $blueprint->seed(), 9, '0', STR_PAD_LEFT)),
            'fetched_at' => $blueprint->startsAt->copy()->addHour(),
            'analyzed_at' => $blueprint->startsAt->copy()->addHour(),
            'detail_fail_count' => 0,
        ]);

        $distanceStream = $streams['distance']['data'] ?? [];
        $hrStream = $streams['heartrate']['data'] ?? [];
        $cadenceStream = $streams['cadence']['data'] ?? [];

        $detail = ActivityDetail::query()->create([
            'activity_id' => $activity->id,
            'name' => $blueprint->name ?? 'Run',
            'start_date_local' => $blueprint->startsAt,
            'distance' => $distanceStream === [] ? 0.0 : round((float) end($distanceStream), 1),
            'moving_time' => $blueprint->movingTimeSec(),
            'elapsed_time' => $blueprint->movingTimeSec(),
            'average_speed' => $blueprint->distanceM / max(1, $blueprint->movingTimeSec()),
            'total_elevation_gain' => $blueprint->elevationGainM,
            'has_heartrate' => $blueprint->hasHrSensor,
            'average_heartrate' => $blueprint->hasHrSensor ? StreamStats::mean($hrStream) : null,
            'max_heartrate' => $blueprint->hasHrSensor ? StreamStats::max($hrStream) : null,
            'average_cadence' => $blueprint->hasCadenceSensor ? StreamStats::mean($cadenceStream) : null,
            'calories' => round($blueprint->distanceM / 1000 * 65),
            'splits_metric' => $splits,
            'summary_polyline' => $blueprint->hasGps
                ? $this->demoPolyline($blueprint->distanceM, $blueprint->seed())
                : null,
            'start_lat' => $blueprint->hasGps ? self::DEMO_START_LAT : null,
            'start_lng' => $blueprint->hasGps ? self::DEMO_START_LNG : null,
            'location_name' => $blueprint->hasGps ? self::DEMO_LOCATION_NAME : null,
            'location_country' => $blueprint->hasGps ? self::DEMO_LOCATION_COUNTRY : null,
            'location_resolved_at' => $blueprint->hasGps ? $blueprint->startsAt->copy()->addMinutes(2) : null,
            'weather_temp_c' => $blueprint->weatherTempC,
            'weather_humidity_pct' => $blueprint->weatherHumidityPct,
            'weather_rain_detected' => $blueprint->weatherRainDetected,
        ]);

        ActivityStream::query()->create([
            'activity_id' => $activity->id,
            'data' => $streams,
        ]);

        $this->computeStreamSummary($detail, $streams);
        $detail->refresh();

        $this->personalRecords->detectAndStore($activity, $detail);
        $this->cardFactory->build($activity, $detail);
        $this->temari->postRunLine($activity, $detail);
    }

    /**
     * @param  array<string, array{data: list<int|float|array{float, float}>}>  $streams
     */
    private function computeStreamSummary(ActivityDetail $detail, array $streams): void
    {
        /** @var array<string, array{lo: int, hi: int}> $hrZones */
        $hrZones = config('runner.hr_zones');
        $optimalCadence = (int) config('runner.optimal_cadence_spm');

        $summary = $this->streamAnalysis->compute(
            $streams,
            $hrZones,
            is_array($detail->splits_metric) ? $detail->splits_metric : null,
            $optimalCadence,
        );

        $minutesInZone = $summary['time_in_zone_min'] ?? null;
        $trimp = is_array($minutesInZone) ? $this->trainingLoad->edwardsTrimp($minutesInZone) : null;

        $detail->update([
            'stream_summary' => $summary === [] ? null : $summary,
            'trimp_edwards' => $trimp,
        ]);
    }

}
