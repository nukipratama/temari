<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function phantomPrCard(User $user): RunCard
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-09-17 17:39:52'),
        'distance' => 2000,
        'stream_summary' => null,
    ]);

    return RunCard::factory()->create(['activity_id' => $activity->id, 'pr_set' => true]);
}

it('reports the corrections on a dry run and keeps none of them', function (): void {
    $card = phantomPrCard(User::factory()->create());

    $this->artisan('run:recompute-card-claims', ['--dry-run' => true])
        ->expectsOutputToContain("1 PR flags cleared [{$card->activity_id}]")
        ->expectsOutputToContain('Dry run: nothing was kept.')
        ->assertSuccessful();

    expect($card->fresh()->pr_set)->toBeTrue();
});

it('keeps the corrections without --dry-run, for the one user asked about', function (): void {
    $card = phantomPrCard(User::factory()->create());
    $other = phantomPrCard(User::factory()->create());

    $this->artisan('run:recompute-card-claims', ['--user' => $card->activity->user_id])->assertSuccessful();

    expect($card->fresh()->pr_set)->toBeFalse()
        ->and($other->fresh()->pr_set)->toBeTrue();
});

it('leaves the seeded demo account alone', function (): void {
    $card = phantomPrCard(User::factory()->demo()->create());

    $this->artisan('run:recompute-card-claims')->assertSuccessful();

    expect($card->fresh()->pr_set)->toBeTrue();
});
