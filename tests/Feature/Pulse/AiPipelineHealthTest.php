<?php

declare(strict_types=1);

use App\Services\AI\AzureConfigCircuitBreaker;
use App\Models\Activity;
use App\Livewire\Pulse\AiPipelineHealth;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the status snapshot without error', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('failed')
        ->assertSee('done');
});

it('shows the content-filter fallback trend line', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('content-filter fallbacks: 0');
});

it('shows an ok health badge when no analysis has failed', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('health: ok');
});

it('shows an alert health badge when an analysis has failed', function (): void {
    Analysis::factory()->failed('boom')->create([
        'subject_type' => Activity::class,
        'subject_id' => 7,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'attempts' => 3,
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('health: alert');
});

it('surfaces a recent failed analysis with its error', function (): void {
    Analysis::factory()->failed('Azure timed out')->create([
        'subject_type' => Activity::class,
        'subject_id' => 42,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'attempts' => 3,
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('Activity #42')
        ->assertSee('Azure timed out');
});

it('shows no dead-letter attention link when nothing is dead-lettered', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertDontSee('/devtools/narration');
});

it('surfaces a dead-letter attention link when a failed analysis exhausted self-heal', function (): void {
    Analysis::factory()->failed('gave up')->create([
        'subject_type' => Activity::class,
        'subject_id' => 99,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('/devtools/narration');
});

it('surfaces the failed_jobs count', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('Failed jobs');
});

it('shows AI generation as healthy by default', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('healthy');
});

it('shows AI generation as paused by the kill-switch', function (): void {
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('paused: kill switch off');
});

it('shows AI generation as paused when Azure is unconfigured', function (): void {
    config(['azure_openai.uri' => '', 'azure_openai.api_key' => '']);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('paused: Azure unconfigured');
});

it('shows AI generation as paused when the config circuit breaker is tripped', function (): void {
    config(['azure_openai.uri' => 'https://x.openai.azure.com/x', 'azure_openai.api_key' => 'wrong-key']);

    // Three consecutive config/auth failures trip the breaker open.
    $breaker = app(AzureConfigCircuitBreaker::class);
    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('paused: check API key / base URL');
});

it('stays healthy when one athlete has spent their own ceiling, since nothing shared has stopped', function (): void {
    $user = User::factory()->create();
    config(['azure_openai.daily_cost_ceiling_per_user' => 1.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    // 1M input @ 2.50/1M = $2.50 spent today by this athlete, over their $1.00.
    TokenUsage::query()->create([
        'user_id' => $user->id,
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => now(),
    ]);

    // The ceiling is per athlete, so one of them running out is ordinary
    // operation rather than an incident the maintainer should be alerted to.
    // Their trip is still visible on /devtools/narration via CostCeilingLedger.
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('healthy');
});

it('shows today spend against the app-wide ceiling', function (): void {
    config(['azure_openai.daily_cost_ceiling_total' => 5.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    TokenUsage::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => now(),
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('app-wide of $5.00')
        ->assertSee('$2.50');
});

it('measures the heaviest athlete today against the per-athlete ceiling', function (): void {
    config(['azure_openai.daily_cost_ceiling_per_user' => 1.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    $heavy = User::factory()->create();
    $light = User::factory()->create();

    TokenUsage::query()->create([
        'user_id' => $heavy->id,
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => now(),
    ]);
    TokenUsage::query()->create([
        'user_id' => $light->id,
        'kind' => 'briefing', 'prompt_tokens' => 1_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000, 'model' => 'gpt-4o', 'created_at' => now(),
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('top athlete of $1.00')
        ->assertSee('1 athlete capped today');
});

it('reports Azure latency percentiles from the usage rows', function (): void {
    foreach ([100, 200, 300, 400, 5000] as $latency) {
        TokenUsage::query()->create([
            'kind' => 'briefing', 'prompt_tokens' => 10, 'completion_tokens' => 10,
            'total_tokens' => 20, 'model' => 'gpt-4o', 'latency_ms' => $latency,
            'created_at' => now()->subMinutes(5),
        ]);
    }

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('300ms')
        ->assertSee('400ms')
        ->assertSee('timed calls');
});

it('shows the ai queue depth and an unknown oldest job on a non-redis queue', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('ai queue')
        ->assertSee('waiting')
        ->assertSee('oldest job');
});
