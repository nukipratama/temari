<?php

declare(strict_types=1);

use App\Http\Requests\EquipAksesoriRequest;
use Illuminate\Support\Facades\Validator;

function passesEquipAksesori(array $data): bool
{
    return Validator::make($data, new EquipAksesoriRequest()->rules())->passes();
}

it('authorizes the request', function (): void {
    expect(new EquipAksesoriRequest()->authorize())->toBeTrue();
});

it('accepts a valid unlock key', function (): void {
    expect(passesEquipAksesori(['unlock_key' => 'accessory.medal_first']))->toBeTrue();
});

it('rejects a missing unlock key', function (): void {
    expect(passesEquipAksesori([]))->toBeFalse();
});

it('rejects a non-string unlock key', function (): void {
    expect(passesEquipAksesori(['unlock_key' => ['nested' => 'array']]))->toBeFalse();
});
