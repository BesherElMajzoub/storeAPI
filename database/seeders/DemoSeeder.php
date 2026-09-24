<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

abstract class DemoSeeder extends Seeder
{
    protected function guardAgainstProduction(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(static::class.' is demo-only and cannot run in production.');
        }
    }
}
