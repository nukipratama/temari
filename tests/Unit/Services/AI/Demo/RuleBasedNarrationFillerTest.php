<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Services\AI\AnalysisType;
use App\Services\AI\Demo\RuleBasedNarrationFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fillerRow(AnalysisType $type, int $subjectId): Analysis
{
    $row = new Analysis();
    $row->analysis_type = $type;
    $row->subject_id = $subjectId;

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

    $flavor = (new RuleBasedNarrationFiller())->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    // Every template carries either the move name or the formatted distance.
    expect($flavor === '' ? '' : $flavor)
        ->toBeString()
        ->and(str_contains($flavor, 'Threshold Hold') || str_contains($flavor, '10.0'))
        ->toBeTrue();
});

it('is deterministic for the same card', function (): void {
    $card = seededCard(Rarity::Rare, 'Steady Tempo');
    $filler = new RuleBasedNarrationFiller();

    $first = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));
    $second = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    expect($first)->toBe($second);
});

it('varies the flavor across rarities', function (): void {
    $filler = new RuleBasedNarrationFiller();
    $flavors = collect(Rarity::cases())
        ->map(fn (Rarity $r): RunCard => seededCard($r, $r->value . ' Move'))
        ->map(fn (RunCard $c): string => $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $c->id)));

    // Distinct pools per rarity + distinct moves → no two cards read the same.
    expect($flavors->unique()->count())->toBe(5);
});

it('appends a badge coda when the card carries a known badge', function (): void {
    $card = seededCard(Rarity::Uncommon, 'Closing Kick', [RunCard::BADGE_NEGATIVE_SPLIT]);

    $flavor = (new RuleBasedNarrationFiller())->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    expect($flavor)->toContain('Paruh kedua');
});

it('falls back to a flat line when the card is missing', function (): void {
    $flavor = (new RuleBasedNarrationFiller())->fillFor(fillerRow(AnalysisType::CardFlavor, 999_999));

    expect($flavor)->toBe('Kartu ini lahir dari sesi yang tenang tapi solid.');
});

it('varies the ecosystem briefing voices by seed deterministically', function (): void {
    $filler = new RuleBasedNarrationFiller();

    $voiceA = $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, 1));
    $voiceB = $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, 2));
    $voiceAAgain = $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, 1));

    expect($voiceA)->toBe($voiceAAgain)
        ->and($voiceA)->not->toBe($voiceB);
});

it('keeps all copy free of em-dashes', function (): void {
    $card = seededCard(Rarity::Legendary, 'Marathon Perdana', [RunCard::BADGE_LONG_SLOW_DISTANCE], 42_195.0);
    $filler = new RuleBasedNarrationFiller();

    $samples = [
        $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id)),
        $filler->fillFor(fillerRow(AnalysisType::BriefingMascotVoice, $card->id)),
        $filler->fillFor(fillerRow(AnalysisType::BriefingFeaturedKartuVoice, $card->id)),
    ];

    foreach ($samples as $sample) {
        expect($sample)->not->toContain('—');
    }
});
