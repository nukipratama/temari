<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Ingest\StreamAnalysis;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Story\RunCardFactory;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\Vibe;
use Closure;
use Illuminate\Support\Carbon;

use function count;
use function is_array;

/**
 * Orchestrates the demo seed: for each `RunBlueprint`, materialises
 * Activity / ActivityDetail / ActivityStream rows, then runs the real
 * ingest pipeline (`StreamAnalysis` → `PersonalRecords` → `RunCardFactory`
 * → `Temari`) so the cards / PRs / story lines on screen are the actual
 * product output. After all runs, `WeeklyAggregator` materialises
 * weekly_snapshots and `Temari::dailyGreeting` seeds today's bubble.
 *
 * Lives outside `DatabaseSeeder` and is invoked by the `demo:seed`
 * command — keeps the regular `db:seed` cheap for unit tests.
 */
class DemoRunSeeder
{
    public const string DEMO_USER_EMAIL = 'demo@teman-lari.local';

    public function __construct(
        private readonly BlueprintLibrary $library,
        private readonly StreamSynthesizer $synthesizer,
        private readonly SplitsBuilder $splitsBuilder,
        private readonly StreamAnalysis $streamAnalysis,
        private readonly TrainingLoad $trainingLoad,
        private readonly PersonalRecords $personalRecords,
        private readonly RunCardFactory $cardFactory,
        private readonly Temari $temari,
        private readonly Vibe $vibe,
        private readonly WeeklyAggregator $weeklyAggregator,
    ) {
    }

    /**
     * Seeds the full demo dataset onto the demo user.
     *
     * @param  bool  $fresh  truncate prior demo runs/cards/snapshots first
     * @param  Closure(string): void|null  $log  optional reporter (command::info etc.)
     */
    public function seed(bool $fresh = false, ?Closure $log = null): int
    {
        $log ??= static fn (string $_): null => null;

        $user = $this->ensureDemoUser($log);
        if ($fresh) {
            $this->wipeDemoData($user, $log);
        }

        $blueprints = $this->library->all();
        usort($blueprints, fn (RunBlueprint $a, RunBlueprint $b): int => $a->startsAt <=> $b->startsAt);

        $log(sprintf('Seeding %d runs for %s...', count($blueprints), $user->email));

        $count = 0;
        foreach ($blueprints as $blueprint) {
            $this->seedOne($user, $blueprint);
            $count++;
            if ($count % 20 === 0) {
                $log(sprintf('  ...%d/%d runs materialised', $count, count($blueprints)));
            }
        }

        $log('Rebuilding weekly snapshots...');
        $weeks = $this->weeklyAggregator->rebuildFor($user);
        $log(sprintf('  %d weekly snapshots written', $weeks));

        $log("Generating today's Temari greeting...");
        $vibeState = $this->vibe->current($user);
        $this->temari->dailyGreeting($user, $vibeState);
        $log("  Today's vibe: {$vibeState}");

        return $count;
    }

    private function ensureDemoUser(Closure $log): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::DEMO_USER_EMAIL],
            [
                'name' => 'Demo Runner',
                'password' => bcrypt('demo-password-not-used-oauth-only'),
                'avatar_url' => null,
            ],
        );

        StravaConnection::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'strava_athlete_id' => 99999999,
                'access_token' => 'demo-mock-token',
                'refresh_token' => 'demo-mock-refresh',
                'token_expires_at' => Carbon::now()->addYear(),
                'scopes' => 'read,activity:read',
            ],
        );

        $log("Demo user ready: {$user->email} (id={$user->id})");

        return $user;
    }

    private function wipeDemoData(User $user, Closure $log): void
    {
        $activityIds = Activity::query()->where('user_id', $user->id)->pluck('id');

        if ($activityIds->isNotEmpty()) {
            ActivityStream::query()->whereIn('activity_id', $activityIds)->delete();
            ActivityDetail::query()->whereIn('activity_id', $activityIds)->delete();
            RunCard::query()->whereIn('activity_id', $activityIds)->delete();
            StoryLine::query()->whereIn('activity_id', $activityIds)->delete();
        }

        PersonalRecord::query()->where('user_id', $user->id)->delete();
        StoryLine::query()->where('user_id', $user->id)->delete();
        WeeklySnapshot::query()->where('user_id', $user->id)->delete();
        Activity::query()->where('user_id', $user->id)->delete();

        $log('Wiped prior demo activities / cards / story lines / PRs / snapshots');
    }

    private function seedOne(User $user, RunBlueprint $blueprint): void
    {
        $streams = $this->synthesizer->build($blueprint);
        $splits = $this->splitsBuilder->build($streams);

        $activity = Activity::query()->create([
            'user_id' => $user->id,
            'strava_external_id' => (int) ('9' . str_pad((string) $blueprint->seed(), 9, '0', STR_PAD_LEFT)),
            'fetched_at' => $blueprint->startsAt->copy()->addHour(),
            'analyzed_at' => $blueprint->startsAt->copy()->addHour(),
            'detail_fail_count' => 0,
        ]);

        $detail = ActivityDetail::query()->create([
            'activity_id' => $activity->id,
            'name' => $blueprint->name ?? 'Run',
            'start_date_local' => $blueprint->startsAt,
            'distance' => $this->totalDistance($streams),
            'moving_time' => $blueprint->movingTimeSec(),
            'elapsed_time' => $blueprint->movingTimeSec(),
            'average_speed' => $blueprint->distanceM / max(1, $blueprint->movingTimeSec()),
            'total_elevation_gain' => $blueprint->elevationGainM,
            'has_heartrate' => $blueprint->hasHrSensor,
            'average_heartrate' => $blueprint->hasHrSensor ? $this->meanOf($streams['heartrate']['data'] ?? []) : null,
            'max_heartrate' => $blueprint->hasHrSensor ? $this->maxOf($streams['heartrate']['data'] ?? []) : null,
            'average_cadence' => $blueprint->hasCadenceSensor ? $this->meanOf($streams['cadence']['data'] ?? []) : null,
            'calories' => round($blueprint->distanceM / 1000 * 65),
            'splits_metric' => $splits,
            'summary_polyline' => $blueprint->hasGps ? $this->polylineFor($blueprint) : null,
            'weather_temp_c' => $blueprint->weatherTempC,
            'weather_humidity_pct' => $blueprint->weatherHumidityPct,
            'weather_rain_detected' => $blueprint->weatherRainDetected,
        ]);

        ActivityStream::query()->create([
            'activity_id' => $activity->id,
            'data' => $streams,
        ]);

        $this->computeStreamSummary($detail, $streams);
        $detail->refresh();

        $this->personalRecords->detectAndStore($activity, $detail);
        $detail->refresh();

        $this->cardFactory->build($activity, $detail);
        $this->temari->postRunLine($activity, $detail);
    }

    /**
     * @param  array<string, array{data: list<int|float|array{float, float}>}>  $streams
     */
    private function computeStreamSummary(ActivityDetail $detail, array $streams): void
    {
        /** @var array<string, array{lo: int, hi: int}> $hrZones */
        $hrZones = config('runner.hr_zones');
        $optimalCadence = (int) config('runner.optimal_cadence_spm');

        $summary = $this->streamAnalysis->compute(
            $streams,
            $hrZones,
            is_array($detail->splits_metric) ? $detail->splits_metric : null,
            $optimalCadence,
        );

        $minutesInZone = $summary['time_in_zone_min'] ?? null;
        $trimp = is_array($minutesInZone) ? $this->trainingLoad->edwardsTrimp($minutesInZone) : null;

        $detail->update([
            'stream_summary' => $summary === [] ? null : $summary,
            'trimp_edwards' => $trimp,
        ]);
    }

    /**
     * @param  array<string, array{data: list<int|float|array{float, float}>}>  $streams
     */
    private function totalDistance(array $streams): float
    {
        $data = $streams['distance']['data'] ?? [];
        if ($data === []) {
            return 0.0;
        }

        return round((float) end($data), 1);
    }

    /**
     * @param  list<int|float|array{float, float}>  $values
     */
    private function meanOf(array $values): ?float
    {
        $scalars = array_filter($values, static fn ($v): bool => is_int($v) || is_float($v));
        if ($scalars === []) {
            return null;
        }

        return round(array_sum($scalars) / count($scalars), 1);
    }

    /**
     * @param  list<int|float|array{float, float}>  $values
     */
    private function maxOf(array $values): ?float
    {
        $scalars = array_filter($values, static fn ($v): bool => is_int($v) || is_float($v));
        if ($scalars === []) {
            return null;
        }

        return (float) max($scalars);
    }

    /**
     * Static canned polyline (a small loop). Real Strava `summary_polyline`
     * is Google's encoded format; here we ship a believable-shaped string
     * derived from the canned latlngs in `StreamSynthesizer`.
     */
    private function polylineFor(RunBlueprint $blueprint): string
    {
        // Pre-encoded small loop near Jakarta — same shape for every demo run,
        // close enough to look like a real Strava polyline preview.
        return '~kpvCggjpV??cAcA{@cAcA{@aA{@s@_@i@Sa@Ce@@a@P_@`@U`@AbAFr@\h@d@^h@Z`@Vd@LXLb@?j@';
    }
}
