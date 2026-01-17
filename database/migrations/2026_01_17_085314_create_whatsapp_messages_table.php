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
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('wamid')->nullable()->index();
            $table->string('phone', 20)->index();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('type', 50)->default('text');
            $table->json('content')->nullable();
            $table->string('status', 20)->default('sent');
            $table->text('error_message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();

            // Composite indexes
            $table->index(['phone', 'created_at']);
            $table->index(['direction', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};