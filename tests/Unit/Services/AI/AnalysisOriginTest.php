<?php

declare(strict_types=1);

use App\Services\AI\AnalysisOrigin;

it('covers the six ways a call starts, an unattributed default, and the rule-based-only reasons', function (): void {
    expect(array_column(AnalysisOrigin::cases(), 'value'))
        ->toBe(['scheduled', 'ingest', 'user', 'recovery', 'replay', 'return', 'unknown', 'demo', 'capped', 'dead_letter', 'content_filter']);
});

it('labels every case for the usage dashboard', function (AnalysisOrigin $origin, string $label): void {
    expect($origin->label())->toBe($label);
})->with([
    'scheduled' => [AnalysisOrigin::Scheduled, 'Scheduled'],
    'ingest' => [AnalysisOrigin::Ingest, 'Ingest cascade'],
    'user' => [AnalysisOrigin::User, 'User-initiated'],
    'recovery' => [AnalysisOrigin::Recovery, 'Recovery'],
    'replay' => [AnalysisOrigin::Replay, 'Replay'],
    'return' => [AnalysisOrigin::Return, 'Athlete return'],
    'unknown' => [AnalysisOrigin::Unknown, 'Unattributed'],
    'demo' => [AnalysisOrigin::Demo, 'Demo account'],
    'capped' => [AnalysisOrigin::Capped, 'Daily ceiling reached'],
    'dead_letter' => [AnalysisOrigin::DeadLetter, 'Dead-lettered block'],
    'content_filter' => [AnalysisOrigin::ContentFilter, 'Content filter fallback'],
]);
