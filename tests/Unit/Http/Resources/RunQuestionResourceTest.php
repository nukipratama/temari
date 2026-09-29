<?php

declare(strict_types=1);

use App\Http\Resources\RunQuestionResource;
use App\Models\AI\RunQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('exposes the exchange and its state, and nothing about the asker', function (): void {
    $row = RunQuestion::factory()->answered('you held the pace.')->create(['question' => 'was this even?']);

    $payload = new RunQuestionResource($row)->toArray(Request::create('/'));

    expect($payload)->toBe([
        'id' => $row->id,
        'activity_id' => $row->activity_id,
        'question' => 'was this even?',
        'answer' => 'you held the pace.',
        'follow_ups' => [],
        'status' => 'done',
        'asked_at' => $row->created_at->toIso8601String(),
    ]);
});

it('carries a null answer while the question is still queued', function (): void {
    $row = RunQuestion::factory()->create();

    $payload = new RunQuestionResource($row)->toArray(Request::create('/'));

    expect($payload['answer'])->toBeNull()->and($payload['status'])->toBe('queued');
});

it('carries the follow-ups the answer offered', function (): void {
    $row = RunQuestion::factory()->answered()->create(['follow_ups' => ['what about km 5?']]);

    expect(new RunQuestionResource($row)->toArray(Request::create('/'))['follow_ups'])->toBe(['what about km 5?']);
});
