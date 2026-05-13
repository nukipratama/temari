<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Models\User;
use App\Services\Run\Story\Briefing;
use App\Services\Run\Story\Contracts\BriefingNarrator;
use App\Services\Run\Story\Narrators\FallbackBriefingNarrator;
use App\Services\Run\Story\Narrators\LlmBriefingNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Mimic prod: Azure env present so the binding wires the LLM chain
    // instead of binding rule-based directly.
    config()->set('azure_openai.uri', 'https://fake.openai.azure.com/openai/deployments/x/chat/completions');
    config()->set('azure_openai.api_key', 'fake-key');
    config()->set('azure_openai.deployment', 'x');
});

it('shows degraded=true on the Dashboard briefing when the LLM throws and we fall back to rule-based', function (): void {
    $user = User::factory()->create();

    // Force the resolver: LLM impl throws, secondary rule-based runs.
    app()->bind(BriefingNarrator::class, function ($app): BriefingNarrator {
        $llm = Mockery::mock(LlmBriefingNarrator::class);
        $llm->shouldReceive('generate')->andThrow(new RuntimeException('Azure 500'));

        return new FallbackBriefingNarrator($llm, $app->make(Briefing::class));
    });

    $this->actingAs($user)->get('/dashboard')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('briefing.degraded', true));
});

it('shows degraded=false when LLM is disabled (env empty) so UI stays clean', function (): void {
    // Re-disable LLM by clearing config; AppServiceProvider re-binds rules-only.
    config()->set('azure_openai.uri', '');
    config()->set('azure_openai.api_key', '');
    app()->forgetInstance(BriefingNarrator::class);
    app()->register(AppServiceProvider::class, force: true);

    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('briefing.degraded', false));
});
