<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Services\AI\AnalysisType;
use App\Services\Telegram\NotifiableAnalysisTypes;

it('maps every notifiable analysis type to a kind', function (): void {
    foreach (array_keys(NotifiableAnalysisTypes::TYPES) as $value) {
        expect(NotificationKind::forAnalysisType(AnalysisType::from((string) $value)))
            ->toBeInstanceOf(NotificationKind::class);
    }
});

it('maps a non-notifiable analysis type to null', function (): void {
    expect(NotificationKind::forAnalysisType(AnalysisType::CardFlavor))->toBeNull();
});

it('maps each notifiable type to its own distinct kind', function (): void {
    expect(NotificationKind::forAnalysisType(AnalysisType::PostRunSpeech))->toBe(NotificationKind::PostRun)
        ->and(NotificationKind::forAnalysisType(AnalysisType::WeeklyRecap))->toBe(NotificationKind::WeeklyRecap)
        ->and(NotificationKind::forAnalysisType(AnalysisType::MonthlyRecap))->toBe(NotificationKind::MonthlyRecap);
});
