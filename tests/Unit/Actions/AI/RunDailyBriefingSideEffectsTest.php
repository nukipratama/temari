<?php

declare(strict_types=1);

use App\Actions\AI\RunDailyBriefingSideEffects;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is resolved as the shared daily briefing side-effect action', function (): void {
    expect(app(RunDailyBriefingSideEffects::class))->toBeInstanceOf(RunDailyBriefingSideEffects::class);
});
