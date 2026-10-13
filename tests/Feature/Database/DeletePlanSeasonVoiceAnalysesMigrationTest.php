<?php

declare(strict_types=1);

use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('deletes every season take with its versions and narration flags, and keeps everything else', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();
    $seasonTake = DB::table('ai_analyses')->insertGetId([
        'subject_type' => Season::class,
        'subject_id' => $season->id,
        'analysis_type' => 'plan_season_voice',
        'status' => 'done',
        'content' => 'a stored take.',
    ]);
    $clamp = DB::table('ai_analyses')->insertGetId([
        'subject_type' => 'plan_clamp_voice_user_day',
        'subject_id' => $user->id,
        'analysis_type' => 'plan_clamp_voice',
        'discriminator' => '2026-10-13',
        'status' => 'done',
        'content' => 'a stored clamp line.',
    ]);
    DB::table('analysis_versions')->insert(['analysis_id' => $seasonTake, 'content' => 'an older take.']);
    DB::table('feedback')->insert([
        ['user_id' => $user->id, 'subject_type' => 'narration', 'subject_id' => $seasonTake, 'reason' => 'facts_wrong'],
        ['user_id' => $user->id, 'subject_type' => 'narration', 'subject_id' => $clamp, 'reason' => 'facts_wrong'],
        ['user_id' => $user->id, 'subject_type' => 'plan_day', 'subject_id' => $seasonTake, 'reason' => 'facts_wrong'],
    ]);

    $migration = require base_path('database/migrations/2026_10_13_000000_delete_plan_season_voice_analyses.php');
    $migration->up();
    $migration->up();

    expect(DB::table('ai_analyses')->pluck('id')->all())->toBe([$clamp])
        ->and(DB::table('analysis_versions')->count())->toBe(0)
        ->and(DB::table('feedback')->orderBy('id')->get(['subject_type', 'subject_id'])->map(fn (object $row): array => [$row->subject_type, $row->subject_id])->all())
        ->toBe([['narration', $clamp], ['plan_day', $seasonTake]]);
});
