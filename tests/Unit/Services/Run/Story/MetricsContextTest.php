<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Run\Story\MetricsContext;
use App\Services\Run\Story\VerdictTimelineItem;
use Illuminate\Support\Carbon;

it('exposes user, vibe, load, verdicts, and asOf as readable props', function (): void {
    $user = User::factory()->make(['id' => 7, 'name' => 'Ada']);
    $verdict = new VerdictTimelineItem(
        activityId: 1,
        mood: 'nyala',
        moodFace: '✨',
        oneline: 'mantap',
        startedAt: Carbon::parse('2026-05-10'),
        distanceKm: 5.2,
    );
    $asOf = Carbon::parse('2026-05-13');

    $ctx = new MetricsContext(
        user: $user,
        vibeState: 'fresh',
        load: ['form' => -1.5, 'form_status' => 'optimal'],
        recentVerdicts: [$verdict],
        asOf: $asOf,
    );

    expect($ctx->user)->toBe($user)
        ->and($ctx->vibeState)->toBe('fresh')
        ->and($ctx->load)->toBe(['form' => -1.5, 'form_status' => 'optimal'])
        ->and($ctx->recentVerdicts)->toBe([$verdict])
        ->and($ctx->asOf)->toEqual($asOf);
});
