<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

it('always allows the viewHorizon gate — real enforcement is upstream in EnsureDevtoolsAccess', function (): void {
    expect(Gate::allows('viewHorizon'))->toBeTrue();
});
