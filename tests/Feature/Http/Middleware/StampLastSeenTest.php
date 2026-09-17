<?php

declare(strict_types=1);

use App\Http\Middleware\StampLastSeen;
use App\Jobs\AI\NarrateOnReturnJob;
use Illuminate\Support\Facades\Bus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function stampFor(?User $user): void
{
    $request = Request::create('/');
    $request->setUserResolver(fn (): ?User => $user);

    app(StampLastSeen::class)->handle($request, fn (): Response => new Response());
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('stamps last_seen_at on the first authenticated request', function (): void {
    Carbon::setTestNow('2026-09-15 08:00:00');
    $user = User::factory()->create(['last_seen_at' => null]);

    stampFor($user);

    expect($user->fresh()->last_seen_at?->toDateTimeString())->toBe('2026-09-15 08:00:00');
});

it('does not re-stamp later the same day', function (): void {
    Carbon::setTestNow('2026-09-15 08:00:00');
    $user = User::factory()->create(['last_seen_at' => null]);
    stampFor($user);

    Carbon::setTestNow('2026-09-15 23:59:00');
    stampFor($user->fresh());

    expect($user->fresh()->last_seen_at?->toDateTimeString())->toBe('2026-09-15 08:00:00');
});

it('re-stamps once the date rolls', function (): void {
    Carbon::setTestNow('2026-09-15 23:00:00');
    $user = User::factory()->create(['last_seen_at' => Carbon::now()]);

    Carbon::setTestNow('2026-09-16 00:30:00');
    stampFor($user->fresh());

    expect($user->fresh()->last_seen_at?->toDateTimeString())->toBe('2026-09-16 00:30:00');
});

it('leaves the demo identity unstamped', function (): void {
    Carbon::setTestNow('2026-09-15 08:00:00');
    $demo = User::factory()->demo()->create(['last_seen_at' => null]);

    stampFor($demo);

    expect($demo->fresh()->last_seen_at)->toBeNull();
});

it('queues the return narration on the first visit after a gap, and only once that day', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-09-15 08:00:00');
    $user = User::factory()->create(['created_at' => Carbon::parse('2026-06-01'), 'last_seen_at' => Carbon::parse('2026-09-07 20:00:00')]);

    stampFor($user);
    stampFor($user->fresh());

    Bus::assertDispatchedTimes(NarrateOnReturnJob::class, 1);
    Bus::assertDispatched(NarrateOnReturnJob::class, fn (NarrateOnReturnJob $job): bool => $job->userId === $user->id);
});

it('queues the return narration for an older account that has never been seen', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-09-15 08:00:00');
    $user = User::factory()->create(['created_at' => Carbon::parse('2026-06-01'), 'last_seen_at' => null]);

    stampFor($user);

    Bus::assertDispatched(NarrateOnReturnJob::class);
});

it('queues nothing for an athlete still inside the active window', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-09-15 08:00:00');
    $user = User::factory()->create(['created_at' => Carbon::parse('2026-06-01'), 'last_seen_at' => Carbon::parse('2026-09-08 06:00:00')]);

    stampFor($user);

    Bus::assertNotDispatched(NarrateOnReturnJob::class);
});

it('queues nothing on the first visit of a brand-new account', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-09-15 08:00:00');
    $user = User::factory()->create(['last_seen_at' => null]);

    stampFor($user);

    Bus::assertNotDispatched(NarrateOnReturnJob::class);
});

it('queues nothing for the demo identity', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-09-15 08:00:00');
    $demo = User::factory()->demo()->create(['created_at' => Carbon::parse('2026-06-01'), 'last_seen_at' => null]);

    stampFor($demo);

    Bus::assertNotDispatched(NarrateOnReturnJob::class);
});

it('is a no-op for a guest', function (): void {
    stampFor(null);
})->throwsNoExceptions();

it('is wired into the web group, so a real page view stamps', function (): void {
    Carbon::setTestNow('2026-09-15 08:00:00');
    $user = User::factory()->create(['last_seen_at' => null]);

    $this->actingAs($user)->get('/ai-use')->assertSuccessful();

    expect($user->fresh()->last_seen_at?->toDateTimeString())->toBe('2026-09-15 08:00:00');
});
