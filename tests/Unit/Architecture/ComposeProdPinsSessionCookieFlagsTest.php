<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('pins the session cookie to secure and same-site lax in the app environment', function (): void {
    $compose = (string) File::get(base_path('compose.prod.yaml'));

    expect($compose)->toMatch('/^  SESSION_SECURE_COOKIE: "true"$/m')
        ->and($compose)->toMatch('/^  SESSION_SAME_SITE: lax$/m');
})->group('structure');
