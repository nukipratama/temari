<?php

$providers = [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
];

// Telescope is a dev dependency and is not installed in prod (composer
// install --no-dev). Register its provider only when the package's parent
// class is present.
if (class_exists(Laravel\Telescope\TelescopeApplicationServiceProvider::class)) {
    $providers[] = App\Providers\TelescopeServiceProvider::class;
}

return $providers;
