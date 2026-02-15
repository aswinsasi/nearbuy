<?php

declare(strict_types=1);

namespace App\Services\FlashDeals;

use App\Enums\FlashDealStatus;
use App\Enums\FlashDealStep;
use App\Models\FlashDeal;
use App\Models\FlashDealClaim;
use App\Models\Shop;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FlashDealMessages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service for Flash Mob Deals business logic.
 *
 * "50% off — BUT only if 30 people claim in 30 minutes!"
 *
 * @srs-ref FD-001 to FD-028 - Flash Mob Deals Module
 * @module Flash Mob Deals
 */
class FlashDealService
{
    /**
     * Default radius for customer notifications (in km).
     */
    protected const DEFAULT_NOTIFICATION_RADIUS_KM = 3;

    /**
     * Coupon code prefix.
     */
    protected const COUPON_PREFIX = 'FLASH';

    public function __construct(
        protected WhatsAppService $whatsApp
    ) {}

    /**
     * Validate input for a specific step.
     *
     * @srs-ref FD-001 to FD-006
     */
    public function validateStepInput(FlashDealStep $step, mixed $input): array
    {
        return match ($step) {
            FlashDealStep::ASK_TITLE => $this->validateTitle($input),
            FlashDealStep::ASK_IMAGE => $this->validateImage($input),
            FlashDealStep::ASK_DISCOUNT => $this->validateDiscount($input),
            FlashDealStep::ASK_DISCOUNT_CAP => $this->validateDiscountCap($input),
            FlashDealStep::ASK_TARGET => $this->validateTarget($input),
            FlashDealStep::ASK_TIME_LIMIT => $this->validateTimeLimit($input),
            FlashDealStep::ASK_SCHEDULE => $this->validateSchedule($input),
            FlashDealStep::ASK_CUSTOM_TIME => $this->validateCustomTime($input),
            default => ['valid' => true, 'value' => $input],
        };
    }

    /**
     * Validate deal title.
     *
     * @srs-ref FD-001
     */
    protected function validateTitle(string $input): array
    {
        $title = trim($input);

        if (strlen($title) < 5) {
            return [
                'valid' => false,
                'error' => 'Title too short. Minimum 5 characters.',
            ];
        }

        if (strlen($title) > 100) {
            return [
                'valid' => false,
                'error' => 'Title too long. Maximum 100 characters.',
            ];
        }

        return ['valid' => true, 'value' => $title];
    }

    /**
     * Validate image upload.
     *
     * @srs-ref FD-002
     */
    protected function validateImage(array $imageData): array
    {
        if (empty($imageData['id']) && empty($imageData['url'])) {
            return [
                'valid' => false,
                'error' => 'Please send a valid image.',
            ];
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (isset($imageData['mime_type']) && !in_array($imageData['mime_type'], $allowedTypes)) {
            return [
                'valid' => false,
                'error' => 'Invalid image type. Please send JPG, PNG, or WebP.',
            ];
        }

        return ['valid' => true, 'value' => $imageData];
    }

    /**
     * Validate discount percentage.
     *
     * @srs-ref FD-003
     */
    protected function validateDiscount(string $input): array
    {
        $discount = filter_var(trim($input), FILTER_VALIDATE_INT);

        if ($discount === false) {
            return [
                'valid' => false,
                'error' => 'Please enter a valid number.',
            ];
        }

        if ($discount < 5 || $discount > 90) {
            return [
                'valid' => false,
                'error' => 'Discount must be between 5% and 90%.',
            ];
        }

        return ['valid' => true, 'value' => $discount];
    }

    /**
     * Validate discount cap.
     *
     * @srs-ref FD-003
     */
    protected function validateDiscountCap(string $input): array
    {
        $input = trim($input);

        // Allow "no cap" responses
        if (in_array(strtolower($input), ['0', 'no', 'no cap', 'none', 'skip'])) {
            return ['valid' => true, 'value' => null];
        }

        $cap = filter_var($input, FILTER_VALIDATE_INT);

        if ($cap === false) {
            return [
                'valid' => false,
                'error' => 'Please enter a valid amount or 0 for no cap.',
            ];
        }

        if ($cap !== 0 && ($cap < 50 || $cap > 10000)) {
            return [
                'valid' => false,
                'error' => 'Cap must be between ₹50 and ₹10,000, or 0 for no cap.',
            ];
        }

        return ['valid' => true, 'value' => $cap === 0 ? null : $cap];
    }

    /**
     * Validate target claims.
     *
     * @srs-ref FD-004
     */
    protected function validateTarget(string $input): array
    {
        // Extract number from button ID like "target_30"
        $target = (int) preg_replace('/[^0-9]/', '', $input);

        if (!in_array($target, [10, 20, 30, 50])) {
            return [
                'valid' => false,
                'error' => 'Please select 10, 20, 30, or 50 people.',
            ];
        }

        return ['valid' => true, 'value' => $target];
    }

    /**
     * Validate time limit.
     *
     * @srs-ref FD-005
     */
    protected function validateTimeLimit(string $input): array
    {
        // Extract number from button ID like "time_30"
        $minutes = (int) preg_replace('/[^0-9]/', '', $input);

        if (!in_array($minutes, [15, 30, 60, 120])) {
            return [
                'valid' => false,
                'error' => 'Please select 15 mins, 30 mins, 1 hour, or 2 hours.',
            ];
        }

        return ['valid' => true, 'value' => $minutes];
    }

    /**
     * Validate schedule selection.
     *
     * @srs-ref FD-006
     */
    protected function validateSchedule(string $input): array
    {
        $scheduleMap = [
            'schedule_now' => 'now',
            'schedule_6pm' => 'today_6pm',
            'schedule_10am' => 'tomorrow_10am',
            'schedule_custom' => 'custom',
        ];

        $schedule = $scheduleMap[$input] ?? $input;

        if (!in_array($schedule, ['now', 'today_6pm', 'tomorrow_10am', 'custom'])) {
            return [
                'valid' => false,
                'error' => 'Please select a valid schedule option.',
            ];
        }

        return ['valid' => true, 'value' => $schedule];
    }

    /**
     * Validate custom time input.
     *
     * @srs-ref FD-006
     */
    protected function validateCustomTime(string $input): array
    {
        $input = trim($input);

        // Try multiple date formats
        $formats = [
            'd/m/Y H:i',
            'd-m-Y H:i',
            'Y-m-d H:i',
            'd/m/Y h:i A',
            'd M Y H:i',
        ];

        $date = null;
        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $input);
                if ($date) {
                    break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if (!$date) {
            return [
                'valid' => false,
                'error' => 'Invalid date format. Please use DD/MM/YYYY HH:MM',
            ];
        }

        if ($date->isPast()) {
            return [
                'valid' => false,
                'error' => 'Please select a future date and time.',
            ];
        }

        if ($date->diffInDays(now()) > 7) {
            return [
                'valid' => false,
                'error' => 'Schedule must be within the next 7 days.',
            ];
        }

        return ['valid' => true, 'value' => $date->toDateTimeString()];
    }

    /**
     * Calculate the scheduled start time.
     *
     * @srs-ref FD-006
     */
    public function calculateStartTime(string $schedule, ?string $customTime = null): Carbon
    {
        return match ($schedule) {
            'now' => now(),
            'today_6pm' => now()->setTime(18, 0, 0)->isPast()
                ? now()->addDay()->setTime(18, 0, 0)
                : now()->setTime(18, 0, 0),
            'tomorrow_10am' => now()->addDay()->setTime(10, 0, 0),
            'custom' => Carbon::parse($customTime),
            default => now(),
        };
    }

    /**
     * Calculate coupon validity end time.
     *
     * @srs-ref FD-007 - Default: store closing same day
     */
    public function calculateCouponValidity(Carbon $startTime): Carbon
    {
        // Default: same day at 10 PM (typical store closing)
        $validity = $startTime->copy()->setTime(22, 0, 0);

        // If deal starts after 10 PM, extend to next day 10 PM
        if ($startTime->hour >= 22) {
            $validity->addDay();
        }

        return $validity;
    }

    /**
     * Download and store the promotional image.
     *
     * @srs-ref FD-002
     */
    public function storeImage(array $imageData, int $shopId): string
    {
        $mediaId = $imageData['id'];

        // Download from WhatsApp
        $imageContent = $this->whatsApp->downloadMedia($mediaId);

        // Generate unique filename
        $extension = $this->getImageExtension($imageData['mime_type'] ?? 'image/jpeg');
        $filename = "flash_deals/{$shopId}/" . Str::uuid() . ".{$extension}";

        // Store in cloud storage
        Storage::disk('s3')->put($filename, $imageContent, 'public');

        return Storage::disk('s3')->url($filename);
    }

    /**
     * Get image extension from mime type.
     */
    protected function getImageExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * Create the flash deal in database.
     *
     * @srs-ref FD-001 to FD-008
     */
    public function createDeal(Shop $shop, array $dealData): FlashDeal
    {
        return DB::transaction(function () use ($shop, $dealData) {
            $startTime = $this->calculateStartTime(
                $dealData['schedule'],
                $dealData['scheduled_at'] ?? null
            );

            $deal = FlashDeal::create([
                'shop_id' => $shop->id,
                'title' => $dealData['title'],
                'description' => $dealData['description'] ?? null,
                'image_url' => $dealData['image_url'],
                'discount_percent' => $dealData['discount_percent'],
                'max_discount_value' => $dealData['max_discount_value'],
                'target_claims' => $dealData['target_claims'],
                'time_limit_minutes' => $dealData['time_limit_minutes'],
                'starts_at' => $startTime,
                'expires_at' => $startTime->copy()->addMinutes($dealData['time_limit_minutes']),
                'coupon_valid_until' => $this->calculateCouponValidity($startTime),
                'coupon_prefix' => self::COUPON_PREFIX,
                'status' => $startTime->isFuture() ? FlashDealStatus::SCHEDULED : FlashDealStatus::LIVE,
                'current_claims' => 0,
            ]);

            Log::info('Flash deal created', [
                'deal_id' => $deal->id,
                'shop_id' => $shop->id,
                'title' => $deal->title,
                'target' => $deal->target_claims,
                'starts_at' => $deal->starts_at,
            ]);

            return $deal;
        });
    }

    /**
     * Launch the deal and notify customers.
     *
     * @srs-ref FD-009, FD-010
     */
    public function launchDeal(FlashDeal $deal): int
    {
        // Find customers within radius
        $customers = $this->findNearbyCustomers(
            $deal->shop->latitude,
            $deal->shop->longitude,
            self::DEFAULT_NOTIFICATION_RADIUS_KM
        );

        $notified = 0;

        foreach ($customers as $customer) {
            try {
                $this->sendDealNotification($deal, $customer);
                $notified++;
            } catch (\Exception $e) {
                Log::warning('Failed to notify customer of flash deal', [
                    'deal_id' => $deal->id,
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update deal with notification count
        $deal->update(['notified_customers_count' => $notified]);

        // Update status to LIVE if it was scheduled
        if ($deal->status === FlashDealStatus::SCHEDULED) {
            $deal->update(['status' => FlashDealStatus::LIVE]);
        }

        Log::info('Flash deal launched', [
            'deal_id' => $deal->id,
            'customers_notified' => $notified,
        ]);

        return $notified;
    }

    /**
     * Find customers within radius of shop.
     */
    protected function findNearbyCustomers(float $lat, float $lng, float $radiusKm): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('type', 'customer')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw(
                "ST_Distance_Sphere(
                    POINT(longitude, latitude),
                    POINT(?, ?)
                ) <= ?",
                [$lng, $lat, $radiusKm * 1000]
            )
            ->get();
    }

    /**
     * Send deal notification to a customer.
     *
     * @srs-ref FD-010, FD-011
     */
    protected function sendDealNotification(FlashDeal $deal, User $customer): void
    {
        $shop = $deal->shop;
        $distance = $this->calculateDistance(
            $customer->latitude,
            $customer->longitude,
            $shop->latitude,
            $shop->longitude
        );

        $capDisplay = $deal->max_discount_value
            ? " (max ₹{$deal->max_discount_value})"
            : '';

        $message = "⚡ *FLASH DEAL ALERT!*\n" .
            "*ഫ്ലാഷ് ഡീൽ അലർട്ട്!*\n\n" .
            "🏪 *{$shop->shop_name}*\n" .
            "📍 {$distance} away\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "🎯 *{$deal->title}*\n\n" .
            "💰 *{$deal->discount_percent}% OFF*{$capDisplay}\n" .
            "⏰ Ends in {$deal->time_limit_minutes} mins\n" .
            "👥 {$deal->current_claims}/{$deal->target_claims} claimed\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "⚠️ *Deal activates ONLY if {$deal->target_claims} people claim!*\n" .
            "_ഡീൽ ആക്ടിവേറ്റ് ആകണമെങ്കിൽ {$deal->target_claims} പേർ ക്ലെയിം ചെയ്യണം!_";

        // Send image with message
        $this->whatsApp->sendImage(
            $customer->phone,
            $deal->image_url,
            $message
        );

        // Send interactive buttons
        $this->whatsApp->sendButtons(
            $customer->phone,
            "Claim your spot now! 🎯",
            [
                ['id' => 'flash_claim_' . $deal->id, 'title' => "🙋 I'm In!"],
                ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share'],
                ['id' => 'flash_skip_' . $deal->id, 'title' => '❌ Not Today'],
            ]
        );
    }

    /**
     * Calculate distance between two coordinates.
     */
    protected function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): string {
        $earthRadius = 6371; // km

        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        if ($distance < 1) {
            return round($distance * 1000) . 'm';
        }

        return round($distance, 1) . 'km';
    }

    /**
     * Generate unique coupon code for a claim.
     *
     * @srs-ref FD-020
     */
    public function generateCouponCode(FlashDeal $deal): string
    {
        $prefix = $deal->coupon_prefix ?? self::COUPON_PREFIX;
        $uniquePart = strtoupper(Str::random(6));

        return "{$prefix}-{$uniquePart}";
    }

    /**
     * Build preview data structure.
     */
    public function buildPreviewData(array $sessionData): array
    {
        return [
            'title' => $sessionData['title'],
            'image_url' => $sessionData['image_url'] ?? null,
            'discount_percent' => $sessionData['discount_percent'],
            'max_discount_value' => $sessionData['max_discount_value'] ?? null,
            'target_claims' => $sessionData['target_claims'],
            'time_limit_minutes' => $sessionData['time_limit_minutes'],
            'schedule' => $sessionData['schedule'],
            'scheduled_at' => $sessionData['scheduled_at'] ?? null,
        ];
    }

    /**
     * Get deal statistics for shop owner.
     */
    public function getDealStats(FlashDeal $deal): array
    {
        return [
            'current_claims' => $deal->current_claims,
            'target_claims' => $deal->target_claims,
            'progress_percent' => round(($deal->current_claims / $deal->target_claims) * 100),
            'time_remaining' => $deal->expires_at->diffForHumans(),
            'is_activated' => $deal->status === FlashDealStatus::ACTIVATED,
            'is_expired' => $deal->status === FlashDealStatus::EXPIRED,
            'notified_count' => $deal->notified_customers_count,
        ];
    }
}