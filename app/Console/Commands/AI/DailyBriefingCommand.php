<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\Activity;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('ai:daily-briefing')]
#[Description('Dispatch briefing analysis for each active user (last 7 days)')]
class DailyBriefingCommand extends Command
{
    public function handle(AnalysisService $service): int
    {
        $today = Carbon::today()->toDateString();

        $activeUserIds = Activity::query()
            ->where('analyzed_at', '>=', Carbon::today()->subDays(7))
            ->whereIn('user_id', User::query()->notDemo()->select('id'))
            ->distinct()
            ->pluck('user_id');

        $users = User::query()->whereIn('id', $activeUserIds)->get();

        $dailyRowTypes = [
            AnalysisType::BriefingMascotVoice,
            AnalysisType::BriefingFeaturedKartuVoice,
            AnalysisType::DailyGreeting,
        ];

        foreach ($users as $user) {
            $service->requestBriefingGroup($user, $today);

            foreach ($dailyRowTypes as $type) {
                $service->request(
                    subjectOrType: $type->subjectType(),
                    subjectId: $user->id,
                    type: $type,
                    discriminator: $today,
                    invalidate: $type->isRuleBased(),
                );
            }
        }

        $this->info("Dispatched daily briefing analysis for {$users->count()} active users.");

        return self::SUCCESS;
    }
}
