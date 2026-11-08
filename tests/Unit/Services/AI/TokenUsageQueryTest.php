<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\AI\ContentFilterEvent;
use App\Models\AI\TokenUsage;
use App\Models\Feedback;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\ServedBy;
use App\Services\AI\TokenUsageQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
});

function tokenUsageQuery(): TokenUsageQuery
{
    return app(TokenUsageQuery::class);
}

function seedQueryUsage(
    string $kind,
    int $prompt,
    int $completion,
    Carbon $when,
    ?int $userId = null,
    string $model = 'gpt-4o',
    AnalysisOrigin $origin = AnalysisOrigin::Unknown,
): void {
    TokenUsage::query()->create([
        'user_id' => $userId,
        'kind' => $kind,
        'origin' => $origin,
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'total_tokens' => $prompt + $completion,
        'model' => $model,
        'created_at' => $when,
    ]);
}

$range = fn (): array => [Carbon::parse('2026-05-01')->startOfDay(), Carbon::parse('2026-05-19')->endOfDay()];

it('groups usage in range by kind and model, leaving out-of-range rows behind', function () use ($range): void {
    seedQueryUsage('briefing', 100, 50, Carbon::parse('2026-05-10'));
    seedQueryUsage('briefing', 200, 80, Carbon::parse('2026-05-11'));
    seedQueryUsage('briefing', 10, 5, Carbon::parse('2026-05-12'), model: 'gpt-4o-mini');
    seedQueryUsage('briefing', 999, 999, Carbon::parse('2026-04-30'));

    [$from, $to] = $range();
    $rows = tokenUsageQuery()->kindModelRows($from, $to, null, null)->keyBy('model');

    expect($rows)->toHaveCount(2)
        ->and((int) nonNull($rows->get('gpt-4o'))->prompt)->toBe(300)
        ->and((int) nonNull($rows->get('gpt-4o'))->calls)->toBe(2)
        ->and((int) nonNull($rows->get('gpt-4o-mini'))->prompt)->toBe(10);
});

it('narrows the kind and origin rows to the kind and origin asked for', function () use ($range): void {
    seedQueryUsage('briefing', 100, 50, Carbon::parse('2026-05-10'), origin: AnalysisOrigin::Ingest);
    seedQueryUsage('run-insight', 300, 150, Carbon::parse('2026-05-11'), origin: AnalysisOrigin::Recovery);

    [$from, $to] = $range();

    expect(tokenUsageQuery()->kindModelRows($from, $to, 'briefing', null)->pluck('kind')->all())->toBe(['briefing'])
        ->and(tokenUsageQuery()->kindModelRows($from, $to, null, 'recovery')->pluck('kind')->all())->toBe(['run-insight'])
        ->and(tokenUsageQuery()->originModelRows($from, $to, 'briefing', null)->pluck('origin')->all())->toBe(['ingest'])
        ->and(tokenUsageQuery()->originModelRows($from, $to, null, 'recovery')->pluck('origin')->all())->toBe(['recovery']);
});

it('lists the distinct kinds and origins present in range, in order', function () use ($range): void {
    seedQueryUsage('run-insight', 10, 5, Carbon::parse('2026-05-10'), origin: AnalysisOrigin::Recovery);
    seedQueryUsage('briefing', 10, 5, Carbon::parse('2026-05-11'), origin: AnalysisOrigin::Ingest);
    seedQueryUsage('briefing', 10, 5, Carbon::parse('2026-05-12'), origin: AnalysisOrigin::Ingest);
    seedQueryUsage('weekly', 10, 5, Carbon::parse('2026-04-01'), origin: AnalysisOrigin::Capped);

    [$from, $to] = $range();

    expect(tokenUsageQuery()->kinds($from, $to)->all())->toBe(['briefing', 'run-insight'])
        ->and(tokenUsageQuery()->origins($from, $to)->all())->toBe(['ingest', 'recovery']);
});

it('sums today\'s tokens only, whatever else was billed', function (): void {
    seedQueryUsage('briefing', 100, 50, Carbon::today());
    seedQueryUsage('briefing', 100, 50, Carbon::yesterday());

    $row = nonNull(tokenUsageQuery()->todayTokens());

    expect((int) $row->prompt)->toBe(100)
        ->and((int) $row->completion)->toBe(50);
});

it('splits the daily rows by day, kind and model and narrows them to one athlete', function () use ($range): void {
    $alice = User::factory()->create();
    seedQueryUsage('briefing', 100, 50, Carbon::parse('2026-05-10 09:00'), userId: $alice->id);
    seedQueryUsage('run-insight', 100, 50, Carbon::parse('2026-05-10 17:00'));
    seedQueryUsage('briefing', 10, 5, Carbon::parse('2026-05-11'), userId: $alice->id, model: 'gpt-4o-mini');

    [$from, $to] = $range();

    expect(tokenUsageQuery()->dailyKindModelRows($from, $to, null))->toHaveCount(3)
        ->and(tokenUsageQuery()->dailyKindModelRows($from, $to, $alice->id)->pluck('day')->all())
        ->toBe(['2026-05-10', '2026-05-11']);
});

it('reads athlete spend from the window start and skips calls with no athlete', function (): void {
    $alice = User::factory()->create();
    seedQueryUsage('briefing', 100, 50, Carbon::parse('2026-05-10'), userId: $alice->id);
    seedQueryUsage('briefing', 100, 50, Carbon::parse('2026-05-10'));
    seedQueryUsage('briefing', 100, 50, Carbon::parse('2026-04-01'), userId: $alice->id);

    $rows = tokenUsageQuery()->athleteSpendRows(Carbon::parse('2026-05-01'));

    expect($rows)->toHaveCount(1)
        ->and((int) nonNull($rows->first())->user_id)->toBe($alice->id);
});

it('counts every athlete but the demo account', function (): void {
    User::factory()->count(2)->create();
    User::factory()->create(['is_demo' => true]);

    expect(tokenUsageQuery()->athleteCount())->toBe(2);
});

it('lists users in id order', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    expect(tokenUsageQuery()->users()->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('counts the content-filter trips in range', function () use ($range): void {
    ContentFilterEvent::query()->create(['kind' => 'briefing', 'created_at' => Carbon::parse('2026-05-10')]);
    ContentFilterEvent::query()->create(['kind' => 'briefing', 'created_at' => Carbon::parse('2026-04-01')]);

    [$from, $to] = $range();

    expect(tokenUsageQuery()->contentFilterTrips($from, $to))->toBe(1);
});

it('lists the done narrations in range with their owner, producer and reason', function () use ($range): void {
    $alice = User::factory()->create();
    $done = fn (Carbon $when, array $attributes = []) => Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $alice->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => $when->toDateString(),
        'status' => AnalysisStatus::Done,
        'content' => 'narrated',
        'generated_at' => $when,
        ...$attributes,
    ]);
    $done(Carbon::parse('2026-05-11'), ['served_by' => ServedBy::RuleBased, 'rule_based_reason' => AnalysisOrigin::Capped]);
    $done(Carbon::parse('2026-04-01'), ['served_by' => ServedBy::Llm]);
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $alice->id,
        'status' => AnalysisStatus::Failed,
        'generated_at' => Carbon::parse('2026-05-12'),
    ]);

    [$from, $to] = $range();

    expect(tokenUsageQuery()->doneNarrations($from, $to))->toBe([
        ['user_id' => $alice->id, 'served_by' => ServedBy::RuleBased, 'rule_based_reason' => AnalysisOrigin::Capped],
    ]);
});

it('counts the flags an athlete filed in range', function () use ($range): void {
    $alice = User::factory()->create();
    Feedback::factory()->for($alice)->create(['subject_id' => 1, 'created_at' => Carbon::parse('2026-05-11')]);
    Feedback::factory()->for($alice)->create(['subject_id' => 2, 'created_at' => Carbon::parse('2026-05-12')]);
    Feedback::factory()->for($alice)->create(['subject_id' => 3, 'created_at' => Carbon::parse('2026-04-01')]);

    [$from, $to] = $range();

    expect(tokenUsageQuery()->flagCounts($from, $to))->toBe([$alice->id => 2]);
});

it('counts dead-lettered blocks regardless of the range, since they still need an action', function (): void {
    $alice = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $alice->id,
        'status' => AnalysisStatus::Failed,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
        'updated_at' => Carbon::parse('2026-01-01'),
    ]);

    expect(tokenUsageQuery()->deadLetterCounts())->toBe([$alice->id => 1]);
});
