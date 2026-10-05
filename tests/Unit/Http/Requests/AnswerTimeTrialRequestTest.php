<?php

declare(strict_types=1);

use App\Http\Requests\AnswerTimeTrialRequest;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

it('authorizes only the athlete who owns the trial day', function (): void {
    $owner = User::factory()->create();
    $session = PlannedSession::factory()->for($owner)->create();
    $request = AnswerTimeTrialRequest::create("/plan/time-trials/{$session->id}", 'POST');
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));

    $request->setUserResolver(fn () => $owner);
    $ownerAllowed = $request->authorize();
    $request->setUserResolver(fn () => User::factory()->create());
    $strangerAllowed = $request->authorize();
    $request->setUserResolver(fn () => null);

    expect($ownerAllowed)->toBeTrue()
        ->and($strangerAllowed)->toBeFalse()
        ->and($request->authorize())->toBeFalse();
});

it('takes a yes or a no and nothing else', function (array $payload, bool $passes): void {
    expect(Validator::make($payload, new AnswerTimeTrialRequest()->rules())->passes())->toBe($passes);
})->with([
    'yes' => [['all_out' => true], true],
    'no' => [['all_out' => false], true],
    'missing' => [[], false],
    'not a boolean' => [['all_out' => 'maybe'], false],
]);
