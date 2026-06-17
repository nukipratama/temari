<?php

declare(strict_types=1);

use App\Services\AI\StructuredChatCaller;
use App\Services\AI\TokenUsageRecorder;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\AI\AzureOpenAIClient;
use App\Exceptions\AI\UnavailableException;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\AI\Narrators\BriefingNarrator;
use App\Services\Run\Story\Vibe;
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

/** @return array{user: User, narrator: BriefingNarrator, client: ClientFake} */
function bootNarrator(string $jsonContent): array
{
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'trimp_edwards' => 60.0,
    ]);

    $client = new ClientFake([
        CreateResponse::fake([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $jsonContent]],
            ],
        ]),
    ]);
    $azure = Mockery::mock(AzureOpenAIClient::class);
    $azure->shouldReceive('client')->andReturn($client);
    $azure->shouldReceive('deploymentFor')->andReturn('gpt-test');

    $narrator = new BriefingNarrator(
        app(Vibe::class),
        app(TrainingLoad::class),
        app(VerdictNarrator::class),
        new StructuredChatCaller($azure, app(TokenUsageRecorder::class)),
    );

    return ['user' => $user, 'narrator' => $narrator, 'client' => $client];
}

it('returns headline + suggestion from a valid LLM structured response', function (): void {
    // Mascot voice was split into BriefingMascotVoiceNarrator (separate LLM
    // call) so this narrator only handles headline + suggestion now.
    ['user' => $user, 'narrator' => $narrator] = bootNarrator(json_encode([
        'headline' => 'Pagi yang oke buat lari pelan',
        'suggestion' => 'Easy run 30 menit, dengerin badan',
    ], JSON_THROW_ON_ERROR));

    $payload = $narrator->generate($user, Carbon::today());

    expect($payload['headline'])->toBe('Pagi yang oke buat lari pelan')
        ->and($payload['suggestion'])->toBe('Easy run 30 menit, dengerin badan');
});

it('throws UnavailableException when the response is not valid JSON', function (): void {
    ['user' => $user, 'narrator' => $narrator] = bootNarrator('not json at all');
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class, 'non-JSON');

it('throws UnavailableException when required fields are missing', function (): void {
    ['user' => $user, 'narrator' => $narrator] = bootNarrator(json_encode(['headline' => 'only one'], JSON_THROW_ON_ERROR));
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class, 'missing required fields');

it('throws UnavailableException when the Azure HTTP call itself throws', function (): void {
    $user = User::factory()->create();
    $client = new ClientFake([new RuntimeException('Azure 500')]);
    $azure = Mockery::mock(AzureOpenAIClient::class);
    $azure->shouldReceive('client')->andReturn($client);
    $azure->shouldReceive('deploymentFor')->andReturn('gpt-test');

    $narrator = new BriefingNarrator(
        app(Vibe::class),
        app(TrainingLoad::class),
        app(VerdictNarrator::class),
        new StructuredChatCaller($azure, app(TokenUsageRecorder::class)),
    );
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class, 'Azure OpenAI call failed');
