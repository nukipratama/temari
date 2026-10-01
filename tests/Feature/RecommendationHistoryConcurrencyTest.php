<?php

declare(strict_types=1);

use App\Models\RecommendationRevision;
use App\Models\RecommendationView;
use App\Models\User;
use App\Services\Run\Plan\RecommendationHistory;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

it('reuses the revision and view a competing writer inserted first', function (): void {
    $user = User::factory()->create();
    $history = app(RecommendationHistory::class);
    $observationId = (string) Str::uuid();
    $original = ['session_type' => 'tempo'];
    $effective = ['session_type' => 'easy'];
    $revisionCompetitor = false;
    $viewCompetitor = false;

    RecommendationRevision::creating(function (RecommendationRevision $revision) use (&$revisionCompetitor): void {
        if ($revisionCompetitor) {
            return;
        }
        $revisionCompetitor = true;
        DB::connection('analytics')->table('recommendation_revisions')->insert([
            'user_id' => $revision->user_id,
            'date' => '2026-10-01',
            'fingerprint' => $revision->fingerprint,
            'policy_version' => 1,
            'original' => json_encode(['session_type' => 'tempo']),
            'effective' => json_encode(['session_type' => 'easy']),
            'created_at' => now(),
        ]);
    });
    RecommendationView::creating(function (RecommendationView $view) use (&$viewCompetitor): void {
        if ($viewCompetitor) {
            return;
        }
        $viewCompetitor = true;
        DB::connection('analytics')->table('recommendation_views')->insert([
            'recommendation_revision_id' => $view->recommendation_revision_id,
            'observation_id' => $view->observation_id,
            'shown_at' => now(),
        ]);
    });

    try {
        $revision = $history->record($user->id, '2026-10-01', $original, $effective);
        $view = $history->shown($revision, $observationId);

        expect($revisionCompetitor)->toBeTrue()
            ->and($viewCompetitor)->toBeTrue()
            ->and(RecommendationRevision::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(RecommendationView::query()->where('observation_id', $observationId)->count())->toBe(1)
            ->and($view->recommendation_revision_id)->toBe($revision->id);
    } finally {
        RecommendationRevision::flushEventListeners();
        RecommendationView::flushEventListeners();
        DB::table('recommendation_views')->where('observation_id', $observationId)->delete();
        DB::table('recommendation_revisions')->where('user_id', $user->id)->delete();
        DB::table('users')->where('id', $user->id)->delete();
    }
});
