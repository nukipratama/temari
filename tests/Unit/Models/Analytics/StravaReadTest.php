<?php

declare(strict_types=1);

use App\Enums\StravaReadPriority;
use App\Enums\StravaReadSource;
use App\Models\Analytics\StravaRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('uses analytics and casts the read metadata', function (): void {
    $read = StravaRead::query()->create([
        'read_at' => Carbon::parse('2026-09-23 12:15:00', 'UTC'),
        'source' => StravaReadSource::Webhook,
        'priority' => StravaReadPriority::Live,
        'endpoint' => 'activity_detail',
        'http_status' => '200',
        'usage_15m' => '31',
        'usage_daily' => '421',
    ]);

    $stored = StravaRead::query()->findOrFail($read->id);

    expect($stored->getConnectionName())->toBe('analytics')
        ->and($stored->id)->toBeInt()
        ->and($stored->read_at)->toBeInstanceOf(Carbon::class)
        ->and($stored->source)->toBe(StravaReadSource::Webhook)
        ->and($stored->priority)->toBe(StravaReadPriority::Live)
        ->and($stored->http_status)->toBe(200)
        ->and($stored->usage_15m)->toBe(31)
        ->and($stored->usage_daily)->toBe(421);
});

it('prunes reads older than 90 days', function (): void {
    $old = StravaRead::query()->create([
        'read_at' => Carbon::now('UTC')->subDays(91),
        'source' => StravaReadSource::Webhook,
        'priority' => StravaReadPriority::Live,
        'endpoint' => 'activity_detail',
        'http_status' => 200,
    ]);
    $recent = StravaRead::query()->create([
        'read_at' => Carbon::now('UTC')->subDays(89),
        'source' => StravaReadSource::Hydration,
        'priority' => StravaReadPriority::Background,
        'endpoint' => 'activity_streams',
        'http_status' => 200,
    ]);

    $this->artisan('model:prune', ['--model' => StravaRead::class])->assertSuccessful();

    expect(StravaRead::query()->find($old->id))->toBeNull()
        ->and(StravaRead::query()->find($recent->id))->not->toBeNull();
});

it('groups documented reads into UTC clock-aligned 15-minute windows on analytics', function (): void {
    $window = Carbon::now('UTC')->startOfDay()->addHours(12);

    foreach ([
        [$window->copy()->addMinute(), StravaReadSource::Webhook, StravaReadPriority::Live, 12],
        [$window->copy()->addMinutes(14), StravaReadSource::Webhook, StravaReadPriority::Live, 15],
        [$window->copy()->addMinutes(4), StravaReadSource::Hydration, StravaReadPriority::Background, 16],
    ] as [$readAt, $source, $priority, $usage]) {
        StravaRead::query()->create([
            'read_at' => $readAt,
            'source' => $source,
            'priority' => $priority,
            'endpoint' => 'activity_detail',
            'http_status' => 200,
            'usage_15m' => $usage,
        ]);
    }

    $rows = DB::connection('analytics')->select(<<<'SQL'
        WITH reads_by_source AS (
            SELECT
                CONCAT(DATE_FORMAT(read_at, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE(read_at) / 15) * 15, 2, '0'), ':00') AS window_start_utc,
                source,
                priority,
                COUNT(*) AS source_reads,
                MAX(usage_15m) AS observed_15min_usage,
                MAX(usage_daily) AS observed_daily_usage
            FROM strava_reads
            WHERE read_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
            GROUP BY window_start_utc, source, priority
        )
        SELECT
            window_start_utc,
            source,
            priority,
            source_reads,
            SUM(source_reads) OVER (PARTITION BY window_start_utc) AS app_reads_in_window,
            MAX(observed_15min_usage) OVER (PARTITION BY window_start_utc) AS strava_15min_usage,
            MAX(observed_daily_usage) OVER (PARTITION BY window_start_utc) AS strava_daily_usage
        FROM reads_by_source
        ORDER BY app_reads_in_window DESC, window_start_utc DESC, source, priority
        SQL);
    $bySource = collect($rows)->keyBy('source');

    expect($rows)->toHaveCount(2)
        ->and($bySource['webhook']->window_start_utc)->toBe($window->format('Y-m-d H:00:00'))
        ->and((int) $bySource['webhook']->source_reads)->toBe(2)
        ->and((int) $bySource['hydration']->source_reads)->toBe(1)
        ->and((int) $bySource['webhook']->app_reads_in_window)->toBe(3)
        ->and((int) $bySource['webhook']->strava_15min_usage)->toBe(16);
});
