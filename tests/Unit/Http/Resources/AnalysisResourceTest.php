<?php

declare(strict_types=1);

use App\Http\Resources\AnalysisResource;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('delegates toArray to Analysis::toPayload for the same row and context', function (): void {
    $row = Analysis::factory()->done('hi')->create([
        'subject_id' => 7,
        'discriminator' => '2026-05-18',
    ]);

    $resource = new AnalysisResource($row, $row->analysis_type, 7, '2026-05-18');

    expect($resource->toArray(Request::create('/')))->toBe(
        Analysis::toPayload($row, $row->analysis_type, $row->analysis_type->subjectType(), 7, '2026-05-18'),
    );
});

it('produces the pending pseudo-payload when the row is null', function (): void {
    $resource = new AnalysisResource(null, AnalysisType::BriefingMascotVoice, 1, '2026-05-18');

    expect($resource->toArray(Request::create('/')))->toBe(
        Analysis::toPayload(null, AnalysisType::BriefingMascotVoice, AnalysisType::BriefingMascotVoice->subjectType(), 1, '2026-05-18'),
    );
});

it('serializes as a flat top-level array, without a "data" wrapper', function (): void {
    $resource = new AnalysisResource(null, AnalysisType::BriefingMascotVoice, 1, null);

    expect(json_decode(json_encode($resource), true))->toBe($resource->toArray(Request::create('/')));
});
