<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_wordpress_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('wordpress_product_id');
            $table->string('wordpress_site_url');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->json('last_sync_payload')->nullable();
            $table->timestamps();

            $table->unique('product_id');
            $table->index('wordpress_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_wordpress_mappings');
    }
};

