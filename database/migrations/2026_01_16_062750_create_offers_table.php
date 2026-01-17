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
        Schema::create('offers', function (Blueprint $table) {
            $table->id();

            // Shop relationship
            $table->foreignId('shop_id')
                ->constrained()
                ->onDelete('cascade');

            // Media content
            $table->string('media_url');
            $table->enum('media_type', ['image', 'pdf'])->default('image');

            // Offer details
            $table->string('caption', 500)->nullable();
            $table->enum('validity_type', ['today', '3days', 'week'])->default('today');

            // Expiration
            $table->timestamp('expires_at');

            // Analytics
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('location_tap_count')->default(0);

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes for querying
            $table->index(['shop_id', 'is_active']);
            $table->index(['expires_at', 'is_active']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};