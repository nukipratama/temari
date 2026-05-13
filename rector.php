<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_84,
        LaravelSetList::LARAVEL_120,
    ])
    ->withSkip([
        // Misfires when the bind closure returns a non-trivial graph
        // (interface → CachingNarrator(FallbackNarrator(Llm, Rules)))
        // — the rule assumes the closure returns the concrete class.
        ContainerBindConcreteWithClosureOnlyRector::class,
    ])
    ->withImportNames(removeUnusedImports: true);
