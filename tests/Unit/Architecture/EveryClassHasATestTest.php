<?php

declare(strict_types=1);

use App\Console\Commands\AI\NarrationEvalFixture;
use App\Console\Commands\Concerns\ConfirmsPermanentRemoval;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\StravaAuthController;
use App\Jobs\Telegram\Concerns\RevokesConnectionOnPermanentFailure;
use App\Notifications\Concerns\AppendsUnreadBadge;
use App\Notifications\Concerns\RechecksRouteAtDelivery;
use App\Notifications\Concerns\SetsWebPushExpiry;
use App\Events\ActivityIngested;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AI\ContentFilterEvent;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\StravaSyncLog;
use App\Services\AI\ChainLink;
use App\Services\AI\ChatCallOptions;
use App\Actions\AI\RecordTokenUsageAction;
use App\Services\Geo\ResolvedLocation;
use App\Services\Run\FeedFilters;
use App\Livewire\Pulse\Concerns\SumsPulseTotals;
use App\Services\AI\Narrators\Concerns\ReadsPreviousActivityNarrative;
use App\Services\AI\Narrators\Concerns\ReadsPreviousDailyNarrative;
use App\Services\Run\Story\BriefingResult;
use App\Services\Run\Story\VerdictTimelineItem;
use App\Services\Telegram\NotifiableAnalysisTypes;
use App\Services\Weather\WeatherSnapshot;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

/**
 * Guards the 1:1 class<->test convention: every concrete app class should have a
 * matching `{ClassName}Test.php` somewhere under tests/. Runs in the `structure`
 * group so CI can execute it before the (expensive) coverage run and fail fast
 * when a new class ships without a test.
 *
 * Exemptions below are the documented exceptions to 1:1, not a TODO list:
 * abstract/interface/enum/exception/provider types carry no standalone test, and
 * a few families are intentionally covered by aggregate suites, which must mark
 * each class with a dataset key or covers() (see aggregateSuiteMarksClass). An exempt class
 * that has its own {Name}Test.php fails the guard, so a stale exemption cannot
 * outlive the test that replaced it.
 *
 * `database/seeders/Demo` is outside the guard (it scans `app/`); it is covered end
 * to end by DemoSeedCommandTest.
 */
/**
 * True when the suite keys a `dataset()`/`->with()` entry by `$class::class` or
 * passes it to `covers()`; a name in a comment, string or plain reference is not a marker.
 */
function aggregateSuiteMarksClass(string $source, string $class): bool
{
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $imports, PREG_SET_ORDER);
    $aliases = collect($imports)->mapWithKeys(fn (array $import): array => [($import[2] ?? '') !== '' ? $import[2] : class_basename($import[1]) => $import[1]]);

    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]),
    ));
    $open = [];

    foreach ($tokens as $i => $token) {
        if ($token->text === '(') {
            $open[] = $i > 0 && $tokens[$i - 1]->is(T_STRING) ? $tokens[$i - 1]->text : '';
        } elseif (in_array($token->text, ['[', '#['], true)) {
            $open[] = '[';
        } elseif (in_array($token->text, [')', ']'], true)) {
            array_pop($open);
        } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
            && ($tokens[$i + 2] ?? null)?->is(T_CLASS)) {
            $first = strtok($token->text, '\\') ?: throw new InvalidArgumentException('Class token is empty.');
            $resolved = $token->is(T_NAME_FULLY_QUALIFIED)
                ? ltrim($token->text, '\\')
                : ($aliases->has($first) ? $aliases[$first].substr($token->text, strlen($first)) : $token->text);

            $isDatasetKey = ($tokens[$i + 3] ?? null)?->is(T_DOUBLE_ARROW)
                && in_array(array_slice($open, -2), [['with', '['], ['dataset', '[']], true)
                && array_diff(array_slice($open, 0, -2), ['describe']) === [];

            if ($resolved === $class && (end($open) === 'covers' || $isDatasetKey)) {
                return true;
            }
        }
    }

    return false;
}

it('has a test class for every concrete app class', function (): void {
    // Whole namespaces covered by aggregate suites; each concrete class must be marked in one of them or have its own {Name}Test.php.
    $aggregateSuites = [
        'App\\Services\\AI\\Narrators\\' => ['Unit/Services/AI/Narrators/NarratorsCoverageTest.php'],
        'App\\Services\\AI\\Agent\\Tools\\' => [
            'Unit/Services/AI/Agent/AgentToolsCoverageTest.php',
            'Unit/Services/AI/Narrators/NarratorsCoverageTest.php',
        ],
        'App\\Jobs\\AI\\' => [
            'Unit/Jobs/AI/JobsCoverageTest.php',
            'Unit/Jobs/AI/AnalyzeActivityJobTest.php',
            'Unit/Jobs/AI/AnalyzeRowJobTest.php',
        ],
    ];

    // Concrete classes intentionally without their own {Name}Test file.
    $exemptClasses = [
        // Controllers exercised by behaviour-named feature tests.
        LoginController::class,       // auth feature tests
        StravaAuthController::class,  // StravaAuthTest
        HandleInertiaRequests::class, // framework wiring
        // Immutable value objects / DTOs (no behaviour to unit-test).
        ActivityIngested::class,         // event payload, asserted via DispatchPostRunAnalysisTest + ActivityPipelineCascadeTest
        ChainLink::class,               // chain link identity, asserted via ChainResolverTest
        ChatCallOptions::class,
        NarrationEvalFixture::class,     // fixture identity, asserted via NarrationEvalFixturesTest
        ResolvedLocation::class,
        BriefingResult::class,
        VerdictTimelineItem::class,
        WeatherSnapshot::class,
        FeedFilters::class,          // resolved Feed filter state, asserted via FeedQueryTest
        NotifiableAnalysisTypes::class, // shared type registry, asserted via NotificationEligibilityTest + AnalysisMessagePresenterTest
        // Covered indirectly by the suites that drive them.
        TokenUsage::class,              // StructuredChatCallerTest
        ContentFilterEvent::class,      // AnalyzeRowJobTest
        RecordTokenUsageAction::class, // StructuredChatCallerTest
        StravaSyncLog::class,           // SyncOrchestratorTest
        SumsPulseTotals::class,         // trait, exercised via AiPipelineHealthTest + StravaHealthTest
        ReadsPreviousActivityNarrative::class, // trait, exercised via the PostRunSpeech and RunInsight cases in NarratorsCoverageTest
        ReadsPreviousDailyNarrative::class, // trait, exercised via the BriefingMascotVoice cases in NarratorsCoverageTest
        RevokesConnectionOnPermanentFailure::class, // trait, exercised via TelegramChannelTest
        ConfirmsPermanentRemoval::class, // trait, exercised via RemoveAthleteCommandTest + UserRemoveCommandTest
        AppendsUnreadBadge::class, // trait, exercised via the five push-notification test suites
        RechecksRouteAtDelivery::class, // trait, exercised via the four queued notification test suites
        SetsWebPushExpiry::class, // trait, exercised via the push-notification test suites
    ];

    $testedBasenames = collect(File::allFiles(base_path('tests')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), 'Test.php'))
        ->map(fn ($file): string => substr($file->getFilename(), 0, -strlen('Test.php')))
        ->unique()
        ->flip();

    $staleExemptions = collect($exemptClasses)
        ->filter(fn (string $class): bool => $testedBasenames->has(class_basename($class)))
        ->values();

    expect($staleExemptions->all())->toBe(
        [],
        "These exempt classes now have their own {Name}Test.php. Remove them from the exemption list in this file:\n  ".$staleExemptions->implode("\n  "),
    );

    $unnamed = collect($aggregateSuites)
        ->flatMap(function (array $suites, string $prefix) use ($testedBasenames): array {
            $sources = collect($suites)->map(fn (string $suite): string => File::get(base_path('tests/'.$suite)));

            return collect(File::allFiles(app_path(str_replace('\\', '/', substr($prefix, strlen('App\\'))))))
                ->filter(fn ($file): bool => $file->getExtension() === 'php')
                ->map(fn ($file): string => $prefix.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
                ->filter(fn (string $class): bool => class_exists($class) && ! new ReflectionClass($class)->isAbstract())
                ->reject(fn (string $class): bool => new ReflectionClass($class)->isEnum() || is_subclass_of($class, Throwable::class))
                ->reject(fn (string $class): bool => $testedBasenames->has(class_basename($class)))
                ->reject(fn (string $class): bool => $sources->contains(fn (string $source): bool => aggregateSuiteMarksClass($source, $class)))
                ->all();
        })
        ->values();

    expect($unnamed->all())->toBe(
        [],
        "These classes sit in an aggregate-suite namespace but no suite marks them and they have no {Name}Test.php. Key a dataset entry in the suite by Foo::class, or add ->covers(Foo::class) to a test about it alone:\n  ".$unnamed->implode("\n  "),
    );

    $missing = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(function ($file): string {
            $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '/', '.php'], ['', '\\', ''], $file->getRealPath());

            return 'App\\'.$relative;
        })
        ->filter(fn (string $class): bool => class_exists($class) || interface_exists($class) || trait_exists($class))
        ->reject(fn (string $class): bool => array_any(array_keys($aggregateSuites), fn ($prefix) => str_starts_with($class, (string) $prefix)))
        ->reject(fn (string $class): bool => in_array($class, $exemptClasses, true))
        ->reject(function (string $class): bool {
            $reflection = new ReflectionClass($class);

            return $reflection->isInterface()
                || $reflection->isAbstract()
                || $reflection->isEnum()
                || $reflection->isSubclassOf(Throwable::class)
                || $reflection->isSubclassOf(ServiceProvider::class);
        })
        ->reject(fn (string $class): bool => $testedBasenames->has(class_basename($class)))
        ->values();

    expect($missing->all())->toBe(
        [],
        "These app classes have no {Name}Test.php (and aren't exempted). Add a test or, if intentional, add to the exemption list in this file:\n  ".$missing->implode("\n  "),
    );
})->group('structure');

it('rejects an aggregate suite that names a class only in words', function (string $source): void {
    expect(aggregateSuiteMarksClass($source, 'App\\Jobs\\AI\\FooJob'))->toBeFalse();
})->with([
    'a comment' => ["<?php\n// FooJob is covered below.\nit('runs', fn () => expect(true)->toBeTrue());\n"],
    'a test name' => ["<?php\nit('dispatches FooJob', fn () => expect(true)->toBeTrue());\n"],
    'an import and a bare reference' => ["<?php\nuse App\\Jobs\\AI\\FooJob;\nit('runs', fn () => expect(new FooJob)->toBeObject());\n"],
    'a key outside a dataset' => ["<?php\nuse App\\Jobs\\AI\\FooJob;\nit('runs', function () { expect([FooJob::class => 1])->toHaveCount(1); });\n"],
    'another class sharing the short name' => ["<?php\nuse App\\Other\\FooJob;\nit('runs', fn () => true)->with([FooJob::class => [1]]);\n"],
    'a key inside a dataset value' => ["<?php\nuse App\\Jobs\\AI\\FooJob;\nit('runs', fn (array \$map) => true)->with(['map' => [[FooJob::class => 1]]]);\n"],
    'a mock argument inside a test' => ["<?php\nuse App\\Jobs\\AI\\FooJob;\nit('runs', function () { Mockery::mock()->shouldReceive('push')->with([FooJob::class => 1]); });\n"],
])->group('structure');

it('accepts an aggregate suite that keys a dataset entry by the class or covers it', function (string $source): void {
    expect(aggregateSuiteMarksClass($source, 'App\\Jobs\\AI\\FooJob'))->toBeTrue();
})->with([
    'a with() key' => ["<?php\nuse App\\Jobs\\AI\\FooJob;\nit('runs', fn (int \$n) => expect(\$n)->toBe(1))->with([FooJob::class => [1]]);\n"],
    'a named dataset key' => ["<?php\nuse App\\Jobs\\AI\\FooJob;\ndataset('jobs', [FooJob::class => [1]]);\n"],
    'a fully qualified key' => ["<?php\nit('runs', fn (int \$n) => expect(\$n)->toBe(1))->with([\\App\\Jobs\\AI\\FooJob::class => [1]]);\n"],
    'an aliased import' => ["<?php\nuse App\\Jobs\\AI\\FooJob as Job;\nit('runs', fn (int \$n) => expect(\$n)->toBe(1))->with([Job::class => [1]]);\n"],
    'a closure dataset inside describe()' => ["<?php\nuse App\\Jobs\\AI\\FooJob;\ndescribe('jobs', function () { it('runs', fn (int \$n) => expect(\$n)->toBe(1))->with(fn () => [FooJob::class => [1]]); });\n"],
    'covers()' => ["<?php\nuse App\\Jobs\\AI\\FooJob;\ncovers(FooJob::class);\n"],
])->group('structure');
