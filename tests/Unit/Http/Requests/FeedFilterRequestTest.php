<?php

declare(strict_types=1);

use App\Http\Requests\FeedFilterRequest;

function feedRequest(string $query = ''): FeedFilterRequest
{
    return FeedFilterRequest::create('/history'.($query === '' ? '' : '?'.$query));
}

it('authorizes everyone and validates nothing', function (): void {
    expect(feedRequest()->authorize())->toBeTrue()
        ->and(feedRequest()->rules())->toBe([]);
});

it('falls back to the 8w range for missing or unknown values', function (mixed $query): void {
    expect(feedRequest($query)->range())->toBe('8w');
})->with(['', 'range=', 'range=99y', 'range[]=1y']);

it('keeps a known range', function (string $range): void {
    expect(feedRequest("range={$range}")->range())->toBe($range);
})->with(['8w', '12w', '6m', '1y', 'all']);

it('keeps only known moods, deduplicated and in URL order', function (): void {
    expect(feedRequest('mood=gassed,blazing,gassed,bogus')->moods())->toBe(['gassed', 'blazing']);
});

it('returns no mood filter for absent or fully unknown values', function (string $query): void {
    expect(feedRequest($query)->moods())->toBe([]);
})->with(['', 'mood=', 'mood=bogus', 'mood[]=gassed']);

it('normalises a week deep link to that week\'s sunday', function (): void {
    expect(feedRequest('week=2026-06-17')->week()?->toDateString())->toBe('2026-06-21');
    expect(feedRequest('week=2026-06-21')->week()?->toDateString())->toBe('2026-06-21');
});

it('drops a malformed week', function (string $query): void {
    expect(feedRequest($query)->week())->toBeNull();
})->with(['', 'week=', 'week=2026-6-1', 'week=yesterday', 'week=2026-13-45']);

it('falls back to the newest sort for anything unknown', function (string $query): void {
    expect(feedRequest($query)->sort())->toBe('newest');
})->with(['', 'sort=', 'sort=slowest', 'sort[]=longest']);

it('keeps a known sort mode', function (string $sort): void {
    expect(feedRequest("sort={$sort}")->sort())->toBe($sort);
})->with(['newest', 'longest', 'fastest']);

it('keeps a known distance band and drops anything else', function (): void {
    expect(feedRequest('dist=10-21')->distanceBand())->toBe('10-21')
        ->and(feedRequest('dist=42up')->distanceBand())->toBeNull()
        ->and(feedRequest()->distanceBand())->toBeNull();
});
