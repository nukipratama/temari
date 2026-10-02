<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

const CI_WORKFLOW = '.github/workflows/ci.yml';

function ciClassifyPaths(array $paths): array
{
    $process = new Process([base_path('scripts/ci/classify-checks.sh')], base_path());
    $process->setInput(implode("\n", $paths)."\n");
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $checks = [];
    foreach (explode("\n", trim($process->getOutput())) as $line) {
        [$name, $value] = explode('=', $line, 2);
        $checks[$name] = $value === 'true';
    }

    return $checks;
}

function ciClassifiesAsBackend(string $path): bool
{
    return ciClassifyPaths([$path])['backend'];
}

function ciClassifiesAsFrontend(string $path): bool
{
    return ciClassifyPaths([$path])['frontend'];
}

function ciClassifiesAsDocker(string $path): bool
{
    return ciClassifyPaths([$path])['docker'];
}

it('runs backend CI for every file the token-mirror test reads', function (): void {
    require_once base_path('tests/Unit/Architecture/DesignTokenMirrorsTest.php');

    $unguarded = collect(MIRROR_FILES)
        ->reject(ciClassifiesAsBackend(...))
        ->values();

    expect($unguarded->all())->toBe(
        [],
        "These files are asserted by DesignTokenMirrorsTest, which runs in backend CI, but the CI\n".
        "path filter does not classify them as backend — changing one would skip the test that guards it:\n  ".
        $unguarded->implode("\n  "),
    );
})->group('structure');

it('runs backend CI for every doc the token-docs test reads', function (): void {
    $skillDocs = collect(File::allFiles(base_path('.agents/skills/temari')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
        ->map(fn (SplFileInfo $file): string => '.agents/skills/temari/'.$file->getRelativePathname());

    $docs = ['CLAUDE.md', 'README.md', 'docs/design-tokens.md', ...$skillDocs];

    $unguarded = collect($docs)->reject(ciClassifiesAsBackend(...))->values();

    expect($unguarded->all())->toBe(
        [],
        "DesignTokenDocsTest reads these, but the CI path filter treats them as inert documentation:\n  ".
        $unguarded->implode("\n  "),
    );
})->group('structure');

it('uses the tested classifier and runs every check when the workflow itself changes', function (): void {
    expect(File::get(base_path(CI_WORKFLOW)))->toContain('scripts/ci/classify-checks.sh');
    expect(ciClassifyPaths([CI_WORKFLOW]))->toBe([
        'backend' => true,
        'frontend' => true,
        'docker' => true,
        'worktree' => true,
    ]);
})->group('structure');

it('routes public runtime assets and frontend configuration to frontend CI only', function (): void {
    $paths = [
        'public/sw.js',
        'public/offline.html',
        'public/manifest.webmanifest',
        'public/robots.txt',
        'vitest.config.ts',
        'prettier.config.js',
    ];

    foreach ($paths as $path) {
        expect(ciClassifiesAsBackend($path))->toBeFalse("{$path} should not trigger backend CI.");
        expect(ciClassifiesAsFrontend($path))->toBeTrue("{$path} should trigger frontend CI.");
        expect(ciClassifiesAsDocker($path))->toBeFalse("{$path} should not trigger an image build.");
    }
})->group('structure');

it('routes public PHP entry points to backend or all checks', function (): void {
    expect(ciClassifyPaths(['public/index.php']))->toBe([
        'backend' => true,
        'frontend' => false,
        'docker' => false,
        'worktree' => false,
    ]);
    expect(ciClassifyPaths(['public/frankenphp-worker.php']))->toBe([
        'backend' => true,
        'frontend' => true,
        'docker' => true,
        'worktree' => true,
    ]);
})->group('structure');

it('routes toolchain and testing environment inputs to their checks', function (string $path, array $checks): void {
    expect(ciClassifyPaths([$path]))->toBe($checks);
})->with([
    '.nvmrc' => ['.nvmrc', ['backend' => false, 'frontend' => true, 'docker' => false, 'worktree' => false]],
    '.npmrc' => ['.npmrc', ['backend' => false, 'frontend' => true, 'docker' => false, 'worktree' => false]],
    '.env.testing.example' => ['.env.testing.example', ['backend' => true, 'frontend' => false, 'docker' => false, 'worktree' => false]],
])->group('structure');

it('routes development shell helpers to backend CI only', function (): void {
    foreach ([
        'scripts/deploy/check-restore-counts.sh',
        'scripts/worktree',
        'scripts/worktree-setup.sh',
        'scripts/worktree-hook-create.sh',
        'scripts/tl',
    ] as $path) {
        expect(ciClassifiesAsBackend($path))->toBeTrue("{$path} should trigger backend CI.");
        expect(ciClassifiesAsFrontend($path))->toBeFalse("{$path} should not trigger frontend CI.");
        expect(ciClassifiesAsDocker($path))->toBeFalse("{$path} should not trigger an image build.");
    }
})->group('structure');

it('runs the worktree race harness when the worktree tooling or its harness changes', function (string $path): void {
    expect(ciClassifyPaths([$path])['worktree'])->toBeTrue("{$path} should run the worktree race harness.");
})->with([
    'scripts/worktree',
    'scripts/worktree-setup.sh',
    'scripts/worktree-hook-create.sh',
    'tests/scripts/worktree-races.sh',
])->group('structure');

it('skips the worktree race harness for unrelated changes', function (string $path): void {
    expect(ciClassifyPaths([$path])['worktree'])->toBeFalse("{$path} should not run the worktree race harness.");
})->with([
    'scripts/tl',
    'scripts/deploy/check-restore-counts.sh',
    'app/Models/User.php',
    'tests/Unit/Architecture/CiPathFilterTest.php',
    'resources/js/app.tsx',
])->group('structure');

it('routes infrastructure and server configuration changes to every check', function (): void {
    foreach ([
        'compose.prod.yaml',
        'compose.shared-services.yml',
        'public/.htaccess',
    ] as $path) {
        expect(ciClassifyPaths([$path]))->toBe([
            'backend' => true,
            'frontend' => true,
            'docker' => true,
            'worktree' => true,
        ]);
    }
})->group('structure');

it('keeps renamed-away inputs in the changed path list', function (): void {
    expect(File::get(base_path(CI_WORKFLOW)))->toContain('git diff --no-renames --name-only "$base" HEAD');
})->group('structure');

it('unions mixed changes and takes the all-checks branch for infrastructure', function (): void {
    expect(ciClassifyPaths([
        'docs/decisions/dark-is-the-default-ground.md',
        'public/offline.html',
    ]))->toBe([
        'backend' => false,
        'frontend' => true,
        'docker' => false,
        'worktree' => false,
    ]);

    expect(ciClassifyPaths([
        'docs/decisions/dark-is-the-default-ground.md',
        'public/offline.html',
        'compose.override.yaml',
    ]))->toBe([
        'backend' => true,
        'frontend' => true,
        'docker' => true,
        'worktree' => true,
    ]);
})->group('structure');

it('skips the heavy jobs for planning docs, which nothing asserts against', function (): void {
    foreach ([
        'plan/README.md',
        'docs/decisions/dark-is-the-default-ground.md',
    ] as $path) {
        expect(ciClassifiesAsBackend($path))->toBeFalse();
        expect(ciClassifiesAsFrontend($path))->toBeFalse();
        expect(ciClassifiesAsDocker($path))->toBeFalse();
    }
})->group('structure');
