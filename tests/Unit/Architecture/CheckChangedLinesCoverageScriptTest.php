<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Exercises scripts/check-changed-lines-coverage.php against a throwaway git
 * repo: a base commit, a head commit and a Clover report whose paths come from
 * a different checkout root than the repo, as they do between CI jobs.
 *
 * @param  array<string, string>  $baseFiles  repo-relative path => contents at the base commit
 * @param  array<string, string>  $headFiles  repo-relative path => contents at the head commit
 * @param  array<string, array<int, int>>  $statements  repo-relative path => statement line => hit count
 * @return array{0: int, 1: string}
 */
function runChangedLinesGuard(array $baseFiles, array $headFiles, array $statements, float $min = 90.0): array
{
    $root = sys_get_temp_dir().'/changed-lines-guard-'.uniqid();
    File::ensureDirectoryExists($root);

    $environment = ['GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_INDEX_FILE' => false];

    $git = static function (string ...$arguments) use ($root, $environment): void {
        $process = new Process(['git', '-c', 'user.name=test', '-c', 'user.email=test@example.test', ...$arguments], $root, $environment);
        $process->mustRun();
    };

    $commit = static function (array $files, string $message) use ($root, $git): void {
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));
            File::put($root.'/'.$path, $contents);
        }

        $git('add', '-A');
        $git('commit', '-q', '--no-verify', '-m', $message);
    };

    $files = '';
    foreach ($statements as $path => $hits) {
        $files .= '<file name="/runner/workspace/'.$path.'"><line num="1" type="method" name="run" count="0"/>';
        foreach ($hits as $line => $count) {
            $files .= '<line num="'.$line.'" type="stmt" count="'.$count.'"/>';
        }
        $files .= '</file>';
    }
    File::put($root.'/clover.xml', '<?xml version="1.0"?><coverage><project>'.$files.'</project></coverage>');

    try {
        $git('init', '-q');
        $commit($baseFiles, 'base');
        $commit($headFiles, 'head');

        $process = new Process([
            PHP_BINARY,
            base_path('scripts/check-changed-lines-coverage.php'),
            '--base=HEAD~1',
            '--clover='.$root.'/clover.xml',
            '--min='.$min,
        ], $root, $environment);
        $process->run();

        return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    } finally {
        File::deleteDirectory($root);
    }
}

function numberedPhp(int $lines, array $replace = []): string
{
    $content = ["<?php\n"];
    for ($line = 2; $line <= $lines; $line++) {
        $content[] = ($replace[$line] ?? "\$value{$line} = {$line};")."\n";
    }

    return implode('', $content);
}

it('passes when every changed executable line is covered', function (): void {
    [$status, $output] = runChangedLinesGuard(
        ['app/Foo.php' => numberedPhp(6)],
        ['app/Foo.php' => numberedPhp(6, [3 => '$changed = 3;', 4 => '$changed = 4;', 5 => '$changed = 5;'])],
        ['app/Foo.php' => [2 => 0, 3 => 1, 4 => 2, 5 => 1, 6 => 0]],
    );

    expect($status)->toBe(0)
        ->and($output)->toContain('100.0% (3/3');
});

it('fails below the minimum and lists each uncovered changed line', function (): void {
    [$status, $output] = runChangedLinesGuard(
        ['app/Foo.php' => numberedPhp(6)],
        ['app/Foo.php' => numberedPhp(6, [3 => '$changed = 3;', 4 => '$changed = 4;', 5 => '$changed = 5;'])],
        ['app/Foo.php' => [3 => 1, 4 => 0, 5 => 0]],
    );

    expect($status)->toBe(1)
        ->and($output)->toContain('33.3% (1/3')
        ->and($output)->toContain('app/Foo.php:4')
        ->and($output)->toContain('app/Foo.php:5')
        ->and($output)->not->toContain('app/Foo.php:3')
        ->and($output)->toContain('below the required 90.0%');
});

it('passes exactly at the minimum', function (): void {
    $changes = collect(range(2, 11))->mapWithKeys(fn (int $line): array => [$line => "\$changed = {$line};"])->all();
    $hits = collect(range(2, 11))->mapWithKeys(fn (int $line): array => [$line => $line === 2 ? 0 : 1])->all();

    [$status, $output] = runChangedLinesGuard(
        ['app/Foo.php' => numberedPhp(11)],
        ['app/Foo.php' => numberedPhp(11, $changes)],
        ['app/Foo.php' => $hits],
    );

    expect($status)->toBe(0)
        ->and($output)->toContain('90.0% (9/10');
});

it('ignores changed lines outside app and files that are not php', function (): void {
    [$status, $output] = runChangedLinesGuard(
        ['app/Foo.php' => numberedPhp(4), 'routes/web.php' => numberedPhp(4), 'app/notes.md' => "old\n"],
        ['app/Foo.php' => numberedPhp(4, [3 => '$changed = 3;']), 'routes/web.php' => numberedPhp(4, [3 => '$changed = 3;']), 'app/notes.md' => "new\n"],
        ['app/Foo.php' => [3 => 1], 'routes/web.php' => [3 => 0], 'app/notes.md' => [1 => 0]],
    );

    expect($status)->toBe(0)
        ->and($output)->toContain('100.0% (1/1');
});

it('ignores changed lines the Clover does not list as statements and untouched uncovered statements', function (): void {
    [$status, $output] = runChangedLinesGuard(
        ['app/Foo.php' => numberedPhp(5)],
        ['app/Foo.php' => numberedPhp(5, [3 => '// a new comment', 4 => '$changed = 4;'])],
        ['app/Foo.php' => [2 => 0, 4 => 1, 5 => 0]],
    );

    expect($status)->toBe(0)
        ->and($output)->toContain('100.0% (1/1');
});

it('passes when the diff has no changed executable lines', function (): void {
    [$status, $output] = runChangedLinesGuard(
        ['app/Foo.php' => numberedPhp(3), 'docs/readme.md' => "old\n"],
        ['app/Foo.php' => numberedPhp(3), 'docs/readme.md' => "new\n"],
        ['app/Foo.php' => [2 => 0, 3 => 0]],
    );

    expect($status)->toBe(0)
        ->and($output)->toContain('100.0% (0/0');
});

it('counts every line of a newly added file', function (): void {
    [$status, $output] = runChangedLinesGuard(
        ['docs/readme.md' => "old\n"],
        ['docs/readme.md' => "old\n", 'app/Bar.php' => numberedPhp(4)],
        ['app/Bar.php' => [2 => 1, 3 => 0, 4 => 1]],
    );

    expect($status)->toBe(1)
        ->and($output)->toContain('66.7% (2/3')
        ->and($output)->toContain('app/Bar.php:3');
});

it('exits with a usage error when an option is missing', function (): void {
    $process = new Process([PHP_BINARY, base_path('scripts/check-changed-lines-coverage.php'), '--base=HEAD']);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage:');
});

it('runs the changed-lines check in the ci-gate merge step with full git history', function (): void {
    $steps = collect(Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs']['ci-gate']['steps']);
    $checkout = $steps->first(fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/checkout@'));

    expect($checkout['with']['fetch-depth'])->toBe(0)
        ->and($steps->firstWhere('id', 'merge')['run'])->toContain('scripts/check-changed-lines-coverage.php')
        ->toContain('--min=90');
});
