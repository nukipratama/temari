<?php

declare(strict_types=1);

use App\Enums\Badge;

it('returns a non-empty label for every case', function (Badge $badge): void {
    expect($badge->label())->toBeString()->not->toBe('');
})->with(Badge::cases());

it('exposes a representative label', function (): void {
    expect(Badge::HeatTamer->label())->toBe('🔥 Heat Tamer')
        ->and(Badge::Headwind->label())->toBe('🌬️ Headwind');
});

it('lists the tracked badges for unlock criteria', function (): void {
    expect(Badge::tracked())->toBe([
        Badge::NightOwl,
        Badge::EarlyBird,
        Badge::RainWarrior,
        Badge::NegativeSplit,
        Badge::HeatTamer,
        Badge::Z2Master,
        Badge::Headwind,
    ]);
});

it('exposes an emoji-free prompt label for every case', function (Badge $badge): void {
    $label = $badge->promptLabel();

    expect($label)->toBeString()->not->toBe('')
        // First character is a letter: the leading emoji has been stripped.
        ->and(preg_match('/^\p{L}/u', $label))->toBe(1)
        // No snake_case slug bled through.
        ->and($label)->not->toContain('_');
})->with(Badge::cases());

it('maps representative badges to their emoji-free prompt labels', function (): void {
    expect(Badge::NegativeSplit->promptLabel())->toBe('Negative Split')
        ->and(Badge::RainWarrior->promptLabel())->toBe('Rain Warrior')
        ->and(Badge::HeldBack->promptLabel())->toBe('Held Back');
});

it('maps a list of slugs to prompt labels, dropping unknown slugs', function (): void {
    expect(Badge::promptLabelsFor(['negative_split', 'not_a_badge', 'rain_warrior']))
        ->toBe(['Negative Split', 'Rain Warrior']);
});

it('builds a slug to label catalog covering every case', function (): void {
    $labels = Badge::labels();

    expect($labels)->toHaveCount(count(Badge::cases()));

    foreach (Badge::cases() as $badge) {
        expect($labels)->toHaveKey($badge->value)
            ->and($labels[$badge->value])->toBe($badge->label());
    }
});
