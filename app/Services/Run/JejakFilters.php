<?php

declare(strict_types=1);

namespace App\Services\Run;

use App\Services\Run\Story\Temari;
use Illuminate\Support\Carbon;

final readonly class JejakFilters
{
    /**
     * Range chip → days back from today. Default `8w` keeps the page snappy
     * for typical browsing while letting users pull up to a year on demand.
     * {@see self::RANGE_ALL} is the unbounded escalation (every run, any age).
     */
    public const array RANGE_DAYS = [
        '8w' => 56,
        '12w' => 84,
        '6m' => 182,
        '1y' => 365,
    ];

    /** Unbounded range: no lower bound, every analyzed run regardless of age. */
    public const string RANGE_ALL = 'all';

    /**
     * Selectable moods for the Jejak filter. Mirrors the `Mood` union in
     * resources/js/types/inertia.ts; anything else in `?mood=` is dropped rather
     * than 404ing, so a stale or hand-edited URL degrades to a wider view.
     */
    public const array MOODS = [
        Temari::MOOD_NYALA,
        Temari::MOOD_ENTENG,
        Temari::MOOD_OLENG,
        Temari::MOOD_LEMES,
        Temari::MOOD_MUMET,
        Temari::MOOD_ADEM,
    ];

    /**
     * Distance bands in metres as `[min inclusive, max exclusive|null]`. Cut at
     * the distances runners actually think in (5K, 10K, half marathon) rather
     * than at even numbers. `21up` is open-ended so an ultra still lands
     * somewhere.
     */
    public const array DISTANCE_BANDS = [
        '0-5' => [0, 5000],
        '5-10' => [5000, 10000],
        '10-21' => [10000, 21097],
        '21up' => [21097, null],
    ];

    /**
     * Sort modes. `newest` is the default chronological view the week grouping
     * depends on; the other two rank runs globally, which the page renders as a
     * flat list instead (weekly recap cards only make sense in date order).
     */
    public const string SORT_NEWEST = 'newest';

    public const string SORT_LONGEST = 'longest';

    public const string SORT_FASTEST = 'fastest';

    public const array SORTS = [self::SORT_NEWEST, self::SORT_LONGEST, self::SORT_FASTEST];

    /**
     * @param  array<int, string>  $moods
     */
    public function __construct(
        public string $range,
        public bool $rangeAutoWidened,
        public ?Carbon $rangeStart,
        public array $moods,
        public ?string $distanceBand,
        public string $sort,
        public ?Carbon $week,
    ) {
    }
}
