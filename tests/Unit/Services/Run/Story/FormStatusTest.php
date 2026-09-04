<?php

declare(strict_types=1);

use App\Services\Run\Story\FormStatus;

it('label returns fallback when load is null', function (): void {
    expect(FormStatus::label(null))->toBe('not read yet');
});

it('label resolves all form_status enum values', function (): void {
    expect(FormStatus::label(['form_status' => 'fresh']))->toBe('feeling fresh')
        ->and(FormStatus::label(['form_status' => 'optimal']))->toBe('right on track')
        ->and(FormStatus::label(['form_status' => 'fatigued']))->toBe('getting tired')
        ->and(FormStatus::label(['form_status' => 'overreaching']))->toBe('overreaching')
        ->and(FormStatus::label(['form_status' => 'unknown_value']))->toBe('right on track');
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
