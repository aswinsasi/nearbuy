<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for flash_deals table.
 *
 * "50% off — BUT only if 30 people claim in 30 minutes!"
 *
 * Supports:
 * - Regular Flash Deals
 * - Chain Deals (progressive tier unlocks)
 * - Surprise/Mystery Deals (hidden content until claim)
 * - Rescue Mode (extend time / bonus discount)
 *
 * @srs-ref Section 5.3.1 - flash_deals table
 * @srs-ref FD-001 to FD-028 - Flash Mob Deals Module
 * @module Flash Mob Deals
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flash_deals', function (Blueprint $table) {
            // =====================================================
            // PRIMARY KEYS & RELATIONSHIPS
            // =====================================================
            $table->id();
            $table->foreignId('shop_id')
                ->constrained('shops')
                ->onDelete('cascade');

            // =====================================================
            // CORE DEAL INFORMATION (FD-001 to FD-003)
            // =====================================================
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('image_url', 500);
            $table->string('category', 50)->nullable()
                ->comment('Deal category for filtering');

            // =====================================================
            // DISCOUNT CONFIGURATION (FD-003)
            // =====================================================
            $table->unsignedTinyInteger('discount_percent')
                ->comment('Discount percentage (5-90)');
            $table->unsignedInteger('max_discount_value')->nullable()
                ->comment('Maximum discount cap in rupees');

            // =====================================================
            // TARGET & TIME CONFIGURATION (FD-004, FD-005)
            // =====================================================
            $table->unsignedSmallInteger('target_claims')
                ->comment('Number of claims needed to activate (10/20/30/50)');
            $table->unsignedSmallInteger('time_limit_minutes')
                ->comment('Duration in minutes (15/30/60/120)');

            // =====================================================
            // SCHEDULING (FD-006, FD-007)
            // =====================================================
            $table->timestamp('starts_at')
                ->comment('When the deal goes live');
            $table->timestamp('expires_at')
                ->comment('When the deal ends (starts_at + time_limit)');
            $table->timestamp('coupon_valid_until')->nullable()
                ->comment('Coupon validity period (default: store closing)');

            // =====================================================
            // STATUS & PROGRESS
            // =====================================================
            $table->string('status', 20)->default('scheduled')
                ->comment('scheduled, live, activated, expired, cancelled');
            $table->unsignedInteger('current_claims')->default(0)
                ->comment('Current number of claims');
            $table->string('coupon_prefix', 10)->default('FLASH')
                ->comment('Prefix for generated coupon codes');

            // =====================================================
            // NOTIFICATION TRACKING
            // =====================================================
            $table->unsignedInteger('notified_customers_count')->default(0)
                ->comment('Number of customers notified when deal went live');

            // =====================================================
            // ACTIVATION & EXPIRY TIMESTAMPS
            // =====================================================
            $table->timestamp('activated_at')->nullable()
                ->comment('When target was reached and deal activated');
            $table->timestamp('expired_at')->nullable()
                ->comment('When deal expired without reaching target');

            // =====================================================
            // CHAIN DEAL FIELDS (Section 4.5.2)
            // Progressive tier unlocks: 20 people = 20%, 35 = 35%, 50 = 50%
            // =====================================================
            $table->boolean('is_chain_deal')->default(false)
                ->comment('Whether this is a multi-tier chain deal');
            $table->json('chain_tiers')->nullable()
                ->comment('JSON: {1: {claims: 20, discount: 20}, 2: {...}}');
            $table->unsignedTinyInteger('current_chain_level')->default(0)
                ->comment('Current unlocked tier level (0 = none yet)');

            // =====================================================
            // SURPRISE/MYSTERY DEAL FIELDS (Section 4.5.3)
            // Hidden content revealed only on claim
            // =====================================================
            $table->boolean('is_surprise_deal')->default(false)
                ->comment('Whether discount/product is hidden until claim');
            $table->string('hidden_title', 150)->nullable()
                ->comment('Actual title (shown after reveal)');
            $table->unsignedTinyInteger('hidden_discount')->nullable()
                ->comment('Actual discount (shown after reveal)');
            $table->string('hidden_product', 200)->nullable()
                ->comment('Product description (shown after reveal)');
            $table->string('mystery_image_url', 500)->nullable()
                ->comment('Placeholder mystery image before reveal');

            // =====================================================
            // RESCUE MODE FIELDS (Section 4.5.1)
            // Save deals at 80%+ with <5 mins remaining
            // =====================================================
            $table->boolean('rescue_extended')->default(false)
                ->comment('Whether time was extended via rescue');
            $table->timestamp('rescue_extended_at')->nullable();
            $table->unsignedTinyInteger('rescue_extension_minutes')->nullable()
                ->comment('Minutes added via rescue (default: 10)');
            $table->boolean('rescue_bonus_added')->default(false)
                ->comment('Whether bonus discount was added via rescue');
            $table->unsignedTinyInteger('rescue_bonus_percent')->nullable()
                ->comment('Bonus percentage added (default: 5)');
            $table->unsignedTinyInteger('original_discount_percent')->nullable()
                ->comment('Original discount before rescue bonus');

            // =====================================================
            // METADATA
            // =====================================================
            $table->json('metadata')->nullable()
                ->comment('Additional flexible data');
            $table->timestamps();

            // =====================================================
            // INDEXES
            // =====================================================
            $table->index('shop_id');
            $table->index('status');
            $table->index('starts_at');
            $table->index('expires_at');
            $table->index(['status', 'starts_at']);
            $table->index(['status', 'expires_at']);
            $table->index(['shop_id', 'status']);
            $table->index('is_chain_deal');
            $table->index('is_surprise_deal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flash_deals');
    }
};