<?php

declare(strict_types=1);

use App\Services\AI\AnalysisType;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\PlanNarrationRequester;
use App\Services\AI\HistoryNarrationGate;
use App\Services\AI\AnalysisService;
use App\Actions\AI\SettleEarlyNarrationAction;
use App\Actions\AI\RecentlyActiveUsers;

arch('Run does not take new dependencies on the AI namespaces')
    ->expect('App\Services\Run')
    ->not->toUse(['App\Services\AI', 'App\Actions\AI'])
    ->ignoring([
        AnalysisType::class,
        AnalysisStatus::class,
        PlanNarrationRequester::class,
        HistoryNarrationGate::class,
        AnalysisService::class,
        SettleEarlyNarrationAction::class,
        RecentlyActiveUsers::class,
    ])
    ->group('structure');
