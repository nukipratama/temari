<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisSubjectAuthorizer;
use App\Services\AI\AnalysisType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets the owner through and rejects a stranger for every AnalysisType', function (AnalysisType $type): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $subjectId = match ($type) {
        AnalysisType::BriefingSuggestion,
        AnalysisType::BriefingMascotVoice,
        AnalysisType::BriefingFeaturedKartuVoice,
        AnalysisType::PersonaSummary,
        AnalysisType::AkuProfileVoice,
        AnalysisType::MonthlyRecap => $owner->id,
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsightTechnical,
        AnalysisType::RunInsightSplits,
        AnalysisType::RunInsightZones => Activity::factory()->for($owner)->create()->id,
        AnalysisType::WeeklyRecap => WeeklySnapshot::factory()->for($owner)->create()->id,
        AnalysisType::PrContext => PersonalRecord::factory()->for($owner)->create()->id,
        AnalysisType::CardFlavor => RunCard::factory()
            ->for(Activity::factory()->for($owner))
            ->create()->id,
    };

    expect(fn () => AnalysisSubjectAuthorizer::authorize($owner, $type, $subjectId))
        ->not->toThrow(AuthorizationException::class)
        ->and(fn () => AnalysisSubjectAuthorizer::authorize($stranger, $type, $subjectId))
        ->toThrow(AuthorizationException::class, "Subject does not belong to user (type={$type->value})");
})->with(array_combine(
    array_column(AnalysisType::cases(), 'value'),
    AnalysisType::cases(),
));

it('handles every AnalysisType (no UnhandledMatchError) so a new type can never bypass authorization', function (): void {
    $user = User::factory()->create();

    // A subject id owned by nobody: every match arm should evaluate false and
    // throw AuthorizationException. A new AnalysisType without a match arm would
    // instead throw \UnhandledMatchError, failing this test instead of prod.
    foreach (AnalysisType::cases() as $type) {
        expect(fn () => AnalysisSubjectAuthorizer::authorize($user, $type, PHP_INT_MAX))
            ->toThrow(AuthorizationException::class);
    }
});
