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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('carrier', 50)->default('aras');
            $table->string('tracking_number', 100)->unique()->nullable();
            $table->string('status', 50)->nullable();
            $table->text('status_description')->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->decimal('desi', 10, 3)->nullable();
            $table->string('barcode_pdf')->nullable()->comment('Kargo barkodu PDF');
            $table->boolean('is_dropshipping')->default(false);
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_signature')->nullable();
            $table->timestamp('last_update_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('tracking_number');
            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
