<?php

declare(strict_types=1);

use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-06-01 00:00:00'));
afterEach(fn () => Carbon::setTestNow());

function settleWeeks(User $user, int $count, string $from = '2026-05-31'): void
{
    $week = Carbon::parse($from);
    for ($i = 0; $i < $count; $i++) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => $week->copy()->subDays(7 * $i)->toDateString(),
            'runs' => 2,
        ]);
    }
}

it('mints for a user who completed a cycle and spends for one who went runless', function (): void {
    $earner = User::factory()->create();
    settleWeeks($earner, 4);

    $rester = User::factory()->create();
    settleWeeks($rester, 3, '2026-05-24');
    StreakRestToken::factory()->for($rester)->create(['earned_for_week_ending' => '2026-05-10']);

    $this->artisan('streak:settle')
        ->expectsOutputToContain('minted 1 rest tokens, spent 1')
        ->assertSuccessful();

    expect(StreakRestToken::unspentCountForUser($earner->id))->toBe(1)
        ->and(StreakRestToken::unspentCountForUser($rester->id))->toBe(0)
        ->and(WeeklySnapshot::consecutiveWeekStreak($rester->id))->toBe(3);
});

it('leaves a user with no snapshots alone', function (): void {
    $user = User::factory()->create();

    $this->artisan('streak:settle')->assertSuccessful();

    expect(StreakRestToken::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('is scheduled ahead of the weekly recap, which reads the streak it settles', function (): void {
    $minuteOf = function (string $command): int {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e): bool => str_contains((string) $e->command, $command));

        expect($event)->not->toBeNull("[{$command}] is no longer scheduled");

        [$minute, $hour, , , $day] = explode(' ', $event->expression);

        expect($day)->toBe('1', "[{$command}] no longer runs on Monday");

        return ((int) $hour * 60) + (int) $minute;
    };

    expect($minuteOf('streak:settle'))->toBeLessThan($minuteOf('ai:weekly-recap'));
});
