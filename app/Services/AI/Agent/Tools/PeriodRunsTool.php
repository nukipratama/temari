<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\DurationFormatter;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Plan\EffectiveSession;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\VerdictTimelineItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Every run of the period a recap is about, bounded to that period's own days.
 */
abstract class PeriodRunsTool extends NoArgumentTool
{
    public function __construct(
        private readonly User $user,
        private readonly Carbon $from,
        private readonly Carbon $to,
        private readonly VerdictNarrator $verdicts,
    ) {
    }

    abstract protected function period(): string;

    abstract protected function span(): string;

    /**
     * The runs a recap counts in the period: analysed, dated, the user's own.
     *
     * @return Builder<ActivityDetail>
     */
    public static function detailsBetween(User $user, Carbon $from, Carbon $to): Builder
    {
        return Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '>=', $from)
            ->where('activity_details.start_date_local', '<=', $to);
    }

    public function description(): string
    {
        return "Every run of the {$this->period()} you're telling, {$this->span()} in order: day and date, the "
            .'run name, km, moving_time (h:mm:ss or mm:ss) and pace_formatted (mm:ss/km), the only '
            .'forms to quote, avg_hr when the run carried heart rate, mood and intensity '
            .'(easy/moderate/hard) where known, planned (the session the plan asked for that day, '
            .'session_type plus its grade: done/partial/missed/overreached, skip for a day they '
            .'excused, planned when not graded yet) or null when no plan covered the day, and '
            .'verdict, the one-line read already written for that run, null until it exists. Call '
            ."this to tell what actually happened across the {$this->period()}, run by run.";
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $bounds = [
            "{$this->period()}_start" => $this->from->toDateString(),
            "{$this->period()}_ending" => $this->to->toDateString(),
        ];

        $details = self::detailsBetween($this->user, $this->from, $this->to)
            ->orderBy('activity_details.start_date_local')
            ->get([
                'activity_details.activity_id',
                'activity_details.name',
                'activity_details.start_date_local',
                'activity_details.distance',
                'activity_details.moving_time',
                'activity_details.elapsed_time',
                'activity_details.average_heartrate',
            ]);

        if ($details->isEmpty()) {
            return [...$bounds, 'runs' => []];
        }

        $verdicts = collect($this->verdicts->between($this->user, $this->from, $this->to))
            ->keyBy(fn (VerdictTimelineItem $item): int => $item->activityId);
        $sessions = PlannedSession::query()
            ->where('user_id', $this->user->id)
            ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])
            ->get()
            ->keyBy(fn (PlannedSession $session): string => $session->date->toDateString());

        return [
            ...$bounds,
            'runs' => $details->map(function (ActivityDetail $detail) use ($verdicts, $sessions): array {
                $startedAt = $detail->start_date_local;
                assert($startedAt !== null);
                $verdict = $verdicts->get($detail->activity_id);
                $session = $sessions->get($startedAt->toDateString());
                $pace = $detail->paceSecPerKm();

                return [
                    'day' => $startedAt->format('l'),
                    'date' => $startedAt->toDateString(),
                    'name' => $detail->name,
                    'km' => DistanceFormatter::km((float) $detail->distance),
                    'moving_time' => $detail->moving_time === null ? null : DurationFormatter::hms($detail->moving_time),
                    'pace_formatted' => $pace === null ? null : PaceFormatter::format($pace),
                    'avg_hr' => $detail->average_heartrate === null ? null : (int) round($detail->average_heartrate),
                    'mood' => $verdict?->mood->value,
                    'intensity' => $verdict?->intensity,
                    'planned' => $session === null ? null : [
                        'session_type' => EffectiveSession::settledTypeOf($session)->value,
                        'grade' => $session->status->value,
                    ],
                    'verdict' => $verdict === null || $verdict->oneline === '' ? null : $verdict->oneline,
                ];
            })->values()->all(),
        ];
    }
}
