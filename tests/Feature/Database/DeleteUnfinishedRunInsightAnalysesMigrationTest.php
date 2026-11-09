<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function insertRunInsightMigrationRow(int $activityId, string $type, string $status): int
{
    return DB::table('ai_analyses')->insertGetId([
        'subject_type' => Activity::class,
        'subject_id' => $activityId,
        'analysis_type' => $type,
        'status' => $status,
        'content' => $status === 'done' ? 'a stored narration.' : null,
    ]);
}

it('deletes unfinished run insights with their versions, deliveries and flags, and keeps everything else', function (): void {
    $user = User::factory()->create();
    $unfinished = [];
    foreach (['pending', 'queued', 'processing', 'failed'] as $index => $status) {
        $unfinished[] = insertRunInsightMigrationRow($index + 1, 'run_insight', $status);
    }
    $doneInsight = insertRunInsightMigrationRow(5, 'run_insight', 'done');
    $pendingSpeech = insertRunInsightMigrationRow(6, 'post_run_speech', 'pending');

    foreach ([$unfinished[3], $doneInsight, $pendingSpeech] as $analysisId) {
        DB::table('analysis_versions')->insert(['analysis_id' => $analysisId, 'content' => 'an older narration.']);
        DB::table('notification_deliveries')->insert(['analysis_id' => $analysisId, 'channel' => 'telegram', 'created_at' => now()]);
    }
    DB::table('feedback')->insert([
        ['user_id' => $user->id, 'subject_type' => 'narration', 'subject_id' => $unfinished[0], 'reason' => 'facts_wrong'],
        ['user_id' => $user->id, 'subject_type' => 'narration', 'subject_id' => $doneInsight, 'reason' => 'facts_wrong'],
        ['user_id' => $user->id, 'subject_type' => 'plan_day', 'subject_id' => $unfinished[0], 'reason' => 'facts_wrong'],
    ]);
    DB::connection('analytics')->table('ai_token_usages')->insert([
        'user_id' => $user->id,
        'analysis_id' => $unfinished[3],
        'kind' => 'run_insight',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'total_tokens' => 15,
    ]);

    $migration = require base_path('database/migrations/2026_11_09_000100_delete_unfinished_run_insight_analyses.php');
    $migration->up();
    $migration->up();

    expect(DB::table('ai_analyses')->orderBy('id')->pluck('id')->all())->toBe([$doneInsight, $pendingSpeech])
        ->and(DB::table('analysis_versions')->orderBy('analysis_id')->pluck('analysis_id')->all())->toBe([$doneInsight, $pendingSpeech])
        ->and(DB::table('notification_deliveries')->orderBy('analysis_id')->pluck('analysis_id')->all())->toBe([$doneInsight, $pendingSpeech])
        ->and(DB::table('feedback')->orderBy('id')->get(['subject_type', 'subject_id'])->map(fn (object $row): array => [$row->subject_type, $row->subject_id])->all())
        ->toBe([['narration', $doneInsight], ['plan_day', $unfinished[0]]])
        ->and(DB::connection('analytics')->table('ai_token_usages')->pluck('analysis_id')->all())->toBe([$unfinished[3]]);
});

it('deletes every unfinished run insight when there are more than one chunk of them', function (): void {
    DB::table('ai_analyses')->insert(array_map(fn (int $subjectId): array => [
        'subject_type' => Activity::class,
        'subject_id' => $subjectId,
        'analysis_type' => 'run_insight',
        'status' => 'pending',
    ], range(1, 501)));

    $migration = require base_path('database/migrations/2026_11_09_000100_delete_unfinished_run_insight_analyses.php');
    $migration->up();

    expect(DB::table('ai_analyses')->count())->toBe(0);
});
