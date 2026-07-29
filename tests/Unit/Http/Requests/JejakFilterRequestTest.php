<?php

declare(strict_types=1);

use App\Http\Requests\JejakFilterRequest;

function jejakRequest(string $query = ''): JejakFilterRequest
{
    return JejakFilterRequest::create('/aktivitas'.($query === '' ? '' : '?'.$query));
}

it('authorizes everyone and validates nothing', function (): void {
    expect(jejakRequest()->authorize())->toBeTrue()
        ->and(jejakRequest()->rules())->toBe([]);
});

it('falls back to the 8w range for missing or unknown values', function (mixed $query): void {
    expect(jejakRequest($query)->range())->toBe('8w');
})->with(['', 'range=', 'range=99y', 'range[]=1y']);

it('keeps a known range', function (string $range): void {
    expect(jejakRequest("range={$range}")->range())->toBe($range);
})->with(['8w', '12w', '6m', '1y', 'all']);

it('keeps only known moods, deduplicated and in URL order', function (): void {
    expect(jejakRequest('mood=lemes,nyala,lemes,bogus')->moods())->toBe(['lemes', 'nyala']);
});

it('returns no mood filter for absent or fully unknown values', function (string $query): void {
    expect(jejakRequest($query)->moods())->toBe([]);
})->with(['', 'mood=', 'mood=bogus', 'mood[]=lemes']);

it('normalises a week deep link to that week\'s sunday', function (): void {
    expect(jejakRequest('week=2026-06-17')->week()?->toDateString())->toBe('2026-06-21');
    expect(jejakRequest('week=2026-06-21')->week()?->toDateString())->toBe('2026-06-21');
});

it('drops a malformed week', function (string $query): void {
    expect(jejakRequest($query)->week())->toBeNull();
})->with(['', 'week=', 'week=2026-6-1', 'week=yesterday', 'week=2026-13-45']);

it('falls back to the newest sort for anything unknown', function (string $query): void {
    expect(jejakRequest($query)->sort())->toBe('newest');
})->with(['', 'sort=', 'sort=slowest', 'sort[]=longest']);

it('keeps a known sort mode', function (string $sort): void {
    expect(jejakRequest("sort={$sort}")->sort())->toBe($sort);
})->with(['newest', 'longest', 'fastest']);

it('keeps a known distance band and drops anything else', function (): void {
    expect(jejakRequest('dist=10-21')->distanceBand())->toBe('10-21')
        ->and(jejakRequest('dist=42up')->distanceBand())->toBeNull()
        ->and(jejakRequest()->distanceBand())->toBeNull();
});

it('trims the search term and treats blank as absent', function (): void {
    expect(jejakRequest('q='.urlencode('  pagi  '))->search())->toBe('pagi')
        ->and(jejakRequest('q='.urlencode('   '))->search())->toBeNull()
        ->and(jejakRequest()->search())->toBeNull();
});

it('truncates an overlong search term instead of rejecting it', function (): void {
    $term = str_repeat('a', 200);

    expect(jejakRequest("q={$term}")->search())->toBe(str_repeat('a', 60));
});
