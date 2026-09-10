<?php

declare(strict_types=1);

use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\DevtoolsAction;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\CeilingOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'devtools.password' => 'secret',
        'azure_openai.prices' => ['gpt-test' => ['input_per_1m' => 1000.0, 'output_per_1m' => 2000.0]],
        'azure_openai.replay_daily_cap' => 0.50,
    ]);
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')]);
});

function narrationAthlete(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

function narrationBlock(User $user, AnalysisType $type = AnalysisType::TrendRead, array $attributes = []): Analysis
{
    return Analysis::factory()->create([
        'subject_type' => 'briefing_user_day',
        'subject_id' => $user->id,
        'analysis_type' => $type,
        ...$attributes,
    ]);
}

function athletePath(User $user, string $suffix = ''): string
{
    return "/devtools/narration/athletes/{$user->id}{$suffix}";
}

describe('show', function (): void {
    it('renders the athlete page with the header, narrations and the replay budget', function (): void {
        $user = narrationAthlete();
        narrationBlock($user, AnalysisType::TrendRead, [
            'status' => AnalysisStatus::Done,
            'content' => 'a read of the trend',
        ]);

        $this->get(athletePath($user))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Narration/Athlete')
                    ->where('tab', 'narrations')
                    ->where('header.athlete.id', $user->id)
                    ->where('narrations.0.content', 'a read of the trend')
                    ->where('replayBudget.cap', 0.5)
                    ->where('replayBudget.cap_reached', false)
                    ->has('costByKind')
                    ->has('attention.failed')
                    ->has('audit')
                    ->etc(),
            );
    });

    it('404s on an athlete that does not exist', function (): void {
        $this->get('/devtools/narration/athletes/999999')->assertNotFound();
    });

    it('challenges a request without the devtools password in production', function (): void {
        $user = narrationAthlete();
        app()->detectEnvironment(fn (): string => 'production');

        $this->withHeaders(['Authorization' => ''])
            ->get(athletePath($user))
            ->assertUnauthorized();
    });

    it('filters the narration list by kind and status from the query string', function (): void {
        $user = narrationAthlete();
        narrationBlock($user, AnalysisType::TrendRead, ['status' => AnalysisStatus::Done, 'content' => 'kept']);
        narrationBlock($user, AnalysisType::WeeklyRecap, ['status' => AnalysisStatus::Failed]);

        $this->get(athletePath($user, '?kind=trend_read&status=done&tab=cost'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('tab', 'cost')
                    ->where('filters.kind', 'trend_read')
                    ->has('narrations', 1)
                    ->etc(),
            );
    });

    it('rejects an unknown kind, status or tab', function (): void {
        $user = narrationAthlete();

        $this->get(athletePath($user, '?kind=nope'))->assertSessionHasErrors('kind');
        $this->get(athletePath($user, '?status=nope'))->assertSessionHasErrors('status');
        $this->get(athletePath($user, '?tab=nope'))->assertSessionHasErrors('tab');
    });
});

describe('actions', function (): void {
    it('retries every failed block and records the action', function (): void {
        $user = narrationAthlete();
        narrationBlock($user, AnalysisType::TrendRead, ['status' => AnalysisStatus::Failed, 'attempts' => 1]);
        $this->mock(AnalysisService::class)->shouldReceive('request')->once();

        $this->from(athletePath($user))
            ->post(athletePath($user, '/retry-failed'))
            ->assertRedirect(athletePath($user))
            ->assertSessionHas('info');

        $action = DevtoolsAction::query()->sole();
        expect($action->action)->toBe('narration.retry_failed')
            ->and($action->user_id)->toBe($user->id)
            ->and($action->payload)->toBe(['blocks' => 1]);
    });

    it('re-arms only the dead-lettered blocks and records the action', function (): void {
        $user = narrationAthlete();
        narrationBlock($user, AnalysisType::TrendRead, ['status' => AnalysisStatus::Failed, 'attempts' => 1]);
        narrationBlock($user, AnalysisType::WeeklyRecap, [
            'status' => AnalysisStatus::Failed,
            'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
        ]);
        $this->mock(AnalysisService::class)->shouldReceive('request')->once();

        $this->post(athletePath($user, '/re-arm'))->assertRedirect();

        expect(DevtoolsAction::query()->sole()->payload)->toBe(['blocks' => 1]);
    });

    it('queues a Strava sync for the athlete', function (): void {
        Bus::fake();
        $user = narrationAthlete();

        $this->post(athletePath($user, '/resync'))->assertRedirect();

        Bus::assertDispatched(
            SyncActivitiesJob::class,
            fn (SyncActivitiesJob $job): bool => $job->userId === $user->id && $job->stravaActivityId === null,
        );
        expect(DevtoolsAction::query()->sole()->action)->toBe('narration.resync');
    });

    it('sets and clears a today-only ceiling override', function (): void {
        $user = narrationAthlete();
        $override = app(CeilingOverride::class);

        $this->post(athletePath($user, '/ceiling'), ['ceiling' => 3.5])->assertRedirect();
        expect($override->get($user->id))->toBe(3.5);

        $this->post(athletePath($user, '/ceiling/clear'))->assertRedirect();
        expect($override->get($user->id))->toBeNull();

        expect(DevtoolsAction::query()->pluck('action')->all())->toBe([
            'narration.ceiling_override',
            'narration.ceiling_clear',
        ]);
    });

    it('rejects a ceiling that is not a positive number', function (): void {
        $user = narrationAthlete();

        $this->post(athletePath($user, '/ceiling'), ['ceiling' => -1])->assertSessionHasErrors('ceiling');
        $this->post(athletePath($user, '/ceiling'), [])->assertSessionHasErrors('ceiling');
    });
});

describe('replay', function (): void {
    it('replays a block with the replay origin and records it', function (): void {
        $user = narrationAthlete();
        $block = narrationBlock($user, AnalysisType::TrendRead, [
            'status' => AnalysisStatus::Done,
            'content' => 'the flagged one',
        ]);

        $service = $this->mock(AnalysisService::class);
        $service->shouldReceive('shouldServeRuleBased')->andReturnFalse();
        $service->shouldReceive('request')->once();

        $this->post(athletePath($user, '/replay'), ['analysis_id' => $block->id])
            ->assertRedirect()
            ->assertSessionHas('info');

        expect(DevtoolsAction::query()->sole()->payload)
            ->toMatchArray(['analysis_id' => $block->id, 'kind' => 'trend_read']);
    });

    it('refuses the replay once the daily replay cap is reached', function (): void {
        $user = narrationAthlete();
        $block = narrationBlock($user, AnalysisType::TrendRead, ['status' => AnalysisStatus::Done, 'content' => 'x']);
        TokenUsage::query()->create([
            'kind' => 'trend_read',
            'origin' => AnalysisOrigin::Replay,
            'prompt_tokens' => 500,
            'completion_tokens' => 0,
            'total_tokens' => 500,
            'model' => 'gpt-test',
            'created_at' => Carbon::now(),
        ]);
        $this->mock(AnalysisService::class)->shouldNotReceive('request');

        $this->post(athletePath($user, '/replay'), ['analysis_id' => $block->id])
            ->assertRedirect()
            ->assertSessionHas('info', fn (string $message): bool => str_contains($message, 'refused'));

        expect(DevtoolsAction::query()->count())->toBe(0);
    });

    it('404s on a block that belongs to another athlete', function (): void {
        $user = narrationAthlete();
        $block = narrationBlock(narrationAthlete(), AnalysisType::TrendRead);

        $this->post(athletePath($user, '/replay'), ['analysis_id' => $block->id])->assertNotFound();
    });
});
