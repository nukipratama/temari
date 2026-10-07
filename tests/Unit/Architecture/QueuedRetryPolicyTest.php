<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePlanClampVoiceJob;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Jobs\AI\AnalyzeProfileVoiceJob;
use App\Jobs\AI\AnalyzeTrendReadJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Jobs\AI\AnswerRunQuestionJob;
use App\Jobs\AI\FlushDeadLetterAlertJob;
use App\Jobs\AI\KickoffRecapsJob;
use App\Jobs\AI\NarrateOnReturnJob;
use App\Jobs\AI\SendMaintainerAlertJob;
use App\Jobs\Gamification\SettleStreakWeeksJob;
use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Jobs\Notifications\RetryStaleWebPushNotificationJob;
use App\Jobs\Run\RebuildTrendSnapshotsJob;
use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Jobs\Run\ReconcilePlanJob;
use App\Jobs\Run\ReconcileScheduledTrendSnapshotsJob;
use App\Jobs\Run\RegeneratePlanJob;
use App\Jobs\Run\RollWeeklySnapshotsForwardJob;
use App\Jobs\Strava\CleanupDeletedActivityJob;
use App\Jobs\Strava\HydrateBacklogForUserJob;
use App\Jobs\Strava\IngestActivityJob;
use App\Jobs\Strava\ResyncActivityJob;
use App\Jobs\Strava\RetryOrphanedStravaGrantReleasesJob;
use App\Jobs\Strava\SyncActivitiesJob;
use App\Jobs\Strava\SyncZonesJob;
use App\Jobs\Strava\VerifyStravaRevocationJob;
use App\Jobs\Telegram\HandleTelegramUpdateJob;
use App\Jobs\Telegram\SendTelegramLinkWelcomeJob;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\DayClampedNotification;
use App\Notifications\FitnessImprovedNotification;
use App\Notifications\MorningBriefingNotification;
use App\Notifications\RaceOutcomeNotification;
use App\Notifications\RaceTomorrowNotification;
use App\Notifications\StravaDisconnectedNotification;
use App\Notifications\StreakReminderNotification;
use App\Notifications\TestNotification;
use App\Notifications\TimeTrialNotification;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

/**
 * @return list<class-string>
 */
function queuedClassesUnder(string ...$directories): array
{
    $classes = [];

    foreach ($directories as $directory) {
        foreach (File::allFiles(app_path($directory)) as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $directory.'/'.$file->getRelativePathname());

            if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! new ReflectionClass($class)->isAbstract()) {
                $classes[] = $class;
            }
        }
    }

    sort($classes);

    return $classes;
}

/**
 * @param  class-string  $class
 * @return list<mixed>
 */
function resolvedRetryPolicy(string $class): array
{
    $instance = new ReflectionClass($class)->newInstanceWithoutConstructor();
    $job = $instance instanceof Notification
        ? new SendQueuedNotifications(new Collection(), $instance, ['mail'])
        : $instance;

    $queue = Queue::connection('sync');
    $payload = Closure::bind(fn (object $job): array => $this->createPayloadArray($job, 'default'), $queue, $queue::class)($job);

    $uniqueFor = null;

    if ($instance instanceof ShouldBeUnique) {
        $uniqueFor = Closure::bind(
            fn (object $job): mixed => method_exists($job, 'uniqueFor')
                ? $job->uniqueFor()
                : ($this->getAttributeValue($job, UniqueFor::class, 'uniqueFor') ?? 0),
            new UniqueLock(Mockery::mock(Repository::class)),
            UniqueLock::class,
        )($instance);
    }

    return [$payload['maxTries'], $payload['backoff'], $payload['timeout'], $payload['maxExceptions'], $uniqueFor];
}

/** @var array<class-string, list<mixed>> $expectedRetryPolicies [maxTries, backoff, timeout, maxExceptions, uniqueFor] */
$expectedRetryPolicies = [
    AnalyzeActivityJob::class => [3, '10,60', null, null, null],
    AnalyzeBriefingMascotVoiceJob::class => [3, '10,60', null, null, null],
    AnalyzeCardFlavorJob::class => [3, '10,60', null, null, null],
    AnalyzeMonthlyRecapJob::class => [3, '10,60', null, null, null],
    AnalyzePlanClampVoiceJob::class => [3, '10,60', null, null, null],
    AnalyzePlanSeasonVoiceJob::class => [3, '10,60', null, null, null],
    AnalyzeProfileVoiceJob::class => [3, '10,60', null, null, null],
    AnalyzeTrendReadJob::class => [3, '10,60', null, null, null],
    AnalyzeWeeklyRecapJob::class => [3, '10,60', null, null, null],
    AnswerRunQuestionJob::class => [3, '10,60', null, null, null],
    FlushDeadLetterAlertJob::class => [null, null, null, null, null],
    KickoffRecapsJob::class => [null, null, null, null, null],
    NarrateOnReturnJob::class => [null, null, null, null, null],
    SendMaintainerAlertJob::class => [null, null, null, null, null],
    SettleStreakWeeksJob::class => [3, '30,120', null, null, 3600],
    ResolveActivityLocationJob::class => [null, null, null, null, 1200],
    RetryStaleWebPushNotificationJob::class => [3, '30,120', null, null, null],
    RebuildTrendSnapshotsJob::class => [3, '30,120', null, null, 3600],
    RecalibrateTrainingHistoryJob::class => [null, '30,120', 120, 3, 3600],
    ReconcilePlanJob::class => [10, '30,120', null, 3, 3600],
    ReconcileScheduledTrendSnapshotsJob::class => [3, '30,120', null, null, 3600],
    RegeneratePlanJob::class => [null, '30,120', 120, null, 3600],
    RollWeeklySnapshotsForwardJob::class => [3, '30,120', null, null, 900],
    CleanupDeletedActivityJob::class => [null, '60,300,900,3600', null, null, null],
    HydrateBacklogForUserJob::class => [null, null, null, null, null],
    IngestActivityJob::class => [null, null, null, 3, 21600],
    ResyncActivityJob::class => [null, null, null, 3, 21600],
    RetryOrphanedStravaGrantReleasesJob::class => [null, null, null, null, null],
    SyncActivitiesJob::class => [3, '30,120', null, null, null],
    SyncZonesJob::class => [3, '30,120', null, null, null],
    VerifyStravaRevocationJob::class => [3, '30,120', null, null, null],
    HandleTelegramUpdateJob::class => [3, '30,120', null, null, null],
    SendTelegramLinkWelcomeJob::class => [3, '30,120', null, null, null],
    AnalysisReadyNotification::class => [3, '30,120', null, null, null],
    DayClampedNotification::class => [3, '30,120', null, null, null],
    FitnessImprovedNotification::class => [3, '30,120', null, null, null],
    MorningBriefingNotification::class => [3, '30,120', null, null, null],
    RaceOutcomeNotification::class => [3, '30,120', null, null, null],
    RaceTomorrowNotification::class => [3, '30,120', null, null, null],
    StravaDisconnectedNotification::class => [3, '30,120', null, null, null],
    StreakReminderNotification::class => [3, '30,120', null, null, null],
    TestNotification::class => [3, '30,120', null, null, null],
    TimeTrialNotification::class => [3, '30,120', null, null, null],
];

it('pins a retry policy for every queued job and notification', function () use ($expectedRetryPolicies): void {
    $expected = array_keys($expectedRetryPolicies);
    sort($expected);

    expect(queuedClassesUnder('Jobs', 'Notifications'))->toBe($expected);
});

it('resolves the pinned retry policy the way the queue does', function (string $class, array $policy): void {
    expect(resolvedRetryPolicy($class))->toBe($policy);
})->with(array_map(
    fn (string $class, array $policy): array => [$class, $policy],
    array_keys($expectedRetryPolicies),
    $expectedRetryPolicies,
));
