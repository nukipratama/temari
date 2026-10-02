<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

dataset('dev and ci service manifests', [
    'compose.yaml',
    'compose.shared-services.yml',
    '.github/workflows/backend-ci.yml',
    '.github/workflows/refresh-shards.yml',
    'tests/fixtures/restore-db-ci-compose.yml',
]);

it('pins dev and CI MySQL and Redis to the digests prod runs', function (string $manifest): void {
    preg_match('/^FROM mysql@(sha256:[0-9a-f]{64})$/m', (string) File::get(base_path('docker/mysql/Dockerfile')), $mysql);
    preg_match('/^\s*image:\s*(redis:[^@\s]+@sha256:[0-9a-f]{64})$/m', (string) File::get(base_path('compose.prod.yaml')), $redis);
    $contents = (string) File::get(base_path($manifest));
    preg_match_all('/^\s*image:\s*\'?((?:mysql|redis)[^\'\s]*)\'?\s*$/m', $contents, $images);
    preg_match_all('/^\s*((?:mysql|redis):\S+)\s*\\\\?$/m', $contents, $dockerRunImages);
    $images = [...$images[1], ...$dockerRunImages[1]];

    expect($mysql)->toHaveKey(1)
        ->and($redis)->toHaveKey(1)
        ->and($images)->not->toBeEmpty();

    foreach ($images as $image) {
        str_starts_with($image, 'mysql')
            ? expect($image)->toEndWith('@'.$mysql[1])
            : expect($image)->toBe($redis[1]);
    }
})->with('dev and ci service manifests')->group('structure');

it('keeps .nvmrc on the Node release the Dockerfile pins', function (): void {
    preg_match('/node:(\d+\.\d+\.\d+)-alpine/', (string) File::get(base_path('Dockerfile')), $pinned);

    expect($pinned)->toHaveKey(1)
        ->and(trim((string) File::get(base_path('.nvmrc'))))->toBe($pinned[1]);
})->group('structure');
