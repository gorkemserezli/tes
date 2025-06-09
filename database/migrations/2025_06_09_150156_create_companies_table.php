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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('company_name');
            $table->string('tax_number', 20)->unique();
            $table->string('tax_office');
            $table->text('address');
            $table->string('city', 100);
            $table->string('district', 100);
            $table->string('postal_code', 10)->nullable();
            $table->boolean('is_approved')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->decimal('credit_limit', 10, 2)->default(0);
            $table->decimal('remaining_credit', 10, 2)->default(0);
            $table->decimal('balance', 10, 2)->default(0)->comment('Cari bakiye');
            $table->timestamps();

            // Indexes
            $table->index('tax_number');
            $table->index('is_approved');
            $table->index('balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
