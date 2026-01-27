<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create worker_badges table - Gamification badges for workers.
 *
 * @srs-ref Section 3.6 - Worker Gamification
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('worker_badges', function (Blueprint $table) {
            $table->id();
            
            // Reference
            $table->foreignId('worker_id')->constrained('job_workers')->onDelete('cascade');
            
            // Badge info
            $table->string('badge_type', 50)
                ->comment('queue_master, speed_runner, helpful_hand, early_bird, five_star, top_earner, first_job, ten_jobs, fifty_jobs, hundred_jobs');
            
            // Badge metadata
            $table->string('badge_name', 100)->nullable()
                ->comment('Display name in Malayalam/English');
            $table->string('badge_icon', 10)->default('🏅')
                ->comment('Emoji for badge');
            
            // Achievement data
            $table->json('achievement_data')->nullable()
                ->comment('Metadata about how badge was earned');
            
            // Timing
            $table->timestamp('earned_at')->useCurrent();
            
            $table->timestamps();
            
            // Unique constraint - one badge type per worker
            $table->unique(['worker_id', 'badge_type'], 'worker_badges_unique');
            
            // Indexes
            $table->index('worker_id');
            $table->index('badge_type');
            $table->index('earned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_badges');
    }
};