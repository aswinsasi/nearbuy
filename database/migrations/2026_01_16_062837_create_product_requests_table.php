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
        Schema::create('product_requests', function (Blueprint $table) {
            $table->id();

            // Requester
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            // Request identifier (format: NB-XXXX)
            $table->string('request_number', 20)->unique();

            // Request details
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
            ])->nullable();

            $table->text('description');
            $table->string('image_url')->nullable();

            // Search location
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->unsignedTinyInteger('radius_km')->default(5);

            // Status tracking
            $table->enum('status', [
                'open',
                'collecting',
                'closed',
                'expired'
            ])->default('open');

            // Expiration
            $table->timestamp('expires_at');

            // Response tracking
            $table->unsignedInteger('shops_notified')->default(0);
            $table->unsignedInteger('response_count')->default(0);

            $table->timestamps();

            // Indexes
            $table->index(['latitude', 'longitude'], 'requests_location_index');
            $table->index(['status', 'expires_at']);
            $table->index('category');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_requests');
    }
};