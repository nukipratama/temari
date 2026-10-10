<?php

declare(strict_types=1);

namespace App\Actions\Run\Metrics;

use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TimeInZoneSummary;
use Illuminate\Support\Carbon;
use stdClass;

class ResolveRecentStreamSummariesAction
{
    private const int READ_DAYS = TimeInZoneSummary::WINDOW_WEEKS * 7;

    /** @var array<int, array{from: string, rows: list<array{started_at: string, summary: StreamSummary}>}> */
    private array $held = [];

    /**
     * @return list<StreamSummary>
     */
    public function __invoke(User $user, Carbon $asOf, int $days): array
    {
        $since = $asOf->copy()->subDays($days)->startOfDay()->toDateTimeString();
        $readFrom = $asOf->copy()->subDays(max($days, self::READ_DAYS))->startOfDay();
        $held = $this->held[$user->id] ?? null;

        if ($held === null || $readFrom->toDateTimeString() < $held['from']) {
            $held = $this->held[$user->id] = ['from' => $readFrom->toDateTimeString(), 'rows' => $this->read($user, $readFrom)];
        }

        $summaries = [];
        foreach ($held['rows'] as $row) {
            if ($row['started_at'] >= $since) {
                $summaries[] = $row['summary'];
            }
        }

        return $summaries;
    }

    /**
     * @return list<array{started_at: string, summary: StreamSummary}>
     */
    private function read(User $user, Carbon $from): array
    {
        $rows = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->where('start_date_local', '>=', $from)
            ->whereNotNull('stream_summary')
            ->orderBy('id')
            ->toBase()
            ->get(['start_date_local', 'stream_summary'])
            ->map(fn (stdClass $row): array => [
                'started_at' => (string) $row->start_date_local,
                'summary' => StreamSummary::fromArray(json_decode($row->stream_summary, true)),
            ])
            ->all();

        return array_values($rows);
    }
}
