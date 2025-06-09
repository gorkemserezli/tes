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
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('level', ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']);
            $table->string('channel', 50)->default('application')->comment('Log kanalı (payment, order, auth, system vb.)');
            $table->text('message');
            $table->json('context')->nullable()->comment('Ek bilgiler, hata detayları');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('exception_class')->nullable();
            $table->string('exception_file', 500)->nullable();
            $table->integer('exception_line')->nullable();
            $table->text('exception_trace')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['level', 'channel']);
            $table->index('created_at');
            $table->index('user_id');
            $table->index('exception_class');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
