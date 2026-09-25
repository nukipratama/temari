<?php

use App\Services\Run\Story\Card\RunForm;
use App\Enums\Rarity;
use App\Services\Run\Story\Card\CardFacts;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use App\Enums\SessionType;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Services\AI\AnalysisService;
use Illuminate\Support\Carbon;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentLoop;
use App\Services\AI\Agent\AgentTool;
use App\Services\AI\AzureCallThrottle;
use App\Services\AI\AzureConfigCircuitBreaker;
use App\Services\AI\AzureOpenAIClient;
use App\Services\AI\NarratedAnalysis;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\StructuredChatCaller;
use App\Actions\AI\RecordTokenUsageAction;
use Database\Factories\TrendDailySnapshotFactory;
use Database\Factories\WeeklySnapshotFactory;
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

pest()->beforeEach(function (): void {
    fake()->seed(crc32(static::class.$this->name()));
    WeeklySnapshotFactory::resetSequence();
    TrendDailySnapshotFactory::resetSequence();
    $this->app->bind(AzureCallThrottle::class, fn (): AzureCallThrottle => new AzureCallThrottle(function (int $seconds): void {
    }));

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
        app(NarrationOrigin::class),
        app(NarratedAnalysis::class),
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
    $service->shouldReceive('requestProfileVoice')
        ->andReturnUsing(function (User $user, string $isoWeek, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = [
                'subjectOrType' => AnalysisType::PROFILE_VOICE_SUBJECT_TYPE,
                'subjectId' => $user->id,
                'type' => AnalysisType::ProfileVoice,
                'discriminator' => $isoWeek,
                'delaySeconds' => null,
                'invalidate' => $invalidate,
                'ruleBased' => false,
            ];

            return new Analysis();
        });
    $service->shouldReceive('requestDeferred')
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator') + ['delaySeconds' => null, 'invalidate' => null, 'ruleBased' => false];

            return new Analysis();
        });

    $service->shouldReceive('shouldServeRuleBased')->andReturn(false);
    $service->shouldReceive('requestBriefing')
        ->andReturnUsing(function (User $user, string $discriminator, bool $invalidate = false, ?int $delaySeconds = null) use (&$captured): Analysis {
            $captured[] = [
                'subjectOrType' => AnalysisType::BRIEFING_SUBJECT_TYPE,
                'subjectId' => $user->id,
                'type' => AnalysisType::BriefingMascotVoice,
                'discriminator' => $discriminator,
                'delaySeconds' => $delaySeconds,
                'invalidate' => $invalidate,
                'ruleBased' => false,
            ];

            return new Analysis();
        });

    return $service;
}

/**
 * Stages an Analysis row for a Telegram push-notification test: Done (with
 * $content) by default, or still-pending when $done is false. Shared by the
 * SendMonthlyRecapNotificationControllerTest / SendWeeklyRecapNotificationControllerTest
 * push tests, which both stage the
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

/**
 * Headers for the follow-up request `<Deferred>` fires once the shell has
 * painted, and for the `only:` reloads the analysis pollers fire. Such a
 * response is bare JSON rather than the HTML page object, so assert it with
 * assertJsonPath()/json() rather than assertInertia()/viewData().
 *
 * @param  object  $actingAs  The authenticated test case.
 * @return array<string, string>
 */
function inertiaPartialHeaders(object $actingAs, string $url, string $component, string $props): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersionFor($actingAs, $url),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $props,
    ];
}

/**
 * Backdates a race season roughly 9 weeks and fills its past days with a
 * rest/easy/tempo/long rotation, so a Plan/Profile query-budget fixture
 * exercises SeasonGamificationContext over a real stretch of past days
 * instead of the day-old season a plain factory create gives.
 */
function seedPastSeasonWeeks(User $user, RaceGoal $race): void
{
    Season::factory()->for($user)->create([
        'race_goal_id' => $race->id,
        'anchor_weekly_volume_km' => 30.0,
        'volume_floor_km' => 20.0,
        'block_goals_appended_at' => Carbon::today(),
        'starts_at' => Carbon::today()->subWeeks(9)->toDateString(),
        'ends_at' => $race->race_date->toDateString(),
    ]);

    foreach (range(1, 62) as $daysAgo) {
        $sessionType = match (true) {
            $daysAgo % 7 === 0 => SessionType::Rest,
            $daysAgo % 7 === 3 => SessionType::Long,
            $daysAgo % 7 === 5 => SessionType::Tempo,
            default => SessionType::Easy,
        };
        PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'session_type' => $sessionType,
        ]);
    }
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

/**
 * A share-card fixture built straight from the value object, so a style test
 * asserts on what it draws rather than on how a run resolves into facts (that
 * is CardFactsTest's job). Override any field by name.
 */
function cardFacts(
    RunForm $form = RunForm::Easy,
    Rarity $rarity = Rarity::Common,
    ?string $polyline = '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
    ?string $heartRate = '142',
    ?string $elevation = '18',
    ?string $weather = '29°C',
    string $dateShort = '13.09.26',
    string $clock = '05:41',
    array $badges = [],
    array $splits = [],
    array $paceProfile = [],
): CardFacts {
    return new CardFacts(
        form: $form,
        rarity: $rarity,
        kind: mb_strtoupper(str_replace('nogps', 'no gps', $form->value)).' RUN',
        km: '5.28',
        distanceKm: 5.28,
        time: '32:18',
        pace: '6:07',
        heartRate: $heartRate,
        elevation: $elevation,
        place: 'Senayan, Jakarta Pusat',
        placeShort: 'SENAYAN',
        weather: $weather,
        dateLong: 'SUN 13 SEP 2026',
        dateShort: $dateShort,
        clock: $clock,
        badges: $badges,
        serial: 'TMR-0418',
        polyline: $polyline,
        raceName: $form === RunForm::Race ? 'JAKARTA CITY 10K' : null,
        raceDistance: $form === RunForm::Race ? '10K' : null,
        splits: $splits,
        paceProfile: $paceProfile,
    );
}
