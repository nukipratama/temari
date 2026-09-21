<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\Badge;
use App\Enums\IntentVerdict;
use App\Enums\PlannedSessionStatus;
use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\RunCard;
use App\Models\Season;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\AI\RuleBased\RuleBasedRunInsights;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function fillerRow(AnalysisType $type, int $subjectId, ?string $discriminator = null): Analysis
{
    $row = new Analysis();
    $row->analysis_type = $type;
    $row->subject_id = $subjectId;
    $row->discriminator = $discriminator;

    return $row;
}

function seededCard(Rarity $rarity, string $move, array $badges = [], float $distance = 8000.0): RunCard
{
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create(['activity_id' => $activity->id, 'distance' => $distance]);

    return RunCard::factory()->create([
        'activity_id' => $activity->id,
        'rarity' => $rarity,
        'special_move' => $move,
        'badges' => $badges,
    ]);
}

function monthlyRunForFiller(User $user, string $date, float $distance): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($date),
        'distance' => $distance,
    ]);
}

it('weaves the card context (move or distance) into the flavor', function (): void {
    $card = seededCard(Rarity::Epic, 'Threshold Hold', [], 10_010.0);

    $flavor = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    // Every template carries either the move name or the formatted distance.
    expect($flavor === '' ? '' : $flavor)
        ->toBeString()
        ->and(str_contains($flavor, 'Threshold Hold') || str_contains($flavor, '10.0'))
        ->toBeTrue();
});

it('is deterministic for the same card', function (): void {
    $card = seededCard(Rarity::Rare, 'Steady Tempo');
    $filler = app(RuleBasedNarrationFiller::class);

    $first = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));
    $second = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    expect($first)->toBe($second);
});

it('varies the flavor across rarities', function (): void {
    $filler = app(RuleBasedNarrationFiller::class);
    $flavors = collect(Rarity::cases())
        ->map(fn (Rarity $r): RunCard => seededCard($r, $r->value . ' Move'))
        ->map(fn (RunCard $c): string => $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $c->id)));

    // Distinct pools per rarity + distinct moves → no two cards read the same.
    expect($flavors->unique()->count())->toBe(5);
});

it('appends a badge coda when the card carries a known badge', function (): void {
    $card = seededCard(Rarity::Uncommon, 'Closing Kick', [Badge::NegativeSplit->value]);

    $flavor = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    expect($flavor)->toContain('Second half');
});

it('appends the headwind badge coda', function (): void {
    $card = seededCard(Rarity::Uncommon, 'Wind Breaker', [Badge::Headwind->value]);

    $flavor = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    expect($flavor)->toContain('Headwind the whole way.');
});

it('falls back to a flat line when the card is missing', function (): void {
    $flavor = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::CardFlavor, 999_999));

    expect($flavor)->toBe('A quiet session, filed anyway.');
});

it('varies the ecosystem briefing voices by seed deterministically', function (): void {
    $filler = app(RuleBasedNarrationFiller::class);

    $voiceA = $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, 1));
    $voiceB = $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, 2));
    $voiceAAgain = $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, 1));

    expect($voiceA)->toBe($voiceAAgain)
        ->and($voiceA)->not->toBe($voiceB);
});

it("states each month's distance, run count, longest run, and volume band", function (): void {
    $user = User::factory()->create();
    monthlyRunForFiller($user, '2026-04-04 06:00:00', 5000.0);
    monthlyRunForFiller($user, '2026-05-03 06:00:00', 7000.0);
    monthlyRunForFiller($user, '2026-05-17 06:00:00', 12_500.0);
    monthlyRunForFiller($user, '2026-06-07 06:00:00', 10_000.0);
    $filler = app(RuleBasedNarrationFiller::class);

    $april = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, $user->id, '2026-04'));
    $may = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, $user->id, '2026-05'));
    $june = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, $user->id, '2026-06'));

    expect($april)->toContain('5.0 km')
        ->and($april)->toMatch('/\b1 (run|session)\b/')
        ->and($april)->not->toMatch('/\b1 (runs|sessions)\b/')
        ->and($april)->toContain('longest')
        ->and($may)->toContain('19.5 km')
        ->and($may)->toMatch('/\b2 (runs|sessions)\b/')
        ->and($may)->toContain('12.5 km')
        ->and($may)->toContain('above your usual month')
        ->and($may)->not->toBe($april)
        ->and($june)->toContain('right around your usual month');
});

it('is deterministic for the same subject and discriminator', function (): void {
    $user = User::factory()->create();
    monthlyRunForFiller($user, '2026-03-08 06:00:00', 8200.0);
    $filler = app(RuleBasedNarrationFiller::class);

    $first = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, $user->id, '2026-03'));
    $second = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, $user->id, '2026-03'));

    expect($first)->toBe($second);
});

it('does not praise a month well below the athlete usual volume', function (): void {
    $user = User::factory()->create();
    monthlyRunForFiller($user, '2026-02-08 06:00:00', 30_000.0);
    monthlyRunForFiller($user, '2026-03-08 06:00:00', 32_000.0);
    monthlyRunForFiller($user, '2026-04-08 06:00:00', 28_000.0);
    monthlyRunForFiller($user, '2026-05-08 06:00:00', 3000.0);

    $recap = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::MonthlyRecap, $user->id, '2026-05'),
    );

    expect($recap)->toContain('below your usual month')
        ->and($recap)->not->toContain('above your usual month')
        ->and($recap)->not->toContain('work actually banks');
});

it('keeps the subject-only seed when the discriminator is null', function (): void {
    $filler = app(RuleBasedNarrationFiller::class);

    // A null discriminator must leave the seed equal to subject_id so existing
    // non-discriminated determinism (and the first-variant default) is preserved.
    $copy = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 0, null));

    expect($copy)->toBe("no dated runs found for this month. there's nothing honest to recap yet.");
});

it('returns deterministic copy for every subject-free analysis arm', function (AnalysisType $type, string $expected): void {
    $copy = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow($type, 0));

    expect($copy)->toBe($expected);
})->with([
    'briefing mascot voice' => [AnalysisType::BriefingMascotVoice, "Easy tempo, 35-45 minutes.\n\nnothing quality has gone into the log since last week and your rhythm's been flat and steady the whole time, so today's the day to break that up. 10 minutes easy to warm up, 15-20 minutes a bit quicker than your usual pace, then cool down. cadence 175+.\n\nWhat to watch: if HR climbs fast at easy pace, drop it to a 15-25 minute run-walk and stop at the cooldown. Brutal heat is reason enough to run the whole thing easy instead."],
    'run insight (no detail)' => [AnalysisType::RunInsight, '[]'],
    'weekly recap' => [AnalysisType::WeeklyRecap, "nothing in the log this week. a gap is a gap, I'm not going to call it anything else."],
    'profile voice' => [AnalysisType::ProfileVoice, "You lean **chill** far more than pushed, and the log backs it up: regular, unhurried, never a big jump. That's a base built the slow way. The open question is when you decide to spend it."],
    'monthly recap' => [AnalysisType::MonthlyRecap, "no dated runs found for this month. there's nothing honest to recap yet."],
    'trend read' => [AnalysisType::TrendRead, "steady is the read.\n\nnothing in this window moved sharply enough to call out on its own. the rhythm held, which is its own kind of answer."],
]);

it('weaves the run distance into the post-run speech', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create(['activity_id' => $activity->id, 'distance' => 5500.0]);

    $speech = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PostRunSpeech, $activity->id));

    expect($speech)->toContain('5.5 km');
});

it('does not cycle the post-run speech in lockstep with consecutive activity ids', function (): void {
    // Regression for the demo feed's most visible defect: with the raw
    // sequential activity id as the pool seed, every 6th run in the History
    // feed rendered the byte-identical line. Consecutive real ids must land
    // on a scattered, not rhythmic, set of phrases.
    $lines = [];
    for ($i = 0; $i < 18; $i++) {
        $activity = Activity::factory()->create();
        ActivityDetail::factory()->create(['activity_id' => $activity->id, 'distance' => 8000.0]);
        $lines[] = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PostRunSpeech, $activity->id));
    }

    expect(count(array_unique($lines)))->toBeGreaterThanOrEqual(8);
});

function postRunLinesOverIds(int $count, ?float $fixedDistance = null): array
{
    $filler = app(RuleBasedNarrationFiller::class);
    $lines = [];
    for ($i = 0; $i < $count; $i++) {
        $activity = Activity::factory()->create();
        ActivityDetail::factory()->create([
            'activity_id' => $activity->id,
            'distance' => $fixedDistance ?? 3000.0 + ($i * 137.0),
            'stream_summary' => [],
            'weather_temp_c' => 24,
            'weather_rain_detected' => false,
        ]);
        $lines[] = $filler->fillFor(fillerRow(AnalysisType::PostRunSpeech, $activity->id));
    }

    return $lines;
}

it('renders the same post-run speech every time for one activity', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create(['activity_id' => $activity->id, 'distance' => 7400.0]);
    $filler = app(RuleBasedNarrationFiller::class);

    $first = $filler->fillFor(fillerRow(AnalysisType::PostRunSpeech, $activity->id));
    $second = $filler->fillFor(fillerRow(AnalysisType::PostRunSpeech, $activity->id));

    expect($first)->toBe($second);
});

it('keeps the post-run speech near-fully distinct across a long feed scroll', function (): void {
    $lines = postRunLinesOverIds(300);

    expect(count(array_unique($lines)))->toBeGreaterThanOrEqual(285);
});

it('varies the post-run speech even when every run is the same distance', function (): void {
    // The opener and closer slots are salted separately, so the only variation
    // left when the distance is constant is the slot pairing itself.
    $lines = postRunLinesOverIds(300, 8000.0);

    expect(count(array_unique($lines)))->toBeGreaterThanOrEqual(150);
});

it('keeps every post-run slot pairing inside the narrated register', function (): void {
    foreach (postRunLinesOverIds(40) as $line) {
        expect($line)->not->toContain('—')
            ->and($line)->not->toMatch('/\bAI\b/')
            ->and($line)->not->toMatch('/\b[A-Z]{3,}\b/')
            ->and($line)->not->toContain('!');
    }
});

it('keeps the widened common flavor pool inside the narrated register', function (): void {
    $filler = app(RuleBasedNarrationFiller::class);
    $flavors = [];
    for ($i = 0; $i < 40; $i++) {
        $card = seededCard(Rarity::Common, "Move {$i}", [], 4000.0 + ($i * 211.0));
        $flavors[] = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));
    }

    foreach ($flavors as $flavor) {
        expect($flavor)->not->toContain('—')
            ->and($flavor)->not->toMatch('/\bAI\b/')
            ->and($flavor)->not->toMatch('/\b[A-Z]{3,}\b/');
    }

    expect(count(array_unique($flavors)))->toBeGreaterThanOrEqual(30);
});

it('falls back to a flat post-run speech when the activity detail is missing', function (): void {
    $speech = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PostRunSpeech, 999_999));

    expect($speech)->toBe("Done. That one's in the log.");
});

it('reads the run-insight claims off the run itself, not a seeded variant', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'average_cadence' => 85.0,
        'average_heartrate' => 150.0,
        'distance' => 5000.0,
        'stream_summary' => ['decoupling_pct' => 6.5],
    ]);

    $expected = RuleBasedRunInsights::claims($detail->fresh());
    $insight = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::RunInsight, $activity->id));

    expect($expected)->not->toBe([])
        ->and(json_decode($insight, true))->toBe($expected);
});

it('falls back to an empty claims list when the insight detail is missing', function (): void {
    $insight = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::RunInsight, 999_999));

    expect($insight)->toBe('[]');
});

it('weaves the snapshot real numbers into the weekly recap', function (): void {
    $snapshot = WeeklySnapshot::factory()->create([
        'distance_km' => 24.6,
        'runs' => 4,
        'form_status' => 'fatigued',
    ]);

    $recap = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::WeeklyRecap, $snapshot->id));

    expect($recap)->toContain('24.6')
        ->and($recap)->toMatch('/\b4 (runs|sessions|times)\b/')
        ->and($recap)->toContain('recovery next week');
});

/**
 * A ran week with a null form_status means the training-load rollup never
 * reached this snapshot before the recap read it (#1010) — the caller
 * (KickoffWeeklyRecaps / RecapHydrationReadiness) is expected to hold the
 * week back until then, so a null here past that gate must be loud rather
 * than a silently generic "steady. that's the read." closer.
 */
it('surfaces a ran week with a missing form_status instead of silently defaulting', function (): void {
    Log::spy();

    $snapshot = WeeklySnapshot::factory()->create([
        'distance_km' => 44.7,
        'runs' => 5,
        'form_status' => null,
    ]);

    $recap = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::WeeklyRecap, $snapshot->id));

    expect($recap)->toContain("steady. that's the read.");
    Log::shouldHaveReceived('warning')
        ->with('narrator.recap.form_status_missing', Mockery::on(
            fn (array $context): bool => $context['snapshot_id'] === $snapshot->id && $context['runs'] === 5,
        ))
        ->once();
});

it('does not log for a normally-resolved form_status', function (): void {
    Log::spy();

    $snapshot = WeeklySnapshot::factory()->create([
        'distance_km' => 24.6,
        'runs' => 4,
        'form_status' => 'optimal',
    ]);

    app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::WeeklyRecap, $snapshot->id));

    Log::shouldNotHaveReceived('warning');
});

it('does not praise a week that is well below the athlete\'s usual', function (): void {
    $userId = WeeklySnapshot::factory()->create(['week_ending' => '2026-08-02', 'distance_km' => 30.0])->user_id;
    WeeklySnapshot::factory()->create(['user_id' => $userId, 'week_ending' => '2026-08-09', 'distance_km' => 32.0]);
    WeeklySnapshot::factory()->create(['user_id' => $userId, 'week_ending' => '2026-08-16', 'distance_km' => 28.0]);

    $snapshot = WeeklySnapshot::factory()->create([
        'user_id' => $userId,
        'week_ending' => '2026-08-23',
        'distance_km' => 1.3,
        'runs' => 1,
        'form_status' => 'optimal',
    ]);

    $recap = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::WeeklyRecap, $snapshot->id));

    expect($recap)->not->toContain("that's the range where the work actually banks.")
        ->and($recap)->not->toContain('above your usual week')
        ->and($recap)->toContain('not a verdict on it');
});

it('keeps the banking line for a week in the athlete\'s usual range', function (): void {
    $userId = WeeklySnapshot::factory()->create(['week_ending' => '2026-08-02', 'distance_km' => 30.0])->user_id;
    WeeklySnapshot::factory()->create(['user_id' => $userId, 'week_ending' => '2026-08-09', 'distance_km' => 32.0]);
    WeeklySnapshot::factory()->create(['user_id' => $userId, 'week_ending' => '2026-08-16', 'distance_km' => 28.0]);

    $snapshot = WeeklySnapshot::factory()->create([
        'user_id' => $userId,
        'week_ending' => '2026-08-23',
        'distance_km' => 29.0,
        'runs' => 4,
        'form_status' => 'optimal',
    ]);

    $recap = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::WeeklyRecap, $snapshot->id));

    expect($recap)->toContain("that's the range where the work actually banks.");
});

it('reads a week well above the athlete\'s usual as a big week', function (): void {
    $userId = WeeklySnapshot::factory()->create(['week_ending' => '2026-08-02', 'distance_km' => 20.0])->user_id;
    WeeklySnapshot::factory()->create(['user_id' => $userId, 'week_ending' => '2026-08-09', 'distance_km' => 22.0]);
    WeeklySnapshot::factory()->create(['user_id' => $userId, 'week_ending' => '2026-08-16', 'distance_km' => 18.0]);

    $snapshot = WeeklySnapshot::factory()->create([
        'user_id' => $userId,
        'week_ending' => '2026-08-23',
        'distance_km' => 45.0,
        'runs' => 6,
        'form_status' => 'optimal',
    ]);

    $recap = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::WeeklyRecap, $snapshot->id));

    expect($recap)->not->toContain("that's the range where the work actually banks.")
        ->and($recap)->toContain('above your usual week');
});

it('pluralizes run and session for a single-run week', function (): void {
    $snapshot = WeeklySnapshot::factory()->create([
        'distance_km' => 5.0,
        'runs' => 1,
        'form_status' => 'fresh',
    ]);

    $recap = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::WeeklyRecap, $snapshot->id));

    expect($recap)->toMatch('/\b1 (run|session|time)\b/')
        ->and($recap)->not->toMatch('/\b1 (runs|sessions|times)\b/');
});

it('phrases an intent hit as the session doing its job', function (): void {
    $session = PlannedSession::factory()->create([
        'session_type' => 'tempo',
        'date' => '2026-05-18',
        'status' => PlannedSessionStatus::Done,
        'intent_verdict' => IntentVerdict::Hit,
    ]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18'),
    );

    expect($voice)->toMatch('/did what it was written for|right where the day asked you to be/');
});

it('phrases an intent miss as the effort not showing up', function (): void {
    $session = PlannedSession::factory()->create([
        'session_type' => 'tempo',
        'date' => '2026-05-18',
        'status' => PlannedSessionStatus::Partial,
        'intent_verdict' => IntentVerdict::Missed,
    ]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18'),
    );

    expect($voice)->toMatch('/never quite showed up|came in softer/');
});

it('phrases too-hard intent as harder than the day called for', function (): void {
    $session = PlannedSession::factory()->create([
        'session_type' => 'easy',
        'date' => '2026-05-18',
        'status' => PlannedSessionStatus::Overreached,
        'intent_verdict' => IntentVerdict::TooHard,
    ]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18'),
    );

    expect($voice)->toMatch('/harder than the day called for|more effort than this one asked for/');
});

it('phrases an unknown intent as unreadable rather than guessing', function (): void {
    $session = PlannedSession::factory()->create([
        'session_type' => 'interval',
        'date' => '2026-05-18',
        'status' => PlannedSessionStatus::Done,
        'intent_verdict' => IntentVerdict::Unknown,
    ]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18'),
    );

    expect($voice)->toMatch("/couldn't make out|not enough signal/");
});

it('phrases a rest day run anyway as worth a nod, with no intent claim', function (): void {
    $session = PlannedSession::factory()->create([
        'session_type' => 'rest',
        'date' => '2026-05-18',
        'status' => PlannedSessionStatus::Done,
        'skipped' => true,
        'ran_anyway' => true,
    ]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18'),
    );

    expect($voice)->toMatch('/anyway|off the hook/');
});

it('phrases a credited rest day with no intent claim', function (): void {
    $session = PlannedSession::factory()->create([
        'session_type' => 'rest',
        'date' => '2026-05-18',
        'status' => PlannedSessionStatus::Done,
    ]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18'),
    );

    expect($voice)->toMatch('/rest|day off/');
});

it('falls back to a generic line for a day that has not been credited', function (): void {
    $session = PlannedSession::factory()->create([
        'session_type' => 'tempo',
        'date' => '2026-05-18',
        'status' => PlannedSessionStatus::Planned,
    ]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18'),
    );

    expect($voice)->toBe('logged.');
});

it('falls back to a generic line when no PlannedSession exists for the day', function (): void {
    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, 999_999, '2026-05-18'),
    );

    expect($voice)->toBe('logged.');
});

it('names the race for a race-oriented season', function (): void {
    $race = RaceGoal::factory()->create(['name' => 'Jakarta Half']);
    $season = Season::factory()->create(['race_goal_id' => $race->id]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PlanSeasonVoice, $season->id));

    expect($voice)->toContain('Jakarta Half');
});

it('mentions the goal only once sustained-ahead has actually held', function (): void {
    $race = RaceGoal::factory()->create(['name' => 'Jakarta Half']);
    $season = Season::factory()->create(['race_goal_id' => $race->id]);
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

    PlanAdaptation::factory()->for($season->user)->create([
        'week_start' => $weekStart->toDateString(),
        'reason' => AdaptationReason::AheadOfRacePace,
    ]);

    $notYetSustained = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PlanSeasonVoice, $season->id));
    expect($notYetSustained)->not->toContain('worth revisiting')
        ->and($notYetSustained)->not->toContain('second look');

    PlanAdaptation::factory()->for($season->user)->create([
        'week_start' => $weekStart->copy()->subWeek()->toDateString(),
        'reason' => AdaptationReason::AheadOfRacePace,
    ]);

    $sustained = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanSeasonVoice, $season->id, 'again'),
    );
    expect($sustained)->toContain('Jakarta Half')
        ->and($sustained)->toMatch('/worth revisiting|second look/');
});

it('frames a self-scaled season as base-building, not a countdown', function (): void {
    $season = Season::factory()->create(['race_goal_id' => null]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PlanSeasonVoice, $season->id));

    expect($voice)->toMatch('/no race on the books|self-scaled block/');
});

it('adds a real-signal coda to the post-run speech (negative split)', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'distance' => 8000.0,
        'stream_summary' => ['negative_split' => true],
        'weather_temp_c' => 24,
        'weather_rain_detected' => false,
    ]);

    $speech = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PostRunSpeech, $activity->id));

    expect($speech)->toContain('8.0 km')
        ->and($speech)->toContain('Second half actually got faster');
});

it('uses km-less flavor templates when the card has no distance', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create(['activity_id' => $activity->id, 'distance' => null]);
    $card = RunCard::factory()->create([
        'activity_id' => $activity->id,
        'rarity' => Rarity::Common,
        'special_move' => 'Langkah Tenang',
        'badges' => [],
    ]);

    $flavor = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    // No GPS distance, so no rendered "km" number leaks into the copy.
    expect($flavor)->not->toContain('km')
        ->and($flavor)->toContain('Langkah Tenang');
});

it('omits the badge coda when the card carries only unknown badges', function (): void {
    $known = seededCard(Rarity::Rare, 'Known session', [Badge::Speedster->value]);
    $unknown = seededCard(Rarity::Rare, 'Mystery session', ['not_a_real_badge']);
    $filler = app(RuleBasedNarrationFiller::class);

    $withCoda = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $known->id));
    $withoutCoda = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $unknown->id));

    // Known badge appends a coda sentence; unknown badge appends nothing, so the
    // bare-base copy is strictly shorter than its sibling's badge-decorated copy.
    expect($withCoda)->toContain('Sub-5 per km.')
        ->and($withoutCoda)->not->toContain('Sub-5 per km.');
});

it('keeps all copy free of em-dashes', function (): void {
    $card = seededCard(Rarity::Legendary, 'Personal Best', [Badge::LongSlowDistance->value], 42_195.0);
    $filler = app(RuleBasedNarrationFiller::class);

    $session = PlannedSession::factory()->create(['session_type' => 'long', 'date' => '2026-05-18']);
    $season = Season::factory()->create();

    $samples = [
        $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id)),
        $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, $card->id)),
        $filler->fillFor(fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18')),
        $filler->fillFor(fillerRow(AnalysisType::PlanSeasonVoice, $season->id)),
    ];

    foreach ($samples as $sample) {
        expect($sample)->not->toContain('—');
    }
});
