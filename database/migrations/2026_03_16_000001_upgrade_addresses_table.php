<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade the addresses table to support the full user address management system.
     * 
     * The existing table has: type, name, phone, line1, line2, city, state, postal_code, country, is_default
     * We're adding: label (home/work/other), full_name, area, street, building, floor, apartment, notes
     * and renaming/aliasing the old fields while keeping backward compatibility.
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            // Add new fields required for the full address management system
            $table->enum('label', ['home', 'work', 'other'])->default('home')->after('user_id');
            $table->string('full_name')->nullable()->after('label');
            $table->string('area')->nullable()->after('city');
            $table->string('street')->nullable()->after('area');
            $table->string('building')->nullable()->after('street');
            $table->string('floor')->nullable()->after('building');
            $table->string('apartment')->nullable()->after('floor');
            $table->text('notes')->nullable()->after('apartment');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['label', 'full_name', 'area', 'street', 'building', 'floor', 'apartment', 'notes']);
        });
    }
};
