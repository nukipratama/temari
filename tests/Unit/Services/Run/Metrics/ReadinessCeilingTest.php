<?php

declare(strict_types=1);

use App\Services\Run\Metrics\ReadinessCeiling;

it('ranks ceilings least-to-most permissive', function (): void {
    expect(ReadinessCeiling::Rest->rank())->toBeLessThan(ReadinessCeiling::EasyOnly->rank())
        ->and(ReadinessCeiling::EasyOnly->rank())->toBeLessThan(ReadinessCeiling::ModerateOk->rank())
        ->and(ReadinessCeiling::ModerateOk->rank())->toBeLessThan(ReadinessCeiling::QualityOk->rank());
});

it('capTo keeps the more restrictive ceiling regardless of order', function (): void {
    expect(ReadinessCeiling::QualityOk->capTo(ReadinessCeiling::EasyOnly))->toBe(ReadinessCeiling::EasyOnly)
        ->and(ReadinessCeiling::EasyOnly->capTo(ReadinessCeiling::QualityOk))->toBe(ReadinessCeiling::EasyOnly)
        ->and(ReadinessCeiling::Rest->capTo(ReadinessCeiling::ModerateOk))->toBe(ReadinessCeiling::Rest)
        ->and(ReadinessCeiling::ModerateOk->capTo(ReadinessCeiling::ModerateOk))->toBe(ReadinessCeiling::ModerateOk);
});

it('exposes stable string values for the LLM context', function (): void {
    expect(ReadinessCeiling::Rest->value)->toBe('rest')
        ->and(ReadinessCeiling::EasyOnly->value)->toBe('easy_only')
        ->and(ReadinessCeiling::ModerateOk->value)->toBe('moderate_ok')
        ->and(ReadinessCeiling::QualityOk->value)->toBe('quality_ok');
});
