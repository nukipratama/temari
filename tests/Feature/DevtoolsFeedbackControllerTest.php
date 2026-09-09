<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\Feedback;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['devtools.password' => 'secret']);
    app()->detectEnvironment(fn (): string => 'production');
});

it('renders with rows, newest first, linking a plan day and a resolvable narration', function (): void {
    $user = User::factory()->create(['name' => 'Ada']);
    $session = PlannedSession::factory()->for($user)->create();
    $analysis = Analysis::factory()->create([
        'subject_type' => 'briefing_user_day',
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    $older = Feedback::factory()->for($user)->onPlanDay($session->id)->create([
        'created_at' => now()->subDay(),
    ]);
    $newer = Feedback::factory()->for($user)->onNarration($analysis->id)->create([
        'note' => 'this is off',
        'created_at' => now(),
    ]);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/devtools/feedback')
        ->assertSuccessful()
        ->assertInertia(
            fn ($page) => $page
            ->component('DevtoolsFeedback')
            ->where('rows.0.id', $newer->id)
            ->where('rows.0.runner', 'Ada')
            ->where('rows.0.note', 'this is off')
            ->where('rows.0.subject_url', route('activities.show', $analysis->subject_id))
            ->where('rows.1.id', $older->id)
            ->where('rows.1.subject_label', 'plan day')
            ->where('rows.1.subject_url', route('plan')),
        );
});

it('labels a narration whose Analysis row is gone as deleted, with no link', function (): void {
    $user = User::factory()->create();
    $feedback = Feedback::factory()->for($user)->onNarration(999999)->create();

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/devtools/feedback')
        ->assertSuccessful()
        ->assertInertia(
            fn ($page) => $page
            ->where('rows.0.id', $feedback->id)
            ->where('rows.0.subject_label', 'narration (deleted)')
            ->where('rows.0.subject_url', null),
        );
});

it('renders no rows when nothing has been flagged', function (): void {
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/devtools/feedback')
        ->assertSuccessful()
        ->assertInertia(
            fn ($page) => $page
            ->component('DevtoolsFeedback')
            ->where('rows', []),
        );
});

it('challenges a request with no devtools password', function (): void {
    $this->get('/devtools/feedback')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Devtools"');
});
