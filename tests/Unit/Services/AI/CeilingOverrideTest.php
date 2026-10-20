<?php

declare(strict_types=1);

use App\Services\AI\CeilingOverride;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::store('array')->clear();
});

function ceilingOverride(): CeilingOverride
{
    return new CeilingOverride();
}

it('round-trips the ceiling as a float', function (): void {
    ceilingOverride()->set(1, 2.5);

    expect(ceilingOverride()->get(1))->toBe(2.5);
});

it('reads back a numeric string as a float, since redis returns numerics unserialized', function (): void {
    Cache::put('ceiling-override:1:'.Carbon::today()->toDateString(), '2.5', Carbon::tomorrow());

    expect(ceilingOverride()->get(1))->toBe(2.5);
});

it('clears an override', function (): void {
    ceilingOverride()->set(1, 2.5);
    ceilingOverride()->clear(1);

    expect(ceilingOverride()->get(1))->toBeNull();
});

it('keys the override per athlete', function (): void {
    ceilingOverride()->set(1, 2.5);

    expect(ceilingOverride()->get(2))->toBeNull();
});

it('does not carry into tomorrow', function (): void {
    ceilingOverride()->set(1, 2.5);

    Carbon::setTestNow(Carbon::tomorrow()->addHour());

    expect(ceilingOverride()->get(1))->toBeNull();
});
