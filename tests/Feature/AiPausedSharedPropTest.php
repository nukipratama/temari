<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AI\AnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// The share caches the global pause signal under a fixed key, so clear it
// between cases to keep one test's value from leaking into the next.
beforeEach(fn () => Cache::flush());

it('shares true when LLM generation is paused', function (): void {
    $this->partialMock(AnalysisService::class)
        ->shouldReceive('generationPaused')
        ->andReturn(true);

    $this->actingAs(User::factory()->create())->get('/records')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('aiPaused', true));
});

it('shares false when the pipeline is healthy', function (): void {
    $this->partialMock(AnalysisService::class)
        ->shouldReceive('generationPaused')
        ->andReturn(false);

    $this->actingAs(User::factory()->create())->get('/records')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('aiPaused', false));
});

it('shares false for a guest without consulting the pipeline', function (): void {
    $this->get('/login')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('aiPaused', false));
});
