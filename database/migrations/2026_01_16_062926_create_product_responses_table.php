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
        Schema::create('product_responses', function (Blueprint $table) {
            $table->id();

            // Relationships
            $table->foreignId('request_id')
                ->constrained('product_requests')
                ->onDelete('cascade');

            $table->foreignId('shop_id')
                ->constrained()
                ->onDelete('cascade');

            // Response content
            $table->string('photo_url')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->text('description')->nullable();

            // Availability status
            $table->boolean('is_available')->default(true);

            // Timestamps
            $table->timestamp('responded_at')->useCurrent();
            $table->timestamps();

            // Indexes
            $table->index('request_id');
            $table->index('shop_id');
            $table->unique(['request_id', 'shop_id']); // One response per shop per request
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_responses');
    }
};