<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeBriefingJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\BriefingComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

it('queues both headline + suggestion jobs on first compose', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');

    $result = app(BriefingComposer::class)->compose($user, $asOf);

    expect($result->headline['status'])->toBe(AnalysisStatus::Queued->value)
        ->and($result->suggestion['status'])->toBe(AnalysisStatus::Queued->value)
        ->and($result->headline['content'])->toBeNull()
        ->and($result->suggestion['content'])->toBeNull();

    // Briefing is now grouped — one job produces both rows.
    Bus::assertDispatched(AnalyzeBriefingJob::class, 1);
});

it('returns stored content when analyses are done', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');

    Analysis::factory()->done('Pagi yang oke')->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);
    Analysis::factory()->done('Easy run aja dulu')->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingSuggestion,
        'discriminator' => '2026-05-18',
    ]);

    $result = app(BriefingComposer::class)->compose($user, $asOf);

    expect($result->headline['content'])->toBe('Pagi yang oke')
        ->and($result->headline['status'])->toBe(AnalysisStatus::Done->value)
        ->and($result->suggestion['content'])->toBe('Easy run aja dulu')
        ->and($result->suggestion['status'])->toBe(AnalysisStatus::Done->value);

    Bus::assertNotDispatched(AnalyzeBriefingJob::class);
});

it('does not re-dispatch when one piece is done and the other is queued', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');

    Analysis::factory()->done('Pagi yang oke')->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);
    Analysis::factory()->queued()->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingSuggestion,
        'discriminator' => '2026-05-18',
    ]);

    app(BriefingComposer::class)->compose($user, $asOf);

    Bus::assertNotDispatched(AnalyzeBriefingJob::class);
});

it('shows the "Kemarin lari" streak label when last run was yesterday', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-05-17'),
        'trimp_edwards' => 50.0,
    ]);

    $result = app(BriefingComposer::class)->compose($user, Carbon::parse('2026-05-18'));

    expect($result->streakLabel)->toBe('Kemarin lari');
});

it('shows the "Sudah N hari" label when last run was 2-3 days ago', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-05-15'),
        'trimp_edwards' => 50.0,
    ]);

    $result = app(BriefingComposer::class)->compose($user, Carbon::parse('2026-05-18'));

    expect($result->streakLabel)->toBe('Sudah 3 hari');
});

it('shows the "Sudah N hari nih" label when last run was more than 3 days ago', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-05-10'),
        'trimp_edwards' => 50.0,
    ]);

    $result = app(BriefingComposer::class)->compose($user, Carbon::parse('2026-05-18'));

    expect($result->streakLabel)->toBe('Sudah 8 hari nih');
});

it('returns a null streak label when the user has never run', function (): void {
    $user = User::factory()->create();

    $result = app(BriefingComposer::class)->compose($user, Carbon::parse('2026-05-18'));

    expect($result->streakLabel)->toBeNull();
});

it('computes non-LLM fields (vibe label, streak, mood) without an LLM call', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-05-18'),
        'trimp_edwards' => 60.0,
    ]);

    $result = app(BriefingComposer::class)->compose($user, Carbon::parse('2026-05-18'));

    expect($result->vibeLabel)->toBeString()->not->toBeEmpty()
        ->and($result->vibeEmoji)->toBeString()->not->toBeEmpty()
        ->and($result->mood)->toBeString()->not->toBeEmpty()
        ->and($result->sigilPattern)->toBeString()->not->toBeEmpty()
        ->and($result->recoveryLabel)->toBeString()->not->toBeEmpty()
        ->and($result->streakLabel)->toBe('Lari hari ini');
});
