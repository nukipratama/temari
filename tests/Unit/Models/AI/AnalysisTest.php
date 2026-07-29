<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Support\Cooldown;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

it('notificationCooldownRemaining is null for a missing or not-Done payload', function (): void {
    expect(Analysis::notificationCooldownRemaining(['id' => null, 'status' => 'done']))->toBeNull()
        ->and(Analysis::notificationCooldownRemaining(['id' => 7, 'status' => 'pending']))->toBeNull();
});

it('notificationCooldownRemaining reflects an active send window for a Done payload', function (): void {
    RateLimiter::hit(Cooldown::notificationKey(7), Cooldown::WINDOW_SECONDS);

    expect(Analysis::notificationCooldownRemaining(['id' => 7, 'status' => 'done']))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(Cooldown::WINDOW_SECONDS);
});

it('toPayload returns retry_after_seconds null when row is null', function (): void {
    $payload = Analysis::toPayload(null, AnalysisType::BriefingMascotVoice, 'briefing_user_day', 1, '2026-05-20');
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
        'analysis_type' => AnalysisType::BriefingMascotVoice,
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

it('payloadsForSubjects reports the same retry_after_seconds as a per-row toPayload', function (): void {
    $snapshots = WeeklySnapshot::factory()->count(3)->create();
    $rows = [];
    foreach ($snapshots as $index => $snapshot) {
        $rows[] = Analysis::query()->create([
            'subject_type' => WeeklySnapshot::class,
            'subject_id' => $snapshot->id,
            'analysis_type' => AnalysisType::WeeklyRecap,
            // The middle row is still Pending, so it must report null even
            // though a stale cooldown key exists for it.
            'status' => $index === 1 ? AnalysisStatus::Pending : AnalysisStatus::Done,
            'content' => 'x',
        ]);
    }
    foreach ($rows as $row) {
        $row->startCooldown();
    }

    $ids = $snapshots->pluck('id')->all();
    $batched = Analysis::payloadsForSubjects(WeeklySnapshot::class, AnalysisType::WeeklyRecap, $ids);

    foreach ($rows as $row) {
        $row->refresh();
        $perRow = Analysis::toPayload($row, AnalysisType::WeeklyRecap, WeeklySnapshot::class, $row->subject_id);
        expect($batched[$row->subject_id])->toBe($perRow);
    }

    expect($batched[$rows[0]->subject_id]['retry_after_seconds'])->toBeGreaterThan(0)
        ->and($batched[$rows[1]->subject_id]['retry_after_seconds'])->toBeNull()
        ->and($batched[$rows[2]->subject_id]['retry_after_seconds'])->toBeGreaterThan(0);
});

it('payloadsForSubjects keeps the null-row payload shape for ids with no analysis', function (): void {
    $snapshot = WeeklySnapshot::factory()->create();

    $payloads = Analysis::payloadsForSubjects(
        WeeklySnapshot::class,
        AnalysisType::WeeklyRecap,
        [$snapshot->id],
    );

    expect($payloads[$snapshot->id])->toBe(
        Analysis::toPayload(null, AnalysisType::WeeklyRecap, WeeklySnapshot::class, $snapshot->id),
    );
});

it('payloadsForSubjects resolves every cooldown in one cache round trip', function (): void {
    $snapshots = WeeklySnapshot::factory()->count(5)->create();
    foreach ($snapshots as $snapshot) {
        Analysis::query()->create([
            'subject_type' => WeeklySnapshot::class,
            'subject_id' => $snapshot->id,
            'analysis_type' => AnalysisType::WeeklyRecap,
            'status' => AnalysisStatus::Done,
            'content' => 'x',
        ])->startCooldown();
    }

    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('many')->once()->andReturn([]);
    Cache::shouldReceive('driver')->once()->andReturn($repository);

    $payloads = Analysis::payloadsForSubjects(
        WeeklySnapshot::class,
        AnalysisType::WeeklyRecap,
        $snapshots->pluck('id')->all(),
    );

    expect($payloads)->toHaveCount(5);
});
