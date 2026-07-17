<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CardReplayController;
use App\Http\Controllers\Api\CardSeenController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\Auth\StravaAuthController;
use App\Http\Controllers\Telegram\Concerns\PushesAnalysisToTelegram;
use App\Jobs\Telegram\Concerns\RevokesConnectionOnPermanentFailure;
use App\Events\ActivityIngested;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\StravaSyncLog;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\RuleBased\RuleBasedInsightBuilder;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\AI\TokenUsageRecorder;
use App\Services\Geo\ResolvedLocation;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Gamification\WeeklyRecap;
use App\Livewire\Pulse\Concerns\SumsPulseTotals;
use App\Services\AI\Narrators\Concerns\ReadsPreviousActivityNarrative;
use App\Services\AI\Narrators\Concerns\ReadsPreviousDailyNarrative;
use App\Services\Run\Story\BriefingResult;
use App\Services\Run\Story\VerdictTimelineItem;
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
 * a few families are intentionally covered by aggregate suites.
 */
it('has a test class for every concrete app class', function (): void {
    // Whole namespaces covered by an aggregate suite rather than per-class files.
    $exemptNamespaces = [
        'App\\Services\\AI\\Narrators\\',  // NarratorsCoverageTest
        'App\\Jobs\\AI\\',                  // JobsCoverageTest (+ AnalyzeActivityJobTest, AnalyzeRowJobTest)
    ];

    // Concrete classes intentionally without their own {Name}Test file.
    $exemptClasses = [
        // Controllers exercised by behaviour-named feature tests.
        CardSeenController::class,    // CardSeenTest
        CardReplayController::class,  // CardSeenTest (replay cases)
        LoginController::class,       // auth feature tests
        StravaAuthController::class,  // StravaAuthTest
        GoalController::class,        // goal feature tests
        HandleInertiaRequests::class, // framework wiring
        // Immutable value objects / DTOs (no behaviour to unit-test).
        ActivityIngested::class,         // event payload, asserted via DispatchPostRunAnalysisTest + ActivityPipelineCascadeTest
        ChatCallOptions::class,
        ResolvedLocation::class,
        BriefingResult::class,
        VerdictTimelineItem::class,
        WeatherSnapshot::class,
        WeeklyRecap::class,             // shaped recap DTO, built + asserted via WeeklyRecapBuilderTest
        // Covered indirectly by the suites that drive them.
        TokenUsage::class,              // StructuredChatCallerTest
        TokenUsageRecorder::class,      // StructuredChatCallerTest
        RuleBasedNarrationFiller::class, // DemoSeedCommandTest
        RuleBasedInsightBuilder::class,  // JobsCoverageTest, AnalyzeActivityJobTest
        PaceFormatter::class,           // exercised across pace tests
        StreamSummary::class,           // StreamAnalysisTest
        StravaSyncLog::class,           // SyncOrchestratorTest
        SumsPulseTotals::class,         // trait, exercised via AiPipelineHealthTest + StravaHealthTest
        ReadsPreviousActivityNarrative::class, // trait, exercised via PostRunSpeechNarratorTest + RunInsightNarratorTest
        ReadsPreviousDailyNarrative::class, // trait, exercised via DailyGreeting + BriefingMascotVoice cases in NarratorsCoverageTest
        PushesAnalysisToTelegram::class, // trait, exercised via the three Send*NotificationControllerTest suites
        RevokesConnectionOnPermanentFailure::class, // trait, exercised via the three Telegram Send*JobTest suites
    ];

    $testedBasenames = collect(File::allFiles(base_path('tests')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), 'Test.php'))
        ->map(fn ($file): string => substr($file->getFilename(), 0, -strlen('Test.php')))
        ->unique()
        ->flip();

    $missing = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(function ($file): string {
            $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '/', '.php'], ['', '\\', ''], $file->getRealPath());

            return 'App\\'.$relative;
        })
        ->filter(fn (string $class): bool => class_exists($class) || interface_exists($class) || trait_exists($class))
        ->reject(fn (string $class): bool => array_any($exemptNamespaces, fn ($prefix) => str_starts_with($class, (string) $prefix)))
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
