<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('reports the generated TS enums are in sync with the PHP enums', function (): void {
    expect(Artisan::call('typescript:enums', ['--check' => true]))->toBe(0);
})->group('structure');

it('emits a string union and value array for each backed enum', function (): void {
    $generated = (string) file_get_contents(resource_path('js/types/generated.ts'));

    expect($generated)
        ->toContain("export type Rarity = 'common' | 'uncommon' | 'rare' | 'epic' | 'legendary';")
        ->toContain('export const RARITY_VALUES = [')
        ->toContain('export type AnalysisStatus =')
        ->toContain('export type AnalysisType =')
        ->toContain('export type PrCategory =');
});

it('fails the check when the committed file is stale', function (): void {
    $path = resource_path('js/types/generated.ts');
    $original = (string) file_get_contents($path);

    try {
        file_put_contents($path, "stale\n");
        expect(Artisan::call('typescript:enums', ['--check' => true]))->toBe(1);
    } finally {
        file_put_contents($path, $original);
    }
});
