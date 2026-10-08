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
 * a few families are intentionally covered by aggregate suites. An exempt class
 * that has its own {Name}Test.php fails the guard, so a stale exemption cannot
 * outlive the test that replaced it.
 *
 * `database/seeders/Demo` is outside the guard (it scans `app/`); it is covered end
 * to end by DemoSeedCommandTest.
 */
it('has a test class for every concrete app class', function (): void {
    // Whole namespaces covered by an aggregate suite rather than per-class files.
    $exemptNamespaces = [
        'App\\Services\\AI\\Narrators\\',  // NarratorsCoverageTest
        'App\\Services\\AI\\Agent\\Tools\\', // AgentToolsCoverageTest
        'App\\Jobs\\AI\\',                  // JobsCoverageTest (+ AnalyzeActivityJobTest, AnalyzeRowJobTest)
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
