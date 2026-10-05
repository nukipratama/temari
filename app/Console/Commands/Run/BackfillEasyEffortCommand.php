<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\Activity;
use App\Services\Run\Ingest\StreamAnalysis;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Writes each analyzed run's time over the easy heart-rate cap onto its
 * `stream_summary` from the streams already stored, under the athlete's
 * current zones, touching no other summary key. Zero Strava calls; safe to
 * re-run, since a run already carrying the same figure is left alone.
 */
#[Signature('run:backfill-easy-effort {--user= : Limit to one user id} {--chunk=100 : Runs read per batch}')]
#[Description('Backfill each run\'s time over the easy heart-rate cap from its stored streams.')]
class BackfillEasyEffortCommand extends Command
{
    private const array KEYS = ['easy_cap_bpm' => true, 'over_easy_cap_sec' => true];

    public function handle(StreamAnalysis $streamAnalysis): int
    {
        $userId = $this->option('user');
        $updated = 0;
        $unchanged = 0;

        $activities = Activity::query()
            ->when($userId !== null, fn (Builder $query): Builder => $query->where('user_id', (int) $userId))
            ->whereHas('detail')
            ->whereHas('stream')
            ->with(['detail', 'stream', 'user.runnerProfile'])
            ->lazyById(max(1, (int) $this->option('chunk')));

        foreach ($activities as $activity) {
            $detail = $activity->detail;
            $streams = $activity->stream->data ?? [];
            if ($detail === null) {
                continue;
            }

            $current = $detail->stream_summary ?? [];
            $figure = $streams === [] ? [] : $streamAnalysis->easyCapOverage($streams, $activity->user->hrProfile()['hr_zones']);
            $next = array_diff_key($current, self::KEYS) + $figure;

            if ($next == $current) {
                $unchanged++;
            } else {
                $detail->update(['stream_summary' => $next === [] ? null : $next]);
                $updated++;
            }

            $activity->unsetRelation('detail');
            $activity->unsetRelation('stream');
        }

        $this->line("Backfilled the easy-effort figure on <info>{$updated}</info> run(s), <info>{$unchanged}</info> already current.");

        return self::SUCCESS;
    }
}
