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
    $readAt = Carbon::parse('2026-09-23 19:15:00', 'Asia/Jakarta');
    $read = StravaRead::query()->create([
        'read_at' => $readAt,
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
        ->and($stored->read_at->toIso8601String())->toBe('2026-09-23T19:15:00+07:00')
        ->and($stored->read_at->equalTo($readAt))->toBeTrue()
        ->and($stored->source)->toBe(StravaReadSource::Webhook)
        ->and($stored->priority)->toBe(StravaReadPriority::Live)
        ->and($stored->http_status)->toBe(200)
        ->and($stored->usage_15m)->toBe(31)
        ->and($stored->usage_daily)->toBe(421);
});

it('groups documented reads into UTC clock-aligned 15-minute windows on analytics', function (): void {
    $windowUtc = Carbon::now('UTC')->startOfDay()->addHours(12);
    $windowApp = $windowUtc->copy()->setTimezone('Asia/Jakarta');

    foreach ([
        [$windowApp->copy()->addMinute(), StravaReadSource::Webhook, StravaReadPriority::Live, 12],
        [$windowApp->copy()->addMinutes(14), StravaReadSource::Webhook, StravaReadPriority::Live, 15],
        [$windowApp->copy()->addMinutes(4), StravaReadSource::Hydration, StravaReadPriority::Background, 16],
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
        WITH reads_in_utc AS (
            SELECT
                TIMESTAMPADD(HOUR, -7, read_at) AS read_at_utc,
                source,
                priority,
                usage_15m,
                usage_daily
            FROM strava_reads
            WHERE read_at >= TIMESTAMPADD(HOUR, 7, UTC_TIMESTAMP() - INTERVAL 30 DAY)
        ),
        reads_by_source AS (
            SELECT
                CONCAT(
                    DATE_FORMAT(read_at_utc, '%Y-%m-%d %H:'),
                    LPAD(FLOOR(MINUTE(read_at_utc) / 15) * 15, 2, '0'),
                    ':00'
                ) AS window_start_utc,
                source,
                priority,
                COUNT(*) AS source_reads,
                MAX(usage_15m) AS observed_15min_usage,
                MAX(usage_daily) AS observed_daily_usage
            FROM reads_in_utc
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
        ->and($bySource['webhook']->window_start_utc)->toBe($windowUtc->format('Y-m-d H:00:00'))
        ->and((int) $bySource['webhook']->source_reads)->toBe(2)
        ->and((int) $bySource['hydration']->source_reads)->toBe(1)
        ->and((int) $bySource['webhook']->app_reads_in_window)->toBe(3)
        ->and((int) $bySource['webhook']->strava_15min_usage)->toBe(16);
});
