<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Jobs\AI\AnalyzeBriefingFeaturedKartuVoiceJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\BriefingFeaturedKartuVoiceNarrator;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mockFeaturedKartuNarrator(string $payload): void
{
    $mock = Mockery::mock(BriefingFeaturedKartuVoiceNarrator::class);
    $mock->shouldReceive('generate')->andReturn($payload);
    app()->instance(BriefingFeaturedKartuVoiceNarrator::class, $mock);
}

function featuredKartuRow(int $userId, ?string $discriminator = '2026-05-18'): Analysis
{
    return Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $userId,
        'analysis_type' => AnalysisType::BriefingFeaturedKartuVoice,
        'discriminator' => $discriminator,
    ]);
}

it('loads the card named by the discriminator and passes it to the narrator', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['distance' => 5000.0]);
    $card = RunCard::factory()->for($activity)->create(['rarity' => Rarity::Epic]);

    $mock = Mockery::mock(BriefingFeaturedKartuVoiceNarrator::class);
    $mock->shouldReceive('generate')
        ->once()
        ->with(Mockery::type(User::class), Mockery::on(fn ($c): bool => $c instanceof RunCard && $c->id === $card->id))
        ->andReturn('kartu voice line');
    app()->instance(BriefingFeaturedKartuVoiceNarrator::class, $mock);

    $row = featuredKartuRow($user->id, (string) $card->id);
    new AnalyzeBriefingFeaturedKartuVoiceJob($row->id)->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->content)->toBe('kartu voice line')
        ->and($fresh->status)->toBe(AnalysisStatus::Done);
});

it('passes a null card when the discriminator names no card', function (): void {
    $user = User::factory()->create();
    $mock = Mockery::mock(BriefingFeaturedKartuVoiceNarrator::class);
    $mock->shouldReceive('generate')
        ->once()
        ->with(Mockery::type(User::class), null)
        ->andReturn('no card line');
    app()->instance(BriefingFeaturedKartuVoiceNarrator::class, $mock);

    $row = featuredKartuRow($user->id, null);
    new AnalyzeBriefingFeaturedKartuVoiceJob($row->id)->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('no card line')
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Done);
});

it('marks the row Failed and rethrows when the user is missing', function (): void {
    $row = featuredKartuRow(99999);

    expect(fn () => new AnalyzeBriefingFeaturedKartuVoiceJob($row->id)->handle(app(AnalysisService::class)))
        ->toThrow(ModelNotFoundException::class);

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('swallows a terminal UnavailableException so the worker does not retry', function (): void {
    $user = User::factory()->create();
    $mock = Mockery::mock(BriefingFeaturedKartuVoiceNarrator::class);
    $mock->shouldReceive('generate')->andThrow(new UnavailableException('bad schema'));
    app()->instance(BriefingFeaturedKartuVoiceNarrator::class, $mock);

    $row = featuredKartuRow($user->id);

    // No throw: terminal failures stay marked Failed and do not bubble to the queue.
    new AnalyzeBriefingFeaturedKartuVoiceJob($row->id)->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($row->fresh()->error)->toBe('bad schema');
});

it('requeues a transient upstream failure (no Retry-After) so a manual retry cannot double-bill', function (): void {
    $user = User::factory()->create();
    $mock = Mockery::mock(BriefingFeaturedKartuVoiceNarrator::class);
    $mock->shouldReceive('generate')->andThrow(new TransientUpstreamException('azure 503'));
    app()->instance(BriefingFeaturedKartuVoiceNarrator::class, $mock);

    $row = featuredKartuRow($user->id);

    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    // Falls back to the first backoff step (10s) when there is no Retry-After.
    $queueJob->shouldReceive('release')->once()->with(10);

    $job = new AnalyzeBriefingFeaturedKartuVoiceJob($row->id);
    $job->setJob($queueJob);

    // No rethrow: the row stays Queued (not Failed) for the retry wait, so it is
    // neither re-dispatchable nor shown as a failed "Coba lagi" block.
    $job->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued);
});

it('marks the row Failed and rethrows a transient upstream failure once tries are exhausted', function (): void {
    $user = User::factory()->create();
    $mock = Mockery::mock(BriefingFeaturedKartuVoiceNarrator::class);
    $mock->shouldReceive('generate')->andThrow(new TransientUpstreamException('azure 503'));
    app()->instance(BriefingFeaturedKartuVoiceNarrator::class, $mock);

    $row = featuredKartuRow($user->id);

    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(3); // attempts() == tries, no slot left
    $queueJob->shouldNotReceive('release');

    $job = new AnalyzeBriefingFeaturedKartuVoiceJob($row->id);
    $job->setJob($queueJob);

    expect(fn () => $job->handle(app(AnalysisService::class)))
        ->toThrow(TransientUpstreamException::class, 'azure 503');

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('releases the job with the Retry-After delay instead of rethrowing when one is given', function (): void {
    $user = User::factory()->create();
    $mock = Mockery::mock(BriefingFeaturedKartuVoiceNarrator::class);
    $mock->shouldReceive('generate')->andThrow(new TransientUpstreamException('rate limited', retryAfterSeconds: 23));
    app()->instance(BriefingFeaturedKartuVoiceNarrator::class, $mock);

    $row = featuredKartuRow($user->id);

    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(23);

    $job = new AnalyzeBriefingFeaturedKartuVoiceJob($row->id);
    $job->setJob($queueJob);

    // Releasing replaces rethrow: handle() returns cleanly while the queue
    // re-enqueues the job after the requested delay. The row stays Queued (not
    // Failed) for the wait, so it is neither re-dispatchable nor shown as a
    // failed "Coba lagi" block that a manual retry could double-bill.
    $job->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued);
});
