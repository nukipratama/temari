<?php

declare(strict_types=1);

use App\Services\Run\Story\FormStatus;

it('label returns fallback when load is null', function (): void {
    expect(FormStatus::label(null))->toBe('Form belum kebaca');
});

it('label resolves all form_status enum values', function (): void {
    expect(FormStatus::label(['form_status' => 'fresh']))->toBe('Form Fresh')
        ->and(FormStatus::label(['form_status' => 'optimal']))->toBe('Form Optimal')
        ->and(FormStatus::label(['form_status' => 'fatigued']))->toBe('Lelah')
        ->and(FormStatus::label(['form_status' => 'overreaching']))->toBe('Overreaching')
        ->and(FormStatus::label(['form_status' => 'unknown_value']))->toBe('Form Optimal');
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
