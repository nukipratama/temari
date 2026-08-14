<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\AI\RuleBased\RuleBasedRunInsights;

function detailWith(array $attributes): ActivityDetail
{
    return new ActivityDetail(['activity_id' => 1, ...$attributes]);
}

it('returns no claims for a run with no notable stream data', function (): void {
    expect(RuleBasedRunInsights::claims(detailWith([])))->toBe([]);
});

// ── decoupling ───────────────────────────────────────────────────────

it('blames the heat rather than the aerobic base when a hot run decouples', function (): void {
    $hot = detailWith(['stream_summary' => ['decoupling_pct' => 8.4], 'weather_temp_c' => 33]);
    $mild = detailWith(['stream_summary' => ['decoupling_pct' => 8.4], 'weather_temp_c' => 24]);

    expect(RuleBasedRunInsights::claims($hot)[0])
        ->toMatchArray(['anchor' => 'metric:decoupling', 'value' => '+8.4%'])
        ->and(RuleBasedRunInsights::claims($hot)[0]['text'])->toContain('heat talking')
        ->and(RuleBasedRunInsights::claims($mild)[0]['text'])->toContain("aerobic base isn't quite solid yet");
});

it('calls moderate decoupling normal and stays silent below the floor', function (): void {
    expect(RuleBasedRunInsights::claims(detailWith(['stream_summary' => ['decoupling_pct' => 3.2]]))[0]['text'])
        ->toContain('normal range')
        ->and(RuleBasedRunInsights::claims(detailWith(['stream_summary' => ['decoupling_pct' => 1.1]]))[0]['text'])
        ->toContain('held up well');
});

// ── split direction ──────────────────────────────────────────────────

it('anchors a negative split to metric:negative_split', function (): void {
    $detail = detailWith(['stream_summary' => [
        'negative_split' => true,
        'per_km' => [['km' => 1, 'pace' => '6:00'], ['km' => 2, 'pace' => '5:30']],
    ]]);

    expect(RuleBasedRunInsights::claims($detail)[0])
        ->toMatchArray(['anchor' => 'metric:negative_split']);
});

it('anchors the fastest km to split:<n> when there is no negative split', function (): void {
    $detail = detailWith(['stream_summary' => ['per_km' => [
        ['km' => 1, 'pace' => '6:00'],
        ['km' => 2, 'pace' => '5:20'],
        ['km' => 3, 'pace' => '5:50'],
    ]]]);

    expect(RuleBasedRunInsights::claims($detail)[0])
        ->toMatchArray(['anchor' => 'split:2', 'value' => '5:20']);
});

it('skips the split claim on fewer than 3 readable km', function (): void {
    $detail = detailWith(['stream_summary' => ['per_km' => [
        ['km' => 1, 'pace' => '6:00'],
        ['km' => 2, 'pace' => '5:20'],
    ]]]);

    expect(RuleBasedRunInsights::claims($detail))->toBe([]);
});

// ── grade ────────────────────────────────────────────────────────────

it('claims a notable climb via metric:grade, and stays quiet on flat terrain', function (): void {
    $steep = detailWith(['stream_summary' => ['max_grade_pct' => 11.5]]);
    $flat = detailWith(['stream_summary' => ['max_grade_pct' => 3.0]]);

    expect(RuleBasedRunInsights::claims($steep)[0])
        ->toMatchArray(['anchor' => 'metric:grade', 'value' => '11.5%'])
        ->and(RuleBasedRunInsights::claims($flat))->toBe([]);
});

// ── zone dominance ───────────────────────────────────────────────────

it('claims the dominant zone once it holds most of the session', function (): void {
    $detail = detailWith(['stream_summary' => ['time_in_zone_pct' => ['Z1' => 5.0, 'Z2' => 85.0, 'Z3' => 10.0]]]);

    expect(RuleBasedRunInsights::claims($detail)[0])
        ->toMatchArray(['anchor' => 'zone:z2', 'value' => '85%']);
});

it('stays quiet on zones when no single zone dominates', function (): void {
    $detail = detailWith(['stream_summary' => ['time_in_zone_pct' => ['Z1' => 30.0, 'Z2' => 35.0, 'Z3' => 35.0]]]);

    expect(RuleBasedRunInsights::claims($detail))->toBe([]);
});

it('derives zone shares from minutes when percentages are missing', function (): void {
    $detail = detailWith(['stream_summary' => ['time_in_zone_min' => ['Z1' => 5.0, 'Z2' => 35.0, 'Z3' => 10.0]]]);

    expect(RuleBasedRunInsights::claims($detail)[0])
        ->toMatchArray(['anchor' => 'zone:z2', 'value' => '70%']);
});

// ── pace variability ─────────────────────────────────────────────────

it('claims notable pace variability via metric:pace_variability', function (): void {
    $detail = detailWith(['stream_summary' => ['pace_variability_sec' => 45.0]]);

    expect(RuleBasedRunInsights::claims($detail)[0])
        ->toMatchArray(['anchor' => 'metric:pace_variability']);
});

// ── shape ────────────────────────────────────────────────────────────

it('caps claims at 3 even when every candidate signal is present', function (): void {
    $detail = detailWith(['stream_summary' => [
        'decoupling_pct' => 8.0,
        'negative_split' => true,
        'max_grade_pct' => 12.0,
        'time_in_zone_pct' => ['Z2' => 90.0],
        'pace_variability_sec' => 45.0,
    ]]);

    expect(RuleBasedRunInsights::claims($detail))->toHaveCount(3);
});

it('every claim carries the anchor/text/value/delta shape', function (): void {
    $detail = detailWith(['stream_summary' => ['decoupling_pct' => 8.0]]);

    expect(RuleBasedRunInsights::claims($detail)[0])
        ->toHaveKeys(['anchor', 'text', 'value', 'delta']);
});
