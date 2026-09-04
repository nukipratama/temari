<?php

declare(strict_types=1);

use App\Services\AI\AnalysisOrigin;

it('covers the four ways a call starts, plus an unattributed default', function (): void {
    expect(array_column(AnalysisOrigin::cases(), 'value'))
        ->toBe(['scheduled', 'ingest', 'user', 'recovery', 'unknown']);
});

it('labels every case for the usage dashboard', function (AnalysisOrigin $origin, string $label): void {
    expect($origin->label())->toBe($label);
})->with([
    'scheduled' => [AnalysisOrigin::Scheduled, 'Scheduled'],
    'ingest' => [AnalysisOrigin::Ingest, 'Ingest cascade'],
    'user' => [AnalysisOrigin::User, 'User-initiated'],
    'recovery' => [AnalysisOrigin::Recovery, 'Recovery'],
    'unknown' => [AnalysisOrigin::Unknown, 'Unattributed'],
]);
