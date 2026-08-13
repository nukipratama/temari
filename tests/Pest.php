<?php

use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentLoop;
use App\Services\AI\Agent\AgentTool;
use App\Services\AI\AzureCallThrottle;
use App\Services\AI\AzureConfigCircuitBreaker;
use App\Services\AI\AzureOpenAIClient;
use App\Services\AI\StructuredChatCaller;
use App\Actions\AI\RecordTokenUsageAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
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

/*
|--------------------------------------------------------------------------
| Test Impact Analysis
|--------------------------------------------------------------------------
|
| TIA replays unaffected tests from a pcov-recorded dependency graph, so a
| local run only executes what a change actually touched. CI passes --no-tia
| and stays exhaustive — a narrowed run would break the 95% coverage gate.
|
| The watch list covers the tests that read the filesystem directly. glob()
| and File::allFiles() produce no coverage edges, so nothing would otherwise
| link a newly added class to the 1:1 structure gate that should fail on it.
| The compliance sweeps read the route table the same way, so a new route has
| to re-run them.
|
| The guard is what keeps parallel worktrees working. A worktree's .git is a
| *file* pointing at a host path outside the container's bind mount, so git
| cannot resolve the repo there — and TIA panics on that rather than degrading,
| which would break every Pest run in a worktree. scripts/worktree-setup.sh
| mounts the shared git dir and exports GIT_DIR to restore it; a worktree whose
| stack predates that override still falls through to TIA off.
|
*/

if (is_dir(dirname(__DIR__).'/.git') || is_dir((string) getenv('GIT_DIR'))) {
    pest()->tia()->locally()->watch([
        'app/**/*.php' => 'tests/Unit/Architecture',
        'tests/**/*.php' => 'tests/Unit/Architecture',
        'docs/**/*.md' => 'tests/Unit/Architecture',
        'resources/css/**' => 'tests/Unit/Architecture',
        'resources/js/types/generated.ts' => 'tests/Feature/Console/GenerateTypeScriptEnumsCommandTest.php',
        'routes/**/*.php' => 'tests/Feature/Compliance',
    ]);
}

pest()->beforeEach(function (): void {
    Http::preventStrayRequests();
    // The local Azure call throttle shares one rate-limit bucket across every
    // call; clear it so one test's calls never count against the next.
    RateLimiter::clear('azure-openai-calls');
    // Same for the dead-letter alert coalescing window — a test that fakes the
    // queue (so the flush never actually pulls/resets it) must not leave a
    // stale count behind for the next test's dead-letter assertions.
    Cache::forget('ai.dead_letter.window_count');
    // Same for the global aiPaused shared-prop cache — a test that mocks
    // AnalysisService::generationPaused() must not read a stale answer cached
    // by a previous test's mock.
    Cache::forget('ai-paused');
    // Pest CI skips `npm run build`; neutralize @vite() so Inertia roots render.
    $this->withoutVite();

    // openai-php uses Guzzle directly, so Http::preventStrayRequests can't catch it.
    // Bind a default ClientFake so any unmocked AzureOpenAIClient::client() call
    // fails deterministically instead of hitting the network.
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
    int $cachedTokens = 0,
    int $reasoningTokens = 0,
): CreateResponse {
    return CreateResponse::from([
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
            'input_tokens_details' => ['cached_tokens' => $cachedTokens],
            'output_tokens_details' => ['reasoning_tokens' => $reasoningTokens],
        ],
        'user' => null, 'metadata' => [],
    ], MetaInformation::from([]));
}

/**
 * A stand-in agent tool whose read is whatever $handler returns.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $handler
 */
function fakeAgentTool(string $name, callable $handler): AgentTool
{
    return new class ($name, $handler) implements AgentTool {
        /** @param  callable(array<string, mixed>): array<string, mixed>  $handler */
        public function __construct(private readonly string $toolName, private $handler)
        {
        }

        public function name(): string
        {
            return $this->toolName;
        }

        public function description(): string
        {
            return 'a test read';
        }

        /** @return array<string, mixed> */
        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false];
        }

        /**
         * @param  array<string, mixed>  $arguments
         * @return array<string, mixed>
         */
        public function handle(array $arguments): array
        {
            return ($this->handler)($arguments);
        }
    };
}

/**
 * A Responses-API turn where the model asks for tools instead of answering.
 *
 * @param  list<array{name: string, arguments?: string}>  $calls
 */
function fakeAzureToolCallResponse(
    array $calls,
    int $inputTokens = 10,
    int $outputTokens = 5,
    int $cachedTokens = 0,
    int $reasoningTokens = 0,
): CreateResponse {
    $output = [];
    foreach ($calls as $index => $call) {
        $output[] = [
            'type' => 'function_call',
            'id' => 'fc_'.$index,
            'call_id' => 'call_'.$index,
            'name' => $call['name'],
            'arguments' => $call['arguments'] ?? '{}',
            'status' => 'completed',
        ];
    }

    return CreateResponse::from([
        'id' => 'resp_test', 'object' => 'response', 'created_at' => 0, 'status' => 'completed', 'error' => null,
        'incomplete_details' => null,
        'instructions' => null, 'max_output_tokens' => null, 'model' => 'test',
        'output' => $output,
        'parallel_tool_calls' => true, 'previous_response_id' => null, 'reasoning' => null, 'store' => true,
        'temperature' => 1.0, 'text' => ['format' => ['type' => 'text']], 'tool_choice' => 'auto', 'tools' => [],
        'top_p' => 1.0, 'truncation' => 'disabled',
        'usage' => [
            'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'input_tokens_details' => ['cached_tokens' => $cachedTokens],
            'output_tokens_details' => ['reasoning_tokens' => $reasoningTokens],
        ],
        'user' => null, 'metadata' => [],
    ], MetaInformation::from([]));
}

/**
 * Wrap a scripted ClientFake in a mocked AzureOpenAIClient + StructuredChatCaller —
 * the shared LLM-boundary fake reused across narrator/caller unit tests.
 */
function fakeStructuredCaller(ClientFake $client, string $deployment = 'gpt-test'): StructuredChatCaller
{
    $azure = Mockery::mock(AzureOpenAIClient::class);
    $azure->shouldReceive('client')->andReturn($client);
    $azure->shouldReceive('deploymentFor')->andReturn($deployment);

    return new StructuredChatCaller(
        $azure,
        app(RecordTokenUsageAction::class),
        new AgentLoop($azure, app(AzureConfigCircuitBreaker::class), app(AzureCallThrottle::class)),
    );
}

/**
 * Mocks AnalysisService::request() to capture every call's arguments instead
 * of hitting the real narration pipeline. Shared by the AI backfill/resume
 * command tests, which assert on the request() call shape (subject, type,
 * discriminator, delay, invalidate) rather than the pipeline's own behavior.
 *
 * @param  array<int, array<string, mixed>>  $captured
 */
function captureAnalysisServiceRequests(array &$captured): AnalysisService
{
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator', 'delaySeconds', 'invalidate') + ['ruleBased' => false];

            return new Analysis();
        });
    $service->shouldReceive('requestRuleBased')
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator') + ['delaySeconds' => null, 'invalidate' => null, 'ruleBased' => true];

            return new Analysis();
        });

    return $service;
}

/**
 * Stages an Analysis row for a Telegram push-notification test: Done (with
 * $content) by default, or still-pending when $done is false. Shared by the
 * SendActivityNotificationControllerTest/SendMonthlyRecapNotificationControllerTest/
 * SendWeeklyRecapNotificationControllerTest push tests, which all stage the
 * same shape (analysis_type/subject_type/subject_id/discriminator) and only
 * differ in which subject/type/discriminator they use.
 */
function doneAnalysisFor(
    string $subjectType,
    int $subjectId,
    AnalysisType $type,
    ?string $discriminator = null,
    bool $done = true,
    string $content = 'Done.',
): Analysis {
    $factory = Analysis::factory();
    $factory = $done ? $factory->done($content) : $factory;

    return $factory->create([
        'analysis_type' => $type,
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'discriminator' => $discriminator,
    ]);
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

/**
 * The asset version a partial (`X-Inertia-Partial-Data`) request to `$url` has
 * to echo back. Shared by the controller tests that assert closure props are
 * skipped on the analysis poller's `router.reload({ only })`.
 *
 * It has to be read off a real response: Inertia 409s a partial request whose
 * `X-Inertia-Version` does not match, and the middleware only computes that
 * value while handling a request.
 *
 * Read off the HTML page rather than a bare Inertia GET: without a version
 * header that request 409s too. This adapter renders the page object as the
 * text content of a <script type="application/json"> block (a CSP measure),
 * not as a data-page attribute.
 *
 * @param  object  $actingAs  The authenticated test case.
 */
function inertiaVersionFor(object $actingAs, string $url): string
{
    $html = $actingAs->get($url)->getContent();
    preg_match('/type="application\/json">(.*?)<\/script>/s', (string) $html, $matches);
    $page = json_decode(html_entity_decode($matches[1] ?? ''), true);

    return is_array($page) ? (string) ($page['version'] ?? '') : '';
}

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
