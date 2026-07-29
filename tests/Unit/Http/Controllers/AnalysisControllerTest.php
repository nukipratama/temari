<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Requests\TriggerAnalysisRequest;
use App\Services\AI\AnalysisService;
use App\Services\AI\ChainResolver;
use App\Services\Run\Metrics\SummaryRecomputer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('throws Unauthenticated when the request has no user (defensive guard)', function (): void {
    $controller = new AnalysisController();
    $request = TriggerAnalysisRequest::create('/api/analyses/briefing_mascot_voice/1/trigger', 'POST');

    expect(fn () => $controller->trigger($request, app(AnalysisService::class), app(SummaryRecomputer::class), app(ChainResolver::class), 'briefing_mascot_voice', 1))
        ->toThrow(AuthorizationException::class, 'Unauthenticated');
});
