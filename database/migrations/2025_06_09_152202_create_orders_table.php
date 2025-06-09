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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('user_id')->constrained();
            $table->enum('order_type', ['warehouse', 'dropshipping', 'cargo', 'pickup'])->default('cargo')->comment('Sipariş türü');
            $table->enum('delivery_type', ['standard', 'express', 'pickup'])->default('standard');
            $table->enum('status', ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'])->default('pending');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('vat_total', 10, 2);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2);
            $table->enum('payment_method', ['credit_card', 'bank_transfer', 'balance'])->comment('Ödeme yöntemi');
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');

            // Adres Bilgileri
            $table->text('shipping_address');
            $table->string('shipping_contact_name')->nullable();
            $table->string('shipping_contact_phone', 20)->nullable();
            $table->text('billing_address');
            $table->string('billing_contact_name')->nullable();
            $table->string('billing_contact_phone', 20)->nullable();
            $table->boolean('use_different_shipping')->default(false);

            // Dropshipping için
            $table->boolean('is_dropshipping')->default(false);
            $table->string('dropshipping_barcode_pdf')->nullable()->comment('Dropshipping kargo barkodu PDF yolu');

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('order_number');
            $table->index(['user_id', 'status']);
            $table->index('payment_method');
            $table->index('order_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
