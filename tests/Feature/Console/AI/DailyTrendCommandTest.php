<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('dispatches trend caption analysis for each active user in the last 7 days', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $today = Carbon::today()->toDateString();

    $userA = User::factory()->create();
    Activity::factory()->for($userA)->create(['analyzed_at' => Carbon::today()->subDays(2)]);
    $userB = User::factory()->create();
    Activity::factory()->for($userB)->create(['analyzed_at' => Carbon::today()->subDays(6)]);

    $stale = User::factory()->create();
    Activity::factory()->for($stale)->create(['analyzed_at' => Carbon::today()->subDays(8)]);

    $captured = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->twice()
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $jobClass = null, ?string $discriminator = null, bool $force = false) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator', 'force');

            return new Analysis();
        });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-trend')
        ->expectsOutputToContain('Dispatched trend caption analysis for 2 active users.')
        ->assertSuccessful();

    $subjectIds = collect($captured)
        ->map(fn (array $args): int => $args['subjectId'])
        ->sort()
        ->values()
        ->all();
    expect($subjectIds)->toBe(collect([$userA->id, $userB->id])->sort()->values()->all());

    foreach ($captured as $args) {
        expect($args['subjectOrType'])->toBe(AnalysisType::TREND_CAPTION_SUBJECT_TYPE)
            ->and($args['type'])->toBe(AnalysisType::TrendCaption)
            ->and($args['discriminator'])->toBe($today)
            ->and($args['force'])->toBeTrue();
    }

    Carbon::setTestNow();
});

it('reports zero active users when no analyzed activities are recent', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');

    $user = User::factory()->create();
    Activity::factory()->for($user)->create(['analyzed_at' => Carbon::today()->subDays(15)]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-trend')
        ->expectsOutputToContain('Dispatched trend caption analysis for 0 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});
