<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Actions\Gamification\GrantEligibleUnlocksAction;
use App\Actions\Run\Story\BuildCardContextAction;
use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Services\Run\Metrics\StreamSummary;

class RunCardFactory
{
    public function __construct(
        private readonly SpecialMoves $specialMoves,
        private readonly GrantEligibleUnlocksAction $unlockEngine,
        private readonly BuildCardContextAction $contextBuilder,
        private readonly BadgeEvaluator $badgeEvaluator,
        private readonly RarityScorer $rarityScorer,
    ) {
    }

    public function build(Activity $activity, ActivityDetail $detail): RunCard
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());

        $existing = RunCard::query()->where('activity_id', $activity->id)->first();

        // The PR contribution is sticky: once a card is minted off a PR, a later
        // run beating that PR (which reassigns personal_records.activity_id) must
        // not retroactively downgrade this already-earned card on a rebuild.
        $prSet = ($existing !== null && $existing->pr_set) || $this->hasPrFromThisActivity($activity);

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

        $previousRarityRank = $existing?->rarity->rank() ?? -1;

        $card = RunCard::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'rarity' => $rarity,
                'badges' => $badges,
                'special_move' => $move,
                'pr_set' => $prSet,
            ],
        );

        if (in_array($card->rarity, [Rarity::Epic, Rarity::Legendary], strict: true)) {
            ($this->unlockEngine)($activity->user);
        }

        if ($card->rarity->rank() > $previousRarityRank) {
            $this->queueRevealFor($activity, $card);
        }

        return $card;
    }

    /**
     * Stash the card id on the user so the next page load can pop the reveal
     * modal. Only one reveal can be pending at a time.
     */
    private function queueRevealFor(Activity $activity, RunCard $card): void
    {
        $user = $activity->user;
        if ($user->pending_reveal_card_id !== null) {
            return;
        }
        $user->forceFill(['pending_reveal_card_id' => $card->id])->save();
    }

    private function hasPrFromThisActivity(Activity $activity): bool
    {
        return PersonalRecord::query()
            ->where('activity_id', $activity->id)
            ->exists();
    }
}
