<?php

declare(strict_types=1);

use App\Enums\SessionType;

it('names the effort score each session type usually feels like on the CR-10 scale', function (SessionType $type, ?array $target): void {
    expect($type->perceivedEffortTarget())->toBe($target);
})->with([
    'easy' => [SessionType::Easy, ['low' => 2, 'high' => 4]],
    'long' => [SessionType::Long, ['low' => 3, 'high' => 5]],
    'tempo' => [SessionType::Tempo, ['low' => 6, 'high' => 7]],
    'interval' => [SessionType::Interval, ['low' => 8, 'high' => 9]],
    'race' => [SessionType::Race, null],
    'rest' => [SessionType::Rest, null],
]);
