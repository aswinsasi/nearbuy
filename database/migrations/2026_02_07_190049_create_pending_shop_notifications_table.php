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
        Schema::create('pending_shop_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->onDelete('cascade');
            $table->foreignId('request_id')->constrained('product_requests')->onDelete('cascade');
            $table->decimal('distance_km', 8, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();

            // Prevent duplicate notifications
            $table->unique(['shop_id', 'request_id']);

            // Index for batch processing
            $table->index(['shop_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_shop_notifications');
    }
};