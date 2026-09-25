<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('serializes worktree slot lifecycle races', function (): void {
    $process = new Process(
        [base_path('tests/scripts/worktree-races.sh')],
        base_path(),
        ['GIT_DIR' => false, 'GIT_WORK_TREE' => false],
    );
    $process->setTimeout(30);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    foreach ([
        'PASS: two stale adopters serialize cleanup and claim separate slots',
        'PASS: prune and create serialize stale cleanup and ownership transfer',
        'PASS: interrupted reclaim retries and leaves live owners untouched',
        'PASS: remove holds the slot through Git removal and preserves safety refusals',
    ] as $message) {
        expect($process->getOutput())->toContain($message);
    }
})->group('structure');
