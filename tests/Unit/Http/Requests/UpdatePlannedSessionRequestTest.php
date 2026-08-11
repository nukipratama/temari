<?php

declare(strict_types=1);

use App\Http\Requests\UpdatePlannedSessionRequest;
use Illuminate\Support\Facades\Validator;

function validatePlannedSessionUpdate(array $payload): Illuminate\Validation\Validator
{
    $request = new UpdatePlannedSessionRequest();

    return Validator::make($payload, $request->rules());
}

it('authorizes the request', function (): void {
    expect(new UpdatePlannedSessionRequest()->authorize())->toBeTrue();
});

it('passes an empty payload, since every field is optional', function (): void {
    expect(validatePlannedSessionUpdate([])->passes())->toBeTrue();
});

it('passes a valid move (date)', function (): void {
    expect(validatePlannedSessionUpdate(['date' => now()->addDay()->toDateString()])->passes())->toBeTrue();
});

it('rejects an invalid date', function (): void {
    expect(validatePlannedSessionUpdate(['date' => 'not-a-date'])->fails())->toBeTrue();
});

it('passes a valid resize (distance_band)', function (): void {
    expect(validatePlannedSessionUpdate(['distance_band' => 'medium'])->passes())->toBeTrue();
});

it('rejects an unknown distance_band', function (): void {
    expect(validatePlannedSessionUpdate(['distance_band' => 'huge'])->fails())->toBeTrue();
});

it('passes a valid block (session_type = rest)', function (): void {
    expect(validatePlannedSessionUpdate(['session_type' => 'rest'])->passes())->toBeTrue();
});

it('rejects an unknown session_type', function (): void {
    expect(validatePlannedSessionUpdate(['session_type' => 'sprint'])->fails())->toBeTrue();
});

it('allows a null pace_band, for a rest day', function (): void {
    expect(validatePlannedSessionUpdate(['pace_band' => null])->passes())->toBeTrue();
});

it('rejects an unknown pace_band', function (): void {
    expect(validatePlannedSessionUpdate(['pace_band' => 'sprint'])->fails())->toBeTrue();
});

it('passes an explicit pin/unpin toggle', function (): void {
    expect(validatePlannedSessionUpdate(['pinned' => true])->passes())->toBeTrue()
        ->and(validatePlannedSessionUpdate(['pinned' => false])->passes())->toBeTrue();
});

it('rejects a non-boolean pinned value', function (): void {
    expect(validatePlannedSessionUpdate(['pinned' => 'yes'])->fails())->toBeTrue();
});
