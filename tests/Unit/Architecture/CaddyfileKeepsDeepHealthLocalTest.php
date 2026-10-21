<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('answers 404 to a tunnelled /up before the deep health check runs, leaving local probes alone', function (): void {
    $caddyfile = (string) File::get(base_path('docker/Caddyfile'));

    $matcher = strpos($caddyfile, '@tunnelledHealth {');
    $server = strpos($caddyfile, "\t\tphp_server");

    expect($caddyfile)->toContain("@tunnelledHealth {\n\t\tpath /up\n\t\theader Cf-Connecting-Ip *\n\t}\n\thandle @tunnelledHealth {\n\t\trespond 404\n\t}")
        ->and($matcher)->toBeInt()
        ->and($server)->toBeInt();
    assert(is_int($matcher) && is_int($server));
    expect($matcher)->toBeLessThan($server);
})->group('structure');
