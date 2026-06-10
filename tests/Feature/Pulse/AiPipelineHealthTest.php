<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Livewire\Pulse\AiPipelineHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the status snapshot without error', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('failed')
        ->assertSee('done')
        ->assertSee('Token per jenis')
        ->assertSee('Belum ada pemakaian token');
});

it('shows an ok health badge when no analysis has failed', function (): void {
    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('health: ok');
});

it('shows an alert health badge when an analysis has failed', function (): void {
    DB::table('ai_analyses')->insert([
        'subject_type' => Activity::class,
        'subject_id' => 7,
        'analysis_type' => 'activity_story',
        'status' => 'failed',
        'error' => 'boom',
        'attempts' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('health: alert');
});

it('surfaces a recent failed analysis with its error', function (): void {
    DB::table('ai_analyses')->insert([
        'subject_type' => Activity::class,
        'subject_id' => 42,
        'analysis_type' => 'activity_story',
        'status' => 'failed',
        'error' => 'Azure timed out',
        'attempts' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('Activity #42')
        ->assertSee('Azure timed out');
});

it('shows the per-kind token spend from ai_tokens Pulse entries', function (): void {
    // PULSE_ENABLED=false in tests stops recording at boot; re-enable so the
    // real record -> ingest -> aggregate path is exercised end-to-end.
    Pulse::startRecording();
    Pulse::record('ai_tokens', 'briefing', 165)->sum();
    Pulse::record('ai_tokens', 'activity_story', 300)->sum();
    Pulse::ingest();

    Livewire::test(AiPipelineHealth::class)
        ->assertOk()
        ->assertSee('briefing')
        ->assertSee('165')
        ->assertSee('activity_story')
        ->assertSee('300')
        ->assertSee('465'); // header total across kinds
});
