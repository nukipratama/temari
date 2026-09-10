<?php

declare(strict_types=1);

use App\Enums\FeedbackReason;
use App\Enums\FeedbackSubject;
use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\DevtoolsAction;
use App\Models\Feedback;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\CeilingOverride;
use App\Services\AI\ServedBy;
use App\Services\Devtools\AthleteNarrationReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-10 09:00:00');
    // $1/1k prompt tokens, $2/1k completion, so every cost below is exact.
    config([
        'azure_openai.prices' => ['gpt-test' => ['input_per_1m' => 1000.0, 'output_per_1m' => 2000.0]],
        'azure_openai.daily_cost_ceiling_per_user' => 1.0,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function athleteReport(): AthleteNarrationReport
{
    return app(AthleteNarrationReport::class);
}

function athleteBlock(User $user, AnalysisType $type = AnalysisType::BriefingMascotVoice, array $attributes = []): Analysis
{
    return Analysis::factory()->create([
        'subject_type' => 'briefing_user_day',
        'subject_id' => $user->id,
        'analysis_type' => $type,
        ...$attributes,
    ]);
}

function usageRow(User $user, ?Analysis $block, Carbon $when, int $prompt = 1000, int $completion = 500, array $attributes = []): TokenUsage
{
    return TokenUsage::query()->create([
        'user_id' => $user->id,
        'analysis_id' => $block?->id,
        'kind' => 'briefing',
        'origin' => AnalysisOrigin::Scheduled,
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'total_tokens' => $prompt + $completion,
        'model' => 'gpt-test',
        'latency_ms' => 400,
        'created_at' => $when,
        ...$attributes,
    ]);
}

describe('header', function (): void {
    it('reports today spend, the configured ceiling, a 30-day sparkline and a projection', function (): void {
        $user = User::factory()->create();
        usageRow($user, null, Carbon::parse('2026-09-10 08:00:00'));   // $2.00 today
        usageRow($user, null, Carbon::parse('2026-09-08 08:00:00'));   // $2.00 in the week
        usageRow($user, null, Carbon::parse('2026-09-02 08:00:00'));   // $2.00 in the month, outside the week

        $header = athleteReport()->header($user);

        expect($header['today_spend'])->toBe(2.0)
            ->and($header['ceiling'])->toBe(['value' => 1.0, 'source' => 'config'])
            ->and($header['sparkline'])->toHaveCount(30)
            ->and($header['sparkline'][29])->toBe(['day' => '2026-09-10', 'cost' => 2.0])
            ->and($header['forecast']['month_to_date'])->toBe(6.0)
            // 7-day spend $4.00 -> $0.5714/day over the 20 remaining days.
            ->and(round($header['forecast']['projected'], 2))->toBe(17.43)
            ->and($header['athlete'])->toBe(['id' => $user->id, 'name' => $user->name, 'is_demo' => false]);
    });

    it('labels the ceiling as an override when one is set for today', function (): void {
        $user = User::factory()->create();
        app(CeilingOverride::class)->set($user->id, 4.25);

        expect(athleteReport()->header($user)['ceiling'])->toBe(['value' => 4.25, 'source' => 'override']);
    });

    it('leaves the ceiling null when none is configured', function (): void {
        config(['azure_openai.daily_cost_ceiling_per_user' => null]);

        expect(athleteReport()->header(User::factory()->create())['ceiling']['value'])->toBeNull();
    });
});

describe('narrations', function (): void {
    it('returns the athlete rows newest first with cost, tokens, tool calls and served_by', function (): void {
        $user = User::factory()->create();
        $older = athleteBlock($user);
        $newer = athleteBlock($user, AnalysisType::TrendRead, [
            'status' => AnalysisStatus::Done,
            'content' => 'the fresh one',
            'served_by' => ServedBy::Llm,
        ]);
        usageRow($user, $newer, Carbon::now(), attributes: [
            'steps' => 3,
            'tool_calls' => [['tool' => 'recent_runs', 'arguments_summary' => 'limit=5', 'duration_ms' => 42]],
            'origin' => AnalysisOrigin::User,
        ]);

        $result = athleteReport()->narrations($user->id, null, null, null);

        expect($result['next_cursor'])->toBeNull()
            ->and($result['rows'])->toHaveCount(2);

        [$first, $second] = $result['rows'];

        expect($first['id'])->toBe($newer->id)
            ->and($first['cost'])->toBe(2.0)
            ->and($first['last_cost'])->toBe(2.0)
            ->and($first['prompt_tokens'])->toBe(1000)
            ->and($first['completion_tokens'])->toBe(500)
            ->and($first['latency_ms'])->toBe(400)
            ->and($first['steps'])->toBe(3)
            ->and($first['origin'])->toBe('user')
            ->and($first['served_by'])->toBe('llm')
            ->and($first['tool_calls'][0]['tool'])->toBe('recent_runs')
            ->and($first['content'])->toBe('the fresh one')
            ->and($second['id'])->toBe($older->id)
            ->and($second['served_by'])->toBeNull()
            ->and($second['cost'])->toBe(0.0);
    });

    it('excludes rows owned by another athlete', function (): void {
        $user = User::factory()->create();
        $other = User::factory()->create();
        athleteBlock($other);

        expect(athleteReport()->narrations($user->id, null, null, null)['rows'])->toBe([]);
    });

    it('filters by kind and by status', function (): void {
        $user = User::factory()->create();
        athleteBlock($user, AnalysisType::TrendRead, ['status' => AnalysisStatus::Done, 'content' => 'x']);
        athleteBlock($user, AnalysisType::WeeklyRecap, ['status' => AnalysisStatus::Failed]);

        expect(athleteReport()->narrations($user->id, AnalysisType::TrendRead, null, null)['rows'])->toHaveCount(1)
            ->and(athleteReport()->narrations($user->id, null, AnalysisStatus::Failed, null)['rows'])->toHaveCount(1)
            ->and(athleteReport()->narrations($user->id, AnalysisType::TrendRead, AnalysisStatus::Failed, null)['rows'])->toBe([]);
    });

    it('hands back a cursor when more rows remain and honours it', function (): void {
        $user = User::factory()->create();
        for ($i = 0; $i < AthleteNarrationReport::PAGE_SIZE + 1; $i++) {
            athleteBlock($user, AnalysisType::BriefingMascotVoice, ['discriminator' => "2026-09-{$i}"]);
        }

        $first = athleteReport()->narrations($user->id, null, null, null);
        expect($first['rows'])->toHaveCount(AthleteNarrationReport::PAGE_SIZE)
            ->and($first['next_cursor'])->not->toBeNull();

        $second = athleteReport()->narrations($user->id, null, null, $first['next_cursor']);
        expect($second['rows'])->toHaveCount(1)
            ->and($second['next_cursor'])->toBeNull();
    });

    it('carries the flag reason and the previous version for a re-narrated row', function (): void {
        $user = User::factory()->create();
        $block = athleteBlock($user, AnalysisType::TrendRead, [
            'status' => AnalysisStatus::Done,
            'content' => 'the second take',
        ]);
        AnalysisVersion::query()->create(['analysis_id' => $block->id, 'content' => 'the first take']);
        AnalysisVersion::query()->create(['analysis_id' => $block->id, 'content' => 'the middle take']);
        Feedback::factory()->create([
            'user_id' => $user->id,
            'subject_type' => FeedbackSubject::Narration,
            'subject_id' => $block->id,
            'reason' => FeedbackReason::FactsWrong,
            'note' => 'that never happened',
        ]);

        $row = athleteReport()->narrations($user->id, null, null, null)['rows'][0];

        expect($row['version_count'])->toBe(2)
            ->and($row['previous_content'])->toBe('the middle take')
            ->and($row['flag']['reason'])->toBe(FeedbackReason::FactsWrong->value)
            ->and($row['flag']['note'])->toBe('that never happened');
    });
});

describe('costByKind', function (): void {
    it('splits spend and calls per kind across today, the week and the month', function (): void {
        $user = User::factory()->create();
        usageRow($user, null, Carbon::parse('2026-09-10 08:00:00'));
        usageRow($user, null, Carbon::parse('2026-09-02 08:00:00'));
        usageRow($user, null, Carbon::parse('2026-08-20 08:00:00'), attributes: ['kind' => 'trend_read']);

        $rows = collect(athleteReport()->costByKind($user->id))->keyBy('kind');

        expect($rows['briefing']['today'])->toBe(['cost' => 2.0, 'calls' => 1])
            ->and($rows['briefing']['week'])->toBe(['cost' => 2.0, 'calls' => 1])
            ->and($rows['briefing']['month'])->toBe(['cost' => 4.0, 'calls' => 2])
            ->and($rows['trend_read']['today'])->toBe(['cost' => 0.0, 'calls' => 0])
            ->and($rows['trend_read']['month'])->toBe(['cost' => 2.0, 'calls' => 1]);
    });
});

describe('attention', function (): void {
    it('separates failed, dead-lettered and stuck rows', function (): void {
        $user = User::factory()->create();
        athleteBlock($user, AnalysisType::TrendRead, ['status' => AnalysisStatus::Failed, 'attempts' => 1, 'error' => 'boom']);
        athleteBlock($user, AnalysisType::WeeklyRecap, [
            'status' => AnalysisStatus::Failed,
            'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
        ]);
        athleteBlock($user, AnalysisType::RunInsight, [
            'status' => AnalysisStatus::Processing,
            'queued_at' => Carbon::now()->subHours(Analysis::STALE_IN_FLIGHT_HOURS + 1),
        ]);

        $attention = athleteReport()->attention($user->id);

        expect($attention['failed'])->toHaveCount(1)
            ->and($attention['failed'][0]['error'])->toBe('boom')
            ->and($attention['dead_lettered'])->toHaveCount(1)
            ->and($attention['dead_lettered'][0]['kind'])->toBe(AnalysisType::WeeklyRecap->value)
            ->and($attention['stuck'])->toHaveCount(1)
            ->and($attention['stuck'][0]['status'])->toBe('processing');
    });
});

describe('audit and override', function (): void {
    it('returns the last actions for this athlete only, newest first', function (): void {
        $user = User::factory()->create();
        DevtoolsAction::query()->create(['actor' => 'nuki', 'action' => 'a', 'user_id' => $user->id, 'created_at' => Carbon::now()->subMinute()]);
        DevtoolsAction::query()->create(['actor' => 'nuki', 'action' => 'b', 'user_id' => $user->id, 'created_at' => Carbon::now()]);
        DevtoolsAction::query()->create(['actor' => 'nuki', 'action' => 'c', 'user_id' => $user->id + 999, 'created_at' => Carbon::now()]);

        $audit = athleteReport()->audit($user->id);

        expect($audit)->toHaveCount(2)
            ->and($audit[0]['action'])->toBe('b')
            ->and($audit[0]['actor'])->toBe('nuki');
    });

    it('caps the audit list', function (): void {
        $user = User::factory()->create();
        for ($i = 0; $i < AthleteNarrationReport::AUDIT_LIMIT + 3; $i++) {
            DevtoolsAction::query()->create(['actor' => 'nuki', 'action' => "a{$i}", 'user_id' => $user->id, 'created_at' => Carbon::now()]);
        }

        expect(athleteReport()->audit($user->id))->toHaveCount(AthleteNarrationReport::AUDIT_LIMIT);
    });

    it('reports the active override, or null when there is none', function (): void {
        $user = User::factory()->create();

        expect(athleteReport()->activeOverride($user->id))->toBeNull();

        app(CeilingOverride::class)->set($user->id, 2.5);

        expect(athleteReport()->activeOverride($user->id)['value'])->toBe(2.5);
    });
});
