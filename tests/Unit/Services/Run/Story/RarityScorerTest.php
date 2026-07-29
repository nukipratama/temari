<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Story\CardContext;
use App\Services\Run\Story\RarityScorer;

/** @param array<string, mixed> $attributes */
function scorerDetail(array $attributes = []): ActivityDetail
{
    return new ActivityDetail($attributes + ['distance' => 3_000.0]);
}

function scorerContext(bool $firstBracket = false, bool $weekly = false): CardContext
{
    return new CardContext(false, $firstBracket, $weekly, 0, null);
}

function scorer(): RarityScorer
{
    return app(RarityScorer::class);
}

/** @param array<int, string> $badges */
function scoreOf(ActivityDetail $detail, StreamSummary $summary, array $badges, bool $prSet, CardContext $context): int
{
    return scorer()->score($detail, $summary, $badges, $prSet, $context);
}

it('scores a featureless short run at zero', function (): void {
    expect(scoreOf(scorerDetail(), StreamSummary::fromArray([]), [], false, scorerContext()))->toBe(0);
});

it('adds 3 points for a PR', function (): void {
    expect(scoreOf(scorerDetail(), StreamSummary::fromArray([]), [], true, scorerContext()))->toBe(3);
});

it('adds 2 points for a negative split', function (): void {
    $summary = StreamSummary::fromArray(['negative_split' => true]);

    expect(scoreOf(scorerDetail(), $summary, [], false, scorerContext()))->toBe(2);
});

it('adds 2 points for a long run at or above 12km', function (): void {
    $detail = scorerDetail(['distance' => 12_000.0]);
    $summary = StreamSummary::fromArray(['time_in_zone_pct' => ['Z3' => 20]]);

    expect(scoreOf($detail, $summary, [], false, scorerContext()))->toBe(2);
});

it('adds 1 point for a first distance bracket', function (): void {
    expect(scoreOf(scorerDetail(), StreamSummary::fromArray([]), [], false, scorerContext(firstBracket: true)))->toBe(1);
});

it('adds 1 point for weekly consistency', function (): void {
    expect(scoreOf(scorerDetail(), StreamSummary::fromArray([]), [], false, scorerContext(weekly: true)))->toBe(1);
});

it('adds 1 point per badge earned', function (): void {
    expect(scoreOf(scorerDetail(), StreamSummary::fromArray([]), ['a', 'b'], false, scorerContext()))->toBe(2);
});

it('caps the badge contribution at 3 points', function (): void {
    $badges = ['a', 'b', 'c', 'd', 'e', 'f'];

    expect(scoreOf(scorerDetail(), StreamSummary::fromArray([]), $badges, false, scorerContext()))->toBe(3);
});

it('adds 1 point for zone discipline on a 10km-plus run', function (): void {
    $detail = scorerDetail(['distance' => 10_000.0]);
    $summary = StreamSummary::fromArray(['time_in_zone_pct' => ['Z2' => 95, 'Z3' => 5]]);

    expect(scoreOf($detail, $summary, [], false, scorerContext()))->toBe(1);
});

it('does not award zone discipline below 10km', function (): void {
    $summary = StreamSummary::fromArray(['time_in_zone_pct' => ['Z2' => 95, 'Z3' => 5]]);

    expect(scoreOf(scorerDetail(), $summary, [], false, scorerContext()))->toBe(0);
});

it('sums every point source', function (): void {
    $detail = scorerDetail(['distance' => 12_500.0]);
    $summary = StreamSummary::fromArray([
        'negative_split' => true,
        'time_in_zone_pct' => ['Z2' => 95, 'Z3' => 5],
    ]);

    $score = scoreOf($detail, $summary, ['a', 'b'], true, scorerContext(firstBracket: true, weekly: true));

    expect($score)->toBe(3 + 2 + 2 + 1 + 2 + 1 + 1);
});

it('maps score 0-2 to Biasa (Common)', function (): void {
    expect(scorer()->fromScore(0))->toBe(Rarity::Common);
    expect(scorer()->fromScore(2))->toBe(Rarity::Common);
});

it('maps score 3-4 to Berkesan (Uncommon)', function (): void {
    expect(scorer()->fromScore(3))->toBe(Rarity::Uncommon);
    expect(scorer()->fromScore(4))->toBe(Rarity::Uncommon);
});

it('maps score 5-6 to Langka (Rare)', function (): void {
    expect(scorer()->fromScore(5))->toBe(Rarity::Rare);
    expect(scorer()->fromScore(6))->toBe(Rarity::Rare);
});

it('maps score 7-8 to Istimewa (Epic)', function (): void {
    expect(scorer()->fromScore(7))->toBe(Rarity::Epic);
    expect(scorer()->fromScore(8))->toBe(Rarity::Epic);
});

it('maps score 9+ to Legendaris', function (): void {
    expect(scorer()->fromScore(9))->toBe(Rarity::Legendary);
    expect(scorer()->fromScore(20))->toBe(Rarity::Legendary);
});
