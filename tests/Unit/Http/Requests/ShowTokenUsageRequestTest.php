<?php

declare(strict_types=1);

use App\Http\Requests\ShowTokenUsageRequest;
use Illuminate\Support\Facades\Validator;

function passesTokenUsage(array $data): bool
{
    return Validator::make($data, new ShowTokenUsageRequest()->rules())->passes();
}

it('authorizes the request', function (): void {
    expect(new ShowTokenUsageRequest()->authorize())->toBeTrue();
});

it('accepts an empty payload (all fields optional)', function (): void {
    expect(passesTokenUsage([]))->toBeTrue();
});

it('accepts a known range token', function (): void {
    expect(passesTokenUsage(['range' => '30d']))->toBeTrue();
});

it('rejects an unknown range token', function (): void {
    expect(passesTokenUsage(['range' => 'yesterday']))->toBeFalse();
});

it('rejects a malformed from date', function (): void {
    expect(passesTokenUsage(['from' => 'yesterday']))->toBeFalse();
});

it('accepts a Y-m-d from/to pair', function (): void {
    expect(passesTokenUsage(['from' => '2026-05-01', 'to' => '2026-05-19']))->toBeTrue();
});
