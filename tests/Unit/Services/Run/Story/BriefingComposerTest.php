<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
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
afterEach(fn () => Carbon::setTestNow());

it('returns pending payloads on first compose and dispatches NO LLM jobs', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');

    $result = app(BriefingComposer::class)->compose($user, $asOf);

    expect($result->mascotVoice['status'])->toBe(AnalysisStatus::Pending->value)
        ->and($result->mascotVoice['content'])->toBeNull();

    // No LLM dispatch on page-load reads — analyses are user-triggered.
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

it('returns stored content when analyses are done', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');

    Analysis::factory()->done('Aku liat kemarin lo lari santai, easy hari ini ya')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    $result = app(BriefingComposer::class)->compose($user, $asOf);

    expect($result->mascotVoice['content'])->toBe('Aku liat kemarin lo lari santai, easy hari ini ya')
        ->and($result->mascotVoice['status'])->toBe(AnalysisStatus::Done->value);

    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

it('keys the featured kartu voice by the featured card id and exposes that id', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-05-18')]);
    $card = RunCard::factory()->for($activity)->create(['rarity' => Rarity::Epic]);

    Analysis::factory()->done('Kartu epic kamu')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingFeaturedKartuVoice,
        'discriminator' => (string) $card->id,
    ]);

    $result = app(BriefingComposer::class)->compose($user, $asOf);

    expect($result->featuredCardId)->toBe($card->id)
        ->and($result->featuredKartuVoice['content'])->toBe('Kartu epic kamu')
        ->and($result->featuredKartuVoice['status'])->toBe(AnalysisStatus::Done->value)
        ->and($result->featuredKartuVoice['discriminator'])->toBe((string) $card->id);
});

it('exposes a null featured card id and a pending voice when the user has no cards', function (): void {
    $user = User::factory()->create();

    $result = app(BriefingComposer::class)->compose($user, Carbon::parse('2026-05-18'));

    expect($result->featuredCardId)->toBeNull()
        ->and($result->featuredKartuVoice['status'])->toBe(AnalysisStatus::Pending->value)
        ->and($result->featuredKartuVoice['content'])->toBeNull();
});

it('does not re-dispatch when the voice is done and the kartu voice still queued', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-05-18');

    Analysis::factory()->done('Pagi yang oke')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);
    Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingFeaturedKartuVoice,
        'discriminator' => '7',
    ]);

    app(BriefingComposer::class)->compose($user, $asOf);

    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
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
    'yesterday' => ['2026-05-17', 'Ran yesterday'],
    '3 days ago' => ['2026-05-15', '3 days ago'],
    '8 days ago' => ['2026-05-10', '8 days ago'],
]);

it('returns a null streak label when the user has never run', function (): void {
    $user = User::factory()->create();

    $result = app(BriefingComposer::class)->compose($user, Carbon::parse('2026-05-18'));

    expect($result->streakLabel)->toBeNull();
});

it('labels recovery hours as "Xh" under 72h and "Y days" at 72h and beyond', function (int $hoursAgo, string $label): void {
    // hoursSinceLastRun measures against Carbon::now() when $asOf is "today" on
    // the wall clock (else it bumps to end-of-day), so freeze "now" to asOf
    // itself to get exact hour control.
    $asOf = Carbon::parse('2026-05-18 12:00:00');
    Carbon::setTestNow($asOf);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $asOf->copy()->subHours($hoursAgo),
        'trimp_edwards' => 50.0,
    ]);

    $result = app(BriefingComposer::class)->compose($user, $asOf);

    expect($result->recoveryHoursLabel)->toBe($label);
})->with([
    '10 hours ago' => [10, '10h'],
    '71 hours ago (just under the day boundary)' => [71, '71h'],
    'exactly 72 hours ago (day boundary)' => [72, '3 days'],
    '100 hours ago' => [100, '4 days'],
]);

it('returns a null recovery hours label when the user has never run', function (): void {
    $user = User::factory()->create();

    $result = app(BriefingComposer::class)->compose($user, Carbon::parse('2026-05-18'));

    expect($result->recoveryHoursLabel)->toBeNull();
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
        ->and($result->streakLabel)->toBe('Ran today');
});
