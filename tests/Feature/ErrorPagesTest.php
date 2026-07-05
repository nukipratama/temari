<?php

declare(strict_types=1);

it('renders the branded, Indonesian 404 page for an unknown route', function (): void {
    $this->get('/definitely-not-a-real-route')
        ->assertNotFound()
        ->assertSee('Halamannya gak ketemu')
        ->assertSee('Kembali ke Hari Ini');
});
