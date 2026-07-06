<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\Narrators\BriefingFeaturedKartuVoiceNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Testing\ClientFake;

// The narrator itself never queries the DB, but fakeStructuredCaller() wires a
// real TokenUsageRecorder, and StructuredChatCaller::call() persists a
// TokenUsage row on every successful response.
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
    $narrator = new BriefingFeaturedKartuVoiceNarrator(fakeStructuredCaller($client));

    return ['narrator' => $narrator, 'client' => $client];
}

/** @param  list<string>  $badges */
function featuredCard(User $user, Rarity $rarity, ?float $distance, array $badges): RunCard
{
    $activity = Activity::factory()->make(['id' => 1, 'user_id' => $user->id]);
    $detail = ActivityDetail::factory()->make(['activity_id' => 1, 'distance' => $distance]);
    $activity->setRelation('detail', $detail);

    $card = RunCard::factory()->make([
        'activity_id' => 1,
        'rarity' => $rarity,
        'special_move' => 'Langkah Sunyi',
        'badges' => $badges,
    ]);
    $card->setRelation('activity', $activity);

    return $card;
}

it('returns the kartu voice for the resolved card from a valid LLM response', function (): void {
    $user = User::factory()->make(['id' => 1, 'name' => 'Ada Lovelace']);
    $card = featuredCard($user, Rarity::Legendary, 12000.0, ['anak_pagi', 'negative_split', 'tahan_diri', 'hari_panas']);

    ['narrator' => $narrator, 'client' => $client] = bootFeaturedKartuNarrator(json_encode([
        'kartu_voice' => 'Aku kasih kartu ini karena 12 km tadi solid.',
    ], JSON_THROW_ON_ERROR));

    expect($narrator->generate($user, $card))->toBe('Aku kasih kartu ini karena 12 km tadi solid.');

    // Badge slugs are humanized before the prompt, capped at 3 tags.
    $client->assertSent(OpenAI\Resources\Responses::class, function (string $method, array $params): bool {
        $payload = json_encode($params, JSON_THROW_ON_ERROR);

        return str_contains($payload, 'Anak Pagi')
            && ! str_contains($payload, 'anak_pagi')
            && ! str_contains($payload, 'negative_split')
            && ! str_contains($payload, 'tahan_diri');
    });
});

it('returns a fallback line and skips the LLM when there is no featured card', function (): void {
    $user = User::factory()->make(['id' => 1]);

    ['narrator' => $narrator, 'client' => $client] = bootFeaturedKartuNarrator(
        json_encode(['kartu_voice' => 'unused'], JSON_THROW_ON_ERROR),
    );

    expect($narrator->generate($user, null))
        ->toBe('Belum ada kartu khusus buat kamu minggu ini. Terus lari, aku pantau!');
    $client->assertNothingSent();
});

it('handles a card whose run has a null distance (em dash km) and no badges', function (): void {
    $user = User::factory()->make(['id' => 1]);
    $card = featuredCard($user, Rarity::Epic, null, []);

    ['narrator' => $narrator] = bootFeaturedKartuNarrator(json_encode([
        'kartu_voice' => 'Kartu tanpa jarak.',
    ], JSON_THROW_ON_ERROR));

    expect($narrator->generate($user, $card))->toBe('Kartu tanpa jarak.');
});

it('throws UnavailableException when required keys are missing', function (): void {
    $user = User::factory()->make(['id' => 1]);
    $card = featuredCard($user, Rarity::Rare, 9000.0, []);

    ['narrator' => $narrator] = bootFeaturedKartuNarrator(
        json_encode(['wrong_key' => 'x'], JSON_THROW_ON_ERROR),
    );

    $narrator->generate($user, $card);
})->throws(UnavailableException::class);
