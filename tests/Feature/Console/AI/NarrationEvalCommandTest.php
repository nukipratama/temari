<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\TokenUsage;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\StructuredChatCaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use OpenAI\Resources\Responses;
use OpenAI\Testing\ClientFake;

uses(RefreshDatabase::class);

function evalVoices(int $count = 30): array
{
    return array_fill(0, $count, '8 km, easy the whole way.');
}

function evalClient(array $voices): ClientFake
{
    $client = new ClientFake(array_map(
        fn (string $voice): mixed => fakeAzureResponse(json_encode(['voice' => $voice], JSON_THROW_ON_ERROR)),
        $voices,
    ));
    app()->instance(StructuredChatCaller::class, fakeStructuredCaller($client));

    return $client;
}

it('refuses to start without an explicit --max-calls', function (): void {
    User::factory()->demo()->create();
    $client = evalClient(evalVoices());

    $this->artisan('narration:eval', ['--kind' => ['plan_day_voice']])
        ->expectsOutputToContain('--max-calls')
        ->assertFailed();

    $client->assertNothingSent();
});

it('refuses a cap above the hard ceiling of 60', function (): void {
    User::factory()->demo()->create();
    $client = evalClient(evalVoices());

    $this->artisan('narration:eval', ['--max-calls' => 61])
        ->expectsOutputToContain('60')
        ->assertFailed();

    $client->assertNothingSent();
});

it('refuses a non-numeric or zero cap', function (string $cap): void {
    User::factory()->demo()->create();
    $client = evalClient(evalVoices());

    $this->artisan('narration:eval', ['--max-calls' => $cap])->assertFailed();

    $client->assertNothingSent();
})->with(['0', 'many', '-3']);

it('refuses in production', function (): void {
    User::factory()->demo()->create();
    $client = evalClient(evalVoices());
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('narration:eval', ['--max-calls' => 5])
        ->expectsOutputToContain('production')
        ->assertFailed();

    $client->assertNothingSent();
});

it('refuses without a demo athlete', function (): void {
    $this->artisan('narration:eval', ['--max-calls' => 5])
        ->expectsOutputToContain('demo')
        ->assertFailed();
});

it('refuses an unknown kind', function (): void {
    User::factory()->demo()->create();

    $this->artisan('narration:eval', ['--max-calls' => 5, '--kind' => ['weekly_recap']])
        ->expectsOutputToContain('weekly_recap')
        ->assertFailed();
});

it('never makes more model calls than the cap', function (): void {
    User::factory()->demo()->create();
    $client = evalClient(evalVoices());

    $this->artisan('narration:eval', ['--max-calls' => 3, '--kind' => ['plan_day_voice']])
        ->expectsOutputToContain('cap')
        ->run();

    $client->assertSent(Responses::class, 3);
    expect(TokenUsage::query()->count())->toBe(3);
});

it('dispatches no jobs', function (): void {
    Bus::fake();
    Queue::fake();
    User::factory()->demo()->create();
    evalClient(evalVoices());

    $this->artisan('narration:eval', ['--max-calls' => 7, '--kind' => ['plan_day_voice']])->run();

    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
});

it('rolls back everything it built', function (): void {
    $demo = User::factory()->demo()->create();
    evalClient(evalVoices());

    $this->artisan('narration:eval', ['--max-calls' => 7, '--kind' => ['plan_day_voice']])->run();

    expect(PlannedSession::query()->where('user_id', $demo->id)->count())->toBe(0)
        ->and(Activity::query()->where('user_id', $demo->id)->count())->toBe(0)
        ->and(ActivityDetail::query()->count())->toBe(0);
});

it('rolls back after the model call throws', function (): void {
    $demo = User::factory()->demo()->create();
    $client = new ClientFake([new RuntimeException('azure down')]);
    app()->instance(StructuredChatCaller::class, fakeStructuredCaller($client));

    $this->artisan('narration:eval', ['--max-calls' => 1, '--kind' => ['plan_day_voice']])
        ->expectsOutputToContain('azure down')
        ->assertFailed();

    expect(PlannedSession::query()->where('user_id', $demo->id)->count())->toBe(0)
        ->and(Activity::query()->where('user_id', $demo->id)->count())->toBe(0);
});

it('fails the row and the exit code when the answer inverts a fixture direction', function (): void {
    User::factory()->demo()->create();
    evalClient(array_fill(0, 7, 'it ran harder than the easy day asked for, well past the limit.'));

    $this->artisan('narration:eval', ['--max-calls' => 7, '--kind' => ['plan_day_voice']])
        ->expectsOutputToContain('FAIL')
        ->assertFailed();
});

it('prints the spend from the usage metering', function (): void {
    User::factory()->demo()->create();
    evalClient(['8 km, easy the whole way.']);

    $this->artisan('narration:eval', ['--max-calls' => 1, '--kind' => ['plan_day_voice']])
        ->expectsOutputToContain('Spend')
        ->run();
});

it('runs every kind and reports each fixture against the demo history', function (): void {
    $demo = User::factory()->demo()->create();
    foreach ([20, 18, 16, 14, 12, 10, 5, 3] as $daysAgo) {
        $activity = Activity::factory()->for($demo)->create();
        $seconds = match ($daysAgo) {
            5 => 2400,
            3 => 3800,
            default => 2880,
        };
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => now()->subDays($daysAgo),
            'distance' => 8000.0,
            'moving_time' => $seconds,
            'elapsed_time' => $seconds,
            'stream_summary' => ['per_km' => [['km' => 1, 'pace' => '6:00']]],
        ]);
    }

    $json = static fn (array $payload): string => json_encode($payload, JSON_THROW_ON_ERROR);
    $claims = $json(['claims' => [['anchor' => 'split:1', 'text' => 'km 1 was quicker than your usual.', 'value' => '6:00/km', 'delta' => null]]]);
    $client = new ClientFake([
        ...array_map(fn (string $voice) => fakeAzureResponse($json(['voice' => $voice])), evalVoices(7)),
        ...array_fill(0, 2, fakeAzureResponse($json(['mascot_voice' => "8 km done.\n\nrest up.\n\neat well.", 'session_type' => 'rest']))),
        ...array_fill(0, 3, fakeAzureResponse($claims)),
        fakeAzureResponse($json(['evidence_primary' => 'consistent mornings', 'evidence_secondary' => 'steady weeks', 'profile_voice' => 'a steady runner.'])),
    ]);
    app()->instance(StructuredChatCaller::class, fakeStructuredCaller($client));

    $this->artisan('narration:eval', ['--max-calls' => 13])
        ->expectsOutputToContain('progression_direction')
        ->expectsOutputToContain('hit_easy_today')
        ->expectsOutputToContain('faster_than_past_you')
        ->expectsOutputToContain('Spend: 13 calls')
        ->run();

    $client->assertSent(Responses::class, 13);
});

it('reports a fixture the history cannot support as skipped without spending a call', function (): void {
    User::factory()->demo()->create();
    $client = evalClient(evalVoices());

    $this->artisan('narration:eval', ['--max-calls' => 5, '--kind' => ['run_insight']])
        ->expectsOutputToContain('skipped (no history)')
        ->assertSuccessful();

    $client->assertNothingSent();
});

it('does not spend a call on a fixture that cannot be built', function (): void {
    User::factory()->demo()->create();
    $client = evalClient(evalVoices());
    Event::listen('eloquent.creating: '.PlannedSession::class, fn () => throw new RuntimeException('no such row'));

    $this->artisan('narration:eval', ['--max-calls' => 1, '--kind' => ['plan_day_voice']])
        ->expectsOutputToContain('fixture could not be built: no such row')
        ->expectsOutputToContain('Spend: 0 calls')
        ->assertFailed();

    $client->assertNothingSent();
});
