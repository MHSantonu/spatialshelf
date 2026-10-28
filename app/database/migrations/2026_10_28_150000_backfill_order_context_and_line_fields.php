<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill order columns for databases migrated before the
     * create-table migrations carried them. Fresh installs already have
     * every column from the creates, so each step checks first and is a
     * no-op there. Additive only: no data is touched.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'context')) {
                $table->json('context')->nullable();
            }
        });

        Schema::table('order_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('order_lines', 'status')) {
                $table->string('status')->default('matched');
            }
            if (! Schema::hasColumn('order_lines', 'note')) {
                $table->string('note')->nullable();
            }
            if (! Schema::hasColumn('order_lines', 'alternative_ids')) {
                $table->json('alternative_ids')->nullable();
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `orders` MODIFY `customer_id` BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE `order_lines` MODIFY `product_id` BIGINT UNSIGNED NULL');
        }
    }

    /**
     * Intentionally one-way: fresh installs get these columns from the
     * create-table migrations, so dropping them here could remove columns
     * this migration never added.
     */
    public function down(): void
    {
        //
    }
};
