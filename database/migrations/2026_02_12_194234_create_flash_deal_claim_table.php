<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for flash_deal_claims table.
 *
 * Tracks individual customer claims on flash deals.
 * Each claim gets a position number and unique coupon code upon activation.
 *
 * @srs-ref FD-014 (claim + position), FD-020 (unique coupon)
 * @srs-ref Section 4.6 - Reciprocity tracking (referrals)
 * @module Flash Mob Deals
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flash_deal_claims', function (Blueprint $table) {
            // =====================================================
            // PRIMARY KEYS & RELATIONSHIPS
            // =====================================================
            $table->id();
            $table->foreignId('flash_deal_id')
                ->constrained('flash_deals')
                ->onDelete('cascade');
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // =====================================================
            // CLAIM POSITION (FD-014)
            // "You're #13!"
            // =====================================================
            $table->unsignedInteger('position')
                ->comment('Claim position (1-based, order of claiming)');

            // =====================================================
            // COUPON CODE (FD-020)
            // Unique code: FLASH-XXXXXX
            // =====================================================
            $table->string('coupon_code', 20)->nullable()
                ->comment('Unique coupon code (generated on activation)');
            $table->boolean('coupon_redeemed')->default(false)
                ->comment('Whether coupon has been used at shop');
            $table->timestamp('redeemed_at')->nullable()
                ->comment('When coupon was redeemed');

            // =====================================================
            // REFERRAL TRACKING (Section 4.6 - Reciprocity)
            // Track who invited whom for viral loop
            // =====================================================
            $table->foreignId('referred_by_user_id')->nullable()
                ->constrained('users')
                ->onDelete('set null')
                ->comment('User who shared the deal link');

            // =====================================================
            // MILESTONE NOTIFICATIONS (FD-016)
            // Track which progress updates were sent (25/50/75/90%)
            // =====================================================
            $table->json('milestone_notifications_sent')->nullable()
    ->comment('Array of milestone percentages already notified');

            // =====================================================
            // CLAIM METADATA
            // =====================================================
            $table->string('claim_source', 30)->nullable()
                ->comment('How user found deal: notification, share, surprise_reveal');
            $table->timestamp('claimed_at')
                ->comment('When the claim was made');

            // =====================================================
            // CHAIN DEAL TRACKING
            // =====================================================
            $table->unsignedTinyInteger('claimed_at_level')->nullable()
                ->comment('Chain deal level when claim was made');
            $table->unsignedTinyInteger('claimed_discount_percent')->nullable()
                ->comment('Discount percentage at time of claim');

            // =====================================================
            // TIMESTAMPS
            // =====================================================
            $table->timestamps();

            // =====================================================
            // INDEXES & CONSTRAINTS
            // =====================================================
            // Unique constraint: user can only claim once per deal
            $table->unique(['flash_deal_id', 'user_id'], 'unique_user_deal_claim');

            // Unique coupon codes
            $table->unique('coupon_code');

            // Common query indexes
            $table->index('flash_deal_id');
            $table->index('user_id');
            $table->index('coupon_code');
            $table->index('referred_by_user_id');
            $table->index('claimed_at');
            $table->index(['flash_deal_id', 'position']);
            $table->index(['flash_deal_id', 'claimed_at']);
            $table->index('coupon_redeemed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flash_deal_claims');
    }
};