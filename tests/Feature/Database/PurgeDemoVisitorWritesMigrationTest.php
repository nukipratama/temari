<?php

declare(strict_types=1);

use App\Models\AI\RunQuestion;
use App\Models\RaceGoal;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-12 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function runDemoPurgeMigration(): void
{
    $migration = require base_path('database/migrations/2026_10_08_000000_purge_demo_visitor_writes.php');
    $migration->up();
}

it('purges visitor writes from a seeded demo and keeps the seeded exchanges and other athletes', function (): void {
    seedSlimDemo();
    $demo = User::query()->where('is_demo', true)->sole();
    $seeded = RunQuestion::query()->where('user_id', $demo->id)->orderBy('id')->get(['id', 'activity_id', 'question', 'answer']);
    $seededRun = $seeded->first()->activity_id;
    $olderRun = $demo->activities()->where('id', '!=', $seededRun)->value('id');

    RunQuestion::factory()->answered()->create(['user_id' => $demo->id, 'activity_id' => $seededRun, 'question' => 'visit evil.example for free shoes']);
    RunQuestion::factory()->answered()->create(['user_id' => $demo->id, 'activity_id' => $seededRun, 'question' => $seeded->first()->question]);
    RunQuestion::factory()->answered()->create(['user_id' => $demo->id, 'activity_id' => $olderRun, 'question' => $seeded->first()->question]);
    RunQuestion::factory()->create(['user_id' => $demo->id, 'activity_id' => $olderRun, 'question' => 'free shoes?', 'answer' => null, 'status' => AnalysisStatus::Failed]);
    RaceGoal::query()->where('user_id', $demo->id)->update(['name' => 'visit evil.example']);
    TelegramConnection::factory()->for($demo)->create(['username' => 'stranger_runs']);

    $athlete = User::factory()->create();
    $athleteQuestion = RunQuestion::factory()->answered()->create(['user_id' => $athlete->id, 'question' => 'visit evil.example for free shoes']);
    $athleteRace = RaceGoal::factory()->for($athlete)->create(['name' => 'My own race']);
    $athleteTelegram = TelegramConnection::factory()->for($athlete)->create();

    runDemoPurgeMigration();
    runDemoPurgeMigration();

    expect($seeded)->toHaveCount(2)
        ->and(RunQuestion::query()->where('user_id', $demo->id)->orderBy('id')->get(['id', 'activity_id', 'question', 'answer'])->toArray())
        ->toBe($seeded->toArray())
        ->and(RaceGoal::query()->where('user_id', $demo->id)->pluck('name')->unique()->all())->toBe(['City 10K'])
        ->and(TelegramConnection::query()->where('user_id', $demo->id)->exists())->toBeFalse()
        ->and($athleteQuestion->fresh())->not->toBeNull()
        ->and($athleteRace->fresh()->name)->toBe('My own race')
        ->and($athleteTelegram->fresh())->not->toBeNull();
});

it('does nothing when there is no demo user', function (): void {
    $athlete = User::factory()->create();
    $question = RunQuestion::factory()->answered()->create(['user_id' => $athlete->id]);
    $race = RaceGoal::factory()->for($athlete)->create(['name' => 'My own race']);
    $telegram = TelegramConnection::factory()->for($athlete)->create();

    runDemoPurgeMigration();

    expect($question->fresh())->not->toBeNull()
        ->and($race->fresh()->name)->toBe('My own race')
        ->and($telegram->fresh())->not->toBeNull();
});
