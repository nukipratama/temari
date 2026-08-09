<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
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
        ->and(str_contains($flavor, 'Threshold Hold') || str_contains($flavor, '10,0'))
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

it('appends the lawan_angin badge coda', function (): void {
    $card = seededCard(Rarity::Uncommon, 'Wind Breaker', [Badge::LawanAngin->value]);

    $flavor = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    expect($flavor)->toContain("Strong wind didn't slow you down.");
});

it('falls back to a flat line when the card is missing', function (): void {
    $flavor = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::CardFlavor, 999_999));

    expect($flavor)->toBe('This card was born from a quiet but solid session.');
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

    expect($copy)->toBe('Your rhythm kept going this month. Not forcing it, not disappearing either. The kind of consistent I like to see.');
});

it('returns deterministic copy for every subject-free analysis arm', function (AnalysisType $type, string $expected): void {
    $copy = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow($type, 0));

    expect($copy)->toBe($expected);
})->with([
    'briefing mascot voice' => [AnalysisType::BriefingMascotVoice, "Easy tempo, 35-45 minutes.\n\nYour rhythm's read steady these past few weeks and there hasn't been a quality session since last week, so today I think there's room for a tempo. 10-minute easy warmup, 15-20 minute tempo a bit faster than your average pace, then cooldown, cadence at 175+.\n\nWhat to watch: if HR climbs fast even at an easy pace, back off to a 15-25 minute run-walk or stop at the cooldown. If the weather's hot or you're still feeling wiped, resting isn't a loss either."],
    'run insight splits (no detail)' => [AnalysisType::RunInsightSplits, "The splits aren't fully readable yet."],
    'run insight zones (no detail)' => [AnalysisType::RunInsightZones, "The zone breakdown isn't fully readable yet."],
    'weekly recap' => [AnalysisType::WeeklyRecap, 'Your rhythm was pretty steady this week. Volume was reasonable, recovery got taken care of too.'],
    'pr context' => [AnalysisType::PrContext, 'This PR is the result of consistency over the past few weeks, not luck.'],
    'aku profile voice' => [AnalysisType::AkuProfileVoice, "Your runs lean more **adem** than pushed, and it shows in how it adds up: slow, regular, never a big jump. The type who builds a base patiently. Keep the rhythm going, I'm tracking all of it here."],
    'monthly recap' => [AnalysisType::MonthlyRecap, 'Your rhythm kept going this month. Not forcing it, not disappearing either. The kind of consistent I like to see.'],
]);

it('weaves the run distance into the post-run speech', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create(['activity_id' => $activity->id, 'distance' => 5500.0]);

    $speech = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::PostRunSpeech, $activity->id));

    expect($speech)->toContain('5,5 km');
});

it('does not cycle the post-run speech in lockstep with consecutive activity ids', function (): void {
    // Regression for the demo feed's most visible defect: with the raw
    // sequential activity id as the pool seed, every 6th run in the Riwayat
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

    expect($speech)->toBe('Done. This is the kind of consistency I like to see.');
});

it('reads the run-insight types off the run itself, not a seeded variant', function (AnalysisType $type): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'average_cadence' => 85.0,
        'average_heartrate' => 150.0,
        'distance' => 5000.0,
    ]);

    $insights = app(RuleBasedRunInsights::class);
    $expected = match ($type) {
        AnalysisType::RunInsightTechnical => $insights->technical($detail->fresh()),
        AnalysisType::RunInsightSplits => $insights->splits($detail->fresh()),
        AnalysisType::RunInsightZones => $insights->zones($detail->fresh()),
    };

    $insight = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow($type, $activity->id));

    expect($insight)->toBe($expected);
})->with([
    'technical' => [AnalysisType::RunInsightTechnical],
    'splits' => [AnalysisType::RunInsightSplits],
    'zones' => [AnalysisType::RunInsightZones],
]);

it('falls back to a flat line when the insight detail is missing', function (AnalysisType $type, string $expected): void {
    $insight = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow($type, 999_999));

    expect($insight)->toBe($expected);
})->with([
    'technical' => [AnalysisType::RunInsightTechnical, "The technical detail isn't fully readable yet."],
    'splits' => [AnalysisType::RunInsightSplits, "The splits aren't fully readable yet."],
    'zones' => [AnalysisType::RunInsightZones, "The zone breakdown isn't fully readable yet."],
]);

it('weaves the snapshot real numbers into the weekly recap', function (): void {
    $snapshot = WeeklySnapshot::factory()->create([
        'distance_km' => 24.6,
        'runs' => 4,
        'form_status' => 'fatigued',
    ]);

    $recap = app(RuleBasedNarrationFiller::class)->fillFor(fillerRow(AnalysisType::WeeklyRecap, $snapshot->id));

    expect($recap)->toContain('24,6')
        ->and($recap)->toContain('4')
        ->and($recap)->toContain('recovery next week');
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

    expect($speech)->toContain('8,0 km')
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
    $known = seededCard(Rarity::Rare, 'Sesi Dikenal', [Badge::Kilat->value]);
    $unknown = seededCard(Rarity::Rare, 'Sesi Misteri', ['not_a_real_badge']);
    $filler = app(RuleBasedNarrationFiller::class);

    $withCoda = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $known->id));
    $withoutCoda = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $unknown->id));

    // Known badge appends a coda sentence; unknown badge appends nothing, so the
    // bare-base copy is strictly shorter than its sibling's badge-decorated copy.
    expect($withCoda)->toContain('Sub-5 pace per km, fast.')
        ->and($withoutCoda)->not->toContain('Sub-5 pace per km, fast.');
});

it('keeps all copy free of em-dashes', function (): void {
    $card = seededCard(Rarity::Legendary, 'Marathon Perdana', [Badge::LongSlowDistance->value], 42_195.0);
    $filler = app(RuleBasedNarrationFiller::class);

    $samples = [
        $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id)),
        $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, $card->id)),
        $filler->fillFor(fillerRow(AnalysisType::BriefingFeaturedKartuVoice, $card->id)),
    ];

    foreach ($samples as $sample) {
        expect($sample)->not->toContain('—');
    }
});
