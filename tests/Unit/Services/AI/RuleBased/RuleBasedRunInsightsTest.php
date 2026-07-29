<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\AI\RuleBased\RuleBasedRunInsights;

function insights(): RuleBasedRunInsights
{
    return new RuleBasedRunInsights();
}

function detailWith(array $attributes): ActivityDetail
{
    return new ActivityDetail(['activity_id' => 1, ...$attributes]);
}

// ── technical ────────────────────────────────────────────────────────

it('labels cadence against the doubled spm bands', function (float $cadence, string $expected): void {
    expect(insights()->technical(detailWith(['average_cadence' => $cadence])))->toContain($expected);
})->with([
    'ideal' => [92.0, 'cadence 184 spm (ideal)'],
    'moderate' => [86.0, 'cadence 172 spm (lumayan)'],
    'low' => [81.0, 'cadence 162 spm (masih bisa dinaikin)'],
    'very low' => [75.0, 'cadence 150 spm (cukup rendah)'],
]);

it('reads average HR as a share of the run peak', function (float $avg, int $max, string $expected): void {
    expect(insights()->technical(detailWith(['average_heartrate' => $avg, 'max_heartrate' => $max])))
        ->toContain($expected);
})->with([
    'easy' => [120.0, 190, 'HR rata-rata 120 (zona nyaman)'],
    'moderate' => [145.0, 190, 'HR rata-rata 145 (zona sedang)'],
    'hard' => [165.0, 190, 'HR rata-rata 165 (intens tinggi)'],
    'maximal' => [180.0, 190, 'HR rata-rata 180 (sangat intens)'],
]);

it('states HR without a label when the run carries no peak to compare against', function (): void {
    expect(insights()->technical(detailWith(['average_heartrate' => 150.0, 'max_heartrate' => null])))
        ->toContain('HR rata-rata 150')
        ->not->toContain('zona');
});

it('blames the heat rather than the aerobic base when a hot run decouples', function (): void {
    $hot = detailWith([
        'stream_summary' => ['decoupling_pct' => 8.4],
        'weather_temp_c' => 33,
    ]);
    $mild = detailWith([
        'stream_summary' => ['decoupling_pct' => 8.4],
        'weather_temp_c' => 24,
    ]);

    expect(insights()->technical($hot))->toContain('tapi wajar soalnya tadi panas ~33°C')
        ->and(insights()->technical($mild))->toContain('aerobik base belum solid');
});

it('calls moderate decoupling normal and stays silent below the floor', function (): void {
    expect(insights()->technical(detailWith(['stream_summary' => ['decoupling_pct' => 3.2]])))
        ->toContain('decoupling +3,2%, masih wajar')
        ->and(insights()->technical(detailWith(['stream_summary' => ['decoupling_pct' => 1.1]])))
        ->not->toContain('decoupling');
});

it('mentions elevation only once the climb is worth mentioning', function (): void {
    expect(insights()->technical(detailWith(['total_elevation_gain' => 120.0])))
        ->toContain('elevation gain 120m')
        ->and(insights()->technical(detailWith(['total_elevation_gain' => 30.0])))
        ->not->toContain('elevation');
});

it('falls back to a flat line when the run offers no notable metric', function (): void {
    expect(insights()->technical(detailWith([])))
        ->toBe('Sesi ini metrik-nya konsisten, gak ada yang mencolok.');
});

it('varies the opener frame by activity so identical runs do not all read alike', function (): void {
    $frames = [];
    foreach (range(1, 4) as $activityId) {
        $detail = new ActivityDetail(['activity_id' => $activityId, 'average_cadence' => 90.0]);
        $frames[] = insights()->technical($detail);
    }

    expect(array_unique($frames))->toHaveCount(4);
});

// ── splits ───────────────────────────────────────────────────────────

it('reports a negative split straight off the summary flag', function (): void {
    $detail = detailWith(['stream_summary' => [
        'negative_split' => true,
        'per_km' => [['km' => 1, 'pace' => '6:00'], ['km' => 2, 'pace' => '5:30']],
    ]]);

    expect(insights()->splits($detail))->toContain('Negative split, paruh kedua lebih cepat dari awal.');
});

it('detects a positive split from the per-km paces when the flag is absent', function (): void {
    $detail = detailWith(['stream_summary' => ['per_km' => [
        ['km' => 1, 'pace' => '5:00'],
        ['km' => 2, 'pace' => '5:05'],
        ['km' => 3, 'pace' => '6:00'],
        ['km' => 4, 'pace' => '6:10'],
    ]]]);

    expect(insights()->splits($detail))->toContain('Positive split, pace melambat di paruh kedua.');
});

it('calls an even effort merata and then skips restating consistency', function (): void {
    $detail = detailWith(['stream_summary' => [
        'per_km' => [['km' => 1, 'pace' => '5:00'], ['km' => 2, 'pace' => '5:01']],
        'pace_variability_sec' => 2.0,
    ]]);

    $splits = insights()->splits($detail);

    expect($splits)->toContain('Pacing cukup merata dari awal sampai akhir.')
        ->and($splits)->not->toContain('konsistensi pace');
});

it('describes the km spread by how wide it actually is', function (array $perKm, string $expected): void {
    expect(insights()->splits(detailWith(['stream_summary' => ['per_km' => $perKm]])))->toContain($expected);
})->with([
    'wide' => [[
        ['km' => 1, 'pace' => '5:00'],
        ['km' => 2, 'pace' => '5:20'],
        ['km' => 3, 'pace' => '5:45'],
    ], 'Km 1 tercepat (5:00), km 3 paling lambat, selisih cukup besar.'],
    'noticeable' => [[
        ['km' => 1, 'pace' => '5:00'],
        ['km' => 2, 'pace' => '5:10'],
        ['km' => 3, 'pace' => '5:20'],
    ], 'Km 1 tercepat, gap-nya wajar.'],
    'tight' => [[
        ['km' => 1, 'pace' => '5:00'],
        ['km' => 2, 'pace' => '5:05'],
        ['km' => 3, 'pace' => '5:08'],
    ], 'Gap antar km sangat kecil.'],
]);

it('notes the trailing partial segment as a finish, not a full km', function (): void {
    $detail = detailWith(['stream_summary' => [
        'partial_split' => ['distance_m' => 700, 'pace' => '5:12'],
    ]]);

    expect(insights()->splits($detail))->toBe('Sisa 0,7 km ditutup di 5:12.');
});

it('praises pace consistency when the run was not already called even', function (): void {
    $detail = detailWith(['stream_summary' => [
        'negative_split' => true,
        'per_km' => [['km' => 1, 'pace' => '6:00'], ['km' => 2, 'pace' => '5:30']],
        'pace_variability_sec' => 1.0,
    ]]);

    expect(insights()->splits($detail))->toContain('Konsistensi pace sangat bagus.');
});

it('gives up honestly when the run has no split data', function (): void {
    expect(insights()->splits(detailWith([])))->toBe('Data split belum cukup buat dianalisis.');
});

// ── zones ────────────────────────────────────────────────────────────

it('names the dominant zone, calling it out when it truly dominates', function (array $zones, string $expected): void {
    expect(insights()->zones(detailWith(['stream_summary' => ['time_in_zone_pct' => $zones]])))
        ->toContain($expected);
})->with([
    'dominant' => [['Z1' => 5.0, 'Z2' => 85.0, 'Z3' => 10.0], '85% di Z2'],
    'merely leading' => [['Z1' => 5.0, 'Z2' => 55.0, 'Z3' => 40.0], 'Didominasi Z2 (55%)'],
]);

it('reads zone discipline off the easy and hard shares', function (array $zones, string $expected): void {
    expect(insights()->zones(detailWith(['stream_summary' => ['time_in_zone_pct' => $zones]])))
        ->toContain($expected);
})->with([
    'base building' => [['Z1' => 20.0, 'Z2' => 65.0, 'Z3' => 15.0], 'base building proper, mayoritas easy'],
    'balanced' => [['Z1' => 10.0, 'Z2' => 55.0, 'Z3' => 35.0], 'kombinasi easy dan moderate, seimbang'],
    'too hard' => [['Z2' => 30.0, 'Z3' => 40.0, 'Z4' => 30.0], 'intensitas tinggi, hati-hati overstrain'],
    'some quality' => [['Z2' => 55.0, 'Z3' => 35.0, 'Z4' => 5.0], 'ada porsi quality yang cukup'],
]);

it('flags a heavy Z5 share as something to recover from', function (): void {
    $detail = detailWith(['stream_summary' => ['time_in_zone_pct' => [
        'Z2' => 40.0, 'Z3' => 25.0, 'Z4' => 20.0, 'Z5' => 15.0,
    ]]]);

    expect(insights()->zones($detail))->toContain('Z5 cukup banyak, pastikan recovery cukup');
});

it('derives zone shares from minutes when percentages are missing', function (): void {
    $detail = detailWith(['stream_summary' => ['time_in_zone_min' => [
        'Z1' => 5.0, 'Z2' => 35.0, 'Z3' => 10.0,
    ]]]);

    expect(insights()->zones($detail))->toContain('70% di Z2');
});

it('says so plainly when there is no heart-rate data at all', function (): void {
    expect(insights()->zones(detailWith([])))->toBe('Data heart rate zone belum tersedia.');
});
