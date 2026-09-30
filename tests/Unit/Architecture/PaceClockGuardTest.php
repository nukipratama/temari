<?php

declare(strict_types=1);

use App\Services\Run\Ingest\KmSplitBuilder;
use App\Services\Run\Ingest\StreamAnalysis;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Plan\SessionIntentJudge;
use Illuminate\Support\Facades\File;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function movingPaceClockViolations(array $sources): array
{
    $exceptions = [StreamAnalysis::class, KmSplitBuilder::class, SessionIntentJudge::class];
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $finder = new NodeFinder();
    $printer = new Standard();
    $violations = [];

    foreach ($sources as $class => $source) {
        if (in_array($class, $exceptions, true)) {
            continue;
        }

        $traverser = new NodeTraverser(new NameResolver());
        $nodes = $traverser->traverse($parser->parse($source) ?? []);

        foreach ($finder->findInstanceOf($nodes, StaticCall::class) as $call) {
            if ($call->class instanceof Name
                && $call->class->toString() === PaceCalculator::class
                && isset($call->args[1])
                && str_contains($printer->prettyPrintExpr($call->args[1]->value), 'moving_time')) {
                $violations[] = $class.':'.$call->getStartLine();
            }
        }
    }

    sort($violations);

    return $violations;
}

it('uses elapsed time for quoted paces throughout app', function (): void {
    $sources = [];
    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() === 'php') {
            $class = 'App\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
            $sources[$class] = $file->getContents();
        }
    }

    expect(movingPaceClockViolations($sources))->toBe([]);
})->group('structure');

it('rejects moving time in direct and aggregated pace divisors', function (string $seconds): void {
    $source = '<?php use App\\Services\\Run\\Metrics\\PaceCalculator as Pace; Pace::secPerKm(10000, '.$seconds.');';

    expect(movingPaceClockViolations(['App\\QuotedPace' => $source]))->toBe(['App\\QuotedPace:1']);
})->with([
    '$run->moving_time',
    'array_sum(array_column($runs, "moving_time"))',
])->group('structure');

it('allows elapsed divisors and the three internal pace exceptions', function (): void {
    $moving = '<?php use App\\Services\\Run\\Metrics\\PaceCalculator; PaceCalculator::secPerKm(10000, $run->moving_time);';
    $elapsed = '<?php use App\\Services\\Run\\Metrics\\PaceCalculator; PaceCalculator::secPerKm($moving_time, $run->elapsed_time);';

    expect(movingPaceClockViolations([
        'App\\QuotedPace' => $elapsed,
        StreamAnalysis::class => $moving,
        KmSplitBuilder::class => $moving,
        SessionIntentJudge::class => $moving,
    ]))->toBe([]);
})->group('structure');
