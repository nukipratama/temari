<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('dispatches the monthly recap once per user who ran last month', function (): void {
    Carbon::setTestNow('2026-06-01 05:45:00');

    $active = User::factory()->create();
    $activity = Activity::factory()->for($active)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-05-14 06:30:00'),
    ]);

    $stale = User::factory()->create();
    $oldActivity = Activity::factory()->for($stale)->create();
    ActivityDetail::factory()->for($oldActivity)->create([
        'start_date_local' => Carbon::parse('2026-03-02 06:30:00'),
    ]);

    $captured = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator', 'invalidate');

            return new Analysis();
        });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:monthly-recap')
        ->expectsOutputToContain('Dispatched monthly recap for 1 active users (2026-05).')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
        ->and($captured[0]['subjectId'])->toBe($active->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::MonthlyRecap)
        ->and($captured[0]['discriminator'])->toBe('2026-05')
        ->and($captured[0]['invalidate'])->toBeTrue();

    Carbon::setTestNow();
});

it('dispatches nothing when nobody ran last month', function (): void {
    Carbon::setTestNow('2026-06-01 05:45:00');

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:monthly-recap')
        ->expectsOutputToContain('Dispatched monthly recap for 0 active users (2026-05).')
        ->assertSuccessful();

    Carbon::setTestNow();
});
