<?php

declare(strict_types=1);

use App\Support\NewExceptionLedger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('fingerprints a reported server exception for the next digest', function (): void {
    report(new RuntimeException('athlete 42 broke'));

    $pending = NewExceptionLedger::pull();

    expect($pending)->toHaveCount(1)
        ->and($pending[0]['label'])->toStartWith('RuntimeException at tests/Feature/ReportedExceptionsAreFingerprintedTest.php:')
        ->and(json_encode($pending))->not->toContain('athlete');
});

it('leaves exceptions the handler does not report out of the digest', function (): void {
    report(new NotFoundHttpException());

    expect(NewExceptionLedger::pull())->toBe([]);
});
