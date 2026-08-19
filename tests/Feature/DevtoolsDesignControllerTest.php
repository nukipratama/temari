<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['devtools.password' => 'secret']);
});

it('renders the design token page with the correct devtools password', function (): void {
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/devtools/design')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Devtools/Design'));
});

it('challenges a request with no devtools password', function (): void {
    $this->get('/devtools/design')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Devtools"');
});
