<?php

namespace App\Services\WhatsApp\Messages;

/**
 * Friendly error templates for NearBuy.
 *
 * UX Principles:
 * - Malayalam-English mix (Manglish) for natural feel
 * - Never blame the user
 * - Always provide clear next step
 * - Emojis to soften the message
 * - Every error has actionable buttons (NFR-U-03)
 *
 * Target: Common Kerala people who shouldn't feel intimidated by errors.
 */
class ErrorTemplate
{
    /*
    |--------------------------------------------------------------------------
    | Language Constants
    |--------------------------------------------------------------------------
    */

    public const LANG_EN = 'en';
    public const LANG_ML = 'ml';

    /*
    |--------------------------------------------------------------------------
    | Standard Button Sets
    |--------------------------------------------------------------------------
    */

    public const BUTTONS_RETRY_MENU = [
        ['id' => 'retry', 'title' => '🔄 Try Again'],
        ['id' => 'main_menu', 'title' => '🏠 Menu'],
    ];

    public const BUTTONS_MENU_ONLY = [
        ['id' => 'main_menu', 'title' => '🏠 Menu'],
    ];

    public const BUTTONS_HELP_MENU = [
        ['id' => 'help', 'title' => '❓ Help'],
        ['id' => 'main_menu', 'title' => '🏠 Menu'],
    ];

    /*
    |--------------------------------------------------------------------------
    | Generic / System Errors
    |--------------------------------------------------------------------------
    */

    /**
     * Generic error — something went wrong but we don't know what.
     */
    public static function generic(string $lang = self::LANG_EN, ?string $context = null): array
    {
        $message = ($lang === self::LANG_ML)
            ? "Oops! 😅 Enthoo oru problem.\n\nOnnu koode try cheyyamo?"
            : "Oops! 😅 Something went wrong.\n\nPlease try again?";

        if ($context) {
            $message .= "\n\n_({$context})_";
        }

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /**
     * Server/network error — our fault, not user's.
     */
    public static function serverError(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "😓 Server-il oru issue und.\n\nKurachu kazhinjhu try cheyyuka."
            : "😓 We're having server issues.\n\nPlease try again in a moment.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /**
     * Network/connection error.
     */
    public static function networkError(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "🌐 Connection issue und.\n\nInternet check cheythu try cheyyuka."
            : "🌐 Connection issue.\n\nCheck your internet and try again.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Input Validation Errors
    |--------------------------------------------------------------------------
    */

    /**
     * Generic invalid input — user typed something unexpected.
     */
    public static function invalidInput(string $lang = self::LANG_EN, ?string $hint = null): array
    {
        $message = ($lang === self::LANG_ML)
            ? "Manasilaayilla 🤔\n\nOnnu koode type cheyyamo?"
            : "Didn't understand 🤔\n\nCan you try again?";

        if ($hint) {
            $message .= "\n\n💡 " . $hint;
        }

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /**
     * Expected a button tap but got text.
     */
    public static function expectedButton(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "👆 Mele ulla button tap cheyyuka.\n\nType cheyyanda, tap cheythal mathi!"
            : "👆 Please tap one of the buttons above.\n\nNo need to type!";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'show_options', 'title' => '📋 Show Options'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Expected a list selection.
     */
    public static function expectedList(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📋 Button tap cheythu list-il ninnu select cheyyuka."
            : "📋 Tap the button and select from the list.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'show_list', 'title' => '📋 Show List'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Invalid phone number.
     */
    public static function invalidPhone(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📱 Phone number shariyalla.\n\n10 digit number type cheyyuka.\n_Eg: 9876543210_"
            : "📱 Phone number doesn't look right.\n\nEnter 10 digits.\n_Eg: 9876543210_";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /**
     * Invalid amount.
     */
    public static function invalidAmount(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "💰 Amount shariyalla.\n\nNumbers mathram type cheyyuka.\n_Eg: 5000_"
            : "💰 Amount doesn't look right.\n\nEnter numbers only.\n_Eg: 5000_";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /**
     * Invalid date.
     */
    public static function invalidDate(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📅 Date shariyalla.\n\nDD/MM/YYYY format-il type cheyyuka.\n_Eg: 25/12/2024_"
            : "📅 Date doesn't look right.\n\nUse DD/MM/YYYY format.\n_Eg: 25/12/2024_";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /**
     * Invalid name (too short/long).
     */
    public static function invalidName(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "👤 Peru shariyaayilla.\n\n2-100 characters venam."
            : "👤 Name doesn't look right.\n\nMust be 2-100 characters.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /**
     * Description too short.
     */
    public static function descriptionTooShort(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📝 Kurachu koodi details tharamo?\n\nMinimum 10 characters venam."
            : "📝 Can you add more details?\n\nMinimum 10 characters needed.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Location Errors
    |--------------------------------------------------------------------------
    */

    /**
     * Location required but not provided.
     */
    public static function locationRequired(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📍 Location share cheythaal mathrame use cheyyaan pattuu.\n\nButton tap cheythu share cheyyuka. 🔒 Safe aanu!"
            : "📍 We need your location for this.\n\nTap the button to share. 🔒 It's safe!";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'share_location', 'title' => '📍 Share Location'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'request_location' => true,
        ];
    }

    /**
     * Location sharing failed.
     */
    public static function locationFailed(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📍 Location share cheyyaan pattiyilla.\n\nWhatsApp settings-il location ON aakkuka."
            : "📍 Couldn't get your location.\n\nMake sure location is enabled in WhatsApp settings.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'retry_location', 'title' => '📍 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Media/File Errors
    |--------------------------------------------------------------------------
    */

    /**
     * Media upload failed.
     */
    public static function mediaUploadFailed(string $lang = self::LANG_EN, ?string $reason = null): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📷 Photo/file upload cheyyaan pattiyilla 😕\n\nOnnu koode try cheyyuka."
            : "📷 Couldn't upload your file 😕\n\nPlease try again.";

        if ($reason) {
            $message .= "\n\n_({$reason})_";
        }

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Invalid file type.
     */
    public static function invalidFileType(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📁 Ee file type support alla.\n\n✅ JPG, PNG, PDF mathram."
            : "📁 This file type isn't supported.\n\n✅ Only JPG, PNG, PDF allowed.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 Send Another'],
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * File too large.
     */
    public static function fileTooLarge(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📁 File valuthaanu.\n\n5MB-il kuravulla file ayakkuka."
            : "📁 File is too large.\n\nPlease send a file under 5MB.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 Send Smaller'],
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Session & Authentication Errors
    |--------------------------------------------------------------------------
    */

    /**
     * Session expired/timeout.
     */
    public static function sessionExpired(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "⏰ Session expire aayi.\n\n*menu* type cheythu restart cheyyuka 🔄"
            : "⏰ Session expired.\n\nType *menu* to start fresh 🔄";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'restart', 'title' => '🔄 Start Fresh'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * User not registered.
     */
    public static function notRegistered(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "Welcome! 👋\n\nFirst register cheyyaam — 2 minute mathram!\n\n*menu* type cheyyuka."
            : "Welcome! 👋\n\nLet's get you registered first — takes just 2 minutes!\n\nType *menu*.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'register', 'title' => '📝 Register Now'],
                ['id' => 'browse', 'title' => '👀 Just Browse'],
            ],
        ];
    }

    /**
     * Shop-only feature accessed by customer.
     */
    public static function shopOnly(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "🏪 Ee feature shop owners-nu mathram.\n\nShop register cheyyano?"
            : "🏪 This feature is for shop owners only.\n\nWant to register your shop?";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'register_shop', 'title' => '🏪 Register Shop'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Customer-only feature accessed by shop.
     */
    public static function customerOnly(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "🛒 Ee feature customers-nu mathram."
            : "🛒 This feature is for customers only.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /**
     * Permission denied.
     */
    public static function permissionDenied(string $lang = self::LANG_EN, ?string $action = null): array
    {
        $message = ($lang === self::LANG_ML)
            ? "🚫 Ee action cheyyaan permission illa."
            : "🚫 You don't have permission for this.";

        if ($action) {
            $message = ($lang === self::LANG_ML)
                ? "🚫 \"{$action}\" cheyyaan permission illa."
                : "🚫 You can't {$action}.";
        }

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    /**
     * Rate limited — too many requests.
     */
    public static function rateLimited(string $lang = self::LANG_EN, int $waitMinutes = 5): array
    {
        $message = ($lang === self::LANG_ML)
            ? "⏰ Kurachu kazhinjhu try cheyyuka.\n\n{$waitMinutes} minute kazhinjhu try cheyyaam."
            : "⏰ Please slow down.\n\nTry again in {$waitMinutes} minutes.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /**
     * Daily limit reached.
     */
    public static function dailyLimitReached(string $lang = self::LANG_EN, string $limitType = 'requests'): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📊 Innale-the limit theernu.\n\nNaale try cheyyuka!"
            : "📊 You've reached today's limit.\n\nTry again tomorrow!";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Not Found / Empty Results
    |--------------------------------------------------------------------------
    */

    /**
     * Generic not found.
     */
    public static function notFound(string $lang = self::LANG_EN, ?string $itemType = null): array
    {
        $itemDisplay = $itemType ? self::getItemDisplayName($itemType, $lang) : 'item';

        $message = ($lang === self::LANG_ML)
            ? "🔍 Kandilla.\n\nEe {$itemDisplay} delete aayo expire aayo."
            : "🔍 Not found.\n\nThis {$itemDisplay} may have been deleted or expired.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /**
     * No results for search.
     */
    public static function noResults(string $lang = self::LANG_EN, string $context = 'search'): array
    {
        $data = self::getNoResultsData($context, $lang);

        return [
            'message' => $data['message'],
            'buttons' => $data['buttons'],
        ];
    }

    /**
     * No shops nearby.
     */
    public static function noShopsNearby(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📍 Sameepathu shops kandilla.\n\nRadius koottan try cheyyuka."
            : "📍 No shops found nearby.\n\nTry expanding your search radius.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'expand_radius', 'title' => '📍 Expand Radius'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Expired / Already Done
    |--------------------------------------------------------------------------
    */

    /**
     * Item expired.
     */
    public static function expired(string $lang = self::LANG_EN, string $itemType = 'item'): array
    {
        $itemDisplay = self::getItemDisplayName($itemType, $lang);

        $message = ($lang === self::LANG_ML)
            ? "⏰ Ee {$itemDisplay} expire aayi."
            : "⏰ This {$itemDisplay} has expired.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /**
     * Already done / duplicate action.
     */
    public static function alreadyDone(string $lang = self::LANG_EN, string $action = 'this'): array
    {
        $message = ($lang === self::LANG_ML)
            ? "✅ Already cheythu!\n\nVeruthu cheyyanda."
            : "✅ Already done!\n\nNo need to do it again.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /**
     * Duplicate response (shop already responded to request).
     */
    public static function duplicateResponse(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "✅ Ee request-nu already respond cheythu!"
            : "✅ You already responded to this request!";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Feature Unavailable
    |--------------------------------------------------------------------------
    */

    /**
     * Feature disabled/under maintenance.
     */
    public static function featureDisabled(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "🔧 Ee feature ipoole work cheyyunnilla.\n\nPinne try cheyyuka."
            : "🔧 This feature is under maintenance.\n\nPlease try later.";

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /**
     * Coming soon.
     */
    public static function comingSoon(string $lang = self::LANG_EN, ?string $featureName = null): array
    {
        $message = ($lang === self::LANG_ML)
            ? "🚀 Varunnu soon!\n\nEe feature prepare cheyyunnu."
            : "🚀 Coming soon!\n\nWe're working on this feature.";

        if ($featureName) {
            $message = ($lang === self::LANG_ML)
                ? "🚀 *{$featureName}* varunnu soon!"
                : "🚀 *{$featureName}* is coming soon!";
        }

        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Limits Reached
    |--------------------------------------------------------------------------
    */

    /**
     * Maximum offers reached.
     */
    public static function maxOffersReached(string $lang = self::LANG_EN, int $max = 5): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📢 Maximum {$max} offers kazhinjhu!\n\nPazhathe delete cheythu new upload cheyyuka."
            : "📢 You have {$max} active offers (max).\n\nDelete an old one to upload new.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_my_offers', 'title' => '📢 My Offers'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Maximum requests reached.
     */
    public static function maxRequestsReached(string $lang = self::LANG_EN, int $max = 3): array
    {
        $message = ($lang === self::LANG_ML)
            ? "🔍 Maximum {$max} active requests kazhinjhu!\n\nClose cheythu new onnu start cheyyuka."
            : "🔍 You have {$max} active requests (max).\n\nClose one to create new.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_my_requests', 'title' => '🔍 My Requests'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get display name for item types in both languages.
     */
    private static function getItemDisplayName(string $itemType, string $lang): string
    {
        $names = [
            'offer' => ['en' => 'offer', 'ml' => 'offer'],
            'request' => ['en' => 'request', 'ml' => 'request'],
            'agreement' => ['en' => 'agreement', 'ml' => 'agreement'],
            'shop' => ['en' => 'shop', 'ml' => 'shop'],
            'response' => ['en' => 'response', 'ml' => 'response'],
            'user' => ['en' => 'user', 'ml' => 'user'],
            'fish_alert' => ['en' => 'fish alert', 'ml' => 'fish alert'],
            'job' => ['en' => 'job', 'ml' => 'job'],
            'deal' => ['en' => 'deal', 'ml' => 'deal'],
        ];

        return $names[$itemType][$lang] ?? $itemType;
    }

    /**
     * Get no results data for different contexts.
     */
    private static function getNoResultsData(string $context, string $lang): array
    {
        $data = [
            'offers' => [
                'en' => [
                    'message' => "😕 No offers found here.\n\nTry different category or expand radius.",
                    'buttons' => [
                        ['id' => 'change_category', 'title' => '📂 Change Category'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
                'ml' => [
                    'message' => "😕 Offers kandilla.\n\nCategory maattuka or radius koottuka.",
                    'buttons' => [
                        ['id' => 'change_category', 'title' => '📂 Maattuka'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
            ],
            'responses' => [
                'en' => [
                    'message' => "⏳ No responses yet.\n\nShops have been notified. Check back in 1-2 hours.",
                    'buttons' => [
                        ['id' => 'check_later', 'title' => '🔄 Check Later'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
                'ml' => [
                    'message' => "⏳ Responses vannilla.\n\nShops-ne ariyichu. 1-2 hour kazhinjhu nokkuka.",
                    'buttons' => [
                        ['id' => 'check_later', 'title' => '🔄 Pinne'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
            ],
            'agreements' => [
                'en' => [
                    'message' => "📋 No agreements yet.\n\nCreate one to track money transactions.",
                    'buttons' => [
                        ['id' => 'create_agreement', 'title' => '📝 Create One'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
                'ml' => [
                    'message' => "📋 Agreements illa.\n\nMoney transactions track cheyyaan onnu create cheyyuka.",
                    'buttons' => [
                        ['id' => 'create_agreement', 'title' => '📝 Create'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
            ],
            'fish' => [
                'en' => [
                    'message' => "🐟 No fresh fish alerts yet.\n\nWe'll notify you when catch arrives nearby!",
                    'buttons' => [
                        ['id' => 'fish_settings', 'title' => '⚙️ Alert Settings'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
                'ml' => [
                    'message' => "🐟 Fresh fish alerts illa.\n\nSameepathu fish vannaal ariyikkaam!",
                    'buttons' => [
                        ['id' => 'fish_settings', 'title' => '⚙️ Settings'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
            ],
            'jobs' => [
                'en' => [
                    'message' => "👷 No jobs right now.\n\nCheck back later for new opportunities!",
                    'buttons' => [
                        ['id' => 'job_settings', 'title' => '⚙️ Job Preferences'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
                'ml' => [
                    'message' => "👷 Jobs ipol illa.\n\nPinne nokkuka — new jobs varum!",
                    'buttons' => [
                        ['id' => 'job_settings', 'title' => '⚙️ Settings'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                ],
            ],
        ];

        $fallback = [
            'message' => ($lang === self::LANG_ML)
                ? "😕 Results kandilla.\n\nVere criteria try cheyyuka."
                : "😕 No results found.\n\nTry different criteria.",
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];

        return $data[$context][$lang] ?? $fallback;
    }

    /*
    |--------------------------------------------------------------------------
    | Dynamic Error Retrieval
    |--------------------------------------------------------------------------
    */

    /**
     * Get error by type.
     *
     * @param string $type Error type: 'generic', 'button', 'list', 'location', 'image', 'phone', 'amount', 'date', 'name'
     * @param string $lang Language code
     * @return array{message: string, buttons: array}
     */
    public static function get(string $type, string $lang = self::LANG_EN): array
    {
        return match ($type) {
            'button' => self::expectedButton($lang),
            'list' => self::expectedList($lang),
            'location' => self::expectedLocation($lang),
            'image' => self::expectedImage($lang),
            'phone' => self::invalidPhone($lang),
            'amount' => self::invalidAmount($lang),
            'date' => self::invalidDate($lang),
            'name' => self::invalidName($lang),
            'text' => self::invalidInput($lang),
            'network' => self::networkError($lang),
            'server' => self::serverError($lang),
            default => self::generic($lang),
        };
    }

    /**
     * Get validation error for a specific field.
     *
     * @param string $field Field name: 'phone', 'amount', 'date', 'name', 'description'
     * @param string $lang Language code
     * @return array{message: string, buttons: array}
     */
    public static function validation(string $field, string $lang = self::LANG_EN): array
    {
        return match ($field) {
            'phone' => self::invalidPhone($lang),
            'amount' => self::invalidAmount($lang),
            'date' => self::invalidDate($lang),
            'name' => self::invalidName($lang),
            'description' => self::descriptionTooShort($lang),
            default => self::invalidInput($lang),
        };
    }

    /**
     * Expected location input error.
     */
    public static function expectedLocation(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📍 Location share cheyyuka.\n\nTap the 📎 button → Location → Send Location"
            : "📍 Please share your location.\n\nTap 📎 → Location → Send Location";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'retry', 'title' => '📍 Share Location'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Expected image input error.
     */
    public static function expectedImage(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "📸 Photo ayakkuka.\n\nGallery-yil ninnu select cheyyuka or camera use cheyyuka."
            : "📸 Please send a photo.\n\nSelect from gallery or use camera.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'retry', 'title' => '📸 Send Photo'],
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Build error with custom message and standard retry buttons.
     */
    public static function withRetry(string $message): array
    {
        return [
            'message' => $message,
            'buttons' => self::BUTTONS_RETRY_MENU,
        ];
    }

    /**
     * Build error with menu button only.
     */
    public static function withMenuOnly(string $message): array
    {
        return [
            'message' => $message,
            'buttons' => self::BUTTONS_MENU_ONLY,
        ];
    }

    /**
     * Build error with custom buttons (ensures menu button exists).
     */
    public static function withCustomButtons(string $message, array $buttons): array
    {
        // Ensure menu button exists
        $hasMenu = collect($buttons)->contains(fn($b) => in_array($b['id'], ['main_menu', 'menu']));

        if (!$hasMenu && count($buttons) < 3) {
            $buttons[] = ['id' => 'main_menu', 'title' => '🏠 Menu'];
        }

        return [
            'message' => $message,
            'buttons' => $buttons,
        ];
    }
}