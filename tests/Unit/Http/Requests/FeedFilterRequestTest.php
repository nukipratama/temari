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

it('falls back to the 8w range for missing or unknown values', function (string $query): void {
    expect(feedRequest($query)->range())->toBe('8w');
})->with(['', 'range=', 'range=99y', 'range[]=1y']);

it('keeps a known range', function (string $range): void {
    expect(feedRequest("range={$range}")->range())->toBe($range);
})->with(['8w', '12w', '6m', '1y', 'all']);

it('normalises a week deep link to that week\'s sunday', function (): void {
    expect(feedRequest('week=2026-06-17')->week()?->toDateString())->toBe('2026-06-21');
    expect(feedRequest('week=2026-06-21')->week()?->toDateString())->toBe('2026-06-21');
});

it('drops a malformed week', function (string $query): void {
    expect(feedRequest($query)->week())->toBeNull();
})->with(['', 'week=', 'week=2026-6-1', 'week=yesterday', 'week=2026-13-45']);

it('defaults the week page cursor to two', function (string $query): void {
    expect(feedRequest($query)->weeks())->toBe(2);
})->with(['', 'weeks=', 'weeks=nope', 'weeks=1', 'weeks=0', 'weeks=-5', 'weeks[]=8']);

it('keeps a raised week page cursor, clamped to the ceiling', function (string $query, int $weeks): void {
    expect(feedRequest($query)->weeks())->toBe($weeks);
})->with([
    'a raised cursor' => ['weeks=6', 6],
    'above the ceiling' => ['weeks=9000', 52],
]);
