<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\InboxNotification;
use App\Models\PerformanceEvidence;
use App\Models\User;
use App\Notifications\FitnessImprovedNotification;
use App\Services\Ops\MaintainerAlerter;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-05 10:00:00'));

afterEach(fn () => Carbon::setTestNow());

/** @param array<string, mixed> $attributes */
function athleteWithTenK(int $seconds, array $attributes = []): User
{
    $user = User::factory()->create([...$attributes, 'last_notified_race_m' => 10_000]);
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'race', 'distance_m' => 10_000, 'elapsed_time_sec' => $seconds,
        'performed_on' => '2026-09-27', 'confirmed_at' => '2026-09-27 12:00:00',
    ]);

    return $user;
}

function tenKVdot(int $seconds): float
{
    return round(nonNull(app(VdotEstimator::class)->vdotFromTimeAndDistance($seconds, 10_000)), 1);
}

it('records the first baseline without telling anyone', function (): void {
    Notification::fake();
    $user = athleteWithTenK(3_600);

    $this->artisan('fitness:notify-improvement')
        ->expectsOutputToContain('Seeded 1 baselines and noted an improvement for 0 users.')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($user->refresh()->last_notified_vdot)->toBe(tenKVdot(3_600));
});

it('notes an improvement of half a VDOT point and moves the baseline', function (): void {
    Notification::fake();
    $user = athleteWithTenK(3_500, ['last_notified_vdot' => tenKVdot(3_500) - 0.5]);

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    Notification::assertSentTo($user, FitnessImprovedNotification::class, fn (FitnessImprovedNotification $note): bool => $note->raceDistanceM === 10_000.0
        && abs($note->supportedSec - 3_500) <= 2
        && $note->basisDistanceM === 10_000
        && $note->basisOn === '2026-09-27'
        && $note->notedOn === '2026-10-05');
    expect($user->refresh()->last_notified_vdot)->toBe(tenKVdot(3_500));
});

it('stays quiet below the threshold and keeps the baseline', function (): void {
    Notification::fake();
    $baseline = tenKVdot(3_500) - 0.4;
    $user = athleteWithTenK(3_500, ['last_notified_vdot' => $baseline]);

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    Notification::assertNothingSent();
    expect($user->refresh()->last_notified_vdot)->toBe($baseline);
});

it('re-seeds the baseline silently when the race distance changes', function (): void {
    Notification::fake();
    $user = athleteWithTenK(3_500, ['last_notified_vdot' => tenKVdot(3_500) - 2.0]);
    $user->forceFill(['last_notified_race_m' => 42_195])->save();

    $this->artisan('fitness:notify-improvement')
        ->expectsOutputToContain('Seeded 1 baselines and noted an improvement for 0 users.')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($user->refresh()->last_notified_vdot)->toBe(tenKVdot(3_500))
        ->and($user->last_notified_race_m)->toBe(10_000);
});

it('notes at most once a week', function (): void {
    Notification::fake();
    $baseline = tenKVdot(3_500) - 1.0;
    $user = athleteWithTenK(3_500, ['last_notified_vdot' => $baseline]);
    InboxNotification::factory()->for($user)->create([
        'kind' => 'fitness_improved', 'dedupe_key' => 'fitness_improved:2026-09-29', 'created_at' => '2026-09-29 10:00:05',
    ]);

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    Notification::assertNothingSent();
    expect($user->refresh()->last_notified_vdot)->toBe($baseline);

    Carbon::setTestNow('2026-10-06 10:00:00');
    app(VdotEstimator::class)->forget($user);
    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    Notification::assertSentTo($user, FitnessImprovedNotification::class);
});

it('skips the demo user', function (): void {
    Notification::fake();
    $demo = athleteWithTenK(3_500, ['is_demo' => true, 'last_notified_vdot' => 30.0]);

    $this->artisan('fitness:notify-improvement')
        ->expectsOutputToContain('Seeded 0 baselines and noted an improvement for 0 users.')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($demo->refresh()->last_notified_vdot)->toBe(30.0);
});

it('leaves an athlete with no supported time alone', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    expect($user->refresh()->last_notified_vdot)->toBeNull();
});

it('keeps checking the other athletes when one throws, and alerts once', function (): void {
    $attributes = ['last_notified_vdot' => tenKVdot(3_500) - 0.5];
    athleteWithTenK(3_500, $attributes);
    athleteWithTenK(3_500, $attributes);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('boom'));
    Notification::shouldReceive('send')->once();
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('athletesFailed')->once()->with('fitness:notify-improvement', 1);
    $this->app->instance(MaintainerAlerter::class, $alerter);

    $this->artisan('fitness:notify-improvement')
        ->expectsOutputToContain('noted an improvement for 1 users.')
        ->assertSuccessful();
});

it('skips an athlete with no run analysed since the last check', function (): void {
    Notification::fake();
    $user = athleteWithTenK(3_500, ['last_notified_vdot' => tenKVdot(3_500) - 0.5, 'fitness_checked_at' => '2026-10-05 08:00:00']);
    Activity::factory()->for($user)->create(['analyzed_at' => '2026-10-05 07:59:59']);

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    Notification::assertNothingSent();
    expect(nonNull($user->refresh()->fitness_checked_at)->toDateTimeString())->toBe('2026-10-05 08:00:00');
});

it('checks and stamps an athlete with a run analysed since the last check', function (): void {
    Notification::fake();
    $user = athleteWithTenK(3_500, ['last_notified_vdot' => tenKVdot(3_500) - 0.5, 'fitness_checked_at' => '2026-10-05 08:00:00']);
    Activity::factory()->for($user)->create(['analyzed_at' => '2026-10-05 09:00:00']);

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    Notification::assertSentTo($user, FitnessImprovedNotification::class);
    expect(nonNull($user->refresh()->fitness_checked_at)->toDateTimeString())->toBe('2026-10-05 10:00:00');
});

it('checks an athlete whose run was analysed in the same second as the last stamp', function (): void {
    Notification::fake();
    $user = athleteWithTenK(3_500, ['last_notified_vdot' => tenKVdot(3_500) - 0.5, 'fitness_checked_at' => '2026-10-05 08:00:00']);
    Activity::factory()->for($user)->create(['analyzed_at' => '2026-10-05 08:00:00']);

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    Notification::assertSentTo($user, FitnessImprovedNotification::class);
});

it('checks and stamps an athlete who was never checked', function (): void {
    Notification::fake();
    $user = athleteWithTenK(3_500, ['last_notified_vdot' => tenKVdot(3_500) - 0.5]);

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    Notification::assertSentTo($user, FitnessImprovedNotification::class);
    expect(nonNull($user->refresh()->fitness_checked_at)->toDateTimeString())->toBe('2026-10-05 10:00:00');
});

it('leaves the stamp alone when the check fails, so the next run retries', function (): void {
    athleteWithTenK(3_500, ['last_notified_vdot' => tenKVdot(3_500) - 0.5]);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('boom'));
    $this->app->instance(MaintainerAlerter::class, Mockery::mock(MaintainerAlerter::class)->shouldIgnoreMissing());

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    expect(User::query()->whereNotNull('fitness_checked_at')->exists())->toBeFalse();
});

it('does not stamp an athlete whose rise is held back by the weekly note interval', function (): void {
    Notification::fake();
    $user = athleteWithTenK(3_500, ['last_notified_vdot' => tenKVdot(3_500) - 1.0]);
    InboxNotification::factory()->for($user)->create([
        'kind' => 'fitness_improved', 'dedupe_key' => 'fitness_improved:2026-09-29', 'created_at' => '2026-09-29 10:00:05',
    ]);

    $this->artisan('fitness:notify-improvement')->assertSuccessful();

    expect($user->refresh()->fitness_checked_at)->toBeNull();
});
