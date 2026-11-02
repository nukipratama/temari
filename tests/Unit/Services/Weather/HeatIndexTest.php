<?php

declare(strict_types=1);

use App\Services\Weather\HeatIndex;

it('matches the NWS heat index chart', function (float $tempF, float $humidity, float $chartF): void {
    $tempC = ($tempF - 32) * 5 / 9;

    expect(HeatIndex::fahrenheit($tempC, $humidity))->toEqualWithDelta($chartF, 0.5);
})->with([
    'mild air uses the simple formula' => [80, 40, 80],
    '86 °F at 60 %' => [86, 60, 91],
    '90 °F at 50 %' => [90, 50, 95],
    '84 °F at 90 %' => [84, 90, 98],
    '100 °F at 40 %' => [100, 40, 109],
]);

it('applies the NWS humidity adjustments to the regression', function (float $tempF, float $humidity, float $equationF): void {
    $tempC = ($tempF - 32) * 5 / 9;

    expect(HeatIndex::fahrenheit($tempC, $humidity))->toEqualWithDelta($equationF, 0.05);
})->with([
    'saturated low 80s add 2.1 °F' => [80, 100, 89.29],
    'dry heat subtracts 2.0 °F' => [95, 5, 88.18],
]);
