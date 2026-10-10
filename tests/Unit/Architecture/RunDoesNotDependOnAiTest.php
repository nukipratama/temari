<?php

declare(strict_types=1);

use App\Services\AI\AnalysisType;
use App\Services\AI\AnalysisStatus;
use App\Actions\AI\SettleEarlyNarrationAction;

arch('Run does not take new dependencies on the AI namespaces')
    ->expect('App\Services\Run')
    ->not->toUse(['App\Services\AI', 'App\Actions\AI'])
    ->ignoring([
        AnalysisType::class,
        AnalysisStatus::class,
        SettleEarlyNarrationAction::class,
    ])
    ->group('structure');
