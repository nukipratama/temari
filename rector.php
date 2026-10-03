<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\PropertyFetch\RenamePropertyRector;
use Rector\Set\ValueObject\LevelSetList;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
    ])
    ->withComposerBased(laravel: true)
    ->withSkip([
        // Misfires on closures that return a decorator graph, not a concrete.
        ContainerBindConcreteWithClosureOnlyRector::class,
        RenamePropertyRector::class => [__DIR__.'/tests/Unit/Jobs/AI/AnalyzeRowJobTest.php'],
    ])
    ->withCache(cacheDirectory: __DIR__.'/.rector-cache', cacheClass: FileCacheStorage::class)
    ->withImportNames(removeUnusedImports: true);
