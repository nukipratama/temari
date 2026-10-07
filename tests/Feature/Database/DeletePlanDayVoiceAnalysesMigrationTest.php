<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedAnalysisRow(int $userId, string $type, string $subjectType, ?string $discriminator): int
{
    return DB::table('ai_analyses')->insertGetId([
        'subject_type' => $subjectType,
        'subject_id' => $userId,
        'analysis_type' => $type,
        'discriminator' => $discriminator,
        'status' => 'done',
        'content' => 'a stored read.',
    ]);
}

function seedFeedbackRow(int $userId, string $subjectType, int $subjectId): void
{
    DB::table('feedback')->insert(['user_id' => $userId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'reason' => 'facts_wrong']);
}

it('deletes every day read with its versions and narration flags, and keeps everything else', function (): void {
    $user = User::factory()->create();
    $dayRead = seedAnalysisRow($user->id, 'plan_day_voice', 'plan_day_voice_user_day', '2026-10-06');
    $clamp = seedAnalysisRow($user->id, 'plan_clamp_voice', 'plan_clamp_voice_user_day', '2026-10-06');
    DB::table('analysis_versions')->insert(['analysis_id' => $dayRead, 'content' => 'an older read.']);
    seedFeedbackRow($user->id, 'narration', $dayRead);
    seedFeedbackRow($user->id, 'narration', $clamp);
    seedFeedbackRow($user->id, 'plan_day', $dayRead);

    $migration = require base_path('database/migrations/2026_10_07_010000_delete_plan_day_voice_analyses.php');
    $migration->up();
    $migration->up();

    expect(DB::table('ai_analyses')->pluck('id')->all())->toBe([$clamp])
        ->and(DB::table('analysis_versions')->count())->toBe(0)
        ->and(DB::table('feedback')->orderBy('id')->get(['subject_type', 'subject_id'])->map(fn (object $row): array => [$row->subject_type, $row->subject_id])->all())
        ->toBe([['narration', $clamp], ['plan_day', $dayRead]]);
});
