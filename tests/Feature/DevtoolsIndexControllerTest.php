<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['devtools.password' => 'secret']);
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

it('challenges /horizon and /pulse the same way', function (): void {
    $this->get('/horizon')->assertUnauthorized();
    $this->get('/pulse')->assertUnauthorized();
});

it('lets /horizon and /pulse through with the correct devtools password', function (): void {
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/horizon')
        ->assertSuccessful();

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')])
        ->get('/pulse')
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
