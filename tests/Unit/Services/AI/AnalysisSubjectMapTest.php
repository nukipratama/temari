<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisSubjectMap;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ownerId resolves the owning user across every subject type', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $card = RunCard::factory()->for($activity)->create();
    $pr = PersonalRecord::factory()->for($user)->create();
    $snap = WeeklySnapshot::factory()->for($user)->create();

    $cases = [
        [Activity::class, $activity->id],
        [WeeklySnapshot::class, $snap->id],
        [RunCard::class, $card->id],
        [PersonalRecord::class, $pr->id],
        // A `*_user_*` string subject type: subject_id IS the user id.
        [AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id],
    ];

    foreach ($cases as [$subjectType, $subjectId]) {
        expect(AnalysisSubjectMap::ownerId($subjectType, $subjectId))->toBe($user->id, $subjectType);
    }
});

it('ownerId is null when the subject row no longer exists', function (): void {
    expect(AnalysisSubjectMap::ownerId(Activity::class, 999999))->toBeNull();
});

it('ownerIdsForRows batches owner resolution across mixed subject types', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $card = RunCard::factory()->for($activity)->create();
    $pr = PersonalRecord::factory()->for($user)->create();
    $snap = WeeklySnapshot::factory()->for($user)->create();

    $rows = collect([
        [Activity::class, $activity->id],
        [WeeklySnapshot::class, $snap->id],
        [RunCard::class, $card->id],
        [PersonalRecord::class, $pr->id],
    ])->map(fn (array $case): Analysis => Analysis::factory()->create([
        'subject_type' => $case[0],
        'subject_id' => $case[1],
    ]));

    $owners = AnalysisSubjectMap::ownerIdsForRows(
        Analysis::query()->whereKey($rows->pluck('id')->all())->get(),
    );

    foreach ($rows as $row) {
        expect($owners[$row->id])->toBe($user->id, $row->subject_type);
    }
});

it('ownerIdsForRows falls back to subject_id for an unmapped subject type', function (): void {
    $user = User::factory()->create();
    $row = Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
    ]);

    $owners = AnalysisSubjectMap::ownerIdsForRows(Analysis::query()->whereKey($row->id)->get());

    expect($owners[$row->id])->toBe($user->id);
});

it('ownerIdsForRows maps to null when the subject row no longer exists', function (): void {
    $row = Analysis::factory()->create(['subject_type' => Activity::class, 'subject_id' => 999999]);

    $owners = AnalysisSubjectMap::ownerIdsForRows(Analysis::query()->whereKey($row->id)->get());

    expect($owners[$row->id])->toBeNull();
});

it('resolves no owner for a run card whose activity is still an un-ingested stub', function (): void {
    $user = User::factory()->create();
    $stub = Activity::factory()->for($user)->create(['analyzed_at' => null]);
    $card = RunCard::factory()->for($stub)->create();
    $row = Analysis::factory()->create(['subject_type' => RunCard::class, 'subject_id' => $card->id]);

    expect(AnalysisSubjectMap::ownerId(RunCard::class, $card->id))->toBeNull()
        ->and(AnalysisSubjectMap::ownerIdsForRows(Analysis::query()->whereKey($row->id)->get())[$row->id])->toBeNull()
        ->and(AnalysisSubjectMap::whereOwnedBy(Analysis::query(), $user->id)->pluck('id')->all())->not->toContain($row->id);
});

it('whereOwnedBy selects exactly the rows ownerIdsForRows attributes to that user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $rows = collect();
    foreach ([$user, $other] as $owner) {
        $activity = Activity::factory()->for($owner)->create();
        $card = RunCard::factory()->for($activity)->create();
        $pr = PersonalRecord::factory()->for($owner)->create();
        $snap = WeeklySnapshot::factory()->for($owner)->create();

        foreach ([
            [Activity::class, $activity->id],
            [WeeklySnapshot::class, $snap->id],
            [RunCard::class, $card->id],
            [PersonalRecord::class, $pr->id],
            [AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $owner->id],
        ] as [$subjectType, $subjectId]) {
            $rows->push(Analysis::factory()->create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ]));
        }
    }

    $orphan = Analysis::factory()->create(['subject_type' => Activity::class, 'subject_id' => 999999]);

    $all = Analysis::query()->get();
    $ownerIds = AnalysisSubjectMap::ownerIdsForRows($all);
    $expected = $all->filter(fn (Analysis $row): bool => ($ownerIds[$row->id] ?? null) === $user->id)
        ->pluck('id')->sort()->values()->all();

    $selected = AnalysisSubjectMap::whereOwnedBy(Analysis::query(), $user->id)
        ->pluck('id')->sort()->values()->all();

    expect($expected)->toHaveCount(5)
        ->and($selected)->toBe($expected)
        ->and($selected)->not->toContain($orphan->id);
});
