<?php

declare(strict_types=1);

use App\Services\IntervalsIcu\IntervalsIcuWorkout;

it('carries the event name and the description intervals.icu parses', function (): void {
    $workout = new IntervalsIcuWorkout('Easy 8 km', "Planned by temari.\n\n- 8km 6:33-7:32/km Pace intensity=active");

    expect($workout->name)->toBe('Easy 8 km')
        ->and($workout->description)->toEndWith('intensity=active');
});
