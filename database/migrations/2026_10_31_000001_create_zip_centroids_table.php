<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Centre point of every US ZIP code (Census ZCTA gazetteer). Filled by `php artisan geo:import-zip-centroids`.
        Schema::create('zip_centroids', function (Blueprint $table) {
            $table->char('zip', 5)->primary();
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zip_centroids');
    }
};
