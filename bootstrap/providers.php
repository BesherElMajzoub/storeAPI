<?php

use App\Providers\AppServiceProvider;
$providers = [
    AppServiceProvider::class,
];

// Telescope is a development-only dependency. Register its application
// provider only when the package is installed so production deployments can
// safely use `composer install --no-dev`.
if (class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)) {
    $providers[] = App\Providers\TelescopeServiceProvider::class;
}

return $providers;
