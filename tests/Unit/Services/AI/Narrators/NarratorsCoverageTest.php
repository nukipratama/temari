<?php

declare(strict_types=1);

use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AzureOpenAIClient;
use App\Services\AI\Narrators\CardFlavorNarrator;
use App\Services\AI\Narrators\DailyGreetingNarrator;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\Narrators\PrContextNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
use App\Services\AI\Narrators\TrendCaptionNarrator;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/openai/deployments/x/chat/completions?api-version=2024-10-21');
    config()->set('azure_openai.api_key', 'fake-key');
    config()->set('azure_openai.deployment', 'x');
    config()->set('azure_openai.timeout', 8);
    config()->set('azure_openai.max_completion_tokens', 400);
});

function fakeAzureClient(string $content): AzureOpenAIClient
{
    $client = new ClientFake([
        CreateResponse::fake([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $content]],
            ],
        ]),
    ]);
    $azure = Mockery::mock(AzureOpenAIClient::class);
    $azure->shouldReceive('client')->andReturn($client);

    return $azure;
}

// ── PostRunSpeechNarrator ─────────────────────────────────────────────

function postRunFixture(): array
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0,
        'moving_time' => 1500,
    ]);

    return ['activity' => $activity, 'detail' => $detail];
}

it('PostRunSpeechNarrator returns speech on valid JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $azure = fakeAzureClient(json_encode(['speech' => 'Nice run today!'], JSON_THROW_ON_ERROR));
    $narrator = new PostRunSpeechNarrator($azure);
    expect($narrator->generate($a, $d, 'glow'))->toBe('Nice run today!');
});

it('PostRunSpeechNarrator throws on non-JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $azure = fakeAzureClient('not json');
    $narrator = new PostRunSpeechNarrator($azure);
    $narrator->generate($a, $d, 'glow');
})->throws(UnavailableException::class, 'non-JSON');

it('PostRunSpeechNarrator throws on missing key', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $azure = fakeAzureClient(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new PostRunSpeechNarrator($azure);
    $narrator->generate($a, $d, 'glow');
})->throws(UnavailableException::class, 'missing speech');

// ── DailyGreetingNarrator ─────────────────────────────────────────────

it('DailyGreetingNarrator returns speech on valid JSON', function (): void {
    $user = User::factory()->create();
    $azure = fakeAzureClient(json_encode(['speech' => 'Halo pagi'], JSON_THROW_ON_ERROR));
    $narrator = new DailyGreetingNarrator($azure);
    expect($narrator->generate($user, 'membara'))->toBe('Halo pagi');
});

it('DailyGreetingNarrator throws on missing speech key', function (): void {
    $user = User::factory()->create();
    $azure = fakeAzureClient(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new DailyGreetingNarrator($azure);
    $narrator->generate($user, 'membara');
})->throws(UnavailableException::class);

it('DailyGreetingNarrator throws on non-JSON response', function (): void {
    $user = User::factory()->create();
    $azure = fakeAzureClient('not json');
    $narrator = new DailyGreetingNarrator($azure);
    $narrator->generate($user, 'membara');
})->throws(UnavailableException::class, 'non-JSON');

// ── RunInsightNarrator ────────────────────────────────────────────────

it('RunInsightNarrator returns 3-string payload on valid JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $azure = fakeAzureClient(json_encode([
        'technical' => 'tech text',
        'splits' => 'splits text',
        'zones' => 'zones text',
    ], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($azure);
    $payload = $narrator->generate($a, $d);
    expect($payload['technical'])->toBe('tech text')
        ->and($payload['splits'])->toBe('splits text')
        ->and($payload['zones'])->toBe('zones text');
});

it('RunInsightNarrator throws on missing keys', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $azure = fakeAzureClient(json_encode(['technical' => 'only one'], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($azure);
    $narrator->generate($a, $d);
})->throws(UnavailableException::class);

it('RunInsightNarrator throws on non-JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $azure = fakeAzureClient('not json');
    $narrator = new RunInsightNarrator($azure);
    $narrator->generate($a, $d);
})->throws(UnavailableException::class, 'non-JSON');

// ── WeeklyRecapNarrator ───────────────────────────────────────────────

it('WeeklyRecapNarrator returns narrative on valid JSON', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
        'distance_km' => 30.0,
        'runs' => 4,
    ]);
    $azure = fakeAzureClient(json_encode(['narrative' => 'Minggu solid'], JSON_THROW_ON_ERROR));
    $narrator = new WeeklyRecapNarrator($azure);
    expect($narrator->generate($snap))->toBe('Minggu solid');
});

it('WeeklyRecapNarrator throws on missing narrative key', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
    ]);
    $azure = fakeAzureClient(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new WeeklyRecapNarrator($azure);
    $narrator->generate($snap);
})->throws(UnavailableException::class);

it('WeeklyRecapNarrator throws on non-JSON', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
    ]);
    $azure = fakeAzureClient('not json');
    $narrator = new WeeklyRecapNarrator($azure);
    $narrator->generate($snap);
})->throws(UnavailableException::class, 'non-JSON');

// ── PrContextNarrator ─────────────────────────────────────────────────

it('PrContextNarrator returns flavor on valid JSON', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500,
    ]);
    $azure = fakeAzureClient(json_encode(['flavor' => 'PR baru!'], JSON_THROW_ON_ERROR));
    $narrator = new PrContextNarrator($azure);
    expect($narrator->generate($pr))->toBe('PR baru!');
});

it('PrContextNarrator throws on missing flavor key', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->for($user)->create();
    $azure = fakeAzureClient(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new PrContextNarrator($azure);
    $narrator->generate($pr);
})->throws(UnavailableException::class);

it('PrContextNarrator throws on non-JSON', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->for($user)->create();
    $azure = fakeAzureClient('not json');
    $narrator = new PrContextNarrator($azure);
    $narrator->generate($pr);
})->throws(UnavailableException::class, 'non-JSON');

// ── TrendCaptionNarrator ──────────────────────────────────────────────

it('TrendCaptionNarrator returns caption on valid JSON', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->subWeek()->endOfWeek()->toDateString(),
        'distance_km' => 25,
        'ctl_42d' => 40,
    ]);
    $azure = fakeAzureClient(json_encode(['caption' => 'Tren naik'], JSON_THROW_ON_ERROR));
    $narrator = new TrendCaptionNarrator($azure, app(TrainingLoad::class));
    expect($narrator->generate($user, Carbon::today()))->toBe('Tren naik');
});

it('TrendCaptionNarrator throws on missing caption key', function (): void {
    $user = User::factory()->create();
    $azure = fakeAzureClient(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new TrendCaptionNarrator($azure, app(TrainingLoad::class));
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class);

it('TrendCaptionNarrator throws on non-JSON', function (): void {
    $user = User::factory()->create();
    $azure = fakeAzureClient('not json');
    $narrator = new TrendCaptionNarrator($azure, app(TrainingLoad::class));
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class, 'non-JSON');

// ── CardFlavorNarrator ────────────────────────────────────────────────

function cardFixture(): RunCard
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0,
        'moving_time' => 1500,
    ]);

    return RunCard::factory()->create([
        'activity_id' => $activity->id,
        'rarity' => 'rare',
        'special_move' => 'Pembara Sabar',
    ]);
}

it('CardFlavorNarrator returns flavor on valid JSON', function (): void {
    $card = cardFixture();
    $azure = fakeAzureClient(json_encode(['flavor' => 'Kartu epic!'], JSON_THROW_ON_ERROR));
    $narrator = new CardFlavorNarrator($azure);
    expect($narrator->generate($card))->toBe('Kartu epic!');
});

it('CardFlavorNarrator throws on missing flavor key', function (): void {
    $card = cardFixture();
    $azure = fakeAzureClient(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new CardFlavorNarrator($azure);
    $narrator->generate($card);
})->throws(UnavailableException::class);

it('CardFlavorNarrator throws on non-JSON', function (): void {
    $card = cardFixture();
    $azure = fakeAzureClient('not json');
    $narrator = new CardFlavorNarrator($azure);
    $narrator->generate($card);
})->throws(UnavailableException::class, 'non-JSON');
