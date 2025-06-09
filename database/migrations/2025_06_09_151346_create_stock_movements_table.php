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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['in', 'out', 'adjustment', 'return', 'reserved', 'cancelled']);
            $table->integer('quantity')->comment('Pozitif veya negatif olabilir');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->string('reference_type', 50)->nullable()->comment('orders, returns, manual vb.');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->decimal('unit_cost', 10, 2)->nullable()->comment('Maliyet takibi için');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['product_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
