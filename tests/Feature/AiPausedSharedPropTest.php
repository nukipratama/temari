<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AI\NarrationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// The share caches the global pause signal under a fixed key, so clear it
// between cases to keep one test's value from leaking into the next.
beforeEach(fn () => Cache::flush());

it('shares true when LLM generation is paused', function (): void {
    $analyses = $this->partialMock(NarrationGate::class);
    $analyses->shouldReceive('generationPaused')->andReturn(true);
    $analyses->shouldReceive('pauseReason')->andReturn('kill_switch');

    $this->actingAs(User::factory()->create())->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('aiPaused', true)->where('aiPauseRetriesFailed', true));
});

it('shares false when the pipeline is healthy', function (): void {
    $this->partialMock(NarrationGate::class)
        ->shouldReceive('generationPaused')
        ->andReturn(false);

    $this->actingAs(User::factory()->create())->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('aiPaused', false));
});

it('shares false for a guest without consulting the pipeline', function (): void {
    $this->get('/login')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('aiPaused', false));
});
