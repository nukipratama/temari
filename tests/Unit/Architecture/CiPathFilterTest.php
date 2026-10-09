<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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

/** @return array<string, array<string, bool>> */
function ciClassifyEachPath(array $paths): array
{
    $process = new Process([base_path('scripts/ci/classify-checks.sh'), '--per-path'], base_path());
    $process->setInput(implode("\n", $paths)."\n");
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $classified = [];
    foreach (explode("\n", trim($process->getOutput())) as $line) {
        [$path, $checks] = explode("\t", $line, 2);
        foreach (explode("\t", $checks) as $check) {
            [$name, $value] = explode('=', $check, 2);
            $classified[$path][$name] = $value === 'true';
        }
    }

    return $classified;
}

function ciChecks(
    bool $backend = false,
    bool $frontend = false,
    bool $docker = false,
    bool $worktree = false,
    bool $structure = false,
    bool $image = false,
): array {
    return compact('backend', 'frontend', 'docker', 'worktree', 'structure', 'image');
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

function ciRunsStructureTests(string $path): bool
{
    $checks = ciClassifyPaths([$path]);

    return $checks['backend'] || $checks['structure'];
}

/** @return list<string> */
function ciRepoPathsReadBy(string $testSource): array
{
    static $directoryProbes = [];

    $roots = ['base' => '', 'resource' => 'resources/', 'public' => 'public/'];

    preg_match_all("/\b(base|resource|public)_path\('([^'$]+)'\)/", $testSource, $calls, PREG_SET_ORDER);
    preg_match_all("/^const [A-Z_]+ = '([^'$]+)';/m", $testSource, $constants);

    return collect($calls)
        ->map(fn (array $call): string => $roots[$call[1]].$call[2])
        ->merge($constants[1])
        ->reject(fn (string $path): bool => str_starts_with($path, 'vendor/'))
        ->flatMap(function (string $path) use (&$directoryProbes): array {
            if (is_file(base_path($path))) {
                return [$path];
            }
            if (! is_dir(base_path($path))) {
                return [];
            }

            return $directoryProbes[$path] ??= collect(File::allFiles(base_path($path)))
                ->map(fn (SplFileInfo $file): string => "{$path}/ci-probe.{$file->getExtension()}")
                ->unique()
                ->all();
        })
        ->unique()
        ->values()
        ->all();
}

function ciRunsOnlyStructureTests(string $testSource): bool
{
    return str_contains($testSource, "uses()->group('structure')")
        || preg_match_all('/^(?:it|test)\(/m', $testSource) === substr_count($testSource, "->group('structure')");
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

it('runs the structure tests for every doc the token-docs test reads', function (): void {
    $skillDocs = collect(File::allFiles(base_path('.claude/skills/temari')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
        ->map(fn (SplFileInfo $file): string => '.claude/skills/temari/'.$file->getRelativePathname());

    $docs = ['CLAUDE.md', 'README.md', 'docs/design-tokens.md', ...$skillDocs];

    $unguarded = collect($docs)->reject(ciRunsStructureTests(...))->values();

    expect($unguarded->all())->toBe(
        [],
        "DesignTokenDocsTest reads these, but the CI path filter treats them as inert documentation:\n  ".
        $unguarded->implode("\n  "),
    );
})->group('structure');

it('runs a check that executes every test reading a repo file it names', function (): void {
    $reads = [];

    foreach (File::allFiles(base_path('tests')) as $test) {
        if ($test->getExtension() !== 'php') {
            continue;
        }

        $source = $test->getContents();

        foreach (ciRepoPathsReadBy($source) as $path) {
            $reads[] = [$path, $test, $source];
        }
    }

    $classified = ciClassifyEachPath(array_values(array_unique(array_column($reads, 0))));
    $unguarded = [];

    foreach ($reads as [$path, $test, $source]) {
        $checks = $classified[$path];

        if (! $checks['backend'] && ! ($checks['structure'] && ciRunsOnlyStructureTests($source))) {
            $unguarded[] = "{$path} (read by tests/{$test->getRelativePathname()})";
        }
    }

    expect($unguarded)->toBe(
        [],
        "A test reads these files, but changing one alone runs no CI job that executes that test:\n  ".
        implode("\n  ", $unguarded),
    );
})->group('structure');

it('runs only the structure tests for documentation those tests read', function (array $paths): void {
    expect(ciClassifyPaths($paths))->toBe(ciChecks(structure: true));
})->with([
    'design docs' => [['docs/design-tokens.md', '.claude/skills/temari/references/design-system.md']],
    'llm inventory' => [['docs/architecture/llm-triggers.md']],
    'agent entrypoints' => [['CLAUDE.md', 'README.md']],
])->group('structure');

it('runs the structure tests in their own required job only when backend CI does not run', function (): void {
    $workflow = Yaml::parseFile(base_path(CI_WORKFLOW));
    $job = $workflow['jobs']['structure'];

    expect($job['if'])->toBe("\${{ needs.changes.outputs.structure == 'true' }}")
        ->and($job['timeout-minutes'])->toBeInt()
        ->and(collect($job['steps'])->pluck('run')->filter()->implode("\n"))->toContain('./vendor/bin/pest --group=structure')
        ->and($workflow['jobs']['ci-gate']['needs'])->toContain('structure')
        ->and($workflow['jobs']['ci-gate']['steps'][0]['run'])->toContain('needs.structure.result');

    expect(ciClassifyPaths(['docs/design-tokens.md', 'app/Models/User.php']))->toBe(ciChecks(backend: true));
})->group('structure');

it('uses the tested classifier and runs every check when the workflow itself changes', function (): void {
    expect(File::get(base_path(CI_WORKFLOW)))->toContain('scripts/ci/classify-checks.sh');
    expect(ciClassifyPaths([CI_WORKFLOW]))->toBe(ciChecks(backend: true, frontend: true, docker: true, worktree: true, image: true));
})->group('structure');

it('routes public runtime assets to frontend CI and to the backend tests that check they exist', function (string $path): void {
    expect(ciClassifyPaths([$path]))->toBe(ciChecks(backend: true, frontend: true));
})->with([
    'public/sw.js',
    'public/offline.html',
    'public/manifest.webmanifest',
    'public/robots.txt',
])->group('structure');

it('routes frontend configuration to frontend CI only', function (string $path): void {
    expect(ciClassifyPaths([$path]))->toBe(ciChecks(frontend: true));
})->with([
    'vitest.config.ts',
    'prettier.config.js',
    '.prettierignore',
    '.editorconfig',
    '.npmrc',
])->group('structure');

it('routes frontend source to frontend CI and the structure tests that scan it', function (): void {
    expect(ciClassifyPaths(['resources/js/pages/Plan.tsx']))->toBe(ciChecks(frontend: true, structure: true));
    expect(ciClassifyPaths(['resources/js/types/generated.ts']))->toBe(ciChecks(backend: true, frontend: true));
})->group('structure');

it('routes files a Vitest test reads from outside resources/js to frontend CI', function (string $path): void {
    expect(ciClassifiesAsFrontend($path))->toBeTrue("{$path} should trigger frontend CI.");
})->with([
    'tests/fixtures/inertia-props/Dashboard.json',
    'resources/brand/grounds.mjs',
    'scripts/check-raw-palette.mjs',
    'tsconfig.json',
])->group('structure');

it('routes public PHP entry points to backend CI', function (string $path): void {
    expect(ciClassifyPaths([$path]))->toBe(ciChecks(backend: true));
})->with([
    'public/index.php',
    'public/frankenphp-worker.php',
])->group('structure');

it('routes toolchain and testing environment inputs to their checks', function (string $path, array $checks): void {
    expect(ciClassifyPaths([$path]))->toBe($checks);
})->with([
    '.nvmrc' => ['.nvmrc', ciChecks(backend: true, frontend: true)],
    '.gitignore' => ['.gitignore', ciChecks(backend: true, frontend: true)],
    '.env.testing.example' => ['.env.testing.example', ciChecks(backend: true)],
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
    'Dockerfile',
    'compose.yaml',
])->group('structure');

it('routes infrastructure and server configuration to the checks that read them', function (string $path, array $checks): void {
    expect(ciClassifyPaths([$path]))->toBe($checks);
})->with([
    'Dockerfile' => ['Dockerfile', ciChecks(backend: true, docker: true, image: true)],
    '.dockerignore' => ['.dockerignore', ciChecks(docker: true, structure: true, image: true)],
    'docker/php.ini' => ['docker/php.ini', ciChecks(backend: true, docker: true, image: true)],
    'public/.htaccess' => ['public/.htaccess', ciChecks(backend: true, docker: true)],
    'compose.prod.yaml' => ['compose.prod.yaml', ciChecks(backend: true)],
    'compose.shared-services.yml' => ['compose.shared-services.yml', ciChecks(backend: true)],
    'deploy/restore-dry-run-compose.yml' => ['deploy/restore-dry-run-compose.yml', ciChecks(backend: true)],
])->group('structure');

it('routes GitHub configuration to the checks that read it', function (string $path, array $checks): void {
    expect(ciClassifyPaths([$path]))->toBe($checks);
})->with([
    '.github/labeler.yml' => ['.github/labeler.yml', ciChecks()],
    '.github/dependabot.yml' => ['.github/dependabot.yml', ciChecks()],
    '.github/pull_request_template.md' => ['.github/pull_request_template.md', ciChecks()],
    '.github/workflows/labeler.yml' => ['.github/workflows/labeler.yml', ciChecks(backend: true)],
    '.github/workflows/deploy.yml' => ['.github/workflows/deploy.yml', ciChecks(backend: true)],
    '.github/workflows/backend-ci.yml' => ['.github/workflows/backend-ci.yml', ciChecks(backend: true)],
    '.github/workflows/frontend-ci.yml' => ['.github/workflows/frontend-ci.yml', ciChecks(backend: true, frontend: true)],
    '.github/actions/setup-node/action.yml' => ['.github/actions/setup-node/action.yml', ciChecks(backend: true, frontend: true)],
])->group('structure');

it('keeps renamed-away inputs in the changed path list', function (): void {
    expect(File::get(base_path(CI_WORKFLOW)))->toContain('git diff --no-renames --name-only "$base" HEAD');
})->group('structure');

it('unions mixed changes and takes the all-checks branch for the workflow', function (): void {
    expect(ciClassifyPaths([
        'docs/decisions/dark-is-the-default-ground.md',
        'resources/js/app.tsx',
    ]))->toBe(ciChecks(frontend: true, structure: true));

    expect(ciClassifyPaths([
        'docs/decisions/dark-is-the-default-ground.md',
        'resources/js/app.tsx',
        'app/Models/User.php',
    ]))->toBe(ciChecks(backend: true, frontend: true));

    expect(ciClassifyPaths([
        'docs/decisions/dark-is-the-default-ground.md',
        'resources/js/app.tsx',
        CI_WORKFLOW,
    ]))->toBe(ciChecks(backend: true, frontend: true, docker: true, worktree: true, image: true));
})->group('structure');

it('classifies each path on its own in per-path mode, matching a single-path run', function (): void {
    $paths = [
        'docs/decisions/dark-is-the-default-ground.md',
        'resources/js/app.tsx',
        'app/Models/User.php',
        'Dockerfile',
        'scripts/worktree',
        CI_WORKFLOW,
    ];

    $classified = ciClassifyEachPath($paths);

    expect(array_keys($classified))->toBe($paths);

    foreach ($paths as $path) {
        expect($classified[$path])->toBe(ciClassifyPaths([$path]), $path);
    }
})->group('structure');

it('skips the heavy jobs for planning docs, which nothing asserts against', function (): void {
    foreach ([
        'plan/README.md',
        'docs/decisions/dark-is-the-default-ground.md',
    ] as $path) {
        expect(ciClassifyPaths([$path]))->toBe(ciChecks());
    }
})->group('structure');

it('runs the image-contents check only for the inputs that can change the image\'s entries', function (string $path, bool $image): void {
    expect(ciClassifyPaths([$path])['image'])->toBe($image);
})->with([
    'Dockerfile' => ['Dockerfile', true],
    '.dockerignore' => ['.dockerignore', true],
    'docker/php.ini' => ['docker/php.ini', true],
    'composer.json' => ['composer.json', true],
    'composer.lock' => ['composer.lock', true],
    'package.json' => ['package.json', true],
    'package-lock.json' => ['package-lock.json', true],
    'ci.yml' => ['.github/workflows/ci.yml', true],
    'app source' => ['app/Models/User.php', false],
    'frontend source' => ['resources/js/pages/Plan.tsx', false],
    'backend workflow' => ['.github/workflows/backend-ci.yml', false],
    'docs' => ['docs/architecture/deployment.md', false],
])->group('structure');
