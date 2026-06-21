<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\Activity;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\FeaturedKartuResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('ai:daily-briefing')]
#[Description('Dispatch the daily kickoff (briefing set + trend caption) for each active user (last 7 days)')]
class DailyBriefingCommand extends Command
{
    public function handle(AnalysisService $service, FeaturedKartuResolver $featuredKartu): int
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
            AnalysisType::DailyGreeting,
        ];

        foreach ($users as $user) {
            $service->requestBriefingGroup($user, $today);

            $service->request(
                subjectOrType: AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
                subjectId: $user->id,
                type: AnalysisType::TrendCaption,
                discriminator: $today,
                invalidate: true,
            );

            foreach ($dailyRowTypes as $type) {
                $service->request(
                    subjectOrType: $type->subjectType(),
                    subjectId: $user->id,
                    type: $type,
                    discriminator: $today,
                    invalidate: $type->isRuleBased(),
                );
            }

            // The featured-kartu voice keys off the card id, so it regenerates
            // exactly when the featured pick changes (and never re-bills while it
            // stays the same), instead of once per day against a moving pick.
            $featuredCard = $featuredKartu->resolve($user);
            if ($featuredCard !== null) {
                $service->request(
                    subjectOrType: AnalysisType::BriefingFeaturedKartuVoice->subjectType(),
                    subjectId: $user->id,
                    type: AnalysisType::BriefingFeaturedKartuVoice,
                    discriminator: (string) $featuredCard->id,
                );
            }
        }

        $this->info("Dispatched daily kickoff (briefing + trend caption) for {$users->count()} active users.");

        return self::SUCCESS;
    }
}
