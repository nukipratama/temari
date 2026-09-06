<?php

declare(strict_types=1);

use App\Exceptions\AI\ObsoleteAnalysisException;
use App\Exceptions\AI\UnavailableException;

it('carries its reason through', function (): void {
    $e = new ObsoleteAnalysisException('No PlannedSession for user 2 on 2026-08-31');

    expect($e->getMessage())->toBe('No PlannedSession for user 2 on 2026-08-31');
});

/**
 * AnalyzeGroupJob catches UnavailableException to skip one member of a group.
 * If this extended it, that catch would swallow "the row is obsolete" and the
 * row would survive as Failed — the exact state this exception exists to avoid.
 */
it('is not an UnavailableException, so the group-job catch cannot swallow it', function (): void {
    expect(new ObsoleteAnalysisException('gone'))->not->toBeInstanceOf(UnavailableException::class);
});
