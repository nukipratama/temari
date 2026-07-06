<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Support\Cooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the Kalender page for the current month by default', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Riwayat/Kalender')
            ->where('month', Carbon::today()->format('Y-m'))
            ->has('monthLabel')
            ->has('cells'));
});

it('shares telegramConnected true for a live connection', function (): void {
    $connected = User::factory()->create();
    TelegramConnection::factory()->for($connected)->create();
    $this->actingAs($connected)->get('/kalender')
        ->assertInertia(fn (Assert $page) => $page->where('telegramConnected', true));
});

it('shares telegramConnected false for a revoked connection', function (): void {
    $revoked = User::factory()->create();
    TelegramConnection::factory()->for($revoked)->revoked()->create();
    $this->actingAs($revoked)->get('/kalender')
        ->assertInertia(fn (Assert $page) => $page->where('telegramConnected', false));
});

it('shares telegramConnected false when there is no connection', function (): void {
    $none = User::factory()->create();
    $this->actingAs($none)->get('/kalender')
        ->assertInertia(fn (Assert $page) => $page->where('telegramConnected', false));
});

it('honors ?month=YYYY-MM when valid', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=2026-01')
        ->assertInertia(fn (Assert $page) => $page->where('month', '2026-01'));
});

it('falls back to today\'s month when ?month is invalid', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=bogus')
        ->assertInertia(fn (Assert $page) => $page->where('month', Carbon::today()->format('Y-m')));
});

it('falls back to today\'s month when ?month parses but is impossible (Carbon throws)', function (): void {
    // 9999-99 passes the YYYY-MM regex but Carbon::parse refuses month 99.
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=9999-99')
        ->assertInertia(fn (Assert $page) => $page->where('month', Carbon::today()->format('Y-m')));
});

it('exposes prev / next month strings for navigation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page
            ->where('prevMonth', '2026-04')
            ->where('nextMonth', '2026-06'));
});

it('pads the grid to whole Mon-Sun weeks (divisible by 7)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page
            ->has('cells', fn (Assert $cells) => $cells->etc())
            ->where('cells', fn ($cells) => count($cells) % 7 === 0));
});

it('aggregates multiple runs on the same day into one cell', function (): void {
    $user = User::factory()->create();
    foreach ([3000, 4000] as $distance) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::create(2026, 5, 15),
            'distance' => $distance,
            'moving_time' => (int) ($distance / 1000 * 360),
            'average_heartrate' => 150,
            'trimp_edwards' => 20.0,
        ]);
    }

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page
            ->where('cells', function ($cells) {
                $cell = collect($cells)->firstWhere('date', '2026-05-15');
                return $cell !== null
                    && abs(((float) $cell['distance_km']) - 7.0) < 0.01
                    && $cell['activity_id'] === null; // multi-run days don't link
            }));
});

it('exposes a lifetime stats payload', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Riwayat/Kalender')
            ->has('lifetime.total_runs')
            ->has('lifetime.total_km')
            ->has('lifetime.first_run_at'));
});

it('passes the MonthlyRecap analysis for the viewed month as the monthlyRecap prop', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('Bulan Mei kamu padat, ritmenya kejaga.')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
    ]);

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page
            ->where('monthlyRecap.status', 'done')
            ->where('monthlyRecap.content', 'Bulan Mei kamu padat, ritmenya kejaga.')
            ->where('monthlyRecap.type', AnalysisType::MonthlyRecap->value)
            ->where('monthlyRecap.discriminator', '2026-05')
            ->where('monthlyRecap.telegram_retry_after_seconds', null));
});

it('surfaces the monthly recap Telegram cooldown when a send is on cooldown', function (): void {
    $user = User::factory()->create();
    $recap = Analysis::factory()->done('Bulan Mei kamu padat.')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
    ]);
    RateLimiter::hit(Cooldown::telegramKey($recap->id), Cooldown::WINDOW_SECONDS);

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page
            ->where('monthlyRecap.telegram_retry_after_seconds', fn (?int $s): bool => $s !== null && $s > 0)
            ->etc());
});

it('only matches the recap row for the viewed month, not another month', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('Cerita bulan April.')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-04',
    ]);

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page
            ->where('monthlyRecap.status', 'pending')
            ->where('monthlyRecap.content', null)
            ->where('monthlyRecap.discriminator', '2026-05'));
});

it('flags the latest completed month with a run as the chain head', function (): void {
    Carbon::setTestNow('2026-06-17');
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::create(2026, 5, 20),
    ]);

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page->where('monthlyRecap.is_chain_head', true));

    $this->actingAs($user)->get('/kalender?month=2026-04')
        ->assertInertia(fn (Assert $page) => $page->where('monthlyRecap.is_chain_head', false));
});

it('never flags the current (in-progress) month as the chain head', function (): void {
    Carbon::setTestNow('2026-06-17');
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::create(2026, 6, 5),
    ]);

    $this->actingAs($user)->get('/kalender?month=2026-06')
        ->assertInertia(fn (Assert $page) => $page->where('monthlyRecap.is_chain_head', false));
});
