<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspired_leads', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 30)->unique();
            $table->string('name')->nullable();
            $table->string('source')->default('stay_inspired');
            $table->string('status')->default('new'); // new, contacted, converted, closed
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspired_leads');
    }
};
