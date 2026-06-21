<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Jobs\AI\AnalyzeBriefingFeaturedKartuVoiceJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('dispatches briefing group and daily row types for each active user', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $today = Carbon::today()->toDateString();

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);
    $card = RunCard::factory()->for($activity)->create(['rarity' => Rarity::Epic]);

    $briefingGroupCalls = [];
    $requestCalls = [];

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('requestBriefingGroup')
        ->once()
        ->andReturnUsing(function (User $u, string $discriminator) use (&$briefingGroupCalls): void {
            $briefingGroupCalls[] = ['user_id' => $u->id, 'discriminator' => $discriminator];
        });
    $service->shouldReceive('request')
        ->times(4) // TrendCaption, MascotVoice, DailyGreeting, FeaturedKartuVoice
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$requestCalls): Analysis {
            $requestCalls[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator', 'invalidate');

            return new Analysis();
        });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing + trend caption) for 1 active users.')
        ->assertSuccessful();

    // Briefing group called once for the user with today's discriminator.
    expect($briefingGroupCalls)->toHaveCount(1);
    expect($briefingGroupCalls[0]['user_id'])->toBe($user->id)
        ->and($briefingGroupCalls[0]['discriminator'])->toBe($today);

    // Trend caption first, then the two day-keyed rows, then the featured-kartu row.
    $requestedTypes = collect($requestCalls)->map(fn (array $c): string => $c['type']->value)->all();
    expect($requestedTypes)->toBe([
        'trend_caption',
        'briefing_mascot_voice',
        'daily_greeting',
        'briefing_featured_kartu_voice',
    ]);

    $byType = collect($requestCalls)->keyBy(fn (array $c): string => $c['type']->value);
    foreach ($requestCalls as $call) {
        expect($call['subjectId'])->toBe($user->id);
    }
    // Day-keyed rows carry today; the featured voice keys off the card id so it
    // regenerates only when the featured pick changes.
    expect($byType['trend_caption']['subjectOrType'])->toBe(AnalysisType::TREND_CAPTION_SUBJECT_TYPE)
        ->and($byType['trend_caption']['discriminator'])->toBe($today)
        ->and($byType['briefing_mascot_voice']['discriminator'])->toBe($today)
        ->and($byType['daily_greeting']['discriminator'])->toBe($today)
        ->and($byType['briefing_featured_kartu_voice']['discriminator'])->toBe((string) $card->id);

    // The LLM types invalidate=false; the rule-based trend caption recomputes (invalidate=true).
    $invalidateByType = $byType->map(fn (array $c): bool => $c['invalidate']);
    expect($invalidateByType['trend_caption'])->toBeTrue()
        ->and($invalidateByType['briefing_mascot_voice'])->toBeFalse()
        ->and($invalidateByType['briefing_featured_kartu_voice'])->toBeFalse()
        ->and($invalidateByType['daily_greeting'])->toBeFalse();

    Carbon::setTestNow();
});

it('skips the demo user even with recent analyzed activity', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');

    $real = User::factory()->create();
    $realActivity = Activity::factory()->for($real)->analyzed()->create(['analyzed_at' => Carbon::today()->subDays(1)]);
    ActivityDetail::factory()->for($realActivity)->create(['start_date_local' => Carbon::today()->subDays(1)]);
    RunCard::factory()->for($realActivity)->create(['rarity' => Rarity::Rare]);
    $demo = User::factory()->demo()->create();
    Activity::factory()->for($demo)->create(['analyzed_at' => Carbon::today()->subDays(1)]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('requestBriefingGroup')->once();
    $service->shouldReceive('request')->times(4)->andReturn(new Analysis());
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing + trend caption) for 1 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('a second same-day run only fills missing types and never re-bills Done rows', function (): void {
    // ai:daily-briefing is idempotent: if it runs a second time the same day (a
    // manual re-trigger, or a developer re-run), it must create + dispatch ONLY the
    // rows still missing for the day (e.g. featured_kartu_voice that never got
    // produced), and must NOT re-dispatch (and so never re-bill) rows already Done.
    Carbon::setTestNow('2026-05-11 12:00:00');
    $today = Carbon::today()->toDateString();

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create(['analyzed_at' => Carbon::today()->subDays(2)]);
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()->subDays(2)]);
    $card = RunCard::factory()->for($activity)->create(['rarity' => Rarity::Epic]);

    // Simulate the earlier (00:01) run having already completed the mascot voice
    // and daily greeting, but NOT the featured kartu voice (the row a missed tick
    // left unproduced; here it is simply absent).
    Analysis::factory()->done('kata temari kemarin')->create([
        'subject_type' => AnalysisType::BriefingMascotVoice->subjectType(),
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => $today,
    ]);
    Analysis::factory()->done('selamat pagi')->create([
        'subject_type' => AnalysisType::DailyGreeting->subjectType(),
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::DailyGreeting,
        'discriminator' => $today,
    ]);

    // Real service + faked bus so we observe exactly what the re-run dispatches.
    Bus::fake();

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing + trend caption) for 1 active users.')
        ->assertSuccessful();

    // The missing type is created + dispatched once, keyed by the featured card id.
    Bus::assertDispatched(AnalyzeBriefingFeaturedKartuVoiceJob::class, 1);
    $featured = Analysis::query()
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::BriefingFeaturedKartuVoice)
        ->where('discriminator', (string) $card->id)
        ->firstOrFail();
    expect($featured->status)->toBe(AnalysisStatus::Queued);

    // The already-Done types are neither re-dispatched nor reset (no re-bill).
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertNotDispatched(AnalyzeDailyGreetingJob::class);

    $mascot = Analysis::query()
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::BriefingMascotVoice)
        ->where('discriminator', $today)
        ->firstOrFail();
    $greeting = Analysis::query()
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::DailyGreeting)
        ->where('discriminator', $today)
        ->firstOrFail();
    expect($mascot->status)->toBe(AnalysisStatus::Done)
        ->and($mascot->content)->toBe('kata temari kemarin')
        ->and($greeting->status)->toBe(AnalysisStatus::Done)
        ->and($greeting->content)->toBe('selamat pagi');

    Carbon::setTestNow();
});

it('reports zero active users when no analyzed activities are recent', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');

    $user = User::factory()->create();
    Activity::factory()->for($user)->create(['analyzed_at' => Carbon::today()->subDays(15)]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('requestBriefingGroup');
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing + trend caption) for 0 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});
