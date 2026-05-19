<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalysisController;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzePostRunSpeechJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\BriefingComposer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

it('rejects unknown analysis types with 422', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->postJson('/api/analyses/nonsense/1/trigger')
        ->assertStatus(422)
        ->assertJson(['error' => 'unknown_analysis_type']);
});

it('requires authentication', function (): void {
    $this->postJson('/api/analyses/briefing_headline/1/trigger')
        ->assertStatus(401);
});

it('rejects triggering briefing for another user', function (): void {
    $self = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($self)
        ->postJson("/api/analyses/briefing_headline/{$other->id}/trigger?discriminator=2026-05-18")
        ->assertStatus(403);
});

it('triggers a briefing headline analysis for the authenticated user', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson("/api/analyses/briefing_headline/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJsonStructure(['id', 'status', 'content', 'type', 'subject_type', 'subject_id', 'discriminator']);

    expect($response->json('status'))->toBe(AnalysisStatus::Queued->value)
        ->and($response->json('discriminator'))->toBe('2026-05-18');

    Bus::assertDispatched(AnalyzeBriefingJob::class);
});

it('force re-triggers a briefing even when status is done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('old')->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_headline/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJsonPath('status', AnalysisStatus::Queued->value);

    Bus::assertDispatched(AnalyzeBriefingJob::class);
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

    Bus::assertDispatched(AnalyzePostRunSpeechJob::class);
});

it('returns the current state via GET show', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('content here')->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);

    $this->actingAs($user)
        ->getJson("/api/analyses/briefing_headline/{$user->id}?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJsonPath('status', AnalysisStatus::Done->value)
        ->assertJsonPath('content', 'content here');
});

it('returns a pending pseudo-row when no analysis exists yet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/analyses/briefing_headline/{$user->id}?discriminator=2026-05-18")
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
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    $this->actingAs($other)
        ->postJson("/api/analyses/card_flavor/{$card->id}/trigger")
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson("/api/analyses/card_flavor/{$card->id}/trigger")
        ->assertOk();
});

it('throws Unauthenticated when the request has no user (defensive guard)', function (): void {
    $controller = new AnalysisController();
    $request = Request::create('/api/analyses/briefing_headline/1/trigger', 'POST');

    expect(fn () => $controller->trigger($request, app(AnalysisService::class), 'briefing_headline', 1))
        ->toThrow(AuthorizationException::class, 'Unauthenticated');
});
