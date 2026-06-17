<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AzureOpenAIClient;
use App\Services\AI\Narrators\BriefingFeaturedKartuVoiceNarrator;
use App\Services\AI\StructuredChatCaller;
use App\Services\AI\TokenUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenAI\Testing\ClientFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/openai/deployments/x/chat/completions?api-version=2024-10-21');
    config()->set('azure_openai.api_key', 'fake-key');
    config()->set('azure_openai.deployment', 'x');
    config()->set('azure_openai.timeout', 8);
    config()->set('azure_openai.max_completion_tokens', 400);
});

/** @return array{narrator: BriefingFeaturedKartuVoiceNarrator, client: ClientFake} */
function bootFeaturedKartuNarrator(string $jsonContent): array
{
    $client = new ClientFake([fakeAzureResponse($jsonContent)]);
    $azure = Mockery::mock(AzureOpenAIClient::class);
    $azure->shouldReceive('client')->andReturn($client);
    $azure->shouldReceive('deploymentFor')->andReturn('gpt-test');

    $narrator = new BriefingFeaturedKartuVoiceNarrator(
        new StructuredChatCaller($azure, app(TokenUsageRecorder::class)),
    );

    return ['narrator' => $narrator, 'client' => $client];
}

function runWithCard(User $user, Rarity $rarity, float $distance, Carbon $when): RunCard
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $when,
        'distance' => $distance,
    ]);

    return RunCard::factory()->for($activity)->create([
        'rarity' => $rarity,
        'special_move' => 'Langkah Sunyi',
        'badges' => ['anak_pagi', 'negative_split', 'tahan_diri', 'hari_panas'],
    ]);
}

it('returns the kartu voice from a valid LLM structured response, picking the highest rarity card', function (): void {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    // Lower rarity, more recent.
    runWithCard($user, Rarity::Common, 5000.0, Carbon::today());
    // Higher rarity, older — should still win on rarity rank.
    runWithCard($user, Rarity::Legendary, 12000.0, Carbon::today()->subDay());

    ['narrator' => $narrator] = bootFeaturedKartuNarrator(json_encode([
        'kartu_voice' => 'Aku kasih kartu ini karena 12 km tadi solid.',
    ], JSON_THROW_ON_ERROR));

    $voice = $narrator->generate($user, Carbon::today());

    expect($voice)->toBe('Aku kasih kartu ini karena 12 km tadi solid.');
});

it('returns a fallback line when the user has no analyzed run cards', function (): void {
    $user = User::factory()->create();

    ['narrator' => $narrator, 'client' => $client] = bootFeaturedKartuNarrator(
        json_encode(['kartu_voice' => 'unused'], JSON_THROW_ON_ERROR),
    );

    $voice = $narrator->generate($user);

    expect($voice)->toBe('Belum ada kartu khusus buat kamu minggu ini. Terus lari, aku pantau!');
    // Fallback short-circuits before any LLM call.
    $client->assertNothingSent();
});

it('skips runs without a card and defaults asOf to today', function (): void {
    Carbon::setTestNow('2026-05-19 09:00:00');
    $user = User::factory()->create();

    // Analyzed run with NO run card — must be skipped by the picker.
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 8000.0,
    ]);
    // A run with a card so a featured kartu is picked.
    runWithCard($user, Rarity::Rare, 9000.0, Carbon::today());

    ['narrator' => $narrator] = bootFeaturedKartuNarrator(json_encode([
        'kartu_voice' => 'Kartu langka buat lari kamu.',
    ], JSON_THROW_ON_ERROR));

    $voice = $narrator->generate($user);

    expect($voice)->toBe('Kartu langka buat lari kamu.');
    Carbon::setTestNow();
});

it('handles a card whose run has a null distance (em dash km) and no badges', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => null,
    ]);
    RunCard::factory()->for($activity)->create([
        'rarity' => Rarity::Epic,
        'special_move' => 'Tanpa Letih',
        'badges' => [],
    ]);

    ['narrator' => $narrator] = bootFeaturedKartuNarrator(json_encode([
        'kartu_voice' => 'Kartu tanpa jarak.',
    ], JSON_THROW_ON_ERROR));

    $voice = $narrator->generate($user, Carbon::today());

    expect($voice)->toBe('Kartu tanpa jarak.');
});

it('throws UnavailableException when required keys are missing', function (): void {
    $user = User::factory()->create();
    runWithCard($user, Rarity::Rare, 9000.0, Carbon::today());

    ['narrator' => $narrator] = bootFeaturedKartuNarrator(
        json_encode(['wrong_key' => 'x'], JSON_THROW_ON_ERROR),
    );

    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class);
