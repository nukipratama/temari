<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Requests\TriggerAnalysisRequest;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('throws Unauthenticated when the request has no user (defensive guard)', function (): void {
    $controller = new AnalysisController();
    $request = TriggerAnalysisRequest::create('/api/analyses/briefing_headline/1/trigger', 'POST');

    expect(fn () => $controller->trigger($request, app(AnalysisService::class), app(ActivityPipeline::class), 'briefing_headline', 1))
        ->toThrow(AuthorizationException::class, 'Unauthenticated');
});

it('handles every AnalysisType in subject authorization (no UnhandledMatchError)', function (): void {
    $user = User::factory()->create();
    $controller = new AnalysisController();
    $authorize = new ReflectionMethod($controller, 'authorizeSubject');

    // A subject id owned by nobody: every match arm should evaluate false and
    // throw AuthorizationException. A new AnalysisType without a match arm would
    // instead throw \UnhandledMatchError, failing this test instead of prod.
    foreach (AnalysisType::cases() as $type) {
        expect(fn () => $authorize->invoke($controller, $user, $type, PHP_INT_MAX))
            ->toThrow(AuthorizationException::class);
    }
});
