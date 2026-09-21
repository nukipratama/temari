<?php

declare(strict_types=1);

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('answers readiness without running the dependency health probes', function (): void {
    Event::fake([DiagnosingHealth::class]);

    $this->getJson('/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ready']);

    Event::assertNotDispatched(DiagnosingHealth::class);
});

it('stays reachable while maintenance is active', function (): void {
    app()->maintenanceMode()->activate([]);

    $this->getJson('/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ready']);
});
