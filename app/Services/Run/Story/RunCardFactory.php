<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Actions\Run\Story\BuildCardContextAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Services\AI\HydrationBacklog;
use App\Services\Run\Metrics\StreamSummary;

class RunCardFactory
{
    public function __construct(
        private readonly SpecialMoves $specialMoves,
        private readonly BuildCardContextAction $contextBuilder,
        private readonly BadgeEvaluator $badgeEvaluator,
        private readonly RarityScorer $rarityScorer,
        private readonly HydrationBacklog $backlog,
    ) {
    }

    /**
     * $prSet is the chronological verdict from {@see \App\Actions\Run\Story\RecomputeCardClaimsAction};
     * without it the flag is judged against what is ingested right now.
     */
    public function build(Activity $activity, ActivityDetail $detail, ?bool $prSet = null): RunCard
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());

        $prSet ??= $this->prSetAtIngest($activity, $detail);

        $context = ($this->contextBuilder)($activity, $detail);

        // Badges compute first so rarity can derive from badge count.
        $badges = $this->badgeEvaluator->evaluate($detail, $summary, $context);
        $rarity = $this->rarityScorer->fromScore(
            $this->rarityScorer->score($detail, $summary, $badges, $prSet, $context),
        );

        $move = $this->specialMoves->pick($summary, [
            'distance_m' => $detail->distance,
            'pr_set' => $prSet,
            'seed' => $activity->id,
        ]);

        $card = RunCard::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'rarity' => $rarity,
                'badges' => $badges,
                'special_move' => $move,
                'pr_set' => $prSet,
            ],
        );

        return $card;
    }

    private function prSetAtIngest(Activity $activity, ActivityDetail $detail): bool
    {
        // The PR contribution is sticky: once a card is minted off a PR, a later
        // run beating that PR (which reassigns personal_records.activity_id) must
        // not retroactively downgrade this already-earned card on a rebuild.
        if (RunCard::query()->where('activity_id', $activity->id)->where('pr_set', true)->exists()) {
            return true;
        }

        // Holding a record only proves a PR once every earlier run has landed.
        return PersonalRecord::query()->where('activity_id', $activity->id)->exists()
            && ! $this->backlog->awaitsHydrationBefore($activity->user_id, $detail->start_date_local);
    }
}
