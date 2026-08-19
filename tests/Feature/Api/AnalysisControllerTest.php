<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Requests\TriggerAnalysisRequest;
use App\Jobs\AI\AnalyzeBriefingFeaturedKartuVoiceJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePrContextJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunnerProfile;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\BackfillAgeGate;
use App\Services\AI\ChainResolver;
use App\Services\Run\Metrics\SummaryRecomputer;
use Illuminate\Auth\Access\AuthorizationException;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use App\Support\Cooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    // The briefing cases key off a literal 2026-05-18 day. Pin the clock to it
    // so they stay inside the narration age cutoff as wall time moves on; the
    // cases below that need another date set their own.
    Carbon::setTestNow('2026-05-18 05:30:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('rejects unknown analysis types with 422', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->postJson('/api/analyses/nonsense/1/trigger')
        ->assertStatus(422)
        ->assertJson(['error' => 'unknown_analysis_type']);
});

it('rejects an over-long discriminator with a validation 422', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=".str_repeat('x', 65))
        ->assertStatus(422)
        ->assertJsonValidationErrors('discriminator');
});

it('requires authentication', function (): void {
    $this->postJson('/api/analyses/briefing_mascot_voice/1/trigger')
        ->assertStatus(401);
});

it('rejects triggering briefing for another user', function (): void {
    $self = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($self)
        ->postJson("/api/analyses/briefing_mascot_voice/{$other->id}/trigger?discriminator=2026-05-18")
        ->assertStatus(403);
});

it('triggers a briefing suggestion analysis for the authenticated user', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJsonStructure(['id', 'status', 'content', 'type', 'subject_type', 'subject_id', 'discriminator']);

    expect($response->json('status'))->toBe(AnalysisStatus::Queued->value)
        ->and($response->json('discriminator'))->toBe('2026-05-18');

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

it('force re-triggers a briefing when no cooldown window is active', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('old')->create([
        'subject_id' => $user->id,
        'discriminator' => '2026-05-18',
    ]);

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJsonPath('status', AnalysisStatus::Queued->value)
        ->assertJsonPath('retry_after_seconds', null);

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

it('returns the cached payload with retry_after_seconds when within cooldown', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('fresh content')->create([
        'subject_id' => $user->id,
        'discriminator' => '2026-05-18',
    ]);
    RateLimiter::hit(Analysis::cooldownKey(AnalysisType::BriefingMascotVoice, $user->id, '2026-05-18'), Cooldown::WINDOW_SECONDS);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJsonPath('status', AnalysisStatus::Done->value)
        ->assertJsonPath('content', 'fresh content');

    expect($response->json('retry_after_seconds'))->toBeGreaterThan(0)->toBeLessThanOrEqual(Cooldown::WINDOW_SECONDS);
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

it('authorizes post-run speech only for the activity owner', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $activity = Activity::factory()->for($owner)->create();

    $this->actingAs($stranger)
        ->postJson("/api/analyses/post_run_speech/{$activity->id}/trigger")
        ->assertStatus(403);

    $this->actingAs($owner)
        ->postJson("/api/analyses/post_run_speech/{$activity->id}/trigger")
        ->assertSuccessful();

    Bus::assertDispatched(AnalyzeActivityJob::class);
});

it('responds with the flat payload shape at the top level, not a resource-wrapped "data" envelope', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('content here')->create([
        'subject_id' => $user->id,
        'discriminator' => '2026-05-18',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/analyses/briefing_mascot_voice/{$user->id}?discriminator=2026-05-18")
        ->assertSuccessful();

    expect(array_keys($response->json()))->toEqualCanonicalizing([
        'id', 'status', 'content', 'type', 'is_zone_dependent',
        'subject_type', 'subject_id', 'discriminator', 'attempts',
        'generated_at', 'retry_after_seconds',
    ]);
});

it('returns the current state via GET show', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('content here')->create([
        'subject_id' => $user->id,
        'discriminator' => '2026-05-18',
    ]);

    $this->actingAs($user)
        ->getJson("/api/analyses/briefing_mascot_voice/{$user->id}?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJsonPath('status', AnalysisStatus::Done->value)
        ->assertJsonPath('content', 'content here');
});

it('returns a pending pseudo-row when no analysis exists yet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/analyses/briefing_mascot_voice/{$user->id}?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJsonPath('status', AnalysisStatus::Pending->value)
        ->assertJsonPath('content', null)
        ->assertJsonPath('id', null);
});

it('GET show rejects unknown analysis types with 422', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->getJson('/api/analyses/nonsense/1')
        ->assertStatus(422)
        ->assertJson(['error' => 'unknown_analysis_type']);
});

it('authorizes weekly_recap only for the snapshot owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($owner)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
    ]);

    $this->actingAs($other)
        ->postJson("/api/analyses/weekly_recap/{$snap->id}/trigger")
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson("/api/analyses/weekly_recap/{$snap->id}/trigger")
        ->assertOk();
});

it('authorizes pr_context only for the personal record owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $pr = PersonalRecord::factory()->for($owner)->create();

    $this->actingAs($other)
        ->postJson("/api/analyses/pr_context/{$pr->id}/trigger")
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson("/api/analyses/pr_context/{$pr->id}/trigger")
        ->assertOk();
});

it('authorizes card_flavor only for the card activity owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $activity = Activity::factory()->for($owner)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
    ]);
    $card = RunCard::factory()->for($activity)->create();

    $this->actingAs($other)
        ->postJson("/api/analyses/card_flavor/{$card->id}/trigger")
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson("/api/analyses/card_flavor/{$card->id}/trigger")
        ->assertOk();
});

it('returns 429 when the per-user rate limit is exceeded', function (): void {
    config()->set('ai.rate_limit_per_minute', 3);
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user)
            ->postJson("/api/analyses/post_run_speech/{$activity->id}/trigger")
            ->assertSuccessful();
    }

    $this->actingAs($user)
        ->postJson("/api/analyses/post_run_speech/{$activity->id}/trigger")
        ->assertStatus(429);
});

it('isolates rate limits per user', function (): void {
    config()->set('ai.rate_limit_per_minute', 2);
    $a = User::factory()->create();
    $b = User::factory()->create();
    $activityA = Activity::factory()->for($a)->create();
    $activityB = Activity::factory()->for($b)->create();

    for ($i = 0; $i < 2; $i++) {
        $this->actingAs($a)->postJson("/api/analyses/post_run_speech/{$activityA->id}/trigger")->assertSuccessful();
    }
    $this->actingAs($a)->postJson("/api/analyses/post_run_speech/{$activityA->id}/trigger")->assertStatus(429);

    // user $b is untouched by user $a's bucket
    $this->actingAs($b)->postJson("/api/analyses/post_run_speech/{$activityB->id}/trigger")->assertSuccessful();
});

it('chained weekly_recap retry resumes the earliest unfilled link, not the clicked row', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');
    $user = User::factory()->create();

    // Earliest week is still Pending; a later week was clicked.
    $earliest = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $earliest->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    $clicked = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $clicked->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/weekly_recap/{$clicked->id}/trigger")
        ->assertOk();

    // The payload reflects the resumed (earliest) subject, not the clicked one.
    expect($response->json('subject_id'))->toBe($earliest->id);

    Carbon::setTestNow();
});

it('chained weekly_recap head regenerate (Done clicked row) re-narrates that exact row', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');
    $user = User::factory()->create();

    // An earlier still-Pending link exists, but a Done head is being regenerated.
    $earlier = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $earlier->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    $head = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    Analysis::factory()->done('latest recap')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $head->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'generated_at' => Carbon::now()->subHour(),
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/weekly_recap/{$head->id}/trigger")
        ->assertOk();

    // A Done clicked row is a head regenerate → stays on the clicked (head) row.
    expect($response->json('subject_id'))->toBe($head->id);

    Carbon::setTestNow();
});

it('chained weekly_recap regenerate on a Done NON-head row resumes the chain instead (server guard)', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');
    $user = User::factory()->create();

    // A mid-history Done row (not the head) plus a still-Pending earlier link.
    $earliest = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $earliest->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    $midDone = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 4]);
    Analysis::factory()->done('mid recap')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $midDone->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'generated_at' => Carbon::now()->subHour(),
    ]);
    // The actual head (latest runs>0 week) sits after the clicked mid row.
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 5]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/weekly_recap/{$midDone->id}/trigger")
        ->assertOk();

    // A Done non-head POST must NOT re-narrate itself; it resumes the earliest unfilled link.
    expect($response->json('subject_id'))->toBe($earliest->id);

    Carbon::setTestNow();
});

it('chained monthly_recap retry resumes the earliest unfilled month, not the clicked one', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    $user = User::factory()->create();

    // Earliest month is still Pending; a later month was clicked.
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-03',
        'status' => AnalysisStatus::Pending,
    ]);
    Analysis::factory()->failed()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/monthly_recap/{$user->id}/trigger?discriminator=2026-05")
        ->assertOk();

    // The payload reflects the resumed (earliest) month, not the clicked one.
    expect($response->json('discriminator'))->toBe('2026-03');

    Carbon::setTestNow();
});

it('chained monthly_recap head regenerate (Done clicked month) re-narrates that exact month', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    $user = User::factory()->create();

    // An earlier still-Pending month exists, but a Done head is being regenerated.
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-03',
        'status' => AnalysisStatus::Pending,
    ]);
    Analysis::factory()->done('latest recap')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
        'generated_at' => Carbon::now()->subHour(),
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/monthly_recap/{$user->id}/trigger?discriminator=2026-05")
        ->assertOk();

    // A Done clicked head month is a head regenerate → stays on the clicked month.
    expect($response->json('discriminator'))->toBe('2026-05');

    Carbon::setTestNow();
});

it('monthly_recap trigger on the still-open current month is an inert no-op (never narrated)', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    $user = User::factory()->create();

    // The current month (2026-06) is staged Pending but window-gated until it
    // closes. A hand-crafted trigger must not dispatch a narration.
    $row = Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-06',
        'status' => AnalysisStatus::Pending,
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/monthly_recap/{$user->id}/trigger?discriminator=2026-06")
        ->assertOk();

    expect($response->json('status'))->toBe('pending');
    expect($row->refresh()->status)->toBe(AnalysisStatus::Pending);
    Bus::assertNotDispatched(AnalyzeMonthlyRecapJob::class);

    Carbon::setTestNow();
});

it('weekly_recap trigger on the still-open current week is an inert no-op (never narrated)', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');
    $user = User::factory()->create();

    // A snapshot for the still-running current week (ends after the last closed
    // week's Sunday, 2026-05-17). Its recap waits for the weekly scheduler.
    $current = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24', 'runs' => 3]);
    $row = Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $current->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/weekly_recap/{$current->id}/trigger")
        ->assertOk();

    expect($response->json('status'))->toBe('pending');
    expect($row->refresh()->status)->toBe(AnalysisStatus::Pending);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);

    Carbon::setTestNow();
});

it('chained monthly_recap head regenerate ignores the still-open current month staged row', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    $user = User::factory()->create();

    // The current (still-running) month is staged Pending by the post-run
    // listener but must stay inert: it is not the chain head, and a head
    // regenerate must never resume forward into it.
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-06',
        'status' => AnalysisStatus::Pending,
    ]);
    Analysis::factory()->done('latest recap')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
        'generated_at' => Carbon::now()->subHour(),
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/monthly_recap/{$user->id}/trigger?discriminator=2026-05")
        ->assertOk();

    // The last closed month (2026-05) is the head and regenerates itself; the
    // open 2026-06 row is never narrated.
    expect($response->json('discriminator'))->toBe('2026-05');

    Carbon::setTestNow();
});

it('chained monthly_recap regenerate on a Done NON-head month resumes the chain instead (server guard)', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    $user = User::factory()->create();

    // A mid-history Done month (not the head) plus a still-Pending earlier link.
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-03',
        'status' => AnalysisStatus::Pending,
    ]);
    Analysis::factory()->done('mid recap')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-04',
        'generated_at' => Carbon::now()->subHour(),
    ]);
    // The actual head (latest month) sits after the clicked mid month.
    Analysis::factory()->done('head recap')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
        'generated_at' => Carbon::now()->subHour(),
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/monthly_recap/{$user->id}/trigger?discriminator=2026-04")
        ->assertOk();

    // A Done non-head POST must NOT re-narrate itself; it resumes the earliest unfilled month.
    expect($response->json('discriminator'))->toBe('2026-03');

    Carbon::setTestNow();
});

/**
 * An activity owned by $user dated $startDate with its post-run speech row in
 * $status, used to exercise the per-activity chain in the controller.
 */
function activityWithSpeech(User $user, string $startDate, AnalysisStatus $status, ?string $content = null): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startDate)]);
    $factory = $content !== null ? Analysis::factory()->done($content) : Analysis::factory();
    $factory->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'status' => $status,
        'generated_at' => $content !== null ? Carbon::now()->subHour() : null,
    ]);

    return $activity;
}

it('chained post_run_speech retry resumes the earliest unfilled run, not the clicked one', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    $user = User::factory()->create();

    $earliest = activityWithSpeech($user, '2026-05-01 06:00:00', AnalysisStatus::Pending);
    $clicked = activityWithSpeech($user, '2026-05-10 06:00:00', AnalysisStatus::Failed);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/post_run_speech/{$clicked->id}/trigger")
        ->assertOk();

    // The payload reflects the resumed (earliest) run, not the clicked one.
    expect($response->json('subject_id'))->toBe($earliest->id);

    Carbon::setTestNow();
});

it('chained post_run_speech head regenerate (Done latest run) re-narrates that exact run', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    $user = User::factory()->create();

    // An earlier still-Pending run exists, but the Done latest run (head) is regenerated.
    activityWithSpeech($user, '2026-05-01 06:00:00', AnalysisStatus::Pending);
    $head = activityWithSpeech($user, '2026-05-20 06:00:00', AnalysisStatus::Done, 'latest speech');

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/post_run_speech/{$head->id}/trigger")
        ->assertOk();

    // A Done clicked head run is a head regenerate → stays on the clicked run.
    expect($response->json('subject_id'))->toBe($head->id);

    Carbon::setTestNow();
});

it('chained post_run_speech regenerate on a Done NON-head run resumes the chain instead (server guard)', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    $user = User::factory()->create();

    $earliest = activityWithSpeech($user, '2026-05-01 06:00:00', AnalysisStatus::Pending);
    $clickedMid = activityWithSpeech($user, '2026-05-10 06:00:00', AnalysisStatus::Done, 'mid speech');
    // The actual head (latest run) sits after the clicked mid run.
    activityWithSpeech($user, '2026-05-20 06:00:00', AnalysisStatus::Done, 'head speech');

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/post_run_speech/{$clickedMid->id}/trigger")
        ->assertOk();

    // A Done non-head POST must NOT re-narrate itself; it resumes the earliest unfilled run.
    expect($response->json('subject_id'))->toBe($earliest->id);

    Carbon::setTestNow();
});

it('chained post_run_speech resume does not re-bill an already-Done sibling row of the resumed group', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');
    // Enable auto-dispatch so the group invalidation path actually runs; without
    // it dispatchGroup short-circuits and the re-bill bug can't manifest.
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake');
    $user = User::factory()->create();

    // Earliest activity: its post-run speech is Pending but a run-insight sibling
    // is already Done (a partially-filled group). Resuming must forward-fill the
    // Pending row only, never flip the Done sibling back to Pending (re-bill).
    $earliest = activityWithSpeech($user, '2026-05-01 06:00:00', AnalysisStatus::Pending);
    $doneSibling = Analysis::factory()->done('zona sudah dibaca')->create([
        'subject_type' => Activity::class,
        'subject_id' => $earliest->id,
        'analysis_type' => AnalysisType::RunInsight,
        'discriminator' => null,
        'generated_at' => Carbon::now()->subHour(),
    ]);
    $clicked = activityWithSpeech($user, '2026-05-10 06:00:00', AnalysisStatus::Failed);

    $this->actingAs($user)
        ->postJson("/api/analyses/post_run_speech/{$clicked->id}/trigger")
        ->assertOk();

    expect($doneSibling->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($doneSibling->fresh()->content)->toBe('zona sudah dibaca');

    Carbon::setTestNow();
});

/**
 * The cooldown key embeds the discriminator, so an unconstrained value would
 * find no existing row, skip the cooldown entirely, and firstOrCreate a fresh
 * row plus a real billed Azure call on every request.
 */
it('does not dispatch a billed job for a novel discriminator', function (string $url): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(str_replace('{id}', (string) $user->id, $url))
        ->assertStatus(422)
        ->assertJsonValidationErrors('discriminator');

    Bus::assertNothingDispatched();
    expect(Analysis::query()->count())->toBe(0);
})->with([
    'random key on a daily type' => ['/api/analyses/briefing_mascot_voice/{id}/trigger?discriminator=kEy9fQ2z'],
    'wrong shape on a daily type' => ['/api/analyses/briefing_mascot_voice/{id}/trigger?discriminator=2026-05'],
    'wrong shape on the monthly recap' => ['/api/analyses/monthly_recap/{id}/trigger?discriminator=2026-05-18'],
    'wrong shape on the Aku profile voice' => ['/api/analyses/aku_profile_voice/{id}/trigger?discriminator=2026-05-18'],
    'missing card id on the featured kartu voice' => ['/api/analyses/briefing_featured_kartu_voice/{id}/trigger'],
]);

it('does not dispatch a billed job when a discriminator is sent to a type whose job ignores it', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/analyses/weekly_recap/{$user->id}/trigger?discriminator=kEy9fQ2z")
        ->assertStatus(422)
        ->assertJsonValidationErrors('discriminator');

    Bus::assertNothingDispatched();
    expect(Analysis::query()->count())->toBe(0);
});

/**
 * The demo login is public, so its trigger is served from the rule-based filler:
 * the reviewer keeps a working "Reread" and no anonymous visitor can spend
 * Azure tokens.
 */
it('serves the demo account a rule-based narration instead of dispatching a billed job', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJson(['status' => 'done']);

    Bus::assertNothingDispatched();
    expect($response->json('content'))->toBeString()->not->toBeEmpty();

    $row = Analysis::query()->sole();
    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and($row->content)->toBe($response->json('content'));
});

it('leaves the demo account re-triggerable, since a rule-based fill starts no cooldown', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    $url = "/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18";

    $this->actingAs($user)->postJson($url)->assertSuccessful();
    $this->actingAs($user)->postJson($url)
        ->assertSuccessful()
        ->assertJson(['status' => 'done', 'retry_after_seconds' => null]);

    Bus::assertNothingDispatched();
    expect(Analysis::query()->count())->toBe(1);
});

it('refuses a trigger with 409 while narration is globally paused, without staging a row', function (): void {
    $user = User::factory()->create();
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertStatus(409)
        ->assertJson(['status' => 'pending', 'type' => 'briefing_mascot_voice']);

    Bus::assertNothingDispatched();
    expect(Analysis::query()->count())->toBe(0);
});

it('leaves an existing row untouched when a paused trigger is refused', function (): void {
    $user = User::factory()->create();
    $row = Analysis::factory()->done('narasi lama')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'generated_at' => Carbon::now()->subDay(),
    ]);
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertStatus(409)
        ->assertJson(['status' => 'done', 'content' => 'narasi lama']);

    Bus::assertNothingDispatched();
    expect($row->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->fresh()->content)->toBe('narasi lama');
});

it('still serves the demo account a rule-based narration while narration is paused', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJson(['status' => 'done']);

    Bus::assertNothingDispatched();
    expect($response->json('content'))->toBeString()->not->toBeEmpty();
});

it('still dispatches a real billed job for a non-demo user on the same block', function (): void {
    $user = User::factory()->create(['is_demo' => false]);

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful();

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

// ── trigger → discriminator ownership ───────────────────────────────────────
//
// briefing_featured_kartu_voice keys off a RunCard id under the *caller's own*
// subject id, so authorizing the subject alone let a forged trigger have another
// user's card described in the caller's row.

it('refuses a trigger carrying another user\'s card id, and mints no row for it', function (): void {
    $attacker = User::factory()->create();
    $victim = User::factory()->create();
    $victimActivity = Activity::factory()->for($victim)->analyzed()->create();
    ActivityDetail::factory()->for($victimActivity)->create(['start_date_local' => Carbon::today()]);
    $victimCard = RunCard::factory()->for($victimActivity)->create();

    $this->actingAs($attacker)
        ->postJson("/api/analyses/briefing_featured_kartu_voice/{$attacker->id}/trigger?discriminator={$victimCard->id}")
        ->assertForbidden();

    Bus::assertNothingDispatched();
    expect(Analysis::query()->count())->toBe(0);
});

it('refuses to read back another user\'s card-keyed row', function (): void {
    $attacker = User::factory()->create();
    $victim = User::factory()->create();
    $victimActivity = Activity::factory()->for($victim)->analyzed()->create();
    ActivityDetail::factory()->for($victimActivity)->create(['start_date_local' => Carbon::today()]);
    $victimCard = RunCard::factory()->for($victimActivity)->create();

    $this->actingAs($attacker)
        ->getJson("/api/analyses/briefing_featured_kartu_voice/{$attacker->id}?discriminator={$victimCard->id}")
        ->assertForbidden();
});

it('still lets a user trigger the voice for their own featured card', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);
    $card = RunCard::factory()->for($activity)->create();

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_featured_kartu_voice/{$user->id}/trigger?discriminator={$card->id}")
        ->assertSuccessful();

    Bus::assertDispatched(AnalyzeBriefingFeaturedKartuVoiceJob::class);
});

// ── trigger → narration age cutoff ──────────────────────────────────────────
//
// card_flavor and pr_context are not chained, so nothing else stops a manual
// "Reread" on a years-old run from billing exactly what the ingest-side
// cutoff routed to the rule-based filler.

/** @return array{0: RunCard, 1: PersonalRecord} */
function subjectsForRunAged(User $user, int $days): array
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::now()->subDays($days),
    ]);

    return [
        RunCard::factory()->for($activity)->create(),
        PersonalRecord::factory()->for($user)->create(['activity_id' => $activity->id]),
    ];
}

it('serves a manual retry rule-based when the run is past the narration cutoff', function (): void {
    config()->set('ai.backfill_max_age_days', 84);
    $user = User::factory()->create();
    [$card, $record] = subjectsForRunAged($user, 200);

    foreach (["card_flavor/{$card->id}", "pr_context/{$record->id}"] as $path) {
        $response = $this->actingAs($user)
            ->postJson("/api/analyses/{$path}/trigger")
            ->assertSuccessful()
            ->assertJson(['status' => 'done']);

        expect($response->json('content'))->toBeString()->not->toBeEmpty();
    }

    Bus::assertNothingDispatched();
});

it('still bills a manual retry on a run just inside the cutoff', function (): void {
    config()->set('ai.backfill_max_age_days', 84);
    $user = User::factory()->create();
    [$card, $record] = subjectsForRunAged($user, 83);

    $this->actingAs($user)->postJson("/api/analyses/card_flavor/{$card->id}/trigger")->assertSuccessful();
    $this->actingAs($user)->postJson("/api/analyses/pr_context/{$record->id}/trigger")->assertSuccessful();

    Bus::assertDispatched(AnalyzeCardFlavorJob::class);
    Bus::assertDispatched(AnalyzePrContextJob::class);
});

it('never overwrites narration a too-old run was already billed for', function (): void {
    config()->set('ai.backfill_max_age_days', 84);
    $user = User::factory()->create();
    [$card] = subjectsForRunAged($user, 200);
    Analysis::factory()->done('narasi asli yang sudah dibayar')->create([
        'subject_type' => RunCard::class,
        'subject_id' => $card->id,
        'analysis_type' => AnalysisType::CardFlavor,
        'discriminator' => null,
    ]);

    $this->actingAs($user)
        ->postJson("/api/analyses/card_flavor/{$card->id}/trigger")
        ->assertSuccessful()
        ->assertJson(['content' => 'narasi asli yang sudah dibayar']);

    Bus::assertNothingDispatched();
});

it('serves a hand-crafted briefing trigger for a past day rule-based', function (): void {
    // The two bounds are deliberately different widths and this is the band
    // between them: past the 84-day narration cutoff, so no LLM call, but still
    // inside the 365-day discriminator range, so the request is valid and the
    // age gate (not validation) is what answers it. A day outside the range is
    // a 422 instead, asserted in TriggerAnalysisRequestTest.
    config()->set('ai.backfill_max_age_days', 84);
    $user = User::factory()->create();
    $pastDay = Carbon::today()->subDays(200)->toDateString();

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator={$pastDay}")
        ->assertSuccessful()
        ->assertJson(['status' => 'done']);

    Bus::assertNothingDispatched();
});

// ── trigger → zone recompute: the branch that reads the user's CURRENT zones ──

it('recomputes the stream summary when a zone-dependent run block is re-triggered', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    $recomputer = Mockery::mock(SummaryRecomputer::class);
    $recomputer->shouldReceive('recomputeFromStoredStreams')->once()->with($activity->id);
    app()->instance(SummaryRecomputer::class, $recomputer);

    $this->actingAs($user)
        ->postJson("/api/analyses/run_insight/{$activity->id}/trigger")
        ->assertSuccessful();
});

it('skips the recompute when the user has no custom profile, since the stored summary already used the defaults', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    $recomputer = Mockery::mock(SummaryRecomputer::class);
    $recomputer->shouldNotReceive('recomputeFromStoredStreams');
    app()->instance(SummaryRecomputer::class, $recomputer);

    $this->actingAs($user)
        ->postJson("/api/analyses/run_insight/{$activity->id}/trigger")
        ->assertSuccessful();
});

it('skips the recompute for a zone-dependent recap whose subject is not a run', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create();

    // The weekly recap is zone-dependent but keyed by a WeeklySnapshot id, so a
    // recompute here would target an unrelated activity id.
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $recomputer = Mockery::mock(SummaryRecomputer::class);
    $recomputer->shouldNotReceive('recomputeFromStoredStreams');
    app()->instance(SummaryRecomputer::class, $recomputer);

    $this->actingAs($user)
        ->postJson("/api/analyses/weekly_recap/{$snap->id}/trigger")
        ->assertOk();

    Carbon::setTestNow();
});

it('skips the recompute for a block whose narration does not depend on zones', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    $recomputer = Mockery::mock(SummaryRecomputer::class);
    $recomputer->shouldNotReceive('recomputeFromStoredStreams');
    app()->instance(SummaryRecomputer::class, $recomputer);

    $this->actingAs($user)
        ->postJson("/api/analyses/post_run_speech/{$activity->id}/trigger")
        ->assertSuccessful();
});

// ── trigger → defensive guard ───────────────────────────────────────────────

it('throws Unauthenticated when the request has no user (defensive guard)', function (): void {
    $controller = new AnalysisController();
    $request = TriggerAnalysisRequest::create('/api/analyses/briefing_mascot_voice/1/trigger', 'POST');

    expect(fn () => $controller->trigger(
        $request,
        app(AnalysisService::class),
        app(SummaryRecomputer::class),
        app(ChainResolver::class),
        app(BackfillAgeGate::class),
        'briefing_mascot_voice',
        1,
    ))->toThrow(AuthorizationException::class, 'Unauthenticated');
});
