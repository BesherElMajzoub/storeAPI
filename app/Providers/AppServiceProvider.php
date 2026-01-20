<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::define('admin-access', function ($user) {
            // Logic: User must have a role that is NOT just 'User'
            // Or strictly: Admin, Owner, Manager, Support
            // Assuming 'User' is the default customer role.
            // Since we implemented hasRole in User model:
            return $user->roles()->whereIn('name', ['Admin', 'Owner', 'Manager', 'Support'])->exists();
        });
    }
}
