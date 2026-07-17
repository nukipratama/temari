<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\RuleBased\RuleBasedInsightBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Build an Activity + ActivityDetail pair for the given user with explicit
 * detail attributes. Distance/moving_time default to a 10km @ 5:00/km run.
 *
 * @param  array<string, mixed>  $detailAttrs
 */
function makeRun(User $user, array $detailAttrs = []): array
{
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create(array_merge([
        'distance' => 10000.0,
        'moving_time' => 3000,
        'average_cadence' => null,
        'average_heartrate' => null,
        'max_heartrate' => null,
        'total_elevation_gain' => null,
        'stream_summary' => null,
    ], $detailAttrs));

    return [$activity, $detail];
}

function builder(): RuleBasedInsightBuilder
{
    return new RuleBasedInsightBuilder();
}

/**
 * runInsightSplits()/runInsightZones() only read the passed ActivityDetail's
 * own attributes, no query/relation involved, so no persisted Activity/User
 * is needed to drive them.
 *
 * @param  array<string, mixed>  $detailAttrs
 */
function makeDetail(array $detailAttrs = []): ActivityDetail
{
    return ActivityDetail::factory()->make(array_merge([
        'activity_id' => 1,
        'distance' => 10000.0,
        'moving_time' => 3000,
        'average_cadence' => null,
        'average_heartrate' => null,
        'max_heartrate' => null,
        'total_elevation_gain' => null,
        'stream_summary' => null,
    ], $detailAttrs));
}

it('returns the consistent fallback when no technical parts qualify', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user);

    expect(builder()->runInsightTechnical($activity, $detail))
        ->toBe('Sesi ini metrik-nya konsisten, gak ada yang mencolok.');
});

it('frames the technical note with one of the deterministic openers (not always "Sesi ini")', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, ['average_cadence' => 90.0]);

    $out = builder()->runInsightTechnical($activity, $detail);

    expect($out)
        ->toMatch('/^(Sesi ini|Catatan teknisnya,|Dari angka-angkanya,|Baca teknisnya:) /')
        ->toContain('cadence 180 spm')
        // Deterministic: the same activity yields the same frame on every call.
        ->and(builder()->runInsightTechnical($activity, $detail))->toBe($out);
});

it('labels cadence across every threshold band', function (float $rawCadence, string $label): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, ['average_cadence' => $rawCadence]);

    expect(builder()->runInsightTechnical($activity, $detail))
        ->toContain('cadence')
        ->toContain($label);
})->with([
    'ideal' => [92.0, 'ideal'],            // *2 = 184 >= 180
    'lumayan' => [86.0, 'lumayan'],        // *2 = 172 >= 170
    'masih bisa dinaikin' => [81.0, 'masih bisa dinaikin'], // *2 = 162 >= 160
    'cukup rendah' => [70.0, 'cukup rendah'], // *2 = 140
]);

it('reports raw HR average when max HR is missing or non-positive', function (?int $maxHr): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, [
        'average_heartrate' => 150.0,
        'max_heartrate' => $maxHr,
    ]);

    expect(builder()->runInsightTechnical($activity, $detail))
        ->toContain('HR rata-rata 150')
        ->not->toContain('zona');
})->with([
    'null max' => [null],
    'zero max' => [0],
]);

it('classifies HR reserve into every zone band', function (float $avg, int $max, string $label): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, [
        'average_heartrate' => $avg,
        'max_heartrate' => $max,
    ]);

    expect(builder()->runInsightTechnical($activity, $detail))
        ->toContain('HR rata-rata')
        ->toContain($label);
})->with([
    'zona nyaman' => [130.0, 200, 'zona nyaman'],   // 65% <= 70
    'zona sedang' => [150.0, 200, 'zona sedang'],   // 75% <= 80
    'intens tinggi' => [170.0, 200, 'intens tinggi'], // 85% <= 90
    'sangat intens' => [190.0, 200, 'sangat intens'], // 95% > 90
]);

it('flags high decoupling and wajar decoupling, and skips low decoupling', function (float $dc, ?string $expected): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, [
        'stream_summary' => ['decoupling_pct' => $dc],
        'weather_temp_c' => 20, // cool: isolates decoupling from the heat-aware softening
    ]);

    $out = builder()->runInsightTechnical($activity, $detail);
    if ($expected === null) {
        expect($out)->not->toContain('decoupling');
    } else {
        expect($out)->toContain('decoupling')->toContain($expected);
    }
})->with([
    'high' => [8.0, 'aerobik base belum solid'],
    'ok' => [3.0, 'masih wajar'],
    'low' => [1.0, null],
]);

it('softens high decoupling when the run was hot, keeps the alarm when cool', function (?int $tempC, string $expected, string $unexpected): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, [
        'stream_summary' => ['decoupling_pct' => 8.0],
        'weather_temp_c' => $tempC,
    ]);

    $out = builder()->runInsightTechnical($activity, $detail);

    expect($out)
        ->toContain('decoupling')
        ->toContain($expected)
        ->not->toContain($unexpected);
})->with([
    'hot' => [32, 'tapi wajar soalnya tadi panas ~32°C', 'aerobik base belum solid'],
    'cool' => [20, 'aerobik base belum solid', 'tapi wajar soalnya tadi panas'],
    'no weather data' => [null, 'aerobik base belum solid', 'tapi wajar soalnya tadi panas'],
]);

it('appends elevation gain when ascent exceeds 50m and skips otherwise', function (float $ascent, bool $present): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, [
        'stream_summary' => ['ascent_m' => $ascent],
    ]);

    $out = builder()->runInsightTechnical($activity, $detail);
    $present
        ? expect($out)->toContain('elevation gain')->toContain('120m')
        : expect($out)->not->toContain('elevation gain');
})->with([
    'high' => [120.0, true],
    'low' => [30.0, false],
]);

it('warns about pace variability above the high threshold', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, [
        'stream_summary' => ['pace_variability_sec' => 25.0],
    ]);

    expect(builder()->runInsightTechnical($activity, $detail))
        ->toContain('pace agak bervariasi');
});

it('compares pace against the user rolling average (faster / slower)', function (int $movingTime, string $expected): void {
    $user = User::factory()->create();

    // Seed history: 5 prior runs at 10km in 3000s => 300 sec/km average.
    foreach (range(1, 5) as $i) {
        $past = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($past)->create([
            'distance' => 10000.0,
            'moving_time' => 3000,
            'start_date_local' => Carbon::today()->subDays($i + 1),
        ]);
    }

    [$activity, $detail] = makeRun($user, [
        'moving_time' => $movingTime,
        'start_date_local' => Carbon::today(),
    ]);

    expect(builder()->runInsightTechnical($activity, $detail))->toContain($expected);
})->with([
    'faster' => [2700, 'lebih cepat dari rata-rata kamu'], // 270 sec/km, 30s faster
    'slower' => [3300, 'lebih santai dari biasanya'],       // 330 sec/km, 30s slower
]);

it('averages only the last 30 runs, excluding older ones, for the rolling pace', function (): void {
    $user = User::factory()->create();

    // 30 recent runs at 10km in 3000s => 300 sec/km. These are the window.
    foreach (range(1, 30) as $i) {
        $recent = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($recent)->create([
            'distance' => 10000.0,
            'moving_time' => 3000,
            'start_date_local' => Carbon::today()->subDays($i),
        ]);
    }

    // 10 much older, very slow runs (10km in 6000s => 600 sec/km). If these
    // leaked into the average the mean would climb toward 600 and the current
    // 300 sec/km run would read as "lebih cepat". They must be excluded.
    foreach (range(1, 10) as $i) {
        $old = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($old)->create([
            'distance' => 10000.0,
            'moving_time' => 6000,
            'start_date_local' => Carbon::today()->subDays(100 + $i),
        ]);
    }

    // Current run also at 300 sec/km: equal to the last-30 mean => no faster/slower phrase.
    [$activity, $detail] = makeRun($user, [
        'moving_time' => 3000,
        'start_date_local' => Carbon::today(),
    ]);

    expect(builder()->runInsightTechnical($activity, $detail))
        ->not->toContain('lebih cepat dari rata-rata kamu')
        ->not->toContain('lebih santai dari biasanya');
});

it('compares against the recent window so an old all-time average does not skew it', function (): void {
    $user = User::factory()->create();

    // 30 recent runs at 270 sec/km (10km in 2700s).
    foreach (range(1, 30) as $i) {
        $recent = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($recent)->create([
            'distance' => 10000.0,
            'moving_time' => 2700,
            'start_date_local' => Carbon::today()->subDays($i),
        ]);
    }

    // Older slow runs that would lift an all-time average well above 300.
    foreach (range(1, 20) as $i) {
        $old = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($old)->create([
            'distance' => 10000.0,
            'moving_time' => 6000, // 600 sec/km
            'start_date_local' => Carbon::today()->subDays(100 + $i),
        ]);
    }

    // Current run at 300 sec/km: slower than the recent 270 window by 30s.
    [$activity, $detail] = makeRun($user, [
        'moving_time' => 3000,
        'start_date_local' => Carbon::today(),
    ]);

    expect(builder()->runInsightTechnical($activity, $detail))
        ->toContain('lebih santai dari biasanya');
});

it('omits pace comparison when the user has no rolling average', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, [
        'average_cadence' => 92.0, // give it one part so output is non-empty
    ]);

    expect(builder()->runInsightTechnical($activity, $detail))
        ->not->toContain('lebih cepat')
        ->not->toContain('lebih santai');
});

it('omits pace comparison when current pace is missing', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 3) as $i) {
        $past = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($past)->create([
            'distance' => 10000.0,
            'moving_time' => 3000,
            'start_date_local' => Carbon::today()->subDays($i + 1),
        ]);
    }

    [$activity, $detail] = makeRun($user, [
        'distance' => 0.0, // paceSecPerKm() => null
        'average_cadence' => 92.0,
    ]);

    expect(builder()->runInsightTechnical($activity, $detail))
        ->not->toContain('lebih cepat')
        ->not->toContain('lebih santai');
});

it('combines multiple technical parts into one sentence', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, [
        'average_cadence' => 92.0,
        'average_heartrate' => 150.0,
        'max_heartrate' => 200,
        'stream_summary' => [
            'decoupling_pct' => 8.0,
            'ascent_m' => 120.0,
            'pace_variability_sec' => 25.0,
        ],
    ]);

    expect(builder()->runInsightTechnical($activity, $detail))
        // The opener rotates deterministically by activity id (see the
        // "frames the technical note" test), so match any of the frames
        // instead of hardcoding one - otherwise this flakes on activity id.
        ->toMatch('/^(Sesi ini|Catatan teknisnya,|Dari angka-angkanya,|Baca teknisnya:) /')
        ->toContain(', ')
        ->toEndWith('.');
});

it('returns the not-enough-data message for splits without enough per-km entries', function (?array $summary): void {
    $detail = makeDetail(['stream_summary' => $summary]);

    expect(builder()->runInsightSplits($detail))
        ->toBe('Data split belum cukup buat dianalisis.');
})->with([
    'null summary' => [null],
    'single km' => [['per_km' => [['km' => 1, 'pace' => '5:00']]]],
    'per_km not array' => [['per_km' => 'nope']],
]);

it('notes the trailing sisa segment as a finish alongside the full-km read', function (): void {
    $detail = makeDetail([
        'stream_summary' => [
            'per_km' => [
                ['km' => 1, 'pace' => '6:00'],
                ['km' => 2, 'pace' => '6:00'],
            ],
            'partial_split' => ['distance_m' => 700, 'pace' => '5:30'],
        ],
    ]);

    expect(builder()->runInsightSplits($detail))->toContain('Sisa 0.7 km ditutup di 5:30');
});

it('surfaces the finish even on a run too short for a full-km split read', function (): void {
    $detail = makeDetail([
        'stream_summary' => [
            'per_km' => [['km' => 1, 'pace' => '6:00']], // < 2 full km
            'partial_split' => ['distance_m' => 300, 'pace' => '5:45'],
        ],
    ]);

    expect(builder()->runInsightSplits($detail))
        ->toContain('Sisa 0.3 km ditutup di 5:45')
        ->not->toBe('Data split belum cukup buat dianalisis.');
});

it('omits the finish clause when the run ends on a whole km', function (): void {
    $detail = makeDetail([
        'stream_summary' => [
            'per_km' => [
                ['km' => 1, 'pace' => '6:00'],
                ['km' => 2, 'pace' => '6:00'],
            ],
        ],
    ]);

    expect(builder()->runInsightSplits($detail))->not->toContain('sisa');
});

it('labels a genuine negative split as negative when flagged upstream', function (): void {
    $detail = makeDetail([
        'stream_summary' => [
            'negative_split' => true,
            'per_km' => [
                ['km' => 1, 'pace' => '5:10'],
                ['km' => 2, 'pace' => '4:50'], // second half faster
            ],
        ],
    ]);

    expect(builder()->runInsightSplits($detail))->toContain('Negative split');
});

it('labels a flat run as merata, never positive split, when not a negative split', function (?bool $neg): void {
    $detail = makeDetail([
        'stream_summary' => [
            'negative_split' => $neg, // false or absent => not a strong negative split
            'per_km' => [
                ['km' => 1, 'pace' => '5:00'],
                ['km' => 2, 'pace' => '5:00'], // even effort, no real slow-down
            ],
        ],
    ]);

    expect(builder()->runInsightSplits($detail))
        ->toContain('Pacing cukup merata')
        ->not->toContain('positive split')
        ->not->toContain('Positive split');
})->with([
    'explicit false' => [false],
    'absent flag' => [null],
]);

it('labels a genuine positive split when the second half slows meaningfully', function (): void {
    $detail = makeDetail([
        'stream_summary' => [
            'negative_split' => false,
            'per_km' => [
                ['km' => 1, 'pace' => '4:40'],
                ['km' => 2, 'pace' => '4:40'],
                ['km' => 3, 'pace' => '5:20'],
                ['km' => 4, 'pace' => '5:20'], // second half >1.5% slower
            ],
        ],
    ]);

    expect(builder()->runInsightSplits($detail))
        ->toContain('Positive split, pace melambat di paruh kedua');
});

it('describes km range bands (wide, moderate, tight)', function (array $perKm, string $expected): void {
    $detail = makeDetail([
        'stream_summary' => ['per_km' => $perKm],
    ]);

    expect(builder()->runInsightSplits($detail))->toContain($expected);
})->with([
    'wide' => [[
        ['km' => 1, 'pace' => '4:30'],
        ['km' => 2, 'pace' => '5:00'],
        ['km' => 3, 'pace' => '5:30'], // range 60s > 30
    ], 'selisih cukup besar'],
    'moderate' => [[
        ['km' => 1, 'pace' => '5:00'],
        ['km' => 2, 'pace' => '5:10'],
        ['km' => 3, 'pace' => '5:20'], // range 20s > 15
    ], 'gap-nya wajar'],
    'tight' => [[
        ['km' => 1, 'pace' => '5:00'],
        ['km' => 2, 'pace' => '5:02'],
        ['km' => 3, 'pace' => '5:05'], // range 5s
    ], 'Gap antar km sangat kecil'],
]);

it('capitalises every sentence, not just the first, in a multi-clause splits note', function (): void {
    // Positive split so two sentences assemble: direction + km range.
    $detail = makeDetail([
        'stream_summary' => ['per_km' => [
            ['km' => 1, 'pace' => '4:30'],
            ['km' => 2, 'pace' => '5:00'],
            ['km' => 3, 'pace' => '5:30'],
        ]],
    ]);

    $out = builder()->runInsightSplits($detail);

    expect($out)
        ->toStartWith('Positive split')
        ->toEndWith('.')
        // No lowercase letter directly after a sentence-ending period + space.
        ->not->toMatch('/\. [a-z]/');
});

it('does not restate pace consistency three times on an even-effort run', function (): void {
    // Even effort (merata) with a tight range and low variability: the old
    // builder said "merata" + "pacing sangat konsisten" + "konsistensi pace ...".
    $detail = makeDetail([
        'stream_summary' => [
            'pace_variability_sec' => 5.0,
            'per_km' => [
                ['km' => 1, 'pace' => '5:00'],
                ['km' => 2, 'pace' => '5:00'],
                ['km' => 3, 'pace' => '5:00'],
            ],
        ],
    ]);

    $out = builder()->runInsightSplits($detail);

    expect($out)
        ->toBe('Pacing cukup merata dari awal sampai akhir. Gap antar km sangat kecil.')
        ->not->toContain('konsisten'); // the redundant restatements are gone
});

it('skips the km range part when fewer than three valid paces parse', function (): void {
    $detail = makeDetail([
        'stream_summary' => [
            'negative_split' => true,
            'per_km' => [
                ['km' => 1, 'pace' => '5:00'],
                ['km' => 2, 'pace' => 'bad'],   // unparseable
                ['km' => 3, 'pace' => ''],      // empty
            ],
        ],
    ]);

    $out = builder()->runInsightSplits($detail);
    expect($out)
        ->toContain('Negative split')
        ->not->toContain('tercepat')
        ->not->toContain('gap');
});

it('renders the wide km range with the fastest km pace string', function (): void {
    $detail = makeDetail([
        'stream_summary' => ['per_km' => [
            ['km' => 1, 'pace' => '4:30'],
            ['km' => 2, 'pace' => '5:00'],
            ['km' => 3, 'pace' => '5:30'],
        ]],
    ]);

    expect(builder()->runInsightSplits($detail))
        ->toContain('Km 1 tercepat (4:30)') // sentence-start capitalization (#56)
        ->toContain('km 3 paling lambat');
});

it('comments on variability consistency for splits (sangat bagus / cukup baik)', function (float $var, string $expected): void {
    $detail = makeDetail([
        'stream_summary' => [
            'pace_variability_sec' => $var,
            'per_km' => [
                ['km' => 1, 'pace' => '5:00'],
                ['km' => 2, 'pace' => '5:05'],
            ],
        ],
    ]);

    expect(builder()->runInsightSplits($detail))->toContain($expected);
})->with([
    'consistent' => [5.0, 'Konsistensi pace sangat bagus'],
    'moderate' => [12.0, 'Konsistensi pace cukup baik'],
]);

it('omits the variability comment when variability is high or absent', function (?float $var): void {
    $detail = makeDetail([
        'stream_summary' => [
            'pace_variability_sec' => $var,
            'per_km' => [
                ['km' => 1, 'pace' => '5:00'],
                ['km' => 2, 'pace' => '5:05'],
            ],
        ],
    ]);

    expect(builder()->runInsightSplits($detail))
        ->not->toContain('konsistensi pace');
})->with([
    'high' => [30.0],
    'absent' => [null],
]);

it('returns the no-zone-data message when zones are unavailable', function (): void {
    $detail = makeDetail(['stream_summary' => null]);

    expect(builder()->runInsightZones($detail))
        ->toBe('Data heart rate zone belum tersedia.');
});

it('returns the no-zone-data message when zone minutes total zero', function (): void {
    $detail = makeDetail([
        'stream_summary' => ['time_in_zone_min' => ['Z1' => 0, 'Z2' => 0]],
    ]);

    expect(builder()->runInsightZones($detail))
        ->toBe('Data heart rate zone belum tersedia.');
});

it('derives zone percentages from minutes when pct is absent', function (): void {
    $detail = makeDetail([
        'stream_summary' => ['time_in_zone_min' => ['Z1' => 30, 'Z2' => 30, 'Z3' => 0, 'Z4' => 0, 'Z5' => 0]],
    ]);

    // 50% Z1 + 50% Z2 => easyPct 100 => base building.
    expect(builder()->runInsightZones($detail))
        ->toContain('base building proper');
});

it('uses pct directly and labels dominant zone with the 70% phrasing', function (): void {
    $detail = makeDetail([
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 5, 'Z2' => 75, 'Z3' => 20]],
    ]);

    expect(builder()->runInsightZones($detail))
        ->toContain('75% di Z2');
});

it('labels dominant zone with the didominasi phrasing below 70%', function (): void {
    $detail = makeDetail([
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 30, 'Z2' => 40, 'Z3' => 30]],
    ]);

    expect(builder()->runInsightZones($detail))
        ->toContain('Didominasi Z2');
});

it('assesses zone discipline across each band', function (array $pct, string $expected): void {
    $detail = makeDetail([
        'stream_summary' => ['time_in_zone_pct' => $pct],
    ]);

    expect(builder()->runInsightZones($detail))->toContain($expected);
})->with([
    'base building (easy >= 80)' => [['Z1' => 40, 'Z2' => 45, 'Z3' => 15], 'base building proper, mayoritas easy'],
    'seimbang (easy >= 60)' => [['Z1' => 30, 'Z2' => 35, 'Z3' => 35], 'kombinasi easy dan moderate, seimbang'],
    'overstrain (hard >= 50)' => [['Z2' => 40, 'Z4' => 55, 'Z3' => 5], 'intensitas tinggi, hati-hati overstrain'],
    'quality (hard >= 30)' => [['Z2' => 55, 'Z3' => 25, 'Z4' => 20], 'ada porsi quality yang cukup'],
]);

it('produces no discipline phrase when no band matches', function (): void {
    // easy 55 (<60), hard 25 (<30): match falls through to null.
    $detail = makeDetail([
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 20, 'Z2' => 35, 'Z3' => 20, 'Z4' => 5, 'Z5' => 0]],
    ]);

    expect(builder()->runInsightZones($detail))
        ->not->toContain('base building')
        ->not->toContain('seimbang')
        ->not->toContain('overstrain')
        ->not->toContain('porsi quality');
});

it('warns when Z5 exceeds 10 percent', function (): void {
    $detail = makeDetail([
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 20, 'Z2' => 40, 'Z3' => 10, 'Z4' => 15, 'Z5' => 15]],
    ]);

    expect(builder()->runInsightZones($detail))
        ->toContain('Z5 cukup banyak');
});

it('nudges a Z3+-heavy run that reads as intended-easy via VDOT threshold pace', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0, // VDOT ~50 => threshold pace ~4:00/km
    ]);

    [$activity, $detail] = makeRun($user, [
        'distance' => 8000.0,
        'moving_time' => 3360, // 7:00/km, way slower than threshold => clearly easy
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 20, 'Z2' => 20, 'Z3' => 30, 'Z4' => 20, 'Z5' => 10]],
    ]);

    expect(builder()->runInsightZones($detail))
        ->toContain('kalau niatnya easy')
        ->toMatch('/\d+:\d{2}\/km/');
});

it('does not nudge a correctly-easy run: low Z3+ share never trips the guard', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0,
    ]);

    [$activity, $detail] = makeRun($user, [
        'distance' => 8000.0,
        'moving_time' => 3360, // 7:00/km, clearly easy pace
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 70, 'Z2' => 20, 'Z3' => 10, 'Z4' => 0, 'Z5' => 0]],
    ]);

    expect(builder()->runInsightZones($detail))->not->toContain('niatnya easy');
});

it('does not nudge a firm/tempo run: pace too close to threshold to read as intended-easy', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0, // threshold pace ~4:00/km
    ]);

    [$activity, $detail] = makeRun($user, [
        'distance' => 8000.0,
        'moving_time' => 1960, // ~4:05/km, only ~5s slower than threshold
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 5, 'Z2' => 15, 'Z3' => 30, 'Z4' => 30, 'Z5' => 20]],
    ]);

    expect(builder()->runInsightZones($detail))->not->toContain('niatnya easy');
});

it('nudges via the HR-only fallback when the user has no VDOT, without printing a pace number', function (): void {
    $user = User::factory()->create();
    // No PersonalRecord => VdotEstimator::estimate() returns null.

    [$activity, $detail] = makeRun($user, [
        'distance' => 8000.0,
        'moving_time' => 3360,
        'average_heartrate' => 130.0,
        'max_heartrate' => 200, // 65% HR reserve <= HR_RESERVE_EASY (70)
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 20, 'Z2' => 20, 'Z3' => 30, 'Z4' => 20, 'Z5' => 10]],
    ]);

    expect(builder()->runInsightZones($detail))
        ->toContain('kalau niatnya easy, coba lebih pelan dikit')
        ->not->toMatch('/\d+:\d{2}\/km/');
});

it('skips the grey-zone nudge when no VDOT and no HR data can confirm intended-easy', function (): void {
    $user = User::factory()->create();

    [$activity, $detail] = makeRun($user, [
        'distance' => 8000.0,
        'moving_time' => 3360,
        'average_heartrate' => null,
        'max_heartrate' => null,
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 20, 'Z2' => 20, 'Z3' => 30, 'Z4' => 20, 'Z5' => 10]],
    ]);

    expect(builder()->runInsightZones($detail))->not->toContain('niatnya easy');
});

it('skips the grey-zone nudge when the run is outside the short-to-moderate distance band', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0,
    ]);

    [$activity, $detail] = makeRun($user, [
        'distance' => 21097.0, // half marathon: too long for the guard
        'moving_time' => 21097 * 7, // 7:00/km, clearly easy pace
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 20, 'Z2' => 20, 'Z3' => 30, 'Z4' => 20, 'Z5' => 10]],
    ]);

    expect(builder()->runInsightZones($detail))->not->toContain('niatnya easy');
});

it('exposes all three insights via runInsights', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = makeRun($user, [
        'average_cadence' => 92.0,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z1' => 40, 'Z2' => 45, 'Z3' => 15],
            'per_km' => [
                ['km' => 1, 'pace' => '5:00'],
                ['km' => 2, 'pace' => '5:05'],
            ],
            'negative_split' => true,
            'pace_variability_sec' => 5.0,
        ],
    ]);

    $insights = builder()->runInsights($activity, $detail);

    expect($insights)
        ->toHaveKeys(['technical', 'splits', 'zones'])
        ->and($insights['technical'])->toContain('cadence')
        ->and($insights['splits'])->toContain('Negative split')
        ->and($insights['zones'])->toContain('base building');
});

it('returns the not-enough-data trend caption with fewer than two weeks', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->subWeek()->toDateString(),
    ]);

    expect(builder()->trendCaption($user, Carbon::today()))
        ->toBe('Belum cukup data buat liat tren.');
});

it('builds a full trend caption: rising volume, fresh form, building fitness', function (): void {
    $user = User::factory()->create();

    // Prior weeks (older): low distance, low form, low CTL.
    foreach (range(8, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 10.0,
            'form' => -10.0,
            'ctl_42d' => 20.0,
            'form_status' => 'fatigued',
        ]);
    }
    // Recent weeks (newer): high distance, fresh form, high CTL.
    foreach (range(4, 1) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 30.0,
            'form' => 5.0,
            'ctl_42d' => 30.0,
            'form_status' => 'fresh',
        ]);
    }

    expect(builder()->trendCaption($user, Carbon::today()))
        ->toContain('Volume naik signifikan')
        ->toContain('form lagi segar')
        ->toContain('kondisi lagi fresh')
        ->toContain('fitness sedang membangun')
        ->toEndWith('.');
});

it('describes declining volume and high fatigue with overreaching status', function (): void {
    $user = User::factory()->create();

    foreach (range(8, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 40.0,
            'form' => 10.0,
            'ctl_42d' => 50.0,
            'form_status' => 'optimal',
        ]);
    }
    foreach (range(4, 1) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 20.0,   // -50% => turun cukup banyak
            'form' => -10.0,         // delta -20 => kelelahan terlampau tinggi
            'ctl_42d' => 30.0,
            'form_status' => 'overreaching',
        ]);
    }

    expect(builder()->trendCaption($user, Carbon::today()))
        ->toContain('Volume turun cukup banyak')
        ->toContain('kelelahan terlampau tinggi')
        ->toContain('waspada overreaching');
});

it('covers intermediate volume and form trend bands plus optimal status', function (): void {
    $user = User::factory()->create();

    // Prior baseline.
    foreach (range(8, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 20.0,
            'form' => 0.0,
            'ctl_42d' => 40.0,
            'form_status' => 'optimal',
        ]);
    }
    // Recent: slight volume rise (+10%), slight form dip within optimal band.
    foreach (range(4, 1) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 22.0,   // +10% => naik pelan-pelan
            'form' => -2.0,          // delta -2 => di zona optimal
            'ctl_42d' => 40.0,       // flat => no fitness phrase
            'form_status' => 'optimal',
        ]);
    }

    expect(builder()->trendCaption($user, Carbon::today()))
        ->toContain('Volume naik pelan-pelan')
        ->toContain('form di zona optimal')
        ->toContain('di titik optimal')
        ->not->toContain('fitness sedang membangun');
});

it('covers stable volume, fatigue tanda, and the volume turun dikit band', function (): void {
    $user = User::factory()->create();

    // Scenario A: stable volume + ada tanda fatigue (delta in -10..-5).
    foreach (range(8, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 20.0,
            'form' => 0.0,
            'ctl_42d' => 40.0,
            'form_status' => 'fatigued',
        ]);
    }
    foreach (range(4, 1) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 20.0,   // 0% => stabil
            'form' => -7.0,          // delta -7 => ada tanda fatigue
            'ctl_42d' => 40.0,
            'form_status' => 'fatigued',
        ]);
    }

    expect(builder()->trendCaption($user, Carbon::today()))
        ->toContain('Volume stabil')
        ->toContain('ada tanda fatigue')
        ->toContain('mulai lelah');
});

it('covers the volume turun dikit band and good form delta', function (): void {
    $user = User::factory()->create();

    foreach (range(8, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 20.0,
            'form' => 0.0,
            'ctl_42d' => 40.0,
            'form_status' => 'fresh',
        ]);
    }
    foreach (range(4, 1) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 17.0,   // -15% => turun dikit
            'form' => 3.0,           // delta +3 => form cukup baik
            'ctl_42d' => 40.0,
            'form_status' => 'fresh',
        ]);
    }

    expect(builder()->trendCaption($user, Carbon::today()))
        ->toContain('Volume turun dikit')
        ->toContain('form cukup baik');
});

it('skips the volume phrase when prior distance is zero', function (): void {
    $user = User::factory()->create();

    foreach (range(8, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 0.0,
            'form' => 0.0,
            'ctl_42d' => 0.0,
            'form_status' => null,
        ]);
    }
    foreach (range(4, 1) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => 20.0,
            'form' => 2.0,
            'ctl_42d' => 0.0,
            'form_status' => null,
        ]);
    }

    $caption = builder()->trendCaption($user, Carbon::today());
    expect($caption)
        ->not->toContain('volume')
        ->not->toContain('Volume')
        ->toContain('Form cukup baik');
});
