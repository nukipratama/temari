<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Gamification\GrantEligibleUnlocksAction;
use App\Enums\ExperienceLevel;
use App\Enums\GoalType;
use App\Enums\IngestState;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\AI\Analysis;
use App\Models\AI\RunQuestion;
use App\Models\InboxNotification;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\RunCard;
use App\Models\StravaConnection;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\UnlockGrantedNotification;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\PlanNarrationRequester;
use App\Services\AI\RecapPeriod;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\AI\RunQuestion\RunQuestionSeeds;
use App\Services\Geo\PolylineEncoder;
use App\Services\Run\Ingest\StreamAnalysis;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Plan\WeekPlanBuilder;
use App\Services\Run\Story\RunCardFactory;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\Vibe;
use App\Support\SharedPropCacheKey;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function count;
use function is_array;

class DemoRunSeeder
{
    public const string DEMO_USER_EMAIL = 'demo@temari.local';

    public function __construct(
        private readonly BlueprintLibrary $library,
        private readonly StreamSynthesizer $synthesizer,
        private readonly SplitsBuilder $splitsBuilder,
        private readonly LapsBuilder $lapsBuilder,
        private readonly StreamAnalysis $streamAnalysis,
        private readonly TrainingLoad $trainingLoad,
        private readonly PersonalRecords $personalRecords,
        private readonly RunCardFactory $cardFactory,
        private readonly Temari $temari,
        private readonly Vibe $vibe,
        private readonly WeeklyAggregator $weeklyAggregator,
        private readonly AnalysisService $analysisService,
        private readonly RuleBasedNarrationFiller $filler,
        private readonly GrantEligibleUnlocksAction $unlockEngine,
        private readonly Periodizer $periodizer,
        private readonly WeekPlanBuilder $weekPlanBuilder,
        private readonly TrainingBaseline $trainingBaseline,
        private readonly PlanNarrationRequester $planNarrationRequester,
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

            $log("Regenerating this week's training plan...");
            $this->seedCurrentWeekPlan($user);

            $this->seedTrendRead($user);

            // Grants everything the dataset qualifies for. Card-rarity unlocks
            // (legendary/epic) and the weekly-streak one depend on cards and
            // snapshots that only exist once the loop above has run, so this
            // has to come after it. Wrapped in withSyncQueue so the queued
            // UnlockGrantedNotification runs inline rather than sitting
            // unprocessed in the jobs table; the inbox rows themselves are
            // written below rather than relied on from here, because this
            // returns nothing once every key is already granted.
            $granted = $this->withSyncQueue(fn (): array => ($this->unlockEngine)($user));
            $log(sprintf('  %d accessory unlocks granted (%s)', count($granted), $granted === [] ? 'all already unlocked' : implode(', ', $granted)));

            // Equip the best-in-slot accessories (one per slot) so the demo
            // Temari actually shows off its hardware everywhere it appears.
            // Clear every equipped flag first so a re-seed can't leave a stale
            // sibling equipped in the same slot (two medals at once).
            UserUnlock::query()
                ->where('user_id', $user->id)
                ->update(['equipped' => false]);

            UserUnlock::query()
                ->where('user_id', $user->id)
                ->whereIn('unlock_key', [
                    'accessory.headband_legendary',
                    'accessory.medal_gold',
                ])
                ->update(['equipped' => true]);

            SharedPropCacheKey::EquippedAccessories->forget($user->id);

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

            // Backfill inside withoutDispatching so markDone's Telegram fan-out
            // stays suppressed: the demo never has a real connection, so an
            // enqueued (no-op) notification job per row would just be waste.
            $filled = $this->backfillWithFiller($user);
            $log(sprintf('  %d AI analyses backfilled with rule-based content (hit "Reread" in the UI for real LLM narration).', $filled));

            // Rebuilt rather than topped up. Inbox rows must be written
            // oldest-first (see writeInboxEntries), which a top-up
            // cannot fix: rows already written in the wrong order keep their
            // ids, so the account never converges no matter how often the
            // seed re-runs. Every row here is derived from the seeded dataset
            // and re-written below, and this only ever touches the demo user.
            $user->inboxNotifications()->delete();
            $pendingInbox = [...$this->pendingUnlockInboxEntries($user), ...$this->pendingNarrationInboxEntries($user)];
            $log(sprintf('  %d inbox rows rebuilt', $this->writeInboxEntries($user, $pendingInbox)));
            $log(sprintf('  %d answered run questions seeded', $this->seedRunQuestions($user)));
        });

        return $count;
    }

    /**
     * Daily keep-alive for the demo account, run by the demo:daily-refresh
     * scheduler. Adds one modest synthetic run for today (skipping two rest days
     * a week so the streak looks human) and re-stages + rule-based-fills today's
     * date-keyed narration, so the demo never goes stale or renders an empty
     * an empty, un-narrated block when the date rolls. Zero LLM tokens (runs under
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
            $this->withSyncQueue(fn (): array => ($this->unlockEngine)($user));
            $this->temari->dailyGreeting($user, $this->vibe->current($user));

            // Re-stage the date-keyed surfaces (briefing set, greeting, trend,
            // weekly persona) against today's discriminator — the line that kills
            // an empty, un-narrated block once the calendar day moves past the seed day.
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
        $names = ['Morning run', 'Easy run', 'Easy miles', 'Morning jog', 'Shakeout'];

        return new RunBlueprint(
            startsAt: $date->copy()->setTime(6, $rng->getInt(0, 45)),
            distanceM: $rng->getInt(35, 70) * 100,
            targetPaceSecPerKm: $rng->getInt(390, 460),
            hrProfile: $rng->getInt(0, 1) === 0 ? HrProfile::Z2Steady : HrProfile::Mixed,
            cadenceSpm: 170,
            // Enough relief that the day's run always clears StreamAnalysis'
            // 3% sustained-grade gate, so the vitals card's steepest-grade and
            // flat-pace tiles are never intermittently absent on the newest
            // run — which is the one any reviewer lands on first.
            elevationGainM: $rng->getInt(58, 92),
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
        // Mirrors DailyBriefingCommand so the dashboard's Temari voice card is
        // filled and never renders as empty.
        $this->analysisService->requestBriefing($user, $today);
        // The Aku voice is cached per ISO week — discriminator must match
        // ProfileController::resolveProfileVoice() or the Aku hero misses it.
        $this->analysisService->request(
            subjectOrType: AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::AkuProfileVoice,
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
                        AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE,
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

    /**
     * Regenerates the real 12-week horizon via {@see Periodizer::regenerate()},
     * then backfills the current week's *past* days, which that call always
     * skips (`Periodizer` only ever writes today-forward — see its own
     * docblock). Reuses {@see WeekPlanBuilder} with `notBefore: null` so the
     * past days come from the exact same session-type template as the days
     * `regenerate()` just wrote, then hand-assigns a status/compliance-score
     * cycle across the real `PlannedSessionStatus` bands (see
     * {@see \App\Services\Run\Plan\SessionMatcher}) so Today's day-glyph strip
     * shows real variety instead of an all-`Planned` week.
     */
    private function seedCurrentWeekPlan(User $user): void
    {
        $today = Carbon::today();

        $this->periodizer->regenerate($user);

        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $todaysRow = PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', $today->toDateString())
            ->first();
        if ($todaysRow === null) {
            return;
        }

        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();
        $preference = TrainingPreference::query()->where('user_id', $user->id)->first();
        $sessionsPerWeek = $this->trainingBaseline->forUser($user, $today)['sessions_per_week'];

        $weekRows = $this->weekPlanBuilder->build(
            $currentWeekStart,
            $todaysRow->phase,
            $sessionsPerWeek,
            [],
            $race !== null ? (float) $race->distance_m : null,
            $race === null,
            null,
            0,
            $preference?->run_days,
            $preference?->long_run_day,
        );

        $pastDayStatuses = [
            PlannedSessionStatus::Overreached,
            PlannedSessionStatus::Done,
            PlannedSessionStatus::Partial,
            PlannedSessionStatus::Skip,
        ];
        $scoreFor = [
            PlannedSessionStatus::Overreached->value => 145,
            PlannedSessionStatus::Done->value => 100,
            PlannedSessionStatus::Partial->value => 55,
        ];

        $trainingDayIndex = 0;
        foreach ($weekRows as $date => $row) {
            if (! Carbon::parse($date)->lt($today)) {
                continue;
            }

            if ($row['session_type'] === SessionType::Rest) {
                PlannedSession::query()->updateOrCreate(
                    ['user_id' => $user->id, 'date' => $date],
                    [
                        'phase' => $row['phase'],
                        'session_type' => $row['session_type'],
                        'pinned' => false,
                        'skipped' => false,
                        'status' => PlannedSessionStatus::Done,
                        'compliance_score' => null,
                        'ran_anyway' => false,
                    ],
                );

                continue;
            }

            $status = $pastDayStatuses[$trainingDayIndex % count($pastDayStatuses)];
            $trainingDayIndex++;

            PlannedSession::query()->updateOrCreate(
                ['user_id' => $user->id, 'date' => $date],
                [
                    'phase' => $row['phase'],
                    'session_type' => $row['session_type'],
                    'pinned' => false,
                    'skipped' => $status === PlannedSessionStatus::Skip,
                    'status' => $status,
                    'compliance_score' => $scoreFor[$status->value] ?? null,
                    'ran_anyway' => false,
                ],
            );
        }

        // Fills plan_day_voice (current week's 7 days) / plan_week_voice
        // (this week's PlanAdaptation) / plan_season_voice (the active
        // Season) rule-based, mirroring the demo Plan page's own "Reread"
        // path — see PlanNarrationRequester::ensureDemoFilled's docblock.
        $this->planNarrationRequester->ensureDemoFilled($user, $today);
    }

    /**
     * trend_read has no per-user cadence command reachable from a seeder
     * (TrendReadCommand explicitly excludes demo users, matching the demo
     * billing exclusion), so the demo's three range narrations are staged
     * and rule-based-filled here directly instead.
     */
    private function seedTrendRead(User $user): void
    {
        foreach (AnalysisType::TREND_READ_RANGES as $range) {
            $this->analysisService->requestRuleBased(
                AnalysisType::TREND_READ_SUBJECT_TYPE,
                $user->id,
                AnalysisType::TrendRead,
                $range,
                refillDone: false,
            );
        }
    }

    /**
     * Three representative inbox rows built straight from already-narrated
     * Analysis content, mirroring AnalysisReadyNotification::toInbox()'s own
     * shape (title/payload) without going through the queued notify() path:
     * the last closed week's recap and the last closed month's, each dated to
     * when it would really have landed, plus today's post-run summary. With
     * the unlock rows those fill all three of the Inbox page's buckets and
     * four distinct kinds, rather than one undifferentiated list.
     *
     * @return list<array{at: Carbon, message: InboxMessage, key: string}>
     */
    private function pendingNarrationInboxEntries(User $user): array
    {
        $pending = [];

        $lastClosedWeekEnding = RecapPeriod::lastClosedWeekEnding();
        $weekly = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->whereDate('week_ending', $lastClosedWeekEnding)
            ->first();
        if ($weekly !== null) {
            $pending[] = $this->pendingInboxFromAnalysis(
                $user,
                WeeklySnapshot::class,
                $weekly->id,
                AnalysisType::WeeklyRecap,
                $weekly->week_ending->copy()->addDay(),
            );
        }

        // The last closed month, dated the 1st of the next one, so the inbox
        // carries a third kind rather than only unlocks and a weekly. Monthly
        // recaps key on a synthetic user/month subject, so the month itself is
        // the discriminator rather than a row id.
        $pending[] = $this->pendingInboxFromAnalysis(
            $user,
            AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
            $user->id,
            AnalysisType::MonthlyRecap,
            Carbon::today()->startOfMonth(),
            Carbon::today()->subMonthNoOverflow()->format('Y-m'),
        );

        // Filler blueprints can coincidentally land on the same calendar date
        // as the D-0 keep-alive run seeded in seed()/refreshToday() (more
        // than one activity on "today" is common in this dataset), so this
        // orders by id to deterministically pick that keep-alive run — it's
        // always seeded last — rather than an unordered first() that could
        // pick a different row across re-seeds.
        $todayActivity = Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', fn ($q) => $q->whereDate('start_date_local', Carbon::today()))
            ->latest('id')
            ->first();
        if ($todayActivity !== null) {
            $pending[] = $this->pendingInboxFromAnalysis($user, Activity::class, $todayActivity->id, AnalysisType::PostRunSpeech);
        }

        return array_values(array_filter($pending));
    }

    /**
     * Two answered questions on the newest run, so the Q&A panel's prior-list
     * renders rather than only ever showing its empty state, and returns how
     * many it added.
     *
     * Scoped run Q&A keeps its own table rather than an Analysis row (see
     * docs/decisions/scoped-run-qa-not-an-analysis-row.md), so the seeder's
     * withoutDispatching guard does not reach it and RuleBasedNarrationFiller
     * has nothing to say about it. The answers are therefore fixture text,
     * grounded in the run's own numbers, on the same footing as the rest of
     * the demo's rule-based narration: no LLM call, no tokens.
     */
    private function seedRunQuestions(User $user): int
    {
        $detail = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('start_date_local')
            ->first();
        if ($detail === null) {
            return 0;
        }

        $topics = array_slice(RunQuestionSeeds::for($detail), 0, 2);
        if ($topics === []) {
            return 0;
        }

        $km = round((float) $detail->distance / 1000, 1);
        $paceSec = PaceCalculator::secPerKm((float) $detail->distance, $detail->moving_time);
        $pace = $paceSec === null
            ? 'the pace you held'
            : sprintf('%d:%02d/km', (int) ($paceSec / 60), (int) round(fmod($paceSec, 60)));

        $added = 0;
        foreach ($topics as $topic) {
            $existing = RunQuestion::query()
                ->where('activity_id', $detail->activity_id)
                ->where('question', $topic->question())
                ->exists();
            if ($existing) {
                continue;
            }

            RunQuestion::query()->create([
                'user_id' => $user->id,
                'activity_id' => $detail->activity_id,
                'question' => $topic->question(),
                'answer' => sprintf(
                    'over %s km at %s, nothing here is off. it reads like the rest of your recent work, so treat it as a normal day rather than a signal.',
                    $km,
                    $pace,
                ),
                'status' => AnalysisStatus::Done,
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * Spreads the demo account's unlocks across its seeded run history.
     *
     * Every unlock is granted in one sweep at the end of seeding, so they all
     * carry the same timestamp and the inbox would render a single "today"
     * bucket of 21 rows, with nothing in "this week" or "earlier" and nothing
     * for the load-older window to page in. Deterministic in each row's
     * position, so a re-seed lands on the same dates.
     *
     * @param  Collection<int, UserUnlock>  $unlocks
     */
    private function spreadUnlockDates(User $user, Collection $unlocks): void
    {
        $earliest = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->min('start_date_local');
        if ($earliest === null || $unlocks->isEmpty()) {
            return;
        }

        $start = Carbon::parse($earliest);
        $days = max(1, $start->diffInDays(Carbon::today()));
        $step = $days / ($unlocks->count() + 1);

        foreach ($unlocks->values() as $i => $unlock) {
            $earnedAt = $start->copy()->addDays((int) round($step * ($i + 1)));
            if (! $unlock->unlocked_at->isSameDay($earnedAt)) {
                $unlock->forceFill(['unlocked_at' => $earnedAt])->save();
            }
        }
    }

    /**
     * Writes the inbox row for every already-granted unlock that lacks one,
     * backdated to when it was earned, and returns how many it added.
     *
     * GrantEligibleUnlocksAction short-circuits once every catalog key is
     * granted, so the sweep above notifies nothing on a database whose unlocks
     * predate it — which is every database seeded before unlock notifications
     * existed. Without this, `demo:seed` never converges on the inbox's unlock
     * rows no matter how often it is re-run, and P12's unlock surface stays
     * invisible. Writes the message directly, as the narration entries
     * does, rather than replaying a queued notification.
     */
    /** @return list<array{at: Carbon, message: InboxMessage, key: string}> */
    private function pendingUnlockInboxEntries(User $user): array
    {
        $unlocks = UserUnlock::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        $this->spreadUnlockDates($user, $unlocks);

        $pending = [];
        foreach ($unlocks as $unlock) {
            $celebration = $this->unlockEngine->celebration($unlock->unlock_key);
            if ($celebration === null) {
                continue;
            }

            $message = new UnlockGrantedNotification($celebration)->toInbox($user);
            $pending[] = [
                'at' => $unlock->unlocked_at,
                'message' => $message,
                'key' => $message->dedupeKey ?? 'unlock:' . $unlock->unlock_key,
            ];
        }

        return $pending;
    }

    /** @return array{at: Carbon, message: InboxMessage, key: string}|null */
    private function pendingInboxFromAnalysis(User $user, string $subjectType, int $subjectId, AnalysisType $type, ?Carbon $at = null, ?string $discriminator = null): ?array
    {
        $analysis = Analysis::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('analysis_type', $type)
            ->where('status', AnalysisStatus::Done)
            ->when($discriminator !== null, fn ($q) => $q->where('discriminator', $discriminator))
            ->first();
        if ($analysis === null) {
            return null;
        }

        $message = new AnalysisReadyNotification($analysis)->toInbox($user);
        if ($message === null) {
            return null;
        }

        return [
            'at' => $at ?? Carbon::now(),
            'message' => $message,
            'key' => $message->dedupeKey ?? (string) $analysis->id,
        ];
    }

    /**
     * Writes pending inbox entries oldest-first and returns how many landed.
     *
     * InboxController paginates on `orderByDesc('id')` while the page buckets
     * on created_at. Those only agree while rows are inserted in chronological
     * order, which is automatic in production (a row is written when its
     * notification fires) and has to be arranged here, because these rows are
     * backdated across the whole season. Written out of order, a row falls off
     * the end of the first window and its entire bucket stops rendering.
     *
     * @param  list<array{at: Carbon, message: InboxMessage, key: string}>  $pending
     */
    private function writeInboxEntries(User $user, array $pending): int
    {
        usort($pending, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $written = 0;
        foreach ($pending as $entry) {
            if (! InboxNotification::record($user, $entry['message'], $entry['key'])) {
                continue;
            }

            InboxNotification::query()
                ->where('user_id', $user->id)
                ->where('dedupe_key', $entry['key'])
                ->update(['created_at' => $entry['at']]);

            $written++;
        }

        return $written;
    }

    /**
     * Runs $work with the queue connection forced to `sync`, so a
     * ShouldQueue notification (e.g. UnlockGrantedNotification) fires
     * inline instead of sitting unprocessed in the `jobs` table — nothing
     * in the demo seed ever runs a queue worker. Safe for the demo account:
     * ChannelRouter resolves every notification's `via()` to InAppChannel
     * only here, so this never fires a real Telegram/web-push side effect,
     * only the InboxNotification row itself.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function withSyncQueue(Closure $work): mixed
    {
        $previous = config('queue.default');
        config(['queue.default' => 'sync']);
        try {
            return $work();
        } finally {
            config(['queue.default' => $previous]);
        }
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

        // The demo account is a fully-populated showcase, not a new signup —
        // heals to onboarded on every re-seed so it never lands in the wizard.
        if ($user->onboarded_at === null) {
            $user->markOnboarded();
        }

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

        // The demo user trains for a race, so there has to be one: without it
        // Race renders its empty state and Profile's goal chip renders for
        // nobody. Sub-50 over 10k, the distance the seeded history is built
        // around. updateOrCreate on the active row so a re-seed converges.
        RaceGoal::query()->updateOrCreate(
            ['user_id' => $user->id, 'completed_at' => null],
            [
                'race_date' => Carbon::today()->addWeeks(12),
                'distance_m' => 10_000,
                'goal_time_sec' => 3_000,
                'name' => 'City 10K',
            ],
        );

        // Without a row the whole Settings preferences card runs on
        // TrainingBaseline fallbacks and its "which one's the long run?" block
        // never renders, since that is gated on having run days. The seeded
        // history runs on every weekday about equally, so there is no pattern
        // to derive these from: they are a deliberate fixture for an
        // experienced runner on a race block, matching the race goal seeded
        // above. updateOrCreate so a re-seed converges, as above.
        TrainingPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'experience_level' => ExperienceLevel::Experienced,
                'sessions_per_week' => 5,
                'goal_type' => GoalType::Race,
                'run_days' => [1, 2, 3, 5, 6],
                'long_run_day' => 6,
            ],
        );

        $log("Demo user ready: {$user->email} (id={$user->id})");

        return $user;
    }

    private function seedOne(User $user, RunBlueprint $blueprint): void
    {
        $streams = $this->synthesizer->build($blueprint);
        $splits = $this->splitsBuilder->build($streams);
        $laps = $this->lapsBuilder->build($streams, $blueprint->lapDistancesM);

        $activity = Activity::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'strava_external_id' => (int) ('9' . str_pad((string) $blueprint->seed(), 9, '0', STR_PAD_LEFT)),
            ],
            [
                'fetched_at' => $blueprint->startsAt->copy()->addHour(),
                'analyzed_at' => $blueprint->startsAt->copy()->addHour(),
                'ingest_state' => IngestState::Detailed,
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
            'laps' => $laps,
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
            'weather_wind_speed_kmh' => $blueprint->weatherWindSpeedKmh,
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
            $detail->distance,
            $detail->laps(),
        );

        $minutesInZone = StreamSummary::fromArray($summary)->zoneMinutes();
        $trimp = $minutesInZone !== null ? $this->trainingLoad->edwardsTrimp($minutesInZone) : null;

        $detail->update([
            'stream_summary' => $summary === [] ? null : $summary,
            'trimp_edwards' => $trimp,
        ]);
    }

}
