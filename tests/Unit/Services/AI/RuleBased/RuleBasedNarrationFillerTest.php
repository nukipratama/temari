<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\RunCard;
use App\Models\Season;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\AI\RuleBased\RuleBasedRunInsights;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('varies discriminator-keyed copy across discriminators for the same subject', function (): void {
    $filler = app(RuleBasedNarrationFiller::class);

    // The discriminator folds into the seed, so the same subject reads
    // differently across months. Assert variety across a spread of discriminators
    // (robust to pool size) rather than two specific months, which can collide
    // on the modulo for any given pool count.
    $copies = collect(['2026-02', '2026-03', '2026-05', '2026-08', '2026-11'])
        ->map(fn (string $month): string => $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 1, $month)))
        ->unique();

    expect($copies->count())->toBeGreaterThan(1);
});

it('is deterministic for the same subject and discriminator', function (): void {
    $filler = app(RuleBasedNarrationFiller::class);

    $first = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 7, '2026-03'));
    $second = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 7, '2026-03'));

    expect($first)->toBe($second);
});

it('keeps the subject-only seed when the discriminator is null', function (): void {
    $filler = app(RuleBasedNarrationFiller::class);

    // A null discriminator must leave the seed equal to subject_id so existing
    // non-discriminated determinism (and the first-variant default) is preserved.
    $copy = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 0, null));

    expect($copy)->toBe("The rhythm held all month. You didn't force it and you didn't disappear either.");
});

it('returns deterministic copy for every subject-free analysis arm', function (AnalysisType $type, string $expected): void {
    $copy = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow($type, 0));

    expect($copy)->toBe($expected);
})->with([
    'briefing mascot voice' => [AnalysisType::BriefingMascotVoice, "Easy tempo, 35-45 minutes.\n\nnothing quality has gone into the log since last week and your rhythm's been flat and steady the whole time, so today's the day to break that up. 10 minutes easy to warm up, 15-20 minutes a bit quicker than your usual pace, then cool down. cadence 175+.\n\nWhat to watch: if HR climbs fast at easy pace, drop it to a 15-25 minute run-walk and stop at the cooldown. Brutal heat is reason enough to run the whole thing easy instead."],
    'run insight (no detail)' => [AnalysisType::RunInsight, '[]'],
    'weekly recap' => [AnalysisType::WeeklyRecap, "Nothing in the log this week. A gap is a gap, I'm not going to call it anything else."],
    'pr context' => [AnalysisType::PrContext, "That's a new PR. The old number held until today, and now it doesn't."],
    'profile voice' => [AnalysisType::ProfileVoice, "You lean **chill** far more than pushed, and the log backs it up: regular, unhurried, never a big jump. That's a base built the slow way. The open question is when you decide to spend it."],
    'monthly recap' => [AnalysisType::MonthlyRecap, "The rhythm held all month. You didn't force it and you didn't disappear either."],
    'trend read' => [AnalysisType::TrendRead, "Steady is the read.\n\nNothing in this window moved sharply enough to call out on its own. The rhythm held, which is its own kind of answer."],
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

it('narrates the day\'s prescribed session type', function (): void {
    $session = PlannedSession::factory()->create(['session_type' => 'long', 'date' => '2026-05-18']);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18'),
    );

    expect($voice)->toMatch('/long run|the long one/');
});

it('narrates a skipped day as excused, not as its original session', function (): void {
    $session = PlannedSession::factory()->create(['session_type' => 'tempo', 'date' => '2026-05-18', 'skipped' => true]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18'),
    );

    expect($voice)->toMatch('/skipped|excused/')
        ->and($voice)->not->toContain('tempo');
});

it('falls back to a generic line when no PlannedSession exists for the day', function (): void {
    $voice = app(RuleBasedNarrationFiller::class)->fillFor(
        fillerRow(AnalysisType::PlanDayVoice, 999_999, '2026-05-18'),
    );

    expect($voice)->toBe("today's plan.");
});

it('names the week as lighter when the adaptation is a deload', function (): void {
    $adaptation = PlanAdaptation::factory()->create(['deload' => true]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PlanWeekVoice, $adaptation->id));

    expect($voice)->toMatch('/lighter|deload/');
});

it('names a steady week when the adaptation is not a deload', function (): void {
    $adaptation = PlanAdaptation::factory()->create(['deload' => false]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PlanWeekVoice, $adaptation->id));

    expect($voice)->not->toMatch('/lighter|deload/');
});

it('names the race for a race-oriented season', function (): void {
    $race = RaceGoal::factory()->create(['name' => 'Jakarta Half']);
    $season = Season::factory()->create(['race_goal_id' => $race->id]);

    $voice = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PlanSeasonVoice, $season->id));

    expect($voice)->toContain('Jakarta Half');
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
    $adaptation = PlanAdaptation::factory()->create(['deload' => true]);
    $season = Season::factory()->create();

    $samples = [
        $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id)),
        $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, $card->id)),
        $filler->fillFor(fillerRow(AnalysisType::PlanDayVoice, $session->user_id, '2026-05-18')),
        $filler->fillFor(fillerRow(AnalysisType::PlanWeekVoice, $adaptation->id)),
        $filler->fillFor(fillerRow(AnalysisType::PlanSeasonVoice, $season->id)),
    ];

    foreach ($samples as $sample) {
        expect($sample)->not->toContain('—');
    }
});
