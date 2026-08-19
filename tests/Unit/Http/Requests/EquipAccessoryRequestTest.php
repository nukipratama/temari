<?php

declare(strict_types=1);

use App\Http\Requests\EquipAccessoryRequest;
use Illuminate\Support\Facades\Validator;

function passesEquipAccessory(array $data): bool
{
    return Validator::make($data, new EquipAccessoryRequest()->rules())->passes();
}

it('authorizes the request', function (): void {
    expect(new EquipAccessoryRequest()->authorize())->toBeTrue();
});

it('accepts a valid unlock key', function (): void {
    expect(passesEquipAccessory(['unlock_key' => 'accessory.medal_first']))->toBeTrue();
});

it('rejects a missing unlock key', function (): void {
    expect(passesEquipAccessory([]))->toBeFalse();
});

it('rejects a non-string unlock key', function (): void {
    expect(passesEquipAccessory(['unlock_key' => ['nested' => 'array']]))->toBeFalse();
});
