<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('keeps token fields out of the flashed input after a validation failure', function (string $field): void {
    Route::middleware('web')->post('/__flash-probe', function (): void {
        request()->validate(['required_field' => ['required']]);
    });

    $this->from('/somewhere')
        ->post('/__flash-probe', [$field => 'secret-token', 'visible' => 'kept'])
        ->assertSessionHasErrors('required_field')
        ->assertSessionHasInput('visible', 'kept')
        ->assertSessionMissing("_old_input.{$field}");
})->with(['api_key', 'access_token', 'refresh_token']);
