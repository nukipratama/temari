<?php

declare(strict_types=1);

use App\Http\Requests\UpdateHrZonesRequest;
use App\Services\Run\Metrics\HeartRateZones;
use Illuminate\Support\Facades\Validator;

/**
 * @param  array<int, array{lo:int, hi:int}>|null  $zones
 * @return array<string, mixed>
 */
function hrZonePayload(array $overrides = [], ?array $zones = null): array
{
    return [
        'max_hr' => 180,
        'resting_hr' => 55,
        'zones' => $zones ?? [
            ['lo' => 116, 'hi' => 138],
            ['lo' => 138, 'hi' => 154],
            ['lo' => 154, 'hi' => 168],
            ['lo' => 168, 'hi' => 176],
            ['lo' => 176, 'hi' => 999],
        ],
        ...$overrides,
    ];
}

function validateHrZones(array $payload): Illuminate\Validation\Validator
{
    $request = new UpdateHrZonesRequest();
    $validator = Validator::make($payload, $request->rules(), $request->messages());
    $request->setValidator($validator);
    $request->withValidator($validator);

    return $validator;
}

it('authorizes the request', function (): void {
    expect(new UpdateHrZonesRequest()->authorize())->toBeTrue();
});

it('passes valid ascending gapless zones within bounds', function (): void {
    expect(validateHrZones(hrZonePayload())->passes())->toBeTrue();
});

it('rejects zones that are not five gapless ascending bands around the resting and max rates', function (array $zones): void {
    expect(validateHrZones(hrZonePayload(zones: $zones))->passes())->toBeFalse();
})->with([
    'non-ascending' => [[
        ['lo' => 116, 'hi' => 116],
        ['lo' => 116, 'hi' => 154],
        ['lo' => 154, 'hi' => 168],
        ['lo' => 168, 'hi' => 176],
        ['lo' => 176, 'hi' => 999],
    ]],
    'gapped, where hi does not equal the next lo' => [[
        ['lo' => 116, 'hi' => 138],
        ['lo' => 140, 'hi' => 154],
        ['lo' => 154, 'hi' => 168],
        ['lo' => 168, 'hi' => 176],
        ['lo' => 176, 'hi' => 999],
    ]],
    'Z1.lo below resting_hr' => [[
        ['lo' => 50, 'hi' => 138],
        ['lo' => 138, 'hi' => 154],
        ['lo' => 154, 'hi' => 168],
        ['lo' => 168, 'hi' => 176],
        ['lo' => 176, 'hi' => 999],
    ]],
    'Z5.hi at or below max_hr' => [[
        ['lo' => 116, 'hi' => 138],
        ['lo' => 138, 'hi' => 154],
        ['lo' => 154, 'hi' => 168],
        ['lo' => 168, 'hi' => 176],
        ['lo' => 176, 'hi' => 180],
    ]],
    'a zone count other than five' => [[
        ['lo' => 116, 'hi' => 138],
        ['lo' => 138, 'hi' => 154],
    ]],
]);

it('rejects a max_hr outside 120-220 or a resting_hr outside 30-90', function (array $overrides): void {
    expect(validateHrZones(hrZonePayload($overrides))->passes())->toBeFalse();
})->with([
    'max_hr below' => [['max_hr' => 119]],
    'max_hr above' => [['max_hr' => 221]],
    'resting_hr below' => [['resting_hr' => 29]],
    'resting_hr above' => [['resting_hr' => 91]],
]);

it('accepts max_hr and resting_hr exactly at their inclusive between() boundaries', function (): void {
    $lowBoundary = array_values(HeartRateZones::derive(120, 30));
    $highBoundary = array_values(HeartRateZones::derive(220, 90));

    expect(validateHrZones(hrZonePayload(['max_hr' => 120, 'resting_hr' => 30], $lowBoundary))->passes())->toBeTrue()
        ->and(validateHrZones(hrZonePayload(['max_hr' => 220, 'resting_hr' => 90], $highBoundary))->passes())->toBeTrue();
});

it('rejects a resting_hr greater than or equal to max_hr', function (): void {
    expect(validateHrZones(hrZonePayload(['max_hr' => 120, 'resting_hr' => 80]))->passes())->toBeTrue()
        ->and(validateHrZones(hrZonePayload(['max_hr' => 80, 'resting_hr' => 80]))->passes())->toBeFalse();
});
