<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Run\JejakFilters;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Parses the Jejak listing's query string. Every accessor normalises rather
 * than rejects: an unknown or malformed value widens the view instead of
 * erroring, so a stale or hand-edited URL still shows runs. That is why
 * {@see rules()} is empty.
 */
class JejakFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function range(): string
    {
        $raw = $this->query('range');
        $candidate = is_string($raw) ? $raw : '';

        if ($candidate === JejakFilters::RANGE_ALL || array_key_exists($candidate, JejakFilters::RANGE_DAYS)) {
            return $candidate;
        }

        return '8w';
    }

    /**
     * Selected moods from `?mood=nyala,lemes`, keeping only known values and
     * dropping duplicates. An empty result means "no mood filter".
     *
     * @return array<int, string>
     */
    public function moods(): array
    {
        $raw = $this->query('mood');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_intersect(
            array_unique(explode(',', $raw)),
            JejakFilters::MOODS,
        ));
    }

    /**
     * The `?week=YYYY-MM-DD` deep-link target, normalised to that week's Sunday
     * (WeeklySnapshot.week_ending), or null when absent/malformed. Any date in
     * the week resolves to the same Sunday, so a link built from a run date
     * still lands on the right recap.
     */
    public function week(): ?Carbon
    {
        $raw = $this->query('week');

        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($raw)->endOfWeek(Carbon::SUNDAY)->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /** The requested sort mode, falling back to newest for anything unknown. */
    public function sort(): string
    {
        $raw = $this->query('sort');

        return is_string($raw) && in_array($raw, JejakFilters::SORTS, true) ? $raw : JejakFilters::SORT_NEWEST;
    }

    /** The selected distance band key, or null for "any distance". */
    public function distanceBand(): ?string
    {
        $raw = $this->query('dist');

        return is_string($raw) && array_key_exists($raw, JejakFilters::DISTANCE_BANDS) ? $raw : null;
    }
}
