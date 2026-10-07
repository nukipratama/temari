<?php

declare(strict_types=1);

use App\Services\AI\AnalysisType;
use App\Services\User\UserEraser;

pest()->group('structure');

it('erases every analysis subject type keyed by user id, or names how it is reached', function (): void {
    $erased = new ReflectionClassConstant(UserEraser::class, 'USER_SUBJECT_TYPES')->getValue();

    $erasedAnotherWay = [];

    $subjectTypes = collect(new ReflectionClass(AnalysisType::class)->getReflectionConstants())
        ->filter(fn (ReflectionClassConstant $constant): bool => str_ends_with($constant->getName(), '_SUBJECT_TYPE'))
        ->map(fn (ReflectionClassConstant $constant): string => $constant->getValue());

    expect($subjectTypes)->not->toBeEmpty();

    $unreached = $subjectTypes->reject(
        fn (string $type): bool => in_array($type, $erased, true) || in_array($type, $erasedAnotherWay, true),
    );

    expect($unreached->values()->all())->toBe([]);
});
