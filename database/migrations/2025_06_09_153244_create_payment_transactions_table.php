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
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('transaction_id', 100)->unique()->nullable();
            $table->enum('payment_method', ['credit_card', 'bank_transfer', 'balance']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('TRY');
            $table->enum('status', ['pending', 'success', 'failed', 'cancelled'])->default('pending');

            // Kredi kartı için
            $table->string('card_holder_name')->nullable();
            $table->string('masked_card_number', 20)->nullable();

            // Havale için
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_receipt')->nullable()->comment('Dekont dosya yolu');

            $table->text('gateway_response')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('transaction_id');
            $table->index(['order_id', 'status']);
            $table->index('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
