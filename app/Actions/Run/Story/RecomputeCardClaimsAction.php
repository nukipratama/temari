<?php

declare(strict_types=1);

namespace App\Actions\Run\Story;

use App\Models\Activity;
use App\Models\User;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Story\RunCardFactory;
use App\Services\Run\Story\Temari;
use Illuminate\Support\Facades\DB;

/**
 * Re-judges every card's PR flag in date order, then the post-run mood that
 * reads it. A card's claim is only as good as the history loaded when it was
 * minted, and a backfill loads that history newest-first.
 */
class RecomputeCardClaimsAction
{
    public function __construct(
        private readonly PersonalRecords $personalRecords,
        private readonly RunCardFactory $cardFactory,
        private readonly Temari $temari,
    ) {
    }

    /**
     * @return array{cleared: list<int>, earned: list<int>, moods: int}
     */
    public function __invoke(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $setters = array_flip($this->personalRecords->recordSettingActivityIds($user));
            $result = ['cleared' => [], 'earned' => [], 'moods' => 0];

            $activities = Activity::query()
                ->where('user_id', $user->id)
                ->whereHas('runCard')
                ->with(['detail', 'runCard', 'postRunStoryLine'])
                ->lazyById();

            foreach ($activities as $activity) {
                $detail = $activity->detail;
                $card = $activity->runCard;
                if ($detail === null || $card === null) {
                    continue;
                }

                $prSet = isset($setters[$activity->id]);
                if ($card->pr_set !== $prSet) {
                    $this->cardFactory->build($activity, $detail, $prSet);
                    $result[$prSet ? 'earned' : 'cleared'][] = $activity->id;
                }

                $before = $activity->postRunStoryLine?->mood;
                if ($before !== null && $this->temari->postRunLine($activity, $detail)->mood !== $before) {
                    $result['moods']++;
                }
            }

            return $result;
        });
    }
}
