<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Livewire\Pulse\AiPipelineHealth;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
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

it('shows an ok health badge when no analysis has failed', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('health: ok');
});

it('shows an alert health badge when an analysis has failed', function (): void {
    DB::table('ai_analyses')->insert([
        'subject_type' => Activity::class,
        'subject_id' => 7,
        'analysis_type' => 'activity_story',
        'status' => 'failed',
        'error' => 'boom',
        'attempts' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('health: alert');
});

it('surfaces a recent failed analysis with its error', function (): void {
    DB::table('ai_analyses')->insert([
        'subject_type' => Activity::class,
        'subject_id' => 42,
        'analysis_type' => 'activity_story',
        'status' => 'failed',
        'error' => 'Azure timed out',
        'attempts' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('Activity #42')
        ->assertSee('Azure timed out');
});

it('shows no dead-letter attention link when nothing is dead-lettered', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertDontSee('/ai-usage');
});

it('surfaces a dead-letter attention link when a failed analysis exhausted self-heal', function (): void {
    DB::table('ai_analyses')->insert([
        'subject_type' => Activity::class,
        'subject_id' => 99,
        'analysis_type' => 'activity_story',
        'status' => 'failed',
        'error' => 'gave up',
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('/ai-usage');
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

it('shows AI generation as paused when the daily cost ceiling is hit', function (): void {
    config(['azure_openai.daily_cost_ceiling' => 1.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    // 1M input @ 2.50/1M = $2.50 spent today, over the $1.00 ceiling.
    TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => now(),
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('paused: cost ceiling hit today');
});
