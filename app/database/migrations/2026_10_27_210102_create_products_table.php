<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->decimal('size_value', 10, 2)->nullable();
            $table->string('size_unit')->nullable();
            $table->string('category')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('stock_qty')->default(0);
            $table->boolean('stock_is_estimate')->default(true);
            $table->unsignedInteger('shelf_row')->nullable();
            $table->unsignedInteger('shelf_order')->nullable();
            $table->json('aliases')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
