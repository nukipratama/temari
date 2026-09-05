<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['devtools.password' => 'secret']);
    app()->detectEnvironment(fn (): string => 'production');
});

it('is reachable with the correct devtools password', function (): void {
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/devtools')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Devtools'));
});

it('challenges a request with no devtools password', function (): void {
    $this->get('/devtools')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Devtools"');
});

it('challenges the vendor dashboards the same way', function (): void {
    $this->get('/devtools/horizon')->assertUnauthorized();
    $this->get('/devtools/pulse')->assertUnauthorized();
});

it('lets the vendor dashboards through with the correct devtools password', function (): void {
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/devtools/horizon')
        ->assertSuccessful();

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/devtools/pulse')
        ->assertSuccessful();
});

it('throttles repeated wrong-password guesses at 60 per minute', function (): void {
    foreach (range(1, 60) as $i) {
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode("devtools:wrong-{$i}")])
            ->get('/devtools')
            ->assertUnauthorized();
    }

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/devtools')
        ->assertStatus(429);
});

it('needs no password at all outside production', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config(['devtools.password' => null]);

    $this->get('/devtools')->assertSuccessful();
    $this->get('/devtools/pulse')->assertSuccessful();
});
