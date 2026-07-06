<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Support\Cooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/** Start the re-trigger cooldown window for a row and return its key. */
function startRowCooldown(Analysis $row): string
{
    $key = Analysis::cooldownKey($row->analysis_type, $row->subject_id, $row->discriminator);
    RateLimiter::hit($key, Cooldown::WINDOW_SECONDS);

    return $key;
}

it('returns null cooldown for non-Done rows even with an active window', function (): void {
    foreach ([AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Processing, AnalysisStatus::Failed] as $i => $status) {
        $row = Analysis::factory()->make([
            'discriminator' => "2026-05-{$i}",
            'status' => $status,
        ]);
        startRowCooldown($row);
        expect($row->cooldownRemaining())->toBeNull("status={$status->value}");
    }
});

it('returns null cooldown for a Done row with no active window', function (): void {
    $row = Analysis::factory()->done('x')->make();
    expect($row->cooldownRemaining())->toBeNull();
});

it('returns positive remaining seconds while the window is active', function (): void {
    $row = Analysis::factory()->done('x')->make();
    startRowCooldown($row);
    expect($row->cooldownRemaining())->toBeGreaterThan(0)->toBeLessThanOrEqual(Cooldown::WINDOW_SECONDS);
});

it('returns null once the window is released', function (): void {
    $row = Analysis::factory()->done('x')->make();
    $key = startRowCooldown($row);
    RateLimiter::clear($key);
    expect($row->cooldownRemaining())->toBeNull();
});

it('toPayload surfaces retry_after_seconds from the active window', function (): void {
    $row = Analysis::factory()->done('hi')->make();
    startRowCooldown($row);

    $payload = Analysis::toPayload($row, $row->analysis_type, $row->subject_type, $row->subject_id, $row->discriminator);

    expect($payload['retry_after_seconds'])->toBeGreaterThan(0)->toBeLessThanOrEqual(Cooldown::WINDOW_SECONDS);
});

it('telegramCooldownRemaining is null for a missing or not-Done payload', function (): void {
    expect(Analysis::telegramCooldownRemaining(['id' => null, 'status' => 'done']))->toBeNull()
        ->and(Analysis::telegramCooldownRemaining(['id' => 7, 'status' => 'pending']))->toBeNull();
});

it('telegramCooldownRemaining reflects an active Telegram window for a Done payload', function (): void {
    RateLimiter::hit(Cooldown::telegramKey(7), Cooldown::WINDOW_SECONDS);

    expect(Analysis::telegramCooldownRemaining(['id' => 7, 'status' => 'done']))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(Cooldown::WINDOW_SECONDS);
});

it('toPayload returns retry_after_seconds null when row is null', function (): void {
    $payload = Analysis::toPayload(null, AnalysisType::BriefingHeadline, 'briefing_user_day', 1, '2026-05-20');
    expect($payload['retry_after_seconds'])->toBeNull();
});

it('payloadsForSubjects returns an empty array for no ids', function (): void {
    expect(Analysis::payloadsForSubjects('briefing_user_day', AnalysisType::WeeklyRecap, []))->toBe([]);
});

it('payloadsForSubjects keys payloads by subject id, falling back to a pending payload', function (): void {
    $subjectType = 'weekly_snapshot';

    $done = Analysis::factory()->done('recap text')->create([
        'subject_type' => $subjectType,
        'subject_id' => 10,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $payloads = Analysis::payloadsForSubjects($subjectType, AnalysisType::WeeklyRecap, [10, 20]);

    expect(array_keys($payloads))->toBe([10, 20])
        ->and($payloads[10]['id'])->toBe($done->id)
        ->and($payloads[10]['status'])->toBe('done')
        ->and($payloads[10]['content'])->toBe('recap text')
        ->and($payloads[20]['id'])->toBeNull()
        ->and($payloads[20]['status'])->toBe('pending')
        ->and($payloads[20]['subject_id'])->toBe(20);
});

it('payloadsForSubjects ignores rows of a different type or subject_type', function (): void {
    $subjectType = 'weekly_snapshot';

    Analysis::factory()->done('wrong type')->create([
        'subject_type' => $subjectType,
        'subject_id' => 30,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => null,
    ]);

    $payloads = Analysis::payloadsForSubjects($subjectType, AnalysisType::WeeklyRecap, [30]);

    expect($payloads[30]['id'])->toBeNull()
        ->and($payloads[30]['status'])->toBe('pending');
});

it('stalled() includes Pending and under-budget Failed rows, excludes Done and dead-lettered', function (): void {
    $pending = Analysis::factory()->create(['status' => AnalysisStatus::Pending, 'discriminator' => 'p']);
    $failedUnder = Analysis::factory()->failed()->create(['discriminator' => 'fu']); // attempts 1
    $failedAtBudget = Analysis::factory()->failed()->create([
        'discriminator' => 'fb',
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);
    Analysis::factory()->done('x')->create(['discriminator' => 'd']);

    $ids = Analysis::query()->stalled()->pluck('id');

    expect($ids)->toContain($pending->id)
        ->toContain($failedUnder->id)
        ->not->toContain($failedAtBudget->id);
});

it('deadLettered() is only Failed rows at or over the retry budget', function (): void {
    $dead = Analysis::factory()->failed()->create([
        'discriminator' => 'dead',
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);
    $failedUnder = Analysis::factory()->failed()->create(['discriminator' => 'under']); // attempts 1
    $pendingMaxed = Analysis::factory()->create([
        'status' => AnalysisStatus::Pending,
        'discriminator' => 'pend',
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    $ids = Analysis::query()->deadLettered()->pluck('id');

    expect($ids)->toContain($dead->id)
        ->not->toContain($failedUnder->id)
        ->not->toContain($pendingMaxed->id); // Pending never dead-letters
});

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
        $row = new Analysis(['subject_type' => $subjectType, 'subject_id' => $subjectId]);
        expect($row->ownerId())->toBe($user->id, $subjectType);
    }
});

it('ownerId is null when the subject row no longer exists', function (): void {
    $row = new Analysis(['subject_type' => Activity::class, 'subject_id' => 999999]);
    expect($row->ownerId())->toBeNull();
});

it('ownerIdsForRows batches owner resolution across mixed subject types', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $snap = WeeklySnapshot::factory()->for($user)->create();

    $rowActivity = Analysis::factory()->create(['subject_type' => Activity::class, 'subject_id' => $activity->id]);
    $rowSnap = Analysis::factory()->create(['subject_type' => WeeklySnapshot::class, 'subject_id' => $snap->id]);

    $owners = Analysis::ownerIdsForRows(Analysis::query()->whereKey([$rowActivity->id, $rowSnap->id])->get());

    expect($owners[$rowActivity->id])->toBe($user->id)
        ->and($owners[$rowSnap->id])->toBe($user->id);
});

it('ownerIdsForRows falls back to subject_id for an unmapped subject type', function (): void {
    $user = User::factory()->create();
    $row = Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
    ]);

    $owners = Analysis::ownerIdsForRows(Analysis::query()->whereKey($row->id)->get());

    expect($owners[$row->id])->toBe($user->id);
});

it('ownerIdsForRows maps to null when the subject row no longer exists', function (): void {
    $row = Analysis::factory()->create(['subject_type' => Activity::class, 'subject_id' => 999999]);

    $owners = Analysis::ownerIdsForRows(Analysis::query()->whereKey($row->id)->get());

    expect($owners[$row->id])->toBeNull();
});
