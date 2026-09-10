<?php

declare(strict_types=1);

use App\Models\Analytics\DevtoolsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('writes to the analytics connection', function (): void {
    expect(new DevtoolsAction()->getConnectionName())->toBe('analytics');
});

it('casts the payload to an array and keeps the athlete id an int', function (): void {
    $action = DevtoolsAction::query()->create([
        'actor' => 'nuki',
        'action' => 'ai_usage.retry_failed',
        'user_id' => '7',
        'payload' => ['blocks' => 3],
        'created_at' => Carbon::now(),
    ]);

    $stored = DevtoolsAction::query()->findOrFail($action->id);

    expect($stored->payload)->toBe(['blocks' => 3])
        ->and($stored->user_id)->toBe(7)
        ->and($stored->created_at)->toBeInstanceOf(Carbon::class);
});
