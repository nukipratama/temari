<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * @param  list<string>  $paths
 * @return list<string>
 */
function changedTestsFor(array $paths): array
{
    $process = new Process([base_path('scripts/changed-tests.sh')], base_path());
    $process->setInput(implode("\n", $paths)."\n");
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return array_values(array_filter(explode("\n", trim($process->getOutput()))));
}

it('selects the test paired with a changed class by basename, wherever it lives', function (): void {
    expect(changedTestsFor(['app/Services/AI/SelfHealer.php']))
        ->toBe(['tests/Unit/Services/AI/SelfHealerTest.php']);
    expect(changedTestsFor(['app/Http/Controllers/RunController.php']))
        ->toBe(['tests/Feature/Runs/RunControllerTest.php']);
})->group('structure');

it('selects a changed test file itself, once', function (): void {
    expect(changedTestsFor([
        'tests/Unit/Services/AI/ChainResolverTest.php',
        'app/Services/AI/ChainResolver.php',
    ]))->toBe(['tests/Unit/Services/AI/ChainResolverTest.php']);
})->group('structure');

it('selects nothing for docs, frontend, deleted tests or classes without a test', function (): void {
    expect(changedTestsFor([
        'docs/DESIGN.md',
        'resources/js/pages/Plan.tsx',
        'tests/Unit/Gone/DeletedTest.php',
        'app/Gone/NoSuchClassAnywhere.php',
    ]))->toBe([]);
})->group('structure');

it('is the gate\'s Pest step', function (): void {
    expect(File::get(base_path('scripts/gate.sh')))
        ->toContain('step "pest changed" pest_changed')
        ->toContain('sh scripts/changed-tests.sh');
})->group('structure');
