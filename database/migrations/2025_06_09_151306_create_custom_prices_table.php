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
        Schema::create('custom_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('group_id')->nullable()->constrained('customer_groups')->onDelete('cascade');
            $table->decimal('price', 10, 2);
            $table->integer('min_quantity')->default(1);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['product_id', 'user_id']);
            $table->index(['product_id', 'group_id']);
            $table->index(['start_date', 'end_date']);

            // Ensure either user_id or group_id is set, but not both
            $table->check('(user_id IS NULL AND group_id IS NOT NULL) OR (user_id IS NOT NULL AND group_id IS NULL)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_prices');
    }
};
