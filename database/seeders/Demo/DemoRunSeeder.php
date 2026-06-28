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
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\RecapPeriod;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\Geo\PolylineEncoder;
use App\Services\Run\Ingest\StreamAnalysis;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Story\FeaturedKartuResolver;
use App\Services\Run\Story\RunCardFactory;
use App\Services\Gamification\UnlockEngine;
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
        private readonly RuleBasedNarrationFiller $filler,
        private readonly UnlockEngine $unlockEngine,
        private readonly FeaturedKartuResolver $featuredKartu,
        private readonly PolylineEncoder $polylineEncoder = new PolylineEncoder(),
    ) {
    }

    private function demoPolyline(int $distanceM, int $seed, DemoLocation $location): string
    {
        $rng = new Randomizer(new Mt19937($seed));

        $lat = $location->lat;
        $lng = $location->lng;

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
     * Idempotent: every row is keyed on a deterministic identity (blueprint seed,
     * activity_id, ISO week, …) via updateOrCreate, so re-running converges to the
     * same dataset instead of duplicating or hitting the unique constraint.
     *
     * @param  Closure(string): void|null  $log  optional reporter (command::info etc.)
     */
    public function seed(?Closure $log = null): int
    {
        $log ??= static fn (string $_): null => null;

        $count = 0;

        $this->analysisService->withoutDispatching(function () use ($log, &$count): void {
            $user = $this->ensureDemoUser($log);

            $blueprints = $this->library->all();
            usort($blueprints, fn (RunBlueprint $a, RunBlueprint $b): int => $a->startsAt <=> $b->startsAt);

            $log(sprintf('Seeding %d runs for %s...', count($blueprints), $user->email));

            foreach ($blueprints as $blueprint) {
                $this->seedOne($user, $blueprint);
                $count++;
                if ($count % 20 === 0) {
                    $log(sprintf('  ...%d/%d runs materialised', $count, count($blueprints)));
                }
            }

            // A D-0 run so a fresh seed already looks current (newest run today)
            // rather than the scripted library's ~10-days-stale tail. The
            // demo:daily-refresh scheduler keeps it current from here on.
            $this->seedOne($user, $this->modestBlueprintFor(Carbon::today()));
            $count++;

            $log('Rebuilding weekly snapshots...');
            $weeks = $this->weeklyAggregator->rebuildFor($user);
            $log(sprintf('  %d weekly snapshots written', $weeks));

            // PR-driven unlocks fire incrementally during seedOne, but the
            // card-rarity ones (legendaris/epik) and the weekly-streak one
            // depend on cards + snapshots that only exist after the loop. One
            // final sweep grants everything the dataset now qualifies for.
            $granted = $this->unlockEngine->grantEligible($user);
            $log(sprintf('  %d accessory unlocks granted (%s)', count($granted), $granted === [] ? 'all already unlocked' : implode(', ', $granted)));

            // Equip the best-in-slot accessories (one per slot) so the demo
            // Temari actually shows off its hardware everywhere it appears.
            UserUnlock::query()
                ->where('user_id', $user->id)
                ->whereIn('unlock_key', [
                    'accessory.ikat_kepala_legendaris',
                    'accessory.medal_emas',
                ])
                ->update(['equipped' => true]);

            $log("Generating today's Temari greeting...");
            $vibeState = $this->vibe->current($user);
            $this->temari->dailyGreeting($user, $vibeState);
            $log("  Today's vibe: {$vibeState}");

            // Stage Pending Analysis rows for every surface that uses LLM. No
            // jobs dispatch because the entire seed runs inside
            // withoutDispatching; the rows are flat-filled below with
            // deterministic rule-based content so demo doesn't burn tokens.
            $this->stagePendingAnalyses($user);

            $this->queueBestRevealFor($user);
        });

        $user = User::query()->where('email', self::DEMO_USER_EMAIL)->firstOrFail();
        $filled = $this->backfillWithFiller($user);
        $log(sprintf('  %d AI analyses backfilled with rule-based content (klik "Baca ulang" buat narasi LLM beneran).', $filled));

        return $count;
    }

    /**
     * Daily keep-alive for the demo account, run by the demo:daily-refresh
     * scheduler. Adds one modest synthetic run for today (skipping two rest days
     * a week so the streak looks human) and re-stages + rule-based-fills today's
     * date-keyed narration, so the demo never goes stale or renders an empty
     * "Belum dibaca" when the date rolls. Zero LLM tokens (runs under
     * withoutDispatching + the filler), so the demo-billing exclusion holds.
     *
     * @param  Closure(string): void|null  $log  optional reporter (command::info etc.)
     */
    public function refreshToday(?Closure $log = null): void
    {
        $log ??= static fn (string $_): null => null;

        $this->analysisService->withoutDispatching(function () use ($log): void {
            $user = $this->ensureDemoUser($log);
            $today = Carbon::today();

            // ~5 runs/week: rest on Monday + Thursday so the streak / recovery
            // widgets read like a real training week, not a bot that runs daily.
            if (! in_array($today->dayOfWeekIso, [1, 4], true)) {
                $this->seedOne($user, $this->modestBlueprintFor($today));
                $log("Synthesised today's run for {$user->email}.");
            } else {
                $log('Rest day, refreshing narration only.');
            }

            // CTL is cumulative, so roll the new run forward into every later
            // week's snapshot, then refresh today's greeting + briefing narration.
            $this->weeklyAggregator->rebuildForwardFrom($user, $today);
            $this->unlockEngine->grantEligible($user);
            $this->temari->dailyGreeting($user, $this->vibe->current($user));

            // Re-stage the date-keyed surfaces (briefing set, greeting, trend,
            // weekly persona) against today's discriminator — the line that kills
            // "Belum dibaca" once the calendar day moves past the seed day.
            $this->stagePendingAnalyses($user);

            // Backfill inside withoutDispatching so markDone's Telegram fan-out
            // stays suppressed: the demo never has a real connection, so an
            // enqueued (no-op) notification job every day would just be waste.
            $filled = $this->backfillWithFiller($user);
            $log(sprintf('  %d AI analyses refreshed with rule-based content.', $filled));
        });
    }

    /**
     * A believable easy run for a given day, deterministic per date (so a same-day
     * re-run converges via updateOrCreate). Kept modest on purpose: easy Z2 pace
     * slower than any seeded PR and short enough that RunCardFactory scores it a
     * Common/Uncommon card, so the daily run never beats a record or inflates the
     * curated rarity ladder.
     */
    private function modestBlueprintFor(Carbon $date): RunBlueprint
    {
        $rng = new Randomizer(new Mt19937((int) $date->format('Ymd')));

        $locations = DemoLocation::library();
        $names = ['Lari pagi', 'Easy run', 'Lari santai', 'Jogging pagi', 'Lari ringan'];

        return new RunBlueprint(
            startsAt: $date->copy()->setTime(6, $rng->getInt(0, 45)),
            distanceM: $rng->getInt(35, 70) * 100,
            targetPaceSecPerKm: $rng->getInt(390, 460),
            hrProfile: $rng->getInt(0, 1) === 0 ? HrProfile::Z2Steady : HrProfile::Mixed,
            cadenceSpm: 170,
            elevationGainM: $rng->getInt(10, 60),
            name: $names[$rng->getInt(0, count($names) - 1)],
            tags: ['daily'],
            location: $locations[$rng->getInt(0, count($locations) - 1)],
        );
    }

    /**
     * Point the one-shot reveal modal at the demo user's rarest card instead of
     * whatever run happened to seed first. RunCardFactory::build() queues the
     * first card it creates (the oldest activity, a plain Common easy run), so
     * without this the demo's first login pops an underwhelming reveal. Here we
     * override it to showcase the gimmick on a legendary/epic card. Ties break
     * to the highest card id (most recently seeded).
     */
    private function queueBestRevealFor(User $user): void
    {
        $best = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('special_move')
            ->get()
            ->sortByDesc(fn (RunCard $card): array => [$card->rarity->rank(), $card->id])
            ->first();

        $user->forceFill(['pending_reveal_card_id' => $best?->id])->save();
    }

    private function stagePendingAnalyses(User $user): void
    {
        $activities = Activity::query()->where('user_id', $user->id)->get();
        $prIds = PersonalRecord::query()->where('user_id', $user->id)->pluck('id')->all();
        $cardIds = RunCard::query()->whereIn('activity_id', $activities->pluck('id'))->pluck('id')->all();

        $today = Carbon::today()->toDateString();

        foreach ($activities as $activity) {
            // Stage the whole per-activity group (post-run speech + the three run
            // insights) like production's post-ingest dispatch, so a run's detail
            // page is fully filled, not just its speech block.
            $this->analysisService->requestActivityGroup($activity);
        }
        foreach ($cardIds as $cardId) {
            $this->analysisService->request(
                subjectOrType: RunCard::class,
                subjectId: $cardId,
                type: AnalysisType::CardFlavor,
            );
        }
        foreach ($prIds as $prId) {
            $this->analysisService->request(
                subjectOrType: PersonalRecord::class,
                subjectId: $prId,
                type: AnalysisType::PrContext,
            );
        }
        // Recaps never narrate the still-running current period (see RecapPeriod),
        // so the demo caps weekly recaps at the last fully-closed week to match
        // real narration instead of seeding an open-week recap.
        $lastClosedWeekEnding = RecapPeriod::lastClosedWeekEnding();
        $closedWeeklies = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->whereDate('week_ending', '<=', $lastClosedWeekEnding)
            ->pluck('id');
        foreach ($closedWeeklies as $weeklyId) {
            $this->analysisService->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: $weeklyId,
                type: AnalysisType::WeeklyRecap,
            );
        }
        // Headline + suggestion are one group: stage the whole group (not just the
        // headline) so the dashboard's suggestion card is filled too and never
        // renders "Belum dibaca". Mirrors DailyBriefingCommand.
        $this->analysisService->requestBriefingGroup($user, $today);
        $this->analysisService->request(
            subjectOrType: AnalysisType::BRIEFING_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::BriefingMascotVoice,
            discriminator: $today,
        );
        // The weekly-featured-card voice ("Kartu dari Temari minggu ini" on the
        // dashboard hero) has its own job and is never auto-requested by ingest,
        // so the demo must stage it here or the hero falls back to "Belum dibaca".
        // Keyed by the featured card id (matching BriefingComposer) so the staged
        // quote lines up with the card the hero actually shows.
        $featuredCard = $this->featuredKartu->resolve($user);
        if ($featuredCard !== null) {
            $this->analysisService->request(
                subjectOrType: AnalysisType::BRIEFING_SUBJECT_TYPE,
                subjectId: $user->id,
                type: AnalysisType::BriefingFeaturedKartuVoice,
                discriminator: (string) $featuredCard->id,
            );
        }
        $this->analysisService->request(
            subjectOrType: AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::DailyGreeting,
            discriminator: $today,
        );
        $this->analysisService->request(
            subjectOrType: AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::TrendCaption,
            discriminator: $today,
        );

        // Persona summary is cached per ISO week — discriminator must match
        // ProfileController::resolvePersonaSummary() or the Aku card misses it.
        $this->analysisService->request(
            subjectOrType: AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::PersonaSummary,
            discriminator: Carbon::now()->isoFormat('GGGG-[W]WW'),
        );

        // One monthly recap per calendar month across the seeded window, capped
        // at the last fully-closed month (RecapPeriod) so the demo skips the
        // still-running current month like real narration does.
        $lastClosedMonth = RecapPeriod::lastClosedMonth();
        for ($m = 6; $m >= 0; $m--) {
            $month = Carbon::today()->startOfMonth()->subMonthsNoOverflow($m)->format('Y-m');
            if ($month > $lastClosedMonth) {
                continue;
            }
            $this->analysisService->request(
                subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                subjectId: $user->id,
                type: AnalysisType::MonthlyRecap,
                discriminator: $month,
            );
        }
    }

    private function backfillWithFiller(User $user): int
    {
        $activityIds = Activity::query()->where('user_id', $user->id)->pluck('id');
        $weeklyIds = WeeklySnapshot::query()->where('user_id', $user->id)->pluck('id');
        $prIds = PersonalRecord::query()->where('user_id', $user->id)->pluck('id');
        $cardIds = RunCard::query()->whereIn('activity_id', $activityIds)->pluck('id');

        $rows = Analysis::query()
            ->where('status', '!=', AnalysisStatus::Done)
            ->where(function ($q) use ($user, $activityIds, $weeklyIds, $prIds, $cardIds): void {
                $q->where(fn ($qq) => $qq->where('subject_type', Activity::class)->whereIn('subject_id', $activityIds))
                    ->orWhere(fn ($qq) => $qq->where('subject_type', WeeklySnapshot::class)->whereIn('subject_id', $weeklyIds))
                    ->orWhere(fn ($qq) => $qq->where('subject_type', PersonalRecord::class)->whereIn('subject_id', $prIds))
                    ->orWhere(fn ($qq) => $qq->where('subject_type', RunCard::class)->whereIn('subject_id', $cardIds))
                    ->orWhere(fn ($qq) => $qq->whereIn('subject_type', [
                        AnalysisType::BRIEFING_SUBJECT_TYPE,
                        AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
                        AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
                        AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE,
                        AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                    ])->where('subject_id', $user->id));
            })
            ->get();

        $demoGeneratedAt = Carbon::now()->subHours(2);

        foreach ($rows as $row) {
            $this->analysisService->markDone($row, $this->filler->fillFor($row), $demoGeneratedAt);
        }

        return $rows->count();
    }

    private function ensureDemoUser(Closure $log): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::DEMO_USER_EMAIL],
            [
                'name' => 'Demo Runner',
                'avatar_url' => null,
                'is_demo' => true,
            ],
        );

        // updateOrCreate so re-seeds converge: expiry + revoked_at heal to a healthy ACTIVE connection.
        StravaConnection::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'strava_athlete_id' => 99_999_999,
                'access_token' => 'demo-access-token',
                'refresh_token' => 'demo-refresh-token',
                'token_expires_at' => Carbon::now()->addYear(),
                'scopes' => 'read,activity:read',
                'revoked_at' => null,
            ],
        );

        $log("Demo user ready: {$user->email} (id={$user->id})");

        return $user;
    }

    private function seedOne(User $user, RunBlueprint $blueprint): void
    {
        $streams = $this->synthesizer->build($blueprint);
        $splits = $this->splitsBuilder->build($streams);

        $activity = Activity::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'strava_external_id' => (int) ('9' . str_pad((string) $blueprint->seed(), 9, '0', STR_PAD_LEFT)),
            ],
            [
                'fetched_at' => $blueprint->startsAt->copy()->addHour(),
                'analyzed_at' => $blueprint->startsAt->copy()->addHour(),
                'detail_fail_count' => 0,
            ],
        );

        $distanceStream = $streams['distance']['data'] ?? [];
        $hrStream = $streams['heartrate']['data'] ?? [];
        $cadenceStream = $streams['cadence']['data'] ?? [];

        $location = $blueprint->location ?? DemoLocation::default();

        $detail = ActivityDetail::query()->updateOrCreate([
            'activity_id' => $activity->id,
        ], [
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
                ? $this->demoPolyline($blueprint->distanceM, $blueprint->seed(), $location)
                : null,
            'start_lat' => $blueprint->hasGps ? $location->lat : null,
            'start_lng' => $blueprint->hasGps ? $location->lng : null,
            'location_name' => $blueprint->hasGps ? $location->name : null,
            'location_country' => $blueprint->hasGps ? $location->country : null,
            'location_resolved_at' => $blueprint->hasGps ? $blueprint->startsAt->copy()->addMinutes(2) : null,
            'weather_temp_c' => $blueprint->weatherTempC,
            'weather_humidity_pct' => $blueprint->weatherHumidityPct,
            'weather_rain_detected' => $blueprint->weatherRainDetected,
        ]);

        ActivityStream::query()->updateOrCreate([
            'activity_id' => $activity->id,
        ], [
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
