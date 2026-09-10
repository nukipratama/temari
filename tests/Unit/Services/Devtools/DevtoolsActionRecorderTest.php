<?php

declare(strict_types=1);

use App\Models\Analytics\DevtoolsAction;
use App\Services\Devtools\DevtoolsActionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('records the local actor when the request carries no basic-auth user', function (): void {
    new DevtoolsActionRecorder()->record('ai_usage.recover');

    $action = DevtoolsAction::query()->sole();

    expect($action->actor)->toBe(DevtoolsActionRecorder::LOCAL_ACTOR)
        ->and($action->action)->toBe('ai_usage.recover')
        ->and($action->user_id)->toBeNull()
        ->and($action->payload)->toBeNull();
});

it('records the basic-auth username as the actor', function (): void {
    $request = Request::create('/devtools/ai-usage', 'GET');
    $request->headers->set('PHP_AUTH_USER', 'nuki');
    app()->instance('request', $request);

    new DevtoolsActionRecorder()->record('ai_usage.retry_failed', 7, ['blocks' => 2]);

    $action = DevtoolsAction::query()->sole();

    expect($action->actor)->toBe('nuki')
        ->and($action->user_id)->toBe(7)
        ->and($action->payload)->toBe(['blocks' => 2]);
});

it('logs and swallows a write failure, so a successful action never 500s on its audit row', function (): void {
    Log::spy();

    new DevtoolsActionRecorder()->record('ai_usage.recover', 7, ['blocks' => "\xB1\x31"]);

    expect(DevtoolsAction::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message): bool => $message === 'devtools_action.record_failed',
    );
});
