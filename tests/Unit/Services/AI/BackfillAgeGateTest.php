<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\BackfillAgeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-14 09:00:00');
    config()->set('ai.backfill_max_age_days', 84);
    $this->gate = new BackfillAgeGate();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A run $days old, returned as the card and the PR it holds.
 *
 * @return array{0: RunCard, 1: PersonalRecord}
 */
function agedRunCard(int $days): RunCard
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::now()->subDays($days),
    ]);

    return RunCard::factory()->for($activity)->create();
}

it('treats the cutoff day itself as too old and the day before it as narratable', function (): void {
    expect($this->gate->isTooOld(Carbon::now()->subDays(84)))->toBeTrue()
        ->and($this->gate->isTooOld(Carbon::now()->subDays(83)))->toBeFalse();
});

it('reads the cutoff from config rather than hardcoding it', function (): void {
    config()->set('ai.backfill_max_age_days', 365);

    expect($this->gate->isTooOld(Carbon::now()->subDays(84)))->toBeFalse();
});

it('narrates an undated run, since there is no age to judge it by', function (): void {
    expect($this->gate->isTooOld(null))->toBeFalse();
});

it('blocks a manual card_flavor trigger on a run past the cutoff', function (): void {
    expect($this->gate->blocksManualTrigger(AnalysisType::CardFlavor, agedRunCard(200)->id, null))->toBeTrue();
});

it('lets a manual card_flavor trigger through on a recent run', function (): void {
    expect($this->gate->blocksManualTrigger(AnalysisType::CardFlavor, agedRunCard(3)->id, null))->toBeFalse();
});

it('blocks a hand-crafted briefing trigger for a long-past day', function (): void {
    expect($this->gate->blocksManualTrigger(AnalysisType::BriefingMascotVoice, 1, '2019-03-05'))->toBeTrue()
        ->and($this->gate->blocksManualTrigger(AnalysisType::BriefingMascotVoice, 1, '2026-08-14'))->toBeFalse();
});

it('leaves the chained types to the chain resolver', function (AnalysisType $type): void {
    expect($this->gate->blocksManualTrigger($type, 1, null))->toBeFalse();
})->with([
    'post_run_speech' => [AnalysisType::PostRunSpeech],
    'run_insight' => [AnalysisType::RunInsight],
    'weekly_recap' => [AnalysisType::WeeklyRecap],
    'monthly_recap' => [AnalysisType::MonthlyRecap],
]);

it('never blocks the types whose material is current whatever its date', function (): void {
    expect($this->gate->blocksManualTrigger(AnalysisType::ProfileVoice, 1, '2019-W03'))->toBeFalse()
        ->and($this->gate->blocksManualTrigger(AnalysisType::TrendRead, 1, '30d'))->toBeFalse()
        ->and($this->gate->blocksManualTrigger(AnalysisType::PlanDayVoice, 1, '2019-01-01'))->toBeFalse()
        ->and($this->gate->blocksManualTrigger(AnalysisType::PlanWeekVoice, 1, null))->toBeFalse()
        ->and($this->gate->blocksManualTrigger(AnalysisType::PlanSeasonVoice, 1, null))->toBeFalse();
});

it('states a choice for every analysis type, so a new one cannot slip through unjudged', function (): void {
    foreach (AnalysisType::cases() as $type) {
        expect($this->gate->blocksManualTrigger($type, 1, null))->toBeBool();
    }
});

it('narrates a subject whose run date is unknown rather than guessing its age', function (): void {
    $detaillessCard = RunCard::factory()->create();

    expect($this->gate->blocksManualTrigger(AnalysisType::CardFlavor, $detaillessCard->id, null))->toBeFalse()
        ->and($this->gate->blocksManualTrigger(AnalysisType::CardFlavor, 999999, null))->toBeFalse();
});
