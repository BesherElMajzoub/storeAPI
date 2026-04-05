<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('wishlists', 'wishlist_items');
    }

    public function down(): void
    {
        Schema::rename('wishlist_items', 'wishlists');
    }
};
