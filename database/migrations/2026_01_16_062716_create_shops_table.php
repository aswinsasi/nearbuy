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
        Schema::create('shops', function (Blueprint $table) {
            $table->id();

            // Owner relationship
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            // Shop details
            $table->string('shop_name');
            $table->enum('category', [
                'grocery',
                'electronics',
                'clothes',
                'medical',
                'furniture',
                'mobile',
                'appliances',
                'hardware',
                'restaurant',
                'bakery',
                'stationery',
                'beauty',
                'automotive',
                'jewelry',
                'sports',
                'other'
            ]);

            // Shop location (may differ from owner's location)
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('address')->nullable();

            // Notification preferences for product requests
            $table->string('notification_frequency', 20)->default('immediate');

            // Verification status
            $table->boolean('verified')->default(false);

            // Shop status
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes
            $table->index(['latitude', 'longitude'], 'shops_location_index');
            $table->index('category');
            $table->index('is_active');
            $table->index(['category', 'is_active']);
            $table->unique('user_id'); // One shop per user
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};