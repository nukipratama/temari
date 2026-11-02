<?php

declare(strict_types=1);

use App\Services\Weather\HeatIndex;

it('matches the NWS heat index chart', function (float $tempF, float $humidity, float $chartF): void {
    $tempC = ($tempF - 32) * 5 / 9;

    expect(HeatIndex::fahrenheit($tempC, $humidity))->toEqualWithDelta($chartF, 1.0);
})->with([
    'mild air uses the simple formula' => [80, 40, 80],
    '86 °F at 60 %' => [86, 60, 91],
    '90 °F at 50 %' => [90, 50, 95],
    'humid low 80s take the high-humidity adjustment' => [84, 90, 98],
    '100 °F at 40 %' => [100, 40, 109],
    'dry heat takes the low-humidity adjustment' => [104, 10, 98],
]);
