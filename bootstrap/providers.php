<?php

use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

$providers = [
    AppServiceProvider::class,
];

// Telescope is a development-only dependency. Register its application
// provider only when the package is installed so production deployments can
// safely use `composer install --no-dev`.
if (class_exists(TelescopeApplicationServiceProvider::class)) {
    $providers[] = TelescopeServiceProvider::class;
}

return $providers;
