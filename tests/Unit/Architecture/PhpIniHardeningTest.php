<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('hides the PHP version and compiles assertions out in the production php.ini', function (): void {
    $ini = (string) File::get(base_path('docker/php.ini'));

    expect($ini)->toMatch('/^expose_php\s*=\s*Off$/m')
        ->and($ini)->toMatch('/^zend\.assertions\s*=\s*-1$/m');
})->group('structure');

it('gives CLI opcache a file cache directory the runtime stage creates for www-data', function (): void {
    $ini = (string) File::get(base_path('docker/php.ini'));
    $dockerfile = (string) File::get(base_path('Dockerfile'));
    $runtimeStage = substr($dockerfile, (int) strrpos($dockerfile, 'FROM dunglas/frankenphp@'));

    expect($ini)->toMatch('/^opcache\.enable_cli\s*=\s*1$/m')
        ->and($ini)->toMatch('/^opcache\.file_cache\s*=\s*\/tmp\/opcache$/m')
        ->and($ini)->toMatch('/^opcache\.file_cache_consistency_checks\s*=\s*0$/m')
        ->and($ini)->toMatch('/^opcache\.validate_timestamps\s*=\s*0$/m')
        ->and($runtimeStage)->toMatch('/mkdir -p[^\n]*\/tmp\/opcache/')
        ->and($runtimeStage)->toMatch('/chown -R www-data:www-data(?:[^\n]|\\\\\n)*\/tmp\/opcache/');
})->group('structure');

it('fails the deploy smoke test when a response advertises X-Powered-By', function (): void {
    $steps = Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs']['deploy']['steps'];
    $smoke = collect($steps)->firstWhere('name', 'Smoke test')['run'] ?? '';

    expect($smoke)->toMatch('/grep -qi \'\^x-powered-by:\'/')
        ->toContain('::error::/login advertises X-Powered-By');
})->group('structure');
