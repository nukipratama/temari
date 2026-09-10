<?php

declare(strict_types=1);

use App\Services\AI\CeilingOverride;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::store('array')->clear();
    $this->override = new CeilingOverride();
});

it('returns null for an athlete with no override today', function (): void {
    expect($this->override->get(1))->toBeNull();
});

it('round-trips the ceiling as a float', function (): void {
    $this->override->set(1, 2.5);

    expect($this->override->get(1))->toBe(2.5);
});

it('reads back a numeric string as a float, since redis returns numerics unserialized', function (): void {
    Cache::put('ceiling-override:1:'.Carbon::today()->toDateString(), '2.5', Carbon::tomorrow());

    expect($this->override->get(1))->toBe(2.5);
});

it('clears an override', function (): void {
    $this->override->set(1, 2.5);
    $this->override->clear(1);

    expect($this->override->get(1))->toBeNull();
});

it('keys the override per athlete', function (): void {
    $this->override->set(1, 2.5);

    expect($this->override->get(2))->toBeNull();
});

it('does not carry into tomorrow', function (): void {
    $this->override->set(1, 2.5);

    Carbon::setTestNow(Carbon::tomorrow()->addHour());

    expect($this->override->get(1))->toBeNull();
});
