<?php

declare(strict_types=1);

use App\Enums\PerformanceEvidenceKind;
use App\Models\PerformanceEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts confirmed performance evidence and its optional references', function (): void {
    $evidence = PerformanceEvidence::query()->create([
        'user_id' => (string) User::factory()->create()->id, 'kind' => 'test',
        'distance_m' => '5000', 'elapsed_time_sec' => '1500', 'performed_on' => '2026-10-01', 'confirmed_at' => now(),
    ]);
    expect($evidence->user_id)->toBeInt()->and($evidence->distance_m)->toBe(5000)
        ->and($evidence->elapsed_time_sec)->toBe(1500)->and($evidence->kind)->toBe(PerformanceEvidenceKind::Test)
        ->and($evidence->performed_on->toDateString())->toBe('2026-10-01');
});
