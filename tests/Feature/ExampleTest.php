<?php

declare(strict_types=1);

it('returns a redirect for the guest homepage', function (): void {
    $response = $this->get('/');

    $response->assertRedirect();
});
