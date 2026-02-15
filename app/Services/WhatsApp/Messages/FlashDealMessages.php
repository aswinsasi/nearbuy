<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Enums\FlashDealStep;
use App\Models\FlashDeal;
use Illuminate\Support\Carbon;

/**
 * WhatsApp message templates for Flash Mob Deals.
 *
 * All messages are bilingual (English + Malayalam/Manglish).
 * "50% off — BUT only if 30 people claim in 30 minutes!"
 *
 * @srs-ref FD-001 to FD-028 - Flash Mob Deals Module
 * @module Flash Mob Deals
 */
class FlashDealMessages
{
    /**
     * Welcome message when starting flash deal creation.
     *
     * @srs-ref FD-001
     */
    public static function welcomeCreate(): array
    {
        $message = "⚡ *Flash Deal Create Cheyyaam!*\n" .
            "*ഫ്ലാഷ് ഡീൽ ഉണ്ടാക്കാം!*\n\n" .
            "Create a time-bomb deal that activates only when enough people claim it!\n" .
            "ആവശ്യമായ ആളുകൾ ക്ലെയിം ചെയ്താൽ മാത്രം ആക്ടിവേറ്റ് ആകുന്ന ഡീൽ!\n\n" .
            "📝 *Step 1/7: Deal Title*\n" .
            "Ennaa deal? Title type cheyyuka:\n" .
            "(eg: '50% off all shirts', 'Buy 1 Get 1 Free')";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'flash_cancel', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Ask for promotional image.
     *
     * @srs-ref FD-002
     */
    public static function askImage(string $title): array
    {
        $message = "✅ *Title saved:* {$title}\n\n" .
            "📸 *Step 2/7: Deal Image*\n" .
            "*ഡീൽ പോസ്റ്റർ/ഇമേജ്*\n\n" .
            "Send a promotional image for your deal.\n" .
            "നിങ്ങളുടെ ഡീലിന്റെ പ്രൊമോഷണൽ ഇമേജ് അയക്കുക.\n\n" .
            "_Tip: Use eye-catching images with offer text!_\n" .
            "_ടിപ്പ്: ഓഫർ ടെക്സ്റ്റ് ഉള്ള ആകർഷകമായ ഇമേജ് ഉപയോഗിക്കുക!_";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'flash_back', 'title' => '⬅️ Back'],
                ['id' => 'flash_cancel', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Ask for discount percentage.
     *
     * @srs-ref FD-003
     */
    public static function askDiscount(): array
    {
        $message = "✅ *Image received!*\n\n" .
            "💰 *Step 3/7: Discount Percentage*\n" .
            "*ഡിസ്കൗണ്ട് എത്ര ശതമാനം?*\n\n" .
            "Type the discount percentage (5-90):\n" .
            "ഡിസ്കൗണ്ട് ശതമാനം ടൈപ്പ് ചെയ്യുക (5-90):\n\n" .
            "_Example: 50 for 50% off_";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'flash_back', 'title' => '⬅️ Back'],
                ['id' => 'flash_cancel', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Ask for maximum discount cap.
     *
     * @srs-ref FD-003
     */
    public static function askDiscountCap(int $discount): array
    {
        $message = "✅ *Discount:* {$discount}% off\n\n" .
            "💰 *Step 4/7: Maximum Discount Cap*\n" .
            "*പരമാവധി ഡിസ്കൗണ്ട് തുക*\n\n" .
            "What's the maximum discount amount in ₹?\n" .
            "പരമാവധി ഡിസ്കൗണ്ട് തുക എത്ര രൂപ?\n\n" .
            "_Example: 500 means max ₹500 discount_\n" .
            "_Or type 0 for no cap_";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'flash_no_cap', 'title' => '∞ No Cap'],
                ['id' => 'flash_back', 'title' => '⬅️ Back'],
                ['id' => 'flash_cancel', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Ask for target claim count.
     *
     * @srs-ref FD-004
     */
    public static function askTarget(int $discount, ?int $cap): array
    {
        $capDisplay = $cap ? "₹{$cap}" : 'No cap';

        $message = "✅ *Discount:* {$discount}% off (max {$capDisplay})\n\n" .
            "👥 *Step 5/7: Target Claims*\n" .
            "*എത്ര ആളു വേണം ആക്ടിവേറ്റ് ചെയ്യാൻ?*\n\n" .
            "How many people must claim to activate the deal?\n" .
            "ഡീൽ ആക്ടിവേറ്റ് ചെയ്യാൻ എത്ര പേർ ക്ലെയിം ചെയ്യണം?\n\n" .
            "_More people = More viral potential!_\n" .
            "_കൂടുതൽ ആളുകൾ = കൂടുതൽ വൈറൽ!_";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'target_10', 'title' => '👥 10 people'],
                ['id' => 'target_20', 'title' => '👥 20 people'],
                ['id' => 'target_30', 'title' => '👥 30 people'],
            ],
            'extra_buttons' => [
                ['id' => 'target_50', 'title' => '👥 50 people'],
            ],
        ];
    }

    /**
     * Ask for time limit.
     *
     * @srs-ref FD-005
     */
    public static function askTimeLimit(int $target): array
    {
        $message = "✅ *Target:* {$target} people\n\n" .
            "⏰ *Step 6/7: Time Limit*\n" .
            "*സമയ പരിധി എത്ര?*\n\n" .
            "How long do people have to claim?\n" .
            "ക്ലെയിം ചെയ്യാൻ എത്ര സമയം?\n\n" .
            "_Shorter time = More urgency = More shares!_\n" .
            "_കുറഞ്ഞ സമയം = കൂടുതൽ അടിയന്തിരത = കൂടുതൽ ഷെയർ!_";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'time_15', 'title' => '⚡ 15 mins'],
                ['id' => 'time_30', 'title' => '🔥 30 mins'],
                ['id' => 'time_60', 'title' => '⏰ 1 hour'],
            ],
            'extra_buttons' => [
                ['id' => 'time_120', 'title' => '🕐 2 hours'],
            ],
        ];
    }

    /**
     * Ask for launch schedule.
     *
     * @srs-ref FD-006
     */
    public static function askSchedule(int $timeLimit): array
    {
        $timeDisplay = match ($timeLimit) {
            15 => '15 minutes',
            30 => '30 minutes',
            60 => '1 hour',
            120 => '2 hours',
            default => "{$timeLimit} minutes",
        };

        $message = "✅ *Time limit:* {$timeDisplay}\n\n" .
            "📅 *Step 7/7: Launch Schedule*\n" .
            "*എപ്പോൾ ലോഞ്ച് ചെയ്യണം?*\n\n" .
            "When should the deal go live?\n" .
            "ഡീൽ എപ്പോൾ ലൈവ് ആകണം?";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'schedule_now', 'title' => '🚀 Launch Now!'],
                ['id' => 'schedule_6pm', 'title' => '🌆 Today 6 PM'],
                ['id' => 'schedule_10am', 'title' => '☀️ Tomorrow 10AM'],
            ],
            'extra_buttons' => [
                ['id' => 'schedule_custom', 'title' => '📅 Custom Time'],
            ],
        ];
    }

    /**
     * Ask for custom schedule time.
     *
     * @srs-ref FD-006
     */
    public static function askCustomTime(): array
    {
        $message = "📅 *Custom Launch Time*\n" .
            "*കസ്റ്റം ലോഞ്ച് സമയം*\n\n" .
            "Type the date and time:\n" .
            "തീയതിയും സമയവും ടൈപ്പ് ചെയ്യുക:\n\n" .
            "_Format: DD/MM/YYYY HH:MM_\n" .
            "_Example: 25/01/2026 14:00_";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'flash_back', 'title' => '⬅️ Back'],
                ['id' => 'flash_cancel', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Deal preview message.
     *
     * @srs-ref FD-008
     */
    public static function preview(array $dealData): array
    {
        $title = $dealData['title'];
        $discount = $dealData['discount_percent'];
        $cap = $dealData['max_discount_value'];
        $target = $dealData['target_claims'];
        $timeLimit = $dealData['time_limit_minutes'];
        $schedule = $dealData['schedule'];

        $capDisplay = $cap ? "₹{$cap}" : 'No limit';
        $timeDisplay = match ($timeLimit) {
            15 => '15 mins ⚡',
            30 => '30 mins 🔥',
            60 => '1 hour',
            120 => '2 hours',
            default => "{$timeLimit} mins",
        };

        $scheduleDisplay = match ($schedule) {
            'now' => '🚀 Launch Immediately',
            'today_6pm' => '🌆 Today at 6:00 PM',
            'tomorrow_10am' => '☀️ Tomorrow at 10:00 AM',
            default => "📅 " . ($dealData['scheduled_at'] ?? $schedule),
        };

        $message = "⚡ *FLASH DEAL PREVIEW*\n" .
            "*ഫ്ലാഷ് ഡീൽ പ്രിവ്യൂ*\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "📝 *{$title}*\n\n" .
            "💰 *Discount:* {$discount}% off\n" .
            "💵 *Max discount:* {$capDisplay}\n" .
            "🎯 *Target:* {$target} people\n" .
            "⏱️ *Time limit:* {$timeDisplay}\n" .
            "📅 *Schedule:* {$scheduleDisplay}\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "📸 _Image attached above_\n\n" .
            "*Ready to launch?*\n" .
            "*ലോഞ്ച് ചെയ്യാൻ തയ്യാറാണോ?*";

        return [
            'message' => $message,
            'image_url' => $dealData['image_url'] ?? null,
            'buttons' => [
                ['id' => 'flash_launch', 'title' => '🚀 Launch!'],
                ['id' => 'flash_edit', 'title' => '✏️ Edit'],
                ['id' => 'flash_cancel', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Edit options menu.
     */
    public static function editMenu(): array
    {
        $message = "✏️ *What would you like to edit?*\n" .
            "*എന്താണ് എഡിറ്റ് ചെയ്യേണ്ടത്?*";

        return [
            'message' => $message,
            'list' => [
                'button_text' => 'Select Field',
                'sections' => [
                    [
                        'title' => 'Edit Deal',
                        'rows' => [
                            ['id' => 'edit_title', 'title' => '📝 Title', 'description' => 'Change deal title'],
                            ['id' => 'edit_image', 'title' => '📸 Image', 'description' => 'Change promotional image'],
                            ['id' => 'edit_discount', 'title' => '💰 Discount', 'description' => 'Change discount %'],
                            ['id' => 'edit_cap', 'title' => '💵 Max Cap', 'description' => 'Change discount cap'],
                            ['id' => 'edit_target', 'title' => '🎯 Target', 'description' => 'Change target claims'],
                            ['id' => 'edit_time', 'title' => '⏰ Time Limit', 'description' => 'Change time window'],
                            ['id' => 'edit_schedule', 'title' => '📅 Schedule', 'description' => 'Change launch time'],
                        ],
                    ],
                ],
            ],
            'buttons' => [
                ['id' => 'flash_preview', 'title' => '👁️ Back to Preview'],
                ['id' => 'flash_cancel', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Deal launched successfully message.
     */
    public static function launchSuccess(FlashDeal $deal): array
    {
        $startsAt = $deal->starts_at;
        $isImmediate = $startsAt->isPast() || $startsAt->isNow();

        if ($isImmediate) {
            $scheduleText = "🟢 *LIVE NOW!*";
            $statusEmoji = "🔴";
        } else {
            $scheduleText = "⏳ Scheduled for: " . $startsAt->format('M d, Y \a\t h:i A');
            $statusEmoji = "🟡";
        }

        $message = "🎉 *FLASH DEAL LAUNCHED!*\n" .
            "*ഫ്ലാഷ് ഡീൽ ലോഞ്ച് ചെയ്തു!*\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "{$statusEmoji} *{$deal->title}*\n\n" .
            "💰 {$deal->discount_percent}% off" .
            ($deal->max_discount_value ? " (max ₹{$deal->max_discount_value})" : "") . "\n" .
            "🎯 Target: {$deal->target_claims} people in {$deal->time_limit_minutes} mins\n" .
            "{$scheduleText}\n" .
            "━━━━━━━━━━━━━━━\n\n";

        if ($isImmediate) {
            $message .= "📢 *{$deal->notified_customers_count} customers notified!*\n" .
                "Watch the claims roll in! 🎯\n\n" .
                "_You'll receive updates at 25%, 50%, 75% and when activated._";
        } else {
            $message .= "📅 Your deal is scheduled.\n" .
                "We'll notify you when it goes live!";
        }

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_deal_' . $deal->id, 'title' => '👁️ View Deal'],
                ['id' => 'flash_create_another', 'title' => '⚡ Create Another'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Deal cancelled message.
     */
    public static function cancelled(): array
    {
        $message = "❌ *Flash Deal Cancelled*\n" .
            "*ഫ്ലാഷ് ഡീൽ റദ്ദാക്കി*\n\n" .
            "Your flash deal creation was cancelled.\n" .
            "നിങ്ങളുടെ ഫ്ലാഷ് ഡീൽ റദ്ദാക്കി.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'flash_create', 'title' => '⚡ Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Validation error message.
     */
    public static function validationError(FlashDealStep $step, string $error): array
    {
        $hints = match ($step) {
            FlashDealStep::ASK_TITLE => "Title should be 5-100 characters.\nടൈറ്റിൽ 5-100 അക്ഷരങ്ങൾ ആയിരിക്കണം.",
            FlashDealStep::ASK_IMAGE => "Please send a valid image (JPG, PNG).\nദയവായി ശരിയായ ഇമേജ് അയക്കുക.",
            FlashDealStep::ASK_DISCOUNT => "Discount must be between 5-90%.\nഡിസ്കൗണ്ട് 5-90% ആയിരിക്കണം.",
            FlashDealStep::ASK_DISCOUNT_CAP => "Cap must be ₹50-₹10,000 or 0 for no cap.\nക്യാപ് ₹50-₹10,000 അല്ലെങ്കിൽ 0.",
            FlashDealStep::ASK_CUSTOM_TIME => "Please use format: DD/MM/YYYY HH:MM\nഫോർമാറ്റ്: DD/MM/YYYY HH:MM",
            default => "Please try again.\nദയവായി വീണ്ടും ശ്രമിക്കുക.",
        };

        $message = "⚠️ *Invalid Input*\n\n" .
            $error . "\n\n" .
            "_Hint: {$hints}_";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'flash_back', 'title' => '⬅️ Back'],
                ['id' => 'flash_cancel', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Get button options for target claims.
     */
    public static function getTargetOptions(): array
    {
        return [
            10 => '👥 10 people',
            20 => '👥 20 people',
            30 => '👥 30 people (Recommended)',
            50 => '👥 50 people',
        ];
    }

    /**
     * Get button options for time limits.
     */
    public static function getTimeLimitOptions(): array
    {
        return [
            15 => '⚡ 15 mins (High urgency)',
            30 => '🔥 30 mins (Recommended)',
            60 => '⏰ 1 hour',
            120 => '🕐 2 hours',
        ];
    }

    /**
     * Get button options for schedule.
     */
    public static function getScheduleOptions(): array
    {
        return [
            'now' => '🚀 Launch Now!',
            'today_6pm' => '🌆 Today 6 PM',
            'tomorrow_10am' => '☀️ Tomorrow 10 AM',
            'custom' => '📅 Custom Time',
        ];
    }
}