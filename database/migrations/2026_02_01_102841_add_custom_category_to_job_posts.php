<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add missing columns to job_posts table for custom category support and cancellation tracking.
 * 
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_posts', function (Blueprint $table) {
            // Add custom_category_text for "Other" category option
            if (!Schema::hasColumn('job_posts', 'custom_category_text')) {
                $table->string('custom_category_text', 100)->nullable()
                    ->after('job_category_id')
                    ->comment('Custom job type when "Other" category is selected');
            }
            
            // Add cancelled_at timestamp
            if (!Schema::hasColumn('job_posts', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()
                    ->after('completed_at');
            }
            
            // Add cancellation_reason
            if (!Schema::hasColumn('job_posts', 'cancellation_reason')) {
                $table->string('cancellation_reason', 500)->nullable()
                    ->after('cancelled_at')
                    ->comment('Reason for job cancellation');
            }
        });
        
        // Make job_category_id nullable for custom categories
        // Note: We need to drop the foreign key first, modify the column, then recreate
        try {
            Schema::table('job_posts', function (Blueprint $table) {
                $table->dropForeign(['job_category_id']);
            });
        } catch (\Exception $e) {
            // Foreign key might not exist with expected name
        }
        
        // Modify column to be nullable
        DB::statement('ALTER TABLE job_posts MODIFY job_category_id BIGINT UNSIGNED NULL');
        
        // Recreate foreign key
        Schema::table('job_posts', function (Blueprint $table) {
            $table->foreign('job_category_id')
                ->references('id')
                ->on('job_categories')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_posts', function (Blueprint $table) {
            if (Schema::hasColumn('job_posts', 'custom_category_text')) {
                $table->dropColumn('custom_category_text');
            }
            
            if (Schema::hasColumn('job_posts', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
            
            if (Schema::hasColumn('job_posts', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
        });
    }
};