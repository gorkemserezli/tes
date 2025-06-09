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
        Schema::create('shipping_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('shipment_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('document_type', ['barcode', 'waybill', 'invoice', 'other']);
            $table->string('file_path');
            $table->string('file_name');
            $table->integer('file_size')->comment('Byte cinsinden');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();

            // Indexes
            $table->index(['order_id', 'document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_documents');
    }
};
