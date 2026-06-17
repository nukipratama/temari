<?php

use App\Services\AI\AzureOpenAIClient;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Mockery\MockInterface;
use OpenAI\Testing\ClientFake;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');

pest()->beforeEach(function (): void {
    Http::preventStrayRequests();
    // Pest CI skips `npm run build`; neutralize @vite() so Inertia roots render.
    $this->withoutVite();

    // openai-php uses Guzzle directly, so Http::preventStrayRequests can't
    // catch it. Bind a default ClientFake (no scripted responses) so any
    // unmocked AzureOpenAIClient::client() call surfaces as a deterministic
    // mock error instead of a real DNS lookup. Tests that exercise real
    // narrator output override this via app()->instance() or by constructing
    // the narrator with their own fake.
    $this->app->bind(AzureOpenAIClient::class, function (): AzureOpenAIClient {
        $mock = Mockery::mock(AzureOpenAIClient::class);
        $mock->shouldReceive('client')->andReturnUsing(fn () => new ClientFake([]));
        $mock->shouldReceive('deploymentFor')->andReturn('test-deployment');

        return $mock;
    });
})->in('Feature', 'Unit');

/**
 * Build a clean Azure Responses-API result for ClientFake. `from()` is used (not
 * ::fake(), whose recursive merge mangles outputText) so the decoded text is
 * exactly $content.
 */
function fakeAzureResponse(
    string $content,
    string $status = 'completed',
    ?string $truncateReason = null,
    int $inputTokens = 10,
    int $outputTokens = 5,
): OpenAI\Responses\Responses\CreateResponse {
    return OpenAI\Responses\Responses\CreateResponse::from([
        'id' => 'resp_test', 'object' => 'response', 'created_at' => 0, 'status' => $status, 'error' => null,
        'incomplete_details' => $truncateReason !== null ? ['reason' => $truncateReason] : null,
        'instructions' => null, 'max_output_tokens' => null, 'model' => 'test',
        'output' => [[
            'type' => 'message', 'id' => 'msg_test', 'status' => 'completed', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $content, 'annotations' => []]],
        ]],
        'parallel_tool_calls' => true, 'previous_response_id' => null, 'reasoning' => null, 'store' => true,
        'temperature' => 1.0, 'text' => ['format' => ['type' => 'text']], 'tool_choice' => 'auto', 'tools' => [],
        'top_p' => 1.0, 'truncation' => 'disabled',
        'usage' => [
            'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0],
        ],
        'user' => null, 'metadata' => [],
    ], OpenAI\Responses\Meta\MetaInformation::from([]));
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function mockStravaDriver(callable $configure): MockInterface
{
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('redirectUrl')
        ->once()
        ->with(route('auth.strava.callback'))
        ->andReturnSelf();

    $configure($driver);

    Socialite::shouldReceive('driver')->once()->with('strava')->andReturn($driver);

    return $driver;
}
