<?php

use App\Enums\ScanItemDecision;
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
        Schema::create('scan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('crop_path');
            $table->json('embedding')->nullable();
            $table->unsignedInteger('facings')->nullable();
            $table->unsignedInteger('est_qty')->nullable();
            $table->unsignedInteger('shelf_row')->nullable();
            $table->unsignedInteger('shelf_order')->nullable();
            $table->json('extracted')->nullable();
            $table->foreignId('matched_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->float('match_distance')->nullable();
            $table->string('decision')->default(ScanItemDecision::Pending->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_items');
    }
};
