<?php

declare(strict_types=1);

namespace App\Services\Run;

use Illuminate\Support\Carbon;

/**
 * Windowing for the History feed. Mood/distance/rarity/sort selection was cut
 * from the Feed screen (S7 — the mobile-UX port has no filter UI); this DTO
 * keeps only the range/week-deep-link windowing that still governs how much
 * history the server loads, which isn't itself a user-facing filter control.
 */
final readonly class FeedFilters
{
    /**
     * Range chip → days back from today. Default `8w` keeps the page snappy
     * for typical browsing while letting the auto-widen below pull up to a
     * year on demand. {@see self::RANGE_ALL} is the unbounded escalation
     * (every run, any age).
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
     * Week sections shown per page, and how many each "load older weeks" press
     * adds. Two matches the prototype's own first paint.
     */
    public const int WEEKS_PER_PAGE = 2;

    /** Ceiling on the page cursor, so a hand-edited `?weeks=` can't ask for everything. */
    public const int MAX_WEEKS = 52;

    public function __construct(
        public string $range,
        public bool $rangeAutoWidened,
        public ?Carbon $rangeStart,
        public ?Carbon $week,
    ) {
    }
}
