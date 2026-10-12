<?php

declare(strict_types=1);

use App\Http\Requests\ShowNarrationOverviewRequest;
use Illuminate\Support\Facades\Validator;

function passesTokenUsage(array $data): bool
{
    return Validator::make($data, new ShowNarrationOverviewRequest()->rules())->passes();
}

it('authorizes the request', function (): void {
    expect(new ShowNarrationOverviewRequest()->authorize())->toBeTrue();
});

it('accepts the filters it knows', function (array $data): void {
    expect(passesTokenUsage($data))->toBeTrue();
})->with([
    'an empty payload (all fields optional)' => [[]],
    'a known range token' => [['range' => '30d']],
    'a Y-m-d from/to pair' => [['from' => '2026-05-01', 'to' => '2026-05-19']],
]);

it('rejects an unknown range token or a malformed from date', function (array $data): void {
    expect(passesTokenUsage($data))->toBeFalse();
})->with([
    'an unknown range token' => [['range' => 'yesterday']],
    'a malformed from date' => [['from' => 'yesterday']],
]);

it('accepts a numeric athlete filter and rejects a name', function (): void {
    expect(passesTokenUsage(['athlete' => '7']))->toBeTrue()
        ->and(passesTokenUsage(['athlete' => 'alice']))->toBeFalse();
});
