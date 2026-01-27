<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create job_verifications table - Execution tracking for jobs.
 *
 * @srs-ref Section 3.5 - Job Verification & Completion
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_verifications', function (Blueprint $table) {
            $table->id();
            
            // References
            $table->foreignId('job_post_id')->constrained()->onDelete('cascade');
            $table->foreignId('worker_id')->constrained('job_workers')->onDelete('cascade');
            
            // Arrival verification
            $table->string('arrival_photo_url', 500)->nullable()
                ->comment('Photo proof of arrival');
            $table->timestamp('arrival_verified_at')->nullable();
            $table->decimal('arrival_latitude', 10, 8)->nullable();
            $table->decimal('arrival_longitude', 11, 8)->nullable();
            
            // Completion verification
            $table->string('completion_photo_url', 500)->nullable()
                ->comment('Photo proof of completion');
            $table->timestamp('completion_verified_at')->nullable();
            
            // Confirmation from both parties
            $table->timestamp('worker_confirmed_at')->nullable()
                ->comment('Worker confirms job done');
            $table->timestamp('poster_confirmed_at')->nullable()
                ->comment('Poster confirms satisfaction');
            
            // Payment tracking
            $table->string('payment_method', 20)->nullable()
                ->comment('cash, upi, other');
            $table->timestamp('payment_confirmed_at')->nullable();
            $table->string('payment_reference', 100)->nullable()
                ->comment('UPI transaction ID or reference');
            
            // Rating (poster rates worker)
            $table->unsignedTinyInteger('rating')->nullable()
                ->comment('1-5 stars');
            $table->text('rating_comment')->nullable();
            $table->timestamp('rated_at')->nullable();
            
            // Worker feedback (optional)
            $table->unsignedTinyInteger('worker_rating')->nullable()
                ->comment('Worker rates poster 1-5');
            $table->text('worker_feedback')->nullable();
            
            // Dispute handling
            $table->boolean('has_dispute')->default(false);
            $table->text('dispute_reason')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->string('dispute_resolution', 50)->nullable()
                ->comment('resolved_for_worker, resolved_for_poster, cancelled');
            $table->timestamp('resolved_at')->nullable();
            
            $table->timestamps();
            
            // Unique constraint - one verification per job
            $table->unique('job_post_id');
            
            // Indexes
            $table->index('worker_id');
            $table->index('payment_method');
            $table->index('has_dispute');
            $table->index('arrival_verified_at');
            $table->index('completion_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_verifications');
    }
};