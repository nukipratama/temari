<?php

declare(strict_types=1);

use App\Exceptions\AI\UnavailableException;
use App\Services\AI\AzureOpenAIClient;
use App\Services\AI\StructuredChatCaller;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;

beforeEach(function (): void {
    config()->set('azure_openai.deployment', 'x');
    config()->set('azure_openai.max_tokens', 200);
});

function structuredCaller(string $content): StructuredChatCaller
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

    return new StructuredChatCaller($azure);
}

it('throws UnavailableException when structured output decodes to a non-object value', function (): void {
    structuredCaller('"just a string"')->call('kind', 'sys', [], 'schema', ['headline']);
})->throws(UnavailableException::class, 'structured output not an object');

it('returns the decoded payload when all required keys are present', function (): void {
    $payload = structuredCaller(json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR))
        ->call('kind', 'sys', [], 'schema', ['headline']);

    expect($payload)->toBe(['headline' => 'hi']);
});
