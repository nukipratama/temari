<?php

declare(strict_types=1);

use App\Http\Requests\StoreRecordStampRequest;
use Illuminate\Support\Facades\Validator;

function validateRecordStamp(array $payload): Illuminate\Validation\Validator
{
    $request = new StoreRecordStampRequest();

    return Validator::make($payload, $request->rules());
}

it('authorizes the request', function (): void {
    expect(new StoreRecordStampRequest()->authorize())->toBeTrue();
});

it('passes for every tracked record key', function (): void {
    foreach (['marathon', 'half_marathon', '10km', '5km', 'longest_run'] as $key) {
        expect(validateRecordStamp(['record_key' => $key])->passes())->toBeTrue();
    }
});

it('rejects a record_key outside the tracked set', function (): void {
    expect(validateRecordStamp(['record_key' => 'best_5min'])->fails())->toBeTrue()
        ->and(validateRecordStamp(['record_key' => '1km'])->fails())->toBeTrue()
        ->and(validateRecordStamp(['record_key' => 'not_a_real_key'])->fails())->toBeTrue();
});

it('requires record_key', function (): void {
    expect(validateRecordStamp([])->fails())->toBeTrue();
});
