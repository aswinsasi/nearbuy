<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create fish_sellers table - Fish seller profiles.
 *
 * @srs-ref Section 5.1.2 - fish_sellers table
 * @srs-ref Section 2.3.1 - Seller/Fisherman Registration
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fish_sellers', function (Blueprint $table) {
            $table->id();
            
            // Link to user
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Seller details
            $table->string('business_name', 200);
            $table->string('seller_type', 50)
                ->comment('fisherman, harbour_vendor, fish_shop, wholesaler');
            
            // Location
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('address', 500)->nullable();
            $table->string('market_name', 200)->nullable()
                ->comment('Harbour or market name');
            $table->string('landmark', 200)->nullable();
            
            // Contact
            $table->string('alternate_phone', 20)->nullable();
            $table->string('upi_id', 100)->nullable()
                ->comment('For payments');
            
            // Operating info
            $table->json('operating_hours')->nullable()
                ->comment('{"mon": {"open": "06:00", "close": "12:00"}, ...}');
            $table->json('catch_days')->nullable()
                ->comment('Days when fresh catch typically arrives [0-6]');
            
            // Stats & ratings
            $table->integer('total_catches')->default(0);
            $table->integer('total_sales')->default(0);
            $table->decimal('average_rating', 3, 2)->default(0.00);
            $table->integer('rating_count')->default(0);
            
            // Verification
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_doc_url', 500)->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_accepting_orders')->default(false)
                ->comment('Can receive advance orders');
            
            // Notification preferences
            $table->integer('default_alert_radius_km')->default(5)
                ->comment('Default radius for customer alerts');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->unique('user_id');
            $table->index('seller_type');
            $table->index('is_active');
            $table->index('is_verified');
            $table->index(['latitude', 'longitude']);
            
            // Spatial index for location queries (MySQL)
            $table->rawIndex(
                'latitude, longitude',
                'fish_sellers_location_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fish_sellers');
    }
};
