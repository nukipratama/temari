<?php

declare(strict_types=1);

use App\Enums\Effort;
use App\Enums\SessionType;
use App\Services\Run\Metrics\SessionIntent;

it('maps each planned session type to its effort', function (SessionType $type, Effort $effort): void {
    expect(Effort::fromSessionType($type))->toBe($effort);
})->with([
    [SessionType::Easy, Effort::Easy],
    [SessionType::Long, Effort::Steady],
    [SessionType::Tempo, Effort::Steady],
    [SessionType::Interval, Effort::Hard],
    [SessionType::Race, Effort::Hard],
    [SessionType::Rest, Effort::Rest],
]);

it('maps each session intent to its effort', function (string $intent, Effort $effort): void {
    expect(Effort::fromIntent($intent, 30 * 60))->toBe($effort);
})->with([
    [SessionIntent::RACE, Effort::Hard],
    [SessionIntent::WORKOUT, Effort::Hard],
    [SessionIntent::LONG_RUN, Effort::Steady],
    [SessionIntent::EASY, Effort::Easy],
    [SessionIntent::UNKNOWN, Effort::Unknown],
]);

it('reads an easy run of ninety minutes or more as steady', function (): void {
    expect(Effort::fromIntent(SessionIntent::EASY, Effort::LONG_RUN_MIN_SECONDS - 1))->toBe(Effort::Easy)
        ->and(Effort::fromIntent(SessionIntent::EASY, Effort::LONG_RUN_MIN_SECONDS))->toBe(Effort::Steady);
});

it('keeps an unknown intent unknown however long the run', function (): void {
    expect(Effort::fromIntent(SessionIntent::UNKNOWN, 3 * 60 * 60))->toBe(Effort::Unknown);
});
