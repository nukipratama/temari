<?php

declare(strict_types=1);

it('renders the branded 404 page for an unknown route', function (): void {
    $this->get('/definitely-not-a-real-route')
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee('Back to Today');
});
