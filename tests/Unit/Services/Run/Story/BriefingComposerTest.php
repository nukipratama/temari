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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    Bus::fake();
});

it('returns pending payloads on first compose and dispatches NO LLM jobs', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');

    $result = app(BriefingComposer::class)->compose($user, $asOf);

    expect($result->headline['status'])->toBe(AnalysisStatus::Pending->value)
        ->and($result->suggestion['status'])->toBe(AnalysisStatus::Pending->value)
        ->and($result->mascotVoice['status'])->toBe(AnalysisStatus::Pending->value)
        ->and($result->headline['content'])->toBeNull()
        ->and($result->suggestion['content'])->toBeNull()
        ->and($result->mascotVoice['content'])->toBeNull();

    // No LLM dispatch on page-load reads — analyses are user-triggered.
    Bus::assertNotDispatched(AnalyzeBriefingJob::class);
});

it('returns stored content when analyses are done', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');

    foreach ([
        AnalysisType::BriefingHeadline->value => 'Pagi yang oke',
        AnalysisType::BriefingSuggestion->value => 'Easy run aja dulu',
        AnalysisType::BriefingMascotVoice->value => 'Aku liat kemarin lo lari santai, easy hari ini ya',
    ] as $typeValue => $content) {
        Analysis::factory()->done($content)->create([
            'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => $typeValue,
            'discriminator' => '2026-05-18',
        ]);
    }

    $result = app(BriefingComposer::class)->compose($user, $asOf);

    expect($result->headline['content'])->toBe('Pagi yang oke')
        ->and($result->headline['status'])->toBe(AnalysisStatus::Done->value)
        ->and($result->suggestion['content'])->toBe('Easy run aja dulu')
        ->and($result->suggestion['status'])->toBe(AnalysisStatus::Done->value)
        ->and($result->mascotVoice['content'])->toBe('Aku liat kemarin lo lari santai, easy hari ini ya')
        ->and($result->mascotVoice['status'])->toBe(AnalysisStatus::Done->value);

    Bus::assertNotDispatched(AnalyzeBriefingJob::class);
});

it('does not re-dispatch when some pieces are done and others queued', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');

    Analysis::factory()->done('Pagi yang oke')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);
    foreach ([
        AnalysisType::BriefingSuggestion->value,
        AnalysisType::BriefingMascotVoice->value,
    ] as $typeValue) {
        Analysis::factory()->queued()->create([
            'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => $typeValue,
            'discriminator' => '2026-05-18',
        ]);
    }

    app(BriefingComposer::class)->compose($user, $asOf);

    Bus::assertNotDispatched(AnalyzeBriefingJob::class);
});

it('labels the streak from days since the last run', function (string $lastRun, string $label): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($lastRun),
        'trimp_edwards' => 50.0,
    ]);

    $result = app(BriefingComposer::class)->compose($user, Carbon::parse('2026-05-18'));

    expect($result->streakLabel)->toBe($label);
})->with([
    'yesterday' => ['2026-05-17', 'Kemarin lari'],
    '3 days ago' => ['2026-05-15', 'Sudah 3 hari'],
    '8 days ago' => ['2026-05-10', 'Sudah 8 hari'],
]);

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
