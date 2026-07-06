<?php

declare(strict_types=1);

use App\Http\Requests\UpdateHrZonesRequest;
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
    expect((new UpdateHrZonesRequest())->authorize())->toBeTrue();
});

it('passes valid ascending gapless zones within bounds', function (): void {
    expect(validateHrZones(hrZonePayload())->passes())->toBeTrue();
});

it('rejects non-ascending zones', function (): void {
    $zones = [
        ['lo' => 116, 'hi' => 116],
        ['lo' => 116, 'hi' => 154],
        ['lo' => 154, 'hi' => 168],
        ['lo' => 168, 'hi' => 176],
        ['lo' => 176, 'hi' => 999],
    ];

    expect(validateHrZones(hrZonePayload(zones: $zones))->passes())->toBeFalse();
});

it('rejects gapped zones where hi does not equal the next lo', function (): void {
    $zones = [
        ['lo' => 116, 'hi' => 138],
        ['lo' => 140, 'hi' => 154],
        ['lo' => 154, 'hi' => 168],
        ['lo' => 168, 'hi' => 176],
        ['lo' => 176, 'hi' => 999],
    ];

    expect(validateHrZones(hrZonePayload(zones: $zones))->passes())->toBeFalse();
});

it('rejects a max_hr outside 120-220', function (): void {
    expect(validateHrZones(hrZonePayload(['max_hr' => 119]))->passes())->toBeFalse()
        ->and(validateHrZones(hrZonePayload(['max_hr' => 221]))->passes())->toBeFalse();
});

it('rejects a resting_hr outside 30-90', function (): void {
    expect(validateHrZones(hrZonePayload(['resting_hr' => 29]))->passes())->toBeFalse()
        ->and(validateHrZones(hrZonePayload(['resting_hr' => 91]))->passes())->toBeFalse();
});

it('accepts max_hr and resting_hr exactly at their inclusive between() boundaries', function (): void {
    $lowBoundary = array_values(UpdateHrZonesRequest::deriveZones(120, 30));
    $highBoundary = array_values(UpdateHrZonesRequest::deriveZones(220, 90));

    expect(validateHrZones(hrZonePayload(['max_hr' => 120, 'resting_hr' => 30], $lowBoundary))->passes())->toBeTrue()
        ->and(validateHrZones(hrZonePayload(['max_hr' => 220, 'resting_hr' => 90], $highBoundary))->passes())->toBeTrue();
});

it('rejects a resting_hr greater than or equal to max_hr', function (): void {
    expect(validateHrZones(hrZonePayload(['max_hr' => 120, 'resting_hr' => 80]))->passes())->toBeTrue()
        ->and(validateHrZones(hrZonePayload(['max_hr' => 80, 'resting_hr' => 80]))->passes())->toBeFalse();
});

it('rejects Z1.lo below resting_hr', function (): void {
    $zones = [
        ['lo' => 50, 'hi' => 138],
        ['lo' => 138, 'hi' => 154],
        ['lo' => 154, 'hi' => 168],
        ['lo' => 168, 'hi' => 176],
        ['lo' => 176, 'hi' => 999],
    ];

    expect(validateHrZones(hrZonePayload(zones: $zones))->passes())->toBeFalse();
});

it('rejects Z5.hi less than or equal to max_hr', function (): void {
    $zones = [
        ['lo' => 116, 'hi' => 138],
        ['lo' => 138, 'hi' => 154],
        ['lo' => 154, 'hi' => 168],
        ['lo' => 168, 'hi' => 176],
        ['lo' => 176, 'hi' => 180],
    ];

    expect(validateHrZones(hrZonePayload(zones: $zones))->passes())->toBeFalse();
});

it('rejects a zone count other than five', function (): void {
    $zones = [
        ['lo' => 116, 'hi' => 138],
        ['lo' => 138, 'hi' => 154],
    ];

    expect(validateHrZones(hrZonePayload(zones: $zones))->passes())->toBeFalse();
});

it('derives the config default zones at max 180 resting 55', function (): void {
    expect(UpdateHrZonesRequest::deriveZones(180, 55))->toBe([
        'Z1' => ['lo' => 116, 'hi' => 138],
        'Z2' => ['lo' => 138, 'hi' => 154],
        'Z3' => ['lo' => 154, 'hi' => 168],
        'Z4' => ['lo' => 168, 'hi' => 176],
        'Z5' => ['lo' => 176, 'hi' => 999],
    ]);
});

it('derives gapless zones for an arbitrary max and resting', function (): void {
    $zones = UpdateHrZonesRequest::deriveZones(200, 60);

    expect($zones['Z1']['hi'])->toBe($zones['Z2']['lo'])
        ->and($zones['Z2']['hi'])->toBe($zones['Z3']['lo'])
        ->and($zones['Z3']['hi'])->toBe($zones['Z4']['lo'])
        ->and($zones['Z4']['hi'])->toBe($zones['Z5']['lo'])
        ->and($zones['Z5']['hi'])->toBe(999)
        ->and($zones['Z1']['lo'])->toBeGreaterThanOrEqual(60);
});
