<?php

declare(strict_types=1);

use App\Services\Run\Metrics\TrainingFormStatus;
use App\Services\Run\Story\FormStatus;

it('label returns fallback when load is null', function (): void {
    expect(FormStatus::label(null))->toBe('not read yet');
});

it('label shows the three load balance states', function (): void {
    expect(FormStatus::label(['form_status' => TrainingFormStatus::Fresh]))->toBe('fresh')
        ->and(FormStatus::label(['form_status' => TrainingFormStatus::Optimal]))->toBe('steady')
        ->and(FormStatus::label(['form_status' => TrainingFormStatus::Fatigued]))->toBe('heavy')
        ->and(FormStatus::label(['form_status' => TrainingFormStatus::Overreaching]))->toBe('heavy')
        ->and(FormStatus::label(['form_status' => 'unknown_value']))->toBe('steady');
});

it('tone is neutral when load is null', function (): void {
    expect(FormStatus::tone(null))->toBe('neutral');
});

it('tone maps heavy to warning for both stored heavy states', function (): void {
    expect(FormStatus::tone(['form_status' => TrainingFormStatus::Fresh]))->toBe('positive')
        ->and(FormStatus::tone(['form_status' => TrainingFormStatus::Fatigued]))->toBe('warning')
        ->and(FormStatus::tone(['form_status' => TrainingFormStatus::Overreaching]))->toBe('warning')
        ->and(FormStatus::tone(['form_status' => TrainingFormStatus::Optimal]))->toBe('neutral');
});
