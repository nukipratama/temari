<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The personal records the period's runs set, the period-scoped counterpart to {@see PersonalRecordsTool}.
 */
abstract class PeriodRecordsTool extends NoArgumentTool
{
    public function __construct(
        private readonly User $user,
        private readonly Carbon $from,
        private readonly Carbon $to,
    ) {
    }

    abstract protected function period(): string;

    public function description(): string
    {
        return "The personal records a run this {$this->period()} set that still stand, each with category (a "
            .'distance such as 5km or a best effort such as best_20min), the day and date of the run '
            .'that set it, value_formatted (h:mm:ss or mm:ss, the only form to quote: the time to '
            .'cover a distance, but for a best effort the pace held per km, so quote it as /km) and '
            .'value_sec (raw seconds, for judging size, never for quoting). An empty list means no '
            ."run this {$this->period()} set a PR, so never mention one.";
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $records = PersonalRecord::query()
            ->where('user_id', $this->user->id)
            ->whereIn('activity_id', PeriodRunsTool::detailsBetween($this->user, $this->from, $this->to)->select('activity_details.activity_id'))
            ->orderBy('set_at')
            ->get(['category', 'value_sec', 'set_at'])
            ->map(fn (PersonalRecord $record): array => [
                ...PersonalRecordsTool::entry($record),
                'day' => $record->set_at->format('l'),
                'date' => $record->set_at->toDateString(),
            ])
            ->all();

        return ['personal_records' => $records];
    }
}
