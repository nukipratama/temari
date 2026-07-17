<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Services\Run\PrScoreboardBuilder;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    $this->builder = new PrScoreboardBuilder();
});

/**
 * A lightweight (un-persisted) PR carrying just category + value_sec.
 *
 * user_id is pinned to a literal so the factory doesn't fall through to its
 * `User::factory()` default, which persists a real User row even under
 * ->make() (nested belongsTo factory attributes are always create()'d).
 */
function makePr(string $category, int|float $valueSec = 0, ?int $id = null): PersonalRecord
{
    return PersonalRecord::factory()->make([
        'id' => $id,
        'user_id' => 1,
        'category' => $category,
        'value_sec' => $valueSec,
    ]);
}

describe('roundedTargetSec bucket thresholds', function (): void {
    it('drops hour-scale times to the next-lower 5-minute increment', function (): void {
        // 1:02:27 (3747) → 1:00:00 (3600).
        expect($this->builder->roundedTargetSec(3747))->toBe(3600);
    });

    it('keeps a just-over-the-hour time on the 1:00:00 milestone, not a full 5 min below', function (): void {
        // 1:00:01 (3601) → floor((3601-1)/300)*300 = floor(12)*300 = 3600 (Sub-1:00:00, delta 1s).
        expect($this->builder->roundedTargetSec(3601))->toBe(3600);
    });

    it('keeps exactly 1:00:00 in the hour-scale (5-min) bucket rather than dropping to the 1-min bucket', function (): void {
        // 3600 is at the hour-scale threshold, so it rounds to the 5-min bucket (3300).
        expect($this->builder->roundedTargetSec(3600))->toBe(3300);
    });

    it('rounds 10-to-60-minute times down to the next whole minute', function (): void {
        // 29:11 (1751) → 29:00 (1740).
        expect($this->builder->roundedTargetSec(1751))->toBe(1740)
            ->and($this->builder->roundedTargetSec(601))->toBe(600);
    });

    it('rounds the 600 boundary as a sub-10-minute (15s bucket) value', function (): void {
        // 600 is not > 600, so it falls through to the 15s bucket: floor(599/15)*15 = 585.
        expect($this->builder->roundedTargetSec(600))->toBe(585);
    });

    it('rounds sub-10-minute times down to the next 15s increment', function (): void {
        // 5:00 (300) → 4:45 (285).
        expect($this->builder->roundedTargetSec(300))->toBe(285)
            ->and($this->builder->roundedTargetSec(301))->toBe(300);
    });
});

describe('milestoneFor', function (): void {
    it('returns null target + delta for non-distance (effort) categories', function (): void {
        expect($this->builder->milestoneFor(makePr('best_20min', 320)))
            ->toBe(['target_sec' => null, 'delta_sec' => null]);
    });

    it('returns null target + delta when value_sec is non-positive', function (): void {
        expect($this->builder->milestoneFor(makePr('5km', 0)))
            ->toBe(['target_sec' => null, 'delta_sec' => null]);
    });

    it('computes target + delta for a distance PR', function (): void {
        // 1751 → 1740, delta 11.
        expect($this->builder->milestoneFor(makePr('5km', 1751)))
            ->toBe(['target_sec' => 1740, 'delta_sec' => 11]);
    });
});

describe('pickFeaturedPr', function (): void {
    it('picks the highest-distance PR', function (): void {
        $records = new Collection([
            makePr('5km', 1500, id: 1),
            makePr('half_marathon', 6300, id: 2),
            makePr('10km', 3000, id: 3),
        ]);

        expect($this->builder->pickFeaturedPr($records)?->category->value)->toBe('half_marathon');
    });

    it('falls back to the first record when no distance PR exists', function (): void {
        $records = new Collection([
            makePr('best_20min', 320, id: 1),
            makePr('best_60min', 1100, id: 2),
        ]);

        expect($this->builder->pickFeaturedPr($records)?->category->value)->toBe('best_20min');
    });

    it('returns null for an empty collection', function (): void {
        expect($this->builder->pickFeaturedPr(new Collection()))->toBeNull();
    });
});

describe('splitsPaceSec', function (): void {
    it('returns an empty list for null splits', function (): void {
        expect($this->builder->splitsPaceSec(null))->toBe([]);
    });

    it('converts per-km segments to rounded pace seconds', function (): void {
        $splits = [
            ['distance' => 1000, 'moving_time' => 360],
            ['distance' => 1000, 'moving_time' => 345],
        ];

        expect($this->builder->splitsPaceSec($splits))->toBe([360, 345]);
    });

    it('skips segments with missing or zero distance/time', function (): void {
        $splits = [
            ['distance' => 1000, 'moving_time' => 360],
            ['distance' => 0, 'moving_time' => 300],   // zero distance → null pace, skipped
            ['moving_time' => 300],                     // missing distance → skipped
            ['distance' => 1000],                       // missing time → skipped
        ];

        expect($this->builder->splitsPaceSec($splits))->toBe([360]);
    });

    it('excludes the trailing sub-km partial so the sparkline scale stays full-km', function (): void {
        $splits = [
            ['distance' => 1000, 'moving_time' => 360],
            ['distance' => 1000, 'moving_time' => 350],
            ['distance' => 700, 'moving_time' => 252],   // partial → excluded here
        ];

        expect($this->builder->splitsPaceSec($splits))->toBe([360, 350]);
    });
});

describe('splitsPartialPaceSec', function (): void {
    it('returns null for null, empty, or full-km-ending splits', function (): void {
        expect($this->builder->splitsPartialPaceSec(null))->toBeNull()
            ->and($this->builder->splitsPartialPaceSec([]))->toBeNull()
            ->and($this->builder->splitsPartialPaceSec([
                ['distance' => 1000, 'moving_time' => 360],
            ]))->toBeNull();
    });

    it('returns the normalized pace of a trailing partial (100m..950m)', function (): void {
        $splits = [
            ['distance' => 1000, 'moving_time' => 360],
            ['distance' => 700, 'moving_time' => 252],   // 252 / 0.7 = 360 s/km
        ];

        expect($this->builder->splitsPartialPaceSec($splits))->toBe(360);
    });

    it('ignores a sub-100m trailing sliver', function (): void {
        $splits = [
            ['distance' => 1000, 'moving_time' => 360],
            ['distance' => 40, 'moving_time' => 14],
        ];

        expect($this->builder->splitsPartialPaceSec($splits))->toBeNull();
    });
});

describe('featuredExtras', function (): void {
    it('returns null when no PR is supplied', function (): void {
        expect($this->builder->featuredExtras(null))->toBeNull();
    });

    it('assembles splits, location, weather, and milestone for a distance PR', function (): void {
        $detail = ActivityDetail::factory()->make([
            'activity_id' => 1,
            'location_name' => 'Senayan',
            'weather_temp_c' => 28,
            'weather_humidity_pct' => 75,
            'splits_metric' => [
                ['distance' => 1000, 'moving_time' => 360],
                ['distance' => 1000, 'moving_time' => 350],
            ],
        ]);
        $activity = Activity::factory()->make(['id' => 1, 'user_id' => 1]);
        $activity->setRelation('detail', $detail);

        $pr = PersonalRecord::factory()->make([
            'id' => 1,
            'user_id' => 1,
            'activity_id' => 1,
            'category' => '5km',
            'value_sec' => 1751,
        ]);
        $pr->setRelation('activity', $activity);

        expect($this->builder->featuredExtras($pr))->toBe([
            'pr_id' => $pr->id,
            'splits_pace_sec' => [360, 350],
            'splits_partial_pace_sec' => null,
            'location_name' => 'Senayan',
            'weather_temp_c' => 28,
            'weather_humidity_pct' => 75,
            'target_sec' => 1740,
            'delta_sec' => 11,
        ]);
    });

    it('exposes the trailing partial pace alongside the full-km splits', function (): void {
        $detail = ActivityDetail::factory()->make([
            'activity_id' => 1,
            'splits_metric' => [
                ['distance' => 1000, 'moving_time' => 360],
                ['distance' => 1000, 'moving_time' => 350],
                ['distance' => 700, 'moving_time' => 252],
            ],
        ]);
        $activity = Activity::factory()->make(['id' => 1, 'user_id' => 1]);
        $activity->setRelation('detail', $detail);

        $pr = PersonalRecord::factory()->make([
            'id' => 1,
            'user_id' => 1,
            'activity_id' => 1,
            'category' => '5km',
            'value_sec' => 1751,
        ]);
        $pr->setRelation('activity', $activity);

        $extras = $this->builder->featuredExtras($pr);

        expect($extras['splits_pace_sec'])->toBe([360, 350])
            ->and($extras['splits_partial_pace_sec'])->toBe(360);
    });
});
