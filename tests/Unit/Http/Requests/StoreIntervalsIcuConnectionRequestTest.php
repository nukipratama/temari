<?php

declare(strict_types=1);

use App\Http\Requests\StoreIntervalsIcuConnectionRequest;
use Illuminate\Support\Facades\Validator;

/**
 * @param  array<string, string>  $input
 * @return array<string, mixed>
 */
function validatedIntervalsIcuInput(array $input): array
{
    $request = StoreIntervalsIcuConnectionRequest::create('/settings/intervals-icu', 'POST', $input);
    $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

    return $request->validated();
}

it('accepts the athlete ID with or without its leading i', function (string $given): void {
    expect(validatedIntervalsIcuInput(['athlete_id' => $given, 'api_key' => ' key ']))->toBe(['athlete_id' => 'i123456', 'api_key' => 'key']);
})->with(['i123456', '123456', ' I123456 ']);

it('rejects anything that is not an intervals.icu athlete ID', function (array $payload, bool $passes): void {
    expect(Validator::make($payload, new StoreIntervalsIcuConnectionRequest()->rules())->passes())->toBe($passes);
})->with([
    'valid' => [['athlete_id' => 'i123456', 'api_key' => 'key'], true],
    'missing key' => [['athlete_id' => 'i123456'], false],
    'missing id' => [['api_key' => 'key'], false],
    'url pasted' => [['athlete_id' => 'https://intervals.icu/athlete/i1', 'api_key' => 'key'], false],
    'oversized key' => [['athlete_id' => 'i1', 'api_key' => str_repeat('k', 129)], false],
]);
