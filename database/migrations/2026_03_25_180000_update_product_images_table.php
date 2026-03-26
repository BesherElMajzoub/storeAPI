<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            // Drop the old url column and replace with file-based columns
            $table->dropColumn('url');

            $table->string('path')->nullable()->after('product_id');           // e.g. product-images/abc123.jpg
            $table->string('mime_type', 100)->nullable()->after('path');      // e.g. image/jpeg
            $table->string('original_name')->nullable()->after('mime_type');  // original filename from upload
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['path', 'mime_type', 'original_name']);
            $table->string('url')->after('product_id');
        });
    }
};
