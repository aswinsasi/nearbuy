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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // WhatsApp phone number (unique identifier)
            $table->string('phone', 20)->unique();

            // User profile
            $table->string('name')->nullable();
            $table->enum('type', ['customer', 'shop'])->default('customer');

            // Geolocation (MySQL 8.0+ spatial)
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('address')->nullable();

            // Preferences
            $table->string('language', 5)->default('en');

            // Registration tracking
            $table->timestamp('registered_at')->nullable();

            $table->timestamps();

            // Indexes for geolocation queries
            $table->index(['latitude', 'longitude'], 'users_location_index');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};