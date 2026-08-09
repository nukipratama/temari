<?php

declare(strict_types=1);

use App\Services\Run\Story\FormStatus;

it('label returns fallback when load is null', function (): void {
    expect(FormStatus::label(null))->toBe('Not read yet');
});

it('label resolves all form_status enum values', function (): void {
    expect(FormStatus::label(['form_status' => 'fresh']))->toBe('Feeling Fresh')
        ->and(FormStatus::label(['form_status' => 'optimal']))->toBe('Right on Track')
        ->and(FormStatus::label(['form_status' => 'fatigued']))->toBe('Getting Tired')
        ->and(FormStatus::label(['form_status' => 'overreaching']))->toBe('Overreaching')
        ->and(FormStatus::label(['form_status' => 'unknown_value']))->toBe('Right on Track');
});

it('tone returns neutral when load is null', function (): void {
    expect(FormStatus::tone(null))->toBe('neutral');
});

it('tone resolves all form_status enum values', function (): void {
    expect(FormStatus::tone(['form_status' => 'fresh']))->toBe('positive')
        ->and(FormStatus::tone(['form_status' => 'fatigued']))->toBe('warning')
        ->and(FormStatus::tone(['form_status' => 'overreaching']))->toBe('alert')
        ->and(FormStatus::tone(['form_status' => 'optimal']))->toBe('neutral');
});
