<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Services\AI\AnalysisType;
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
    $card = seededCard(Rarity::Uncommon, 'Closing Kick', [Badge::NegativeSplit->value]);

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

it('varies discriminator-keyed copy across discriminators for the same subject', function (): void {
    $filler = new RuleBasedNarrationFiller();

    // Same subject, different month discriminators must not read byte-identical.
    $january = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 1, '2026-02'));
    $may = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 1, '2026-05'));

    expect($january)->not->toBe($may);
});

it('is deterministic for the same subject and discriminator', function (): void {
    $filler = new RuleBasedNarrationFiller();

    $first = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 7, '2026-03'));
    $second = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 7, '2026-03'));

    expect($first)->toBe($second);
});

it('keeps the subject-only seed when the discriminator is null', function (): void {
    $filler = new RuleBasedNarrationFiller();

    // A null discriminator must leave the seed equal to subject_id so existing
    // non-discriminated determinism (and the first-variant default) is preserved.
    $copy = $filler->fillFor(fillerRow(AnalysisType::MonthlyRecap, 0, null));

    expect($copy)->toBe('Bulan ini ritme kamu jalan terus. Gak ngotot, gak juga ngilang. Konsisten yang aku suka.');
});

it('returns deterministic copy for every subject-free analysis arm', function (AnalysisType $type, string $expected): void {
    $copy = (new RuleBasedNarrationFiller())->fillFor(fillerRow($type, 0));

    expect($copy)->toBe($expected);
})->with([
    'briefing headline' => [AnalysisType::BriefingHeadline, 'Kondisi kamu hari ini **stabil**, kapasitas cukup buat sesi ringan sampai sedang.'],
    'briefing suggestion' => [AnalysisType::BriefingSuggestion, "Tempo ringan, 35-45 menit.\n\nWarmup 10 menit santai, tempo 15-20 menit di zona 3 atas, terus cooldown. Jaga cadence di 175+, napas terengah-engah tapi masih bisa potong kalimat.\n\nYang perlu diperhatikan: kalau HR cepat naik padahal pelan, mundur ke run-walk 15-25 menit atau berhenti di cooldown. Cuaca terasa panas atau badan masih lemes, rest juga tidak rugi."],
    'daily greeting' => [AnalysisType::DailyGreeting, 'Halo. Semoga harimu tenang, kapanpun kamu siap lari aku nunggu.'],
    'run insight splits' => [AnalysisType::RunInsightSplits, 'Pacing kamu cenderung stabil dari awal sampai akhir. Negative split kecil di bagian akhir lebih baik daripada positive split besar.'],
    'run insight zones' => [AnalysisType::RunInsightZones, 'Distribusi zone-nya didominasi easy/zone 2. Cocok buat base building, gak overstrain.'],
    'weekly recap' => [AnalysisType::WeeklyRecap, 'Minggu ini ritme kamu cukup teratur. Volume lari masuk akal, recovery juga keurus.'],
    'pr context' => [AnalysisType::PrContext, 'PR-nya hasil dari konsistensi minggu-minggu sebelumnya, bukan kebetulan.'],
    'trend caption' => [AnalysisType::TrendCaption, 'Tren beberapa minggu terakhir relatif rata. Solid base.'],
    'persona summary' => [AnalysisType::PersonaSummary, 'Pola lari kamu cenderung easy-dominan, sesekali quality. Tipe runner yang ngebangun pelan-pelan.'],
    'aku profile voice' => [AnalysisType::AkuProfileVoice, 'Aku catat semua perjalanan kamu di sini: **kartu**, **rekor**, **aksesori**, ceritanya. Ayo terus jalan.'],
    'monthly recap' => [AnalysisType::MonthlyRecap, 'Bulan ini ritme kamu jalan terus. Gak ngotot, gak juga ngilang. Konsisten yang aku suka.'],
]);

it('weaves the run distance into the post-run speech', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create(['activity_id' => $activity->id, 'distance' => 5500.0]);

    $speech = (new RuleBasedNarrationFiller())->fillFor(fillerRow(AnalysisType::PostRunSpeech, $activity->id));

    expect($speech)->toContain('5.5 km');
});

it('falls back to a flat post-run speech when the activity detail is missing', function (): void {
    $speech = (new RuleBasedNarrationFiller())->fillFor(fillerRow(AnalysisType::PostRunSpeech, 999_999));

    expect($speech)->toBe('Selesai juga. Konsisten kayak gini yang aku suka.');
});

it('reports cadence and HR in the technical insight', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'average_cadence' => 85.0,
        'average_heartrate' => 150.0,
    ]);

    $insight = (new RuleBasedNarrationFiller())->fillFor(fillerRow(AnalysisType::RunInsightTechnical, $activity->id));

    expect($insight)->toContain('cadence rata-rata 170')
        ->and($insight)->toContain('HR rata-rata 150');
});

it('falls back when the technical insight has no cadence or HR', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'average_cadence' => null,
        'average_heartrate' => null,
    ]);

    $insight = (new RuleBasedNarrationFiller())->fillFor(fillerRow(AnalysisType::RunInsightTechnical, $activity->id));

    expect($insight)->toBe('Sesi ini metrik-nya konsisten.');
});

it('falls back to a flat line when the technical insight detail is missing', function (): void {
    $insight = (new RuleBasedNarrationFiller())->fillFor(fillerRow(AnalysisType::RunInsightTechnical, 999_999));

    expect($insight)->toBe('Detail teknis-nya belum kebaca lengkap.');
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

    $flavor = (new RuleBasedNarrationFiller())->fillFor(fillerRow(AnalysisType::CardFlavor, $card->id));

    // No GPS distance, so no rendered "km" number leaks into the copy.
    expect($flavor)->not->toContain('km')
        ->and($flavor)->toContain('Langkah Tenang');
});

it('omits the badge coda when the card carries only unknown badges', function (): void {
    $known = seededCard(Rarity::Rare, 'Sesi Dikenal', [Badge::Kilat->value]);
    $unknown = seededCard(Rarity::Rare, 'Sesi Misteri', ['not_a_real_badge']);
    $filler = new RuleBasedNarrationFiller();

    $withCoda = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $known->id));
    $withoutCoda = $filler->fillFor(fillerRow(AnalysisType::CardFlavor, $unknown->id));

    // Known badge appends a coda sentence; unknown badge appends nothing, so the
    // bare-base copy is strictly shorter than its sibling's badge-decorated copy.
    expect($withCoda)->toContain('Pace di bawah 5 per km, kencang.')
        ->and($withoutCoda)->not->toContain('Pace di bawah 5 per km, kencang.');
});

it('keeps all copy free of em-dashes', function (): void {
    $card = seededCard(Rarity::Legendary, 'Marathon Perdana', [Badge::LongSlowDistance->value], 42_195.0);
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
