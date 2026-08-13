<?php

declare(strict_types=1);

it('returns the landing page for the guest homepage', function (): void {
    $response = $this->get('/');

    $response->assertSuccessful();
});
