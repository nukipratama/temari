<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Renaming\Rector\PropertyFetch\RenamePropertyRector;
use Rector\Set\ValueObject\LevelSetList;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
        LaravelSetList::LARAVEL_TESTING,
    ])
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withComposerBased(laravel: true)
    ->withSkip([
        // Misfires on closures that return a decorator graph, not a concrete.
        ContainerBindConcreteWithClosureOnlyRector::class,
        // Keeps the documented discriminator hook on the authorizer.
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__.'/app/Services/AI/AnalysisSubjectAuthorizer.php'],
        // Passes $e->getCode() as the code, which is a string for PDOException and a TypeError under strict types.
        ThrowWithPreviousExceptionRector::class,
        // Turns stateless private static helpers into instance methods; the repo keeps pure helpers static.
        LocallyCalledStaticMethodToNonStaticRector::class,
        RenamePropertyRector::class => [__DIR__.'/tests/Unit/Jobs/AI/AnalyzeRowJobTest.php'],
    ])
    ->withCache(cacheDirectory: __DIR__.'/.rector-cache', cacheClass: FileCacheStorage::class)
    ->withImportNames(removeUnusedImports: true);
