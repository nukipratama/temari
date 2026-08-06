<?php

declare(strict_types=1);

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
