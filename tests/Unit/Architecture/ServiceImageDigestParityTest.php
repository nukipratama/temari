<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

dataset('dev and ci service manifests', [
    'compose.yaml',
    'compose.shared-services.yml',
    '.github/workflows/ci-backend.yml',
    '.github/workflows/upkeep-refresh-shards.yml',
    '.github/actions/start-redis/action.yml',
]);

it('pins dev and CI MySQL and Redis to the digests prod runs', function (string $manifest): void {
    preg_match('/^FROM mysql(?::[^@\s]+)?@(sha256:[0-9a-f]{64})$/m', (string) File::get(base_path('docker/mysql/Dockerfile')), $mysql);
    preg_match('/^\s*image:\s*(redis:[^@\s]+@sha256:[0-9a-f]{64})$/m', (string) File::get(base_path('compose.prod.yaml')), $redis);
    $contents = (string) File::get(base_path($manifest));
    preg_match_all('/^\s*image:\s*\'?((?:mysql|redis)[^\'\s]*)\'?\s*$/m', $contents, $images);
    preg_match_all('/^\s*((?:mysql|redis):\S+)\s*\\\\?$/m', $contents, $dockerRunImages);
    $images = [...$images[1], ...$dockerRunImages[1]];

    expect($mysql)->toHaveKey(1)
        ->and($redis)->toHaveKey(1)
        ->and($images)->not->toBeEmpty();

    $mysqlDigest = $mysql[1] ?? throw new InvalidArgumentException('The mysql Dockerfile pins no digest.');
    $redisImage = $redis[1] ?? throw new InvalidArgumentException('compose.prod.yaml pins no redis image.');

    foreach ($images as $image) {
        str_starts_with($image, 'mysql')
            ? expect($image)->toEndWith('@'.$mysqlDigest)
            : expect($image)->toBe($redisImage);
    }
})->with('dev and ci service manifests')->group('structure');

it('keeps .nvmrc on the Node release the Dockerfile pins', function (): void {
    preg_match('/^FROM node:(\d+\.\d+\.\d+)-alpine@/m', (string) File::get(base_path('Dockerfile')), $pinned);

    expect($pinned)->toHaveKey(1)
        ->and(trim((string) File::get(base_path('.nvmrc'))))->toBe($pinned[1] ?? throw new InvalidArgumentException('The Dockerfile pins no Node release.'));
})->group('structure');

it('runs every workflow on one PHP minor that matches the Dockerfile header patch', function (): void {
    $versions = collect(['ci.yml', 'ci-backend.yml', 'supply-audit.yml', 'upkeep-refresh-shards.yml'])
        ->mapWithKeys(fn (string $workflow): array => [$workflow => data_get(Yaml::parseFile(base_path(".github/workflows/{$workflow}")), 'env.PHP_VERSION')]);
    $version = $versions->first();
    preg_match('/^# = .*\(PHP (\d+\.\d+\.\d+)\)/m', (string) File::get(base_path('Dockerfile')), $header);

    expect($versions->unique()->all())->toHaveCount(1)
        ->and($header)->toHaveKey(1)
        ->and(implode('.', array_slice(explode('.', $header[1] ?? throw new InvalidArgumentException('The Dockerfile header names no PHP version.')), 0, 2)))->toBe(is_string($version) ? $version : throw new InvalidArgumentException('PHP_VERSION is not a string.'));
})->group('structure');
