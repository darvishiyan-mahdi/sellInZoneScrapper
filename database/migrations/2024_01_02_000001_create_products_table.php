<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->string('external_id');
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->string('status')->default('draft');
            $table->json('raw_data')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['website_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

