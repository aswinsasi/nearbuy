<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Models\FishCatch;
use App\Models\FishSeller;
use App\Models\FishSubscription;
use App\Models\FishType;
use App\Models\FishAlert;
use App\Enums\FishQuantityRange;
use App\Enums\FishSellerType;
use App\Enums\FishAlertFrequency;
use Illuminate\Support\Collection;

/**
 * WhatsApp message templates for Pacha Meen (Fish Alert) module.
 * 
 * BILINGUAL VERSION - English + Malayalam (മലയാളം)
 * Optimized for Kerala market release.
 * 
 * IMPORTANT: WhatsApp List Item Title Limit = 24 characters
 * Keep titles short, put details in description.
 *
 * @srs-ref NFR-U-04: Main menu shall be accessible from any flow state
 * @srs-ref NFR-U-05: System shall support English and regional languages (Malayalam)
 */
class FishMessages
{
    /*
    |--------------------------------------------------------------------------
    | Helper: Truncate title to 24 chars (WhatsApp limit)
    |--------------------------------------------------------------------------
    */
    
    /**
     * Ensure title doesn't exceed 24 characters.
     */
    protected static function safeTitle(string $title, int $maxLen = 24): string
    {
        if (mb_strlen($title) <= $maxLen) {
            return $title;
        }
        return mb_substr($title, 0, $maxLen - 1) . '…';
    }

    /*
    |--------------------------------------------------------------------------
    | Fish Seller Registration Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Welcome message for fish seller registration.
     */
    public static function sellerRegistrationWelcome(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🐟 പച്ച മീൻ',
            'body' => "🐟 *പച്ച മീനിലേക്ക് സ്വാഗതം!*\n" .
                "*Welcome to Pacha Meen!*\n\n" .
                "മീൻ വിൽപ്പനക്കാരനായി രജിസ്റ്റർ ചെയ്യുക:\n\n" .
                "• പച്ച മീൻ പോസ്റ്റ് ചെയ്യുക\n" .
                "• ഉപഭോക്താക്കളെ നേരിട്ട് ബന്ധപ്പെടുക\n" .
                "• വിൽപ്പന കൈകാര്യം ചെയ്യുക\n\n" .
                "നമുക്ക് തുടങ്ങാം! 🎣",
            'buttons' => [
                ['id' => 'continue_registration', 'title' => '✅ തുടരുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Ask for seller type.
     */
    public static function askSellerType(): array
    {
        return [
            'type' => 'list',
            'header' => '🐟 വിൽപ്പനക്കാരൻ',
            'body' => "നിങ്ങൾ ഏത് തരം മീൻ വിൽപ്പനക്കാരനാണ്?\n" .
                "What type of fish seller are you?\n\n" .
                "നിങ്ങളുടെ ബിസിനസിനെ ഏറ്റവും നന്നായി വിവരിക്കുന്നത് തിരഞ്ഞെടുക്കുക:",
            'button' => 'തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'വിൽപ്പനക്കാർ',
                    'rows' => [
                        ['id' => 'seller_type_fisherman', 'title' => '🚣 മുക്കുവൻ', 'description' => 'Fisherman - കടലിൽ നിന്ന് നേരിട്ട്'],
                        ['id' => 'seller_type_harbour_vendor', 'title' => '⚓ തുറമുഖ വിൽപ്പന', 'description' => 'Harbour Vendor - തുറമുഖത്ത്'],
                        ['id' => 'seller_type_fish_shop', 'title' => '🏪 മീൻ കട', 'description' => 'Fish Shop - പട്ടണത്തിൽ കട'],
                        ['id' => 'seller_type_wholesaler', 'title' => '🚛 മൊത്തവ്യാപാരി', 'description' => 'Wholesaler - മൊത്തമായി'],
                        ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Ask for business name.
     */
    public static function askBusinessName(FishSellerType $sellerType): array
    {
        $example = match ($sellerType) {
            FishSellerType::FISHERMAN => 'ഉദാ: "രാഘവൻ ഫ്രഷ് ക്യാച്ച്"',
            FishSellerType::HARBOUR_VENDOR => 'ഉദാ: "കൊച്ചി ഹാർബർ സ്റ്റാൾ"',
            FishSellerType::FISH_SHOP => 'ഉദാ: "മലബാർ സീ ഫുഡ്സ്"',
            FishSellerType::WHOLESALER => 'ഉദാ: "കേരള ഫിഷ് ഹോൾസെയിൽ"',
        };

        return [
            'type' => 'buttons',
            'header' => '📝 ബിസിനസ് പേര്',
            'body' => "📝 *ബിസിനസ് / കട പേര്*\n\n" .
                "നിങ്ങളുടെ ബിസിനസ്/സ്റ്റാൾ പേര് എന്താണ്?\n\n" .
                "{$example}\n\n" .
                "_നിങ്ങളുടെ ബിസിനസ് പേര് ടൈപ്പ് ചെയ്യുക:_",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Ask for location.
     */
    public static function askSellerLocation(): array
    {
        return [
            'type' => 'buttons',
            'header' => '📍 ലൊക്കേഷൻ',
            'body' => "📍 *നിങ്ങളുടെ ലൊക്കേഷൻ*\n\n" .
                "ഉപഭോക്താക്കൾക്ക് നിങ്ങളെ കണ്ടെത്താൻ വിൽപ്പന സ്ഥലം പങ്കിടുക.\n\n" .
                "📎 ബട്ടൺ ടാപ്പ് ചെയ്ത് *Location* തിരഞ്ഞെടുക്കുക.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Ask for market/harbour name.
     */
    public static function askMarketName(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🏪 മാർക്കറ്റ്',
            'body' => "🏪 *മാർക്കറ്റ്/തുറമുഖം പേര്*\n\n" .
                "നിങ്ങൾ എവിടെയാണ് വിൽക്കുന്നത്?\n\n" .
                "_ഉദാ: ഫോർട്ട് കൊച്ചി ഹാർബർ_\n\n" .
                "പേര് ടൈപ്പ് ചെയ്യുക അല്ലെങ്കിൽ Skip:",
            'buttons' => [
                ['id' => 'skip_market', 'title' => '⏭️ ഒഴിവാക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Seller registration complete.
     */
    public static function sellerRegistrationComplete(FishSeller $seller): array
    {
        return [
            'type' => 'buttons',
            'header' => '✅ രജിസ്ട്രേഷൻ പൂർത്തി',
            'body' => "✅ *രജിസ്ട്രേഷൻ പൂർത്തിയായി!*\n\n" .
                "പച്ച മീനിലേക്ക് സ്വാഗതം, *{$seller->business_name}*! 🎉\n\n" .
                "📍 സ്ഥലം: {$seller->location_display}\n" .
                "🏷️ തരം: {$seller->seller_type->labelMl()}\n\n" .
                "ഇപ്പോൾ നിങ്ങൾക്ക് പച്ച മീൻ പോസ്റ്റ് ചെയ്യാം!",
            'buttons' => [
                ['id' => 'fish_post_catch', 'title' => '🎣 മീൻ പോസ്റ്റ്'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Fish Catch Posting Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Start catch posting flow.
     */
    public static function startCatchPosting(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🐟 മീൻ പോസ്റ്റ്',
            'body' => "🐟 *പച്ച മീൻ പോസ്റ്റ് ചെയ്യുക*\n\n" .
                "അടുത്തുള്ള ഉപഭോക്താക്കളെ അറിയിക്കാൻ നിങ്ങളുടെ പച്ച മീൻ ചേർക്കാം!\n\n" .
                "ആദ്യം, മീൻ തരം തിരഞ്ഞെടുക്കുക:",
            'buttons' => [
                ['id' => 'select_fish', 'title' => '🐟 മീൻ തിരഞ്ഞെടുക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Fish category selection.
     * FIXED: Titles within 24 char limit
     */
    public static function selectFishCategory(): array
    {
        $categories = [
            FishType::CATEGORY_SEA_FISH => [
                'icon' => '🌊',
                'title_ml' => 'കടൽ മീൻ',        // Short ML title
                'title_en' => 'Sea Fish',
                'examples' => 'ചാള, അയല, ചൂര',
            ],
            FishType::CATEGORY_FRESHWATER => [
                'icon' => '🏞️',
                'title_ml' => 'ശുദ്ധജല മീൻ',    // Short ML title
                'title_en' => 'Freshwater',
                'examples' => 'തിലാപ്പിയ, കരിമീൻ',
            ],
            FishType::CATEGORY_SHELLFISH => [
                'icon' => '🐚',
                'title_ml' => 'കക്ക വർഗ്ഗം',
                'title_en' => 'Shellfish',
                'examples' => 'കല്ലുമ്മക്കായ, ക്ലാം',
            ],
            FishType::CATEGORY_CRUSTACEAN => [
                'icon' => '🦐',
                'title_ml' => 'ചെമ്മീൻ വർഗ്ഗം',
                'title_en' => 'Prawns/Crabs',
                'examples' => 'ചെമ്മീൻ, ഞണ്ട്',
            ],
        ];

        $rows = [];
        $totalFish = 0;

        foreach ($categories as $categoryKey => $categoryInfo) {
            $count = FishType::active()->where('category', $categoryKey)->count();
            if ($count > 0) {
                $totalFish += $count;
                // Title: icon + ML name only (keeps it short)
                $title = "{$categoryInfo['icon']} {$categoryInfo['title_ml']}";
                $rows[] = [
                    'id' => 'cat_' . $categoryKey,
                    'title' => self::safeTitle($title),
                    'description' => "{$categoryInfo['title_en']} - {$count} types - {$categoryInfo['examples']}",
                ];
            }
        }

        $rows[] = [
            'id' => 'main_menu',
            'title' => '🏠 മെനു',
            'description' => 'Main Menu - മെയിൻ മെനു',
        ];

        return [
            'type' => 'list',
            'header' => '🐟 വിഭാഗം തിരഞ്ഞെടുക്കുക',
            'body' => "ഇന്ന് എന്ത് മീനാണ്?\nWhat fish do you have today?\n\n" .
                "📊 ആകെ: {$totalFish} മീൻ തരങ്ങൾ",
            'button' => 'തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => '📂 മീൻ വിഭാഗങ്ങൾ',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Fish selection from category with pagination.
     * FIXED: Titles within 24 char limit
     */
    public static function selectFishFromCategory(string $category, int $page = 0): array
    {
        $perPage = 8;
        $offset = $page * $perPage;

        $query = FishType::active()
            ->where('category', $category)
            ->orderByDesc('is_popular')
            ->orderBy('sort_order')
            ->orderBy('name_en');

        $totalInCategory = $query->count();
        $fishTypes = (clone $query)->skip($offset)->take($perPage)->get();

        $hasMore = ($offset + $perPage) < $totalInCategory;
        $hasPrevious = $page > 0;

        $rows = $fishTypes->map(function($fish) {
            // Emoji + short name, truncated to 24 chars
            $title = $fish->emoji . ' ' . $fish->name_en;
            return [
                'id' => 'fish_' . $fish->id,
                'title' => self::safeTitle($title),
                'description' => $fish->name_ml . ($fish->price_range ? ' • ' . $fish->price_range : ''),
            ];
        })->toArray();

        if ($hasMore) {
            $remaining = $totalInCategory - $offset - $perPage;
            $rows[] = [
                'id' => "cat_{$category}_page_" . ($page + 1),
                'title' => '➡️ കൂടുതൽ',
                'description' => "More - അടുത്ത {$remaining} എണ്ണം",
            ];
        }

        if ($hasPrevious) {
            $rows[] = [
                'id' => "cat_{$category}_page_" . ($page - 1),
                'title' => '⬅️ മുമ്പത്തേത്',
                'description' => 'Previous page',
            ];
        }

        $rows[] = [
            'id' => 'back_to_categories',
            'title' => '🔙 തിരിച്ച്',
            'description' => 'Back to categories',
        ];

        $rows = array_slice($rows, 0, 10);

        $categoryLabels = [
            'sea_fish' => '🌊 കടൽ മീൻ',
            'freshwater' => '🏞️ ശുദ്ധജല മീൻ',
            'shellfish' => '🐚 കക്ക വർഗ്ഗം',
            'crustacean' => '🦐 ചെമ്മീൻ വർഗ്ഗം',
        ];

        $categoryLabel = $categoryLabels[$category] ?? '🐟 മീൻ';
        $showingStart = $offset + 1;
        $showingEnd = min($offset + $perPage, $totalInCategory);
        $pageInfo = $totalInCategory > $perPage 
            ? "\n\n📄 {$showingStart}-{$showingEnd} / {$totalInCategory}" 
            : "\n\n📄 {$totalInCategory} തരങ്ങൾ";

        return [
            'type' => 'list',
            'header' => self::safeTitle($categoryLabel),
            'body' => "മീൻ തിരഞ്ഞെടുക്കുക:{$pageInfo}",
            'button' => 'മീൻ തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => self::safeTitle($categoryLabel),
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Fish type selection list (legacy).
     */
    public static function selectFishType(array $sections = null): array
    {
        return self::selectFishCategory();
    }

    /**
     * Popular fish quick selection.
     */
    public static function selectPopularFish(): array
    {
        $popular = FishType::getPopularListItems(9);
        $popular[] = ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '🐟 ജനപ്രിയ മീൻ',
            'body' => "ജനപ്രിയ മീൻ തരങ്ങൾ:\n\n_മീൻ പേര് ടൈപ്പ് ചെയ്തും തിരയാം_",
            'button' => 'തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => '⭐ ജനപ്രിയം',
                    'rows' => $popular,
                ],
            ],
        ];
    }

    /**
     * Ask for quantity.
     */
    public static function askQuantity(FishType $fishType): array
    {
        return [
            'type' => 'buttons',
            'header' => "📦 അളവ്",
            'body' => "എത്ര *{$fishType->name_ml}* ({$fishType->name_en}) ഉണ്ട്?\n\n" .
                "ഏകദേശ അളവ് തിരഞ്ഞെടുക്കുക:",
            'buttons' => array_slice(FishQuantityRange::toButtons(), 0, 3),
        ];
    }

    /**
     * Ask for quantity (list for more options).
     * FIXED: Titles within 24 char limit
     */
    public static function askQuantityList(FishType $fishType): array
    {
        $rows = [
            ['id' => 'qty_under_2kg', 'title' => '🪣 2 kg-ൽ താഴെ', 'description' => 'Under 2 kg - ചെറിയ അളവ്'],
            ['id' => 'qty_2_5kg', 'title' => '📦 2-5 kg', 'description' => 'ഇടത്തരം അളവ്'],
            ['id' => 'qty_5_10kg', 'title' => '📦 5-10 kg', 'description' => 'നല്ല അളവ്'],
            ['id' => 'qty_10_20kg', 'title' => '🚛 10-20 kg', 'description' => 'വലിയ അളവ്'],
            ['id' => 'qty_20_50kg', 'title' => '🚛 20-50 kg', 'description' => 'വളരെ വലിയ അളവ്'],
            ['id' => 'qty_above_50kg', 'title' => '🏭 50 kg+', 'description' => 'മൊത്ത വിൽപ്പന - Bulk'],
            ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'],
        ];

        return [
            'type' => 'list',
            'header' => '📦 അളവ്',
            'body' => "എത്ര *{$fishType->name_ml}* ഉണ്ട്?\nHow much {$fishType->name_en}?",
            'button' => 'അളവ് തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'അളവ് ശ്രേണി',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Ask for price.
     */
    public static function askPrice(FishType $fishType): array
    {
        $priceHint = $fishType->price_range
            ? "സാധാരണ വില: {$fishType->price_range}"
            : "കിലോയ്ക്ക് വില നൽകുക";

        return [
            'type' => 'buttons',
            'header' => '💰 വില',
            'body' => "💰 *കിലോയ്ക്ക് വില*\n\n" .
                "{$fishType->emoji} {$fishType->name_ml} ({$fishType->name_en})\n\n" .
                "{$priceHint}\n\n" .
                "_വില ടൈപ്പ് ചെയ്യുക (നമ്പർ മാത്രം):_\n" .
                "ഉദാ: 180",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Ask for photo.
     */
    public static function askPhoto(FishType $fishType): array
    {
        return [
            'type' => 'buttons',
            'header' => '📸 ഫോട്ടോ',
            'body' => "നിങ്ങളുടെ *{$fishType->name_ml}*-ന്റെ ഫോട്ടോ ചേർക്കുക!\n\n" .
                "📎 → Camera/Gallery ടാപ്പ് ചെയ്യുക\n\n" .
                "ഫോട്ടോ ഇല്ലെങ്കിൽ Skip ചെയ്യാം.",
            'buttons' => [
                ['id' => 'skip_photo', 'title' => '⏭️ ഒഴിവാക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Confirm catch posting.
     */
    public static function confirmCatchPosting(array $catchData, FishType $fishType): array
    {
        $qtyRange = $catchData['quantity_range'] ?? 'unknown';
        $qty = self::formatQuantityRangeMl($qtyRange);
        $price = number_format($catchData['price_per_kg'] ?? 0);
        $hasPhoto = !empty($catchData['has_photo']);
        $photoStatus = $hasPhoto ? '✅ ഫോട്ടോ ചേർത്തു' : '📷 ഫോട്ടോ ഇല്ല';

        $buttons = [
            ['id' => 'confirm_post', 'title' => '✅ പോസ്റ്റ് ചെയ്യുക'],
        ];

        if ($hasPhoto) {
            $buttons[] = ['id' => 'edit_photo', 'title' => '📷 ഫോട്ടോ മാറ്റുക'];
        } else {
            $buttons[] = ['id' => 'edit_photo', 'title' => '📷 ഫോട്ടോ ചേർക്കുക'];
        }

        $buttons[] = ['id' => 'edit_details', 'title' => '✏️ എഡിറ്റ്'];

        return [
            'type' => 'buttons',
            'header' => '✅ സ്ഥിരീകരിക്കുക',
            'body' => "മീൻ വിവരങ്ങൾ സ്ഥിരീകരിക്കുക:\n\n" .
                "{$fishType->emoji} *{$fishType->name_ml}*\n" .
                "({$fishType->name_en})\n\n" .
                "📦 അളവ്: {$qty}\n" .
                "💰 വില: ₹{$price}/kg\n" .
                "{$photoStatus}\n\n" .
                "പോസ്റ്റ് ചെയ്യണോ?",
            'buttons' => $buttons,
        ];
    }

    /**
     * Format quantity range in Malayalam.
     */
    protected static function formatQuantityRangeMl(string $range): string
    {
        return match ($range) {
            'under_2kg', 'small' => '2 kg-ൽ താഴെ',
            '2_5kg', '2_5' => '2-5 kg',
            '5_10kg', '5_10' => '5-10 kg',
            '10_20kg', '10_20' => '10-20 kg',
            '20_50kg', '20_50' => '20-50 kg',
            'above_50kg', 'large' => '50 kg+',
            default => str_replace('_', '-', $range) . ' kg',
        };
    }

    /**
     * Catch posted successfully with social proof.
     */
    public static function catchPostedSuccess(FishCatch $catch, int $subscriberCount): array
    {
        $alertMsg = $subscriberCount > 0
            ? "📢 *{$subscriberCount} ഉപഭോക്താക്കൾക്ക്* അറിയിപ്പ് അയയ്ക്കും!"
            : "📢 അടുത്തുള്ള സബ്സ്ക്രൈബേഴ്സിനെ കാത്തിരിക്കുന്നു...";

        return [
            'type' => 'buttons',
            'header' => '🎉 പോസ്റ്റ് ചെയ്തു!',
            'body' => "നിങ്ങളുടെ മീൻ പോസ്റ്റ് ചെയ്തു!\n\n" .
                "{$catch->fishType->emoji} *{$catch->fishType->name_ml}*\n" .
                "📦 {$catch->quantity_display}\n" .
                "💰 {$catch->price_display}\n" .
                "⏰ കാലാവധി: {$catch->time_remaining}\n\n" .
                "{$alertMsg}",
            'buttons' => [
                ['id' => 'add_another', 'title' => '➕ മറ്റൊന്ന് ചേർക്കുക'],
                ['id' => 'view_my_catches', 'title' => '📋 എന്റെ മീൻ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Ask to add another fish.
     */
    public static function askAddAnother(): array
    {
        return [
            'type' => 'buttons',
            'body' => "മറ്റൊരു മീൻ കൂടി ചേർക്കണോ?",
            'buttons' => [
                ['id' => 'add_another_yes', 'title' => '➕ അതെ, ചേർക്കുക'],
                ['id' => 'add_another_no', 'title' => '✅ പൂർത്തിയായി'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Stock Update Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Show seller's active catches for stock update.
     */
    public static function selectCatchForUpdate(Collection $catches): array
    {
        if ($catches->isEmpty()) {
            return [
                'type' => 'buttons',
                'header' => '📋 സജീവ മീൻ ഇല്ല',
                'body' => "📋 *സജീവ മീൻ ഇല്ല*\n\n" .
                    "അപ്ഡേറ്റ് ചെയ്യാൻ സജീവ മീൻ ഇല്ല.\n\n" .
                    "പുതിയ മീൻ പോസ്റ്റ് ചെയ്യാൻ തുടങ്ങുക!",
                'buttons' => [
                    ['id' => 'fish_post_catch', 'title' => '🎣 മീൻ പോസ്റ്റ്'],
                    ['id' => 'main_menu', 'title' => '🏠 മെനു'],
                ],
            ];
        }

        $rows = $catches->map(function($catch) {
            $title = $catch->fishType->emoji . ' ' . $catch->fishType->name_ml;
            return [
                'id' => 'catch_' . $catch->id,
                'title' => self::safeTitle($title),
                'description' => "{$catch->price_display} • {$catch->status->display()}",
            ];
        })->toArray();
        
        $rows[] = ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '📋 സ്റ്റോക്ക് അപ്ഡേറ്റ്',
            'body' => "അപ്ഡേറ്റ് ചെയ്യാൻ മീൻ തിരഞ്ഞെടുക്കുക:",
            'button' => 'മീൻ തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'സജീവ മീൻ',
                    'rows' => array_slice($rows, 0, 10),
                ],
            ],
        ];
    }

    /**
     * Stock update options.
     */
    public static function stockUpdateOptions(FishCatch $catch): array
    {
        return [
            'type' => 'buttons',
            'header' => '📦 സ്റ്റോക്ക് അപ്ഡേറ്റ്',
            'body' => "{$catch->fishType->emoji} *{$catch->fishType->name_ml}*\n" .
                "ഇപ്പോൾ: {$catch->status->display()}\n\n" .
                "പുതിയ നില തിരഞ്ഞെടുക്കുക:",
            'buttons' => [
                ['id' => 'status_available', 'title' => '✅ ലഭ്യമാണ്'],
                ['id' => 'status_low_stock', 'title' => '⚠️ കുറവാണ്'],
                ['id' => 'status_sold_out', 'title' => '❌ തീർന്നു'],
            ],
        ];
    }

    /**
     * Stock updated confirmation.
     */
    public static function stockUpdated(FishCatch $catch): array
    {
        return [
            'type' => 'buttons',
            'header' => '✅ അപ്ഡേറ്റ് ചെയ്തു',
            'body' => "✅ *സ്റ്റോക്ക് അപ്ഡേറ്റ് ചെയ്തു*\n\n" .
                "{$catch->fishType->emoji} {$catch->fishType->name_ml}\n" .
                "നില: {$catch->status->display()}\n\n" .
                "ഉപഭോക്താക്കളെ അറിയിച്ചു.",
            'buttons' => [
                ['id' => 'fish_update_stock', 'title' => '📦 മറ്റൊന്ന് അപ്ഡേറ്റ്'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Customer Subscription Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Subscription welcome.
     */
    public static function subscriptionWelcome(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🐟 മീൻ അലേർട്ട്',
            'body' => "🐟 *പച്ച മീൻ അലേർട്ട്*\n\n" .
                "അടുത്ത് പച്ച മീൻ വരുമ്പോൾ അറിയിപ്പ് ലഭിക്കുക!\n\n" .
                "• ഇഷ്ടമുള്ള മീൻ തരങ്ങൾ തിരഞ്ഞെടുക്കുക\n" .
                "• ലൊക്കേഷനും ദൂരവും സെറ്റ് ചെയ്യുക\n" .
                "• തൽക്ഷണ അലേർട്ടുകൾ ലഭിക്കുക\n\n" .
                "നമുക്ക് സെറ്റ് ചെയ്യാം! 📍",
            'buttons' => [
                ['id' => 'continue_subscribe', 'title' => '✅ തുടരുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Ask for subscription location.
     */
    public static function askSubscriptionLocation(): array
    {
        return [
            'type' => 'buttons',
            'header' => '📍 ലൊക്കേഷൻ',
            'body' => "📍 *നിങ്ങളുടെ ലൊക്കേഷൻ*\n\n" .
                "അടുത്തുള്ള മീൻ അലേർട്ട് ലഭിക്കാൻ ലൊക്കേഷൻ പങ്കിടുക.\n\n" .
                "📎 → *Location* ടാപ്പ് ചെയ്യുക.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Ask for alert radius.
     * FIXED: Titles within 24 char limit
     */
    public static function askAlertRadius(): array
    {
        return [
            'type' => 'list',
            'header' => '📍 അലേർട്ട് ദൂരം',
            'body' => "എത്ര ദൂരം വരെ മീൻ അന്വേഷിക്കണം?\n\n" .
                "ദൂരം തിരഞ്ഞെടുക്കുക:",
            'button' => 'ദൂരം തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'ദൂരം',
                    'rows' => [
                        ['id' => 'radius_3', 'title' => '📍 3 km', 'description' => 'Nearby only - അടുത്ത് മാത്രം'],
                        ['id' => 'radius_5', 'title' => '📍 5 km ⭐', 'description' => 'Recommended - ശുപാർശ'],
                        ['id' => 'radius_10', 'title' => '📍 10 km', 'description' => 'Wider area - വിശാല പ്രദേശം'],
                        ['id' => 'radius_15', 'title' => '📍 15 km', 'description' => 'Extended - വിപുലം'],
                        ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Ask for fish type preferences.
     */
    public static function askFishPreferences(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🐟 മീൻ മുൻഗണന',
            'body' => "ഏത് മീനിന് അലേർട്ട് വേണം?\n\n" .
                "എല്ലാ മീനിനും അല്ലെങ്കിൽ പ്രത്യേക തരങ്ങൾ തിരഞ്ഞെടുക്കാം.",
            'buttons' => [
                ['id' => 'fish_pref_all', 'title' => '🐟 എല്ലാ മീനും'],
                ['id' => 'fish_pref_select', 'title' => '✅ തിരഞ്ഞെടുക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Ask for alert frequency.
     * FIXED: Titles within 24 char limit
     */
    public static function askAlertFrequency(): array
    {
        $rows = [
            ['id' => 'fish_freq_immediate', 'title' => '🔔 ഉടൻ', 'description' => 'Immediate - തൽക്ഷണം അറിയിപ്പ്'],
            ['id' => 'fish_freq_morning_only', 'title' => '🌅 രാവിലെ മാത്രം', 'description' => 'Morning only - 6-8 AM'],
            ['id' => 'fish_freq_twice_daily', 'title' => '☀️ ദിവസം 2 തവണ', 'description' => 'Twice daily - 6 AM & 4 PM'],
            ['id' => 'fish_freq_weekly_digest', 'title' => '📅 ആഴ്ചതോറും', 'description' => 'Weekly summary'],
            ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'],
        ];

        return [
            'type' => 'list',
            'header' => '🔔 അലേർട്ട് ആവൃത്തി',
            'body' => "എത്ര തവണ അലേർട്ട് ലഭിക്കണം?",
            'button' => 'ആവൃത്തി തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'ആവൃത്തി ഓപ്ഷനുകൾ',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Subscription created successfully.
     */
    public static function subscriptionCreated(FishSubscription $subscription): array
    {
        return [
            'type' => 'buttons',
            'header' => '🎉 സബ്സ്ക്രൈബ് ചെയ്തു!',
            'body' => "🎉 *സബ്സ്ക്രൈബ് ചെയ്തു!*\n\n" .
                "പച്ച മീൻ അലേർട്ട് ലഭിക്കും:\n\n" .
                "📍 {$subscription->radius_km} km ഉള്ളിൽ\n" .
                "🐟 {$subscription->fish_types_display}\n" .
                "🔔 {$subscription->frequency_display}\n\n" .
                "പച്ച മീൻ വരുമ്പോൾ അറിയിക്കും! 🐟",
            'buttons' => [
                ['id' => 'fish_browse', 'title' => '🔍 മീൻ കാണുക'],
                ['id' => 'fish_manage_alerts', 'title' => '⚙️ സെറ്റിംഗ്സ്'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Alert Messages with Social Proof
    |--------------------------------------------------------------------------
    */

    /**
     * New catch alert message with social proof.
     */
    public static function newCatchAlert(FishCatch $catch, FishAlert $alert): array
    {
        $seller = $catch->seller;
        $fishType = $catch->fishType;
        $distance = $alert->distance_km
            ? ($alert->distance_km < 1
                ? round($alert->distance_km * 1000) . 'm'
                : round($alert->distance_km, 1) . ' km')
            : '';

        // Social proof - coming count
        $comingCount = $catch->coming_count ?? 0;
        $socialProof = '';
        if ($comingCount > 0) {
            $socialProof = "\n\n🏃 *{$comingCount} പേർ ഇതിനകം പോകുന്നു!*";
        }

        $body = "{$fishType->emoji} *{$fishType->name_ml}*\n" .
            "({$fishType->name_en})\n\n" .
            "💰 *{$catch->price_display}*\n" .
            "📦 {$catch->quantity_display}\n" .
            "⏰ {$catch->freshness_display}" .
            $socialProof . "\n\n" .
            "📍 *{$seller->business_name}*\n" .
            "{$catch->location_display}";

        if ($distance) {
            $body .= "\n🚗 {$distance} അകലെ";
        }

        if ($seller->rating_count > 0) {
            $body .= "\n{$seller->short_rating}";
        }

        $buttons = [
            ['id' => "fish_coming_{$catch->id}_{$alert->id}", 'title' => "🏃 ഞാൻ വരുന്നു!"],
            ['id' => "fish_location_{$catch->id}_{$alert->id}", 'title' => '📍 ലൊക്കേഷൻ'],
            ['id' => 'main_menu', 'title' => '🏠 മെനു'],
        ];

        $message = [
            'type' => 'buttons',
            'header' => '🐟 പച്ച മീൻ അലേർട്ട്!',
            'body' => $body,
            'buttons' => $buttons,
        ];

        if ($catch->photo_url) {
            $message['image'] = $catch->photo_url;
        }

        return $message;
    }

    /**
     * Low stock alert message with urgency.
     */
    public static function lowStockAlert(FishCatch $catch, FishAlert $alert): array
    {
        $comingCount = $catch->coming_count ?? 0;
        $urgency = $comingCount > 0 
            ? "🏃 *{$comingCount} പേർ ഇതിനകം പോയി!*\n" 
            : "";

        return [
            'type' => 'buttons',
            'header' => '⚠️ സ്റ്റോക്ക് കുറവ്!',
            'body' => "⚠️ *സ്റ്റോക്ക് കുറയുന്നു!*\n\n" .
                "{$catch->fishType->emoji} *{$catch->fishType->name_ml}*\n" .
                "📍 {$catch->seller->business_name}\n" .
                "💰 {$catch->price_display}\n\n" .
                $urgency .
                "വേഗം വരൂ! ⏰",
            'buttons' => [
                ['id' => "fish_coming_{$catch->id}_{$alert->id}", 'title' => "🏃 ഞാൻ പോകുന്നു!"],
                ['id' => "fish_location_{$catch->id}_{$alert->id}", 'title' => '📍 ലൊക്കേഷൻ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Batch digest message.
     */
    public static function batchDigest(Collection $catches, FishSubscription $subscription): array
    {
        $locationLabel = $subscription->location_label ?? 'അടുത്ത്';
        $lines = ["📍 {$locationLabel} പച്ച മീൻ:\n"];

        foreach ($catches->take(5) as $catch) {
            $lines[] = "{$catch->fishType->emoji} *{$catch->fishType->name_ml}* - {$catch->price_display}";
            $lines[] = "   📍 {$catch->seller->business_name} • {$catch->freshness_display}\n";
        }

        if ($catches->count() > 5) {
            $more = $catches->count() - 5;
            $lines[] = "_+{$more} കൂടുതൽ മീൻ ലഭ്യമാണ്_";
        }

        return [
            'type' => 'buttons',
            'header' => '🐟 മീൻ സംഗ്രഹം',
            'body' => implode("\n", $lines),
            'buttons' => [
                ['id' => 'fish_browse_all', 'title' => '🔍 എല്ലാം കാണുക'],
                ['id' => 'fish_manage_alerts', 'title' => '⚙️ സെറ്റിംഗ്സ്'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Coming confirmation to customer with share option.
     */
    public static function comingConfirmation(FishCatch $catch): array
    {
        return [
            'type' => 'buttons',
            'header' => "🏃 നിങ്ങൾ പോകുന്നു!",
            'body' => "🏃 *നിങ്ങൾ പോകുകയാണ്!*\n\n" .
                "വിൽപ്പനക്കാരനെ അറിയിച്ചു.\n\n" .
                "{$catch->fishType->emoji} {$catch->fishType->name_ml}\n" .
                "📍 {$catch->seller->business_name}\n" .
                "📞 {$catch->seller->user->formatted_phone}\n\n" .
                "👥 *സുഹൃത്തുക്കളുമായി പങ്കിടുക!*\n" .
                "സുരക്ഷിതമായ യാത്ര! 🚗",
            'buttons' => [
                ['id' => "fish_share_{$catch->id}", 'title' => '📤 പങ്കിടുക'],
                ['id' => "fish_location_{$catch->id}_0", 'title' => '📍 ദിശ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Notification to seller when customer is coming.
     * 
     * @param FishCatch $catch The fish catch
     * @param \App\Models\User $customer The customer who is coming
     * @param int $totalComing Total customers coming so far
     * @param float|null $distanceKm Distance from customer (if available)
     */
    public static function sellerComingNotification(
        FishCatch $catch,
        \App\Models\User $customer,
        int $totalComing = 1,
        ?float $distanceKm = null
    ): array {
        // Format customer phone (partially masked for privacy)
        $customerPhone = $customer->phone ?? '';
        $maskedPhone = strlen($customerPhone) > 6 
            ? substr($customerPhone, 0, -4) . '****' 
            : $customerPhone;

        // Format distance
        $distanceText = '';
        if ($distanceKm !== null) {
            $distanceText = $distanceKm < 1 
                ? "\n📍 " . round($distanceKm * 1000) . " m അകലെ നിന്ന്"
                : "\n📍 " . round($distanceKm, 1) . " km അകലെ നിന്ന്";
        }

        // Total coming message
        $totalText = $totalComing > 1 
            ? "\n\n👥 *ആകെ {$totalComing} പേർ വരുന്നു!*"
            : "";

        return [
            'type' => 'buttons',
            'header' => '🏃 ഉപഭോക്താവ് വരുന്നു!',
            'body' => "🏃 *ഉപഭോക്താവ് വരുന്നു!*\n" .
                "*Customer Coming!*\n\n" .
                "{$catch->fishType->emoji} *{$catch->fishType->name_ml}*\n" .
                "({$catch->fishType->name_en})\n\n" .
                "👤 +{$maskedPhone}" .
                $distanceText .
                $totalText . "\n\n" .
                "⏰ " . now()->format('h:i A'),
            'buttons' => [
                ['id' => 'fish_update_stock', 'title' => '📦 സ്റ്റോക്ക് അപ്ഡേറ്റ്'],
                ['id' => 'fish_my_catches', 'title' => '📋 എന്റെ മീൻ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Seller location message.
     */
    public static function sellerLocation(FishSeller $seller): array
    {
        return [
            'type' => 'location',
            'latitude' => $seller->latitude,
            'longitude' => $seller->longitude,
            'name' => $seller->business_name,
            'address' => $seller->location_display,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Browse Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Browse results.
     */
    public static function browseResults(Collection $catches, string $location = 'അടുത്ത്'): array
    {
        if ($catches->isEmpty()) {
            return [
                'type' => 'buttons',
                'header' => '🐟 മീൻ ഇല്ല',
                'body' => "{$location}-ൽ സജീവ മീൻ കണ്ടില്ല.\n\n" .
                    "പച്ച മീൻ വരുമ്പോൾ അറിയിപ്പ് ലഭിക്കാൻ സബ്സ്ക്രൈബ് ചെയ്യുക!",
                'buttons' => [
                    ['id' => 'fish_subscribe', 'title' => '🔔 സബ്സ്ക്രൈബ്'],
                    ['id' => 'fish_refresh', 'title' => '🔄 പുതുക്കുക'],
                    ['id' => 'main_menu', 'title' => '🏠 മെനു'],
                ],
            ];
        }

        $rows = $catches->take(9)->map(function($catch) {
            $title = $catch->fishType->emoji . ' ' . $catch->fishType->name_ml;
            return [
                'id' => 'catch_' . $catch->id,
                'title' => self::safeTitle($title),
                'description' => substr("{$catch->price_display} • {$catch->freshness_display}", 0, 72),
            ];
        })->toArray();

        $rows[] = ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '🐟 അടുത്തുള്ള മീൻ',
            'body' => "{$catches->count()} മീൻ {$location}-ൽ ലഭ്യമാണ്:",
            'button' => 'മീൻ കാണുക',
            'sections' => [
                [
                    'title' => 'ഇപ്പോൾ ലഭ്യം',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Catch detail view.
     */
    public static function catchDetail(FishCatch $catch, ?float $distanceKm = null): array
    {
        $distance = $distanceKm
            ? ($distanceKm < 1 ? round($distanceKm * 1000) . 'm' : round($distanceKm, 1) . ' km') . ' അകലെ'
            : '';

        // Social proof
        $comingCount = $catch->coming_count ?? 0;
        $socialProof = $comingCount > 0 
            ? "\n🏃 *{$comingCount} പേർ പോകുന്നു*" 
            : "";

        $body = "{$catch->fishType->emoji} *{$catch->fishType->name_ml}*\n" .
            "({$catch->fishType->name_en})\n\n" .
            "💰 *{$catch->price_display}*\n" .
            "📦 {$catch->quantity_display}\n" .
            "⏰ {$catch->freshness_display}\n" .
            "📊 നില: {$catch->status->display()}" .
            $socialProof . "\n\n" .
            "📍 *{$catch->seller->business_name}*\n" .
            "{$catch->location_display}";

        if ($distance) {
            $body .= "\n🚗 {$distance}";
        }

        $body .= "\n{$catch->seller->short_rating}";

        $message = [
            'type' => 'buttons',
            'body' => $body,
            'buttons' => [
                ['id' => "fish_coming_{$catch->id}_0", 'title' => "🏃 ഞാൻ വരുന്നു!"],
                ['id' => "fish_location_{$catch->id}_0", 'title' => '📍 ലൊക്കേഷൻ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];

        if ($catch->photo_url) {
            $message['image'] = $catch->photo_url;
        }

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | Menu Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Fish seller menu.
     */
    public static function fishSellerMenu(FishSeller $seller): array
    {
        $activeCatches = $seller->getActiveCatchCount();

        return [
            'type' => 'list',
            'header' => '🐟 വിൽപ്പന മെനു',
            'body' => "സ്വാഗതം, {$seller->business_name}!\n\n" .
                "📊 സജീവ മീൻ: {$activeCatches}\n" .
                "⭐ റേറ്റിംഗ്: {$seller->short_rating}",
            'button' => 'തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'പ്രവർത്തനങ്ങൾ',
                    'rows' => [
                        ['id' => 'fish_post_catch', 'title' => '🐟 പുതിയ മീൻ പോസ്റ്റ്', 'description' => 'Post new catch'],
                        ['id' => 'fish_update_stock', 'title' => '📦 സ്റ്റോക്ക് അപ്ഡേറ്റ്', 'description' => 'Update stock status'],
                        ['id' => 'fish_my_catches', 'title' => '📋 എന്റെ മീൻ', 'description' => 'View active posts'],
                        ['id' => 'fish_my_stats', 'title' => '📊 സ്ഥിതിവിവരം', 'description' => 'Sales & ratings'],
                        ['id' => 'fish_settings', 'title' => '⚙️ സെറ്റിംഗ്സ്', 'description' => 'Profile & alerts'],
                        ['id' => 'main_menu', 'title' => '🏠 മെയിൻ മെനു', 'description' => 'Main Menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Customer fish menu.
     */
    public static function customerFishMenu(bool $hasSubscription = false): array
    {
        $rows = [
            ['id' => 'fish_browse', 'title' => '🔍 മീൻ കാണുക', 'description' => 'Browse fresh fish nearby'],
        ];

        if ($hasSubscription) {
            $rows[] = ['id' => 'fish_manage_alerts', 'title' => '⚙️ അലേർട്ട് മാനേജ്', 'description' => 'Edit or stop alerts'];
            $rows[] = ['id' => 'fish_pause_alerts', 'title' => '⏸️ അലേർട്ട് നിർത്തുക', 'description' => 'Pause temporarily'];
        } else {
            $rows[] = ['id' => 'fish_subscribe', 'title' => '🔔 മീൻ അലേർട്ട്', 'description' => 'Subscribe for notifications'];
        }

        $rows[] = ['id' => 'main_menu', 'title' => '🏠 മെയിൻ മെനു', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '🐟 പച്ച മീൻ',
            'body' => "എന്ത് ചെയ്യണം?",
            'button' => 'തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'ഓപ്ഷനുകൾ',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Invalid fish type error.
     */
    public static function errorInvalidFishType(): array
    {
        return [
            'type' => 'buttons',
            'body' => "❌ തെറ്റായ മീൻ തരം.\n\n" .
                "ലിസ്റ്റിൽ നിന്ന് തിരഞ്ഞെടുക്കുക അല്ലെങ്കിൽ ശരിയായ മീൻ പേര് ടൈപ്പ് ചെയ്യുക.",
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 വീണ്ടും'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Invalid price error.
     */
    public static function errorInvalidPrice(): array
    {
        return [
            'type' => 'buttons',
            'body' => "❌ തെറ്റായ വില.\n\n" .
                "ശരിയായ വില രൂപയിൽ നൽകുക.\n_ഉദാ: 180_",
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 വീണ്ടും'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Location required error.
     */
    public static function errorLocationRequired(): array
    {
        return [
            'type' => 'buttons',
            'body' => "📍 ദയവായി ലൊക്കേഷൻ പങ്കിടുക.\n\n" .
                "📎 → *Location* ടാപ്പ് ചെയ്യുക.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Not a fish seller error.
     */
    public static function errorNotFishSeller(): array
    {
        return [
            'type' => 'buttons',
            'body' => "🐟 ഈ ഫീച്ചർ രജിസ്റ്റർ ചെയ്ത മീൻ വിൽപ്പനക്കാർക്കുള്ളതാണ്.\n\n" .
                "മീൻ വിൽപ്പനക്കാരനായി രജിസ്റ്റർ ചെയ്യണോ?",
            'buttons' => [
                ['id' => 'fish_seller_register', 'title' => '✅ രജിസ്റ്റർ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * Daily limit reached error.
     */
    public static function errorDailyLimitReached(): array
    {
        return [
            'type' => 'buttons',
            'header' => '⚠️ ദിവസ പരിധി',
            'body' => "⚠️ *ദിവസ പരിധി എത്തി*\n\n" .
                "ഇന്നത്തെ പോസ്റ്റിംഗ് പരിധി എത്തി.\n\n" .
                "നാളെ വീണ്ടും ശ്രമിക്കുക!",
            'buttons' => [
                ['id' => 'fish_my_catches', 'title' => '📋 എന്റെ മീൻ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }
}