<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

it('leaves the viewHorizon gate open in production (edge basicauth is the sole gate)', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(Gate::allows('viewHorizon'))->toBeTrue();
});
