<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Models\JobCategory;
use App\Models\JobWorker;
use App\Models\JobPost;
use App\Models\JobApplication;
use App\Models\JobVerification;
use App\Models\WorkerEarning;
use App\Models\User;
use App\Enums\VehicleType;
use App\Enums\WorkerAvailability;
use App\Enums\JobStatus;
use Illuminate\Support\Collection;

/**
 * WhatsApp message templates for Njaanum Panikkar (Basic Jobs Marketplace) module.
 * 
 * BILINGUAL VERSION - English + Malayalam (മലയാളം)
 * Optimized for Kerala market release.
 * 
 * IMPORTANT: WhatsApp List Item Title Limit = 24 characters
 * Keep titles short, put details in description.
 *
 * @srs-ref Section 3 - Jobs Marketplace
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobMessages
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
    | WORKER REGISTRATION MESSAGES
    |--------------------------------------------------------------------------
    */

    /**
     * 1. Welcome message for worker registration.
     */
    public static function workerWelcome(): array
    {
        return [
            'type' => 'buttons',
            'header' => '👷 ഞാനും പണിക്കാർ',
            'body' => "👷 *ഞാനും പണിക്കാർ - Njaanum Panikkar*\n\n" .
                "Got free time? Earn money doing simple tasks!\n" .
                "ഫ്രീ ടൈം ഉണ്ടോ? ലളിതമായ ജോലികൾ ചെയ്ത് പണം സമ്പാദിക്കൂ!\n\n" .
                "✅ No special skills needed\n" .
                "✅ Work when you want\n" .
                "✅ Get paid same day\n\n" .
                "നമുക്ക് തുടങ്ങാം! 💪",
            'buttons' => [
                ['id' => 'start_worker_registration', 'title' => '✅ രജിസ്റ്റർ ചെയ്യുക'],
                ['id' => 'browse_jobs', 'title' => '🔍 ജോലികൾ കാണുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 2. Ask worker's name.
     */
    public static function askWorkerName(): array
    {
        return [
            'type' => 'buttons',
            'header' => '👤 പേര്',
            'body' => "*Step 1/7* 📝\n\n" .
                "👤 *നിങ്ങളുടെ പേര്*\n\n" .
                "Please enter your full name\n" .
                "നിങ്ങളുടെ മുഴുവൻ പേര് എഴുതുക\n\n" .
                "_ഉദാ: രാജേഷ് കുമാർ_",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 3. Ask worker photo.
     */
    public static function askWorkerPhoto(): array
    {
        return [
            'type' => 'buttons',
            'header' => '📸 ഫോട്ടോ',
            'body' => "*Step 2/7* 📝\n\n" .
                "📸 *പ്രൊഫൈൽ ഫോട്ടോ*\n\n" .
                "A clear photo helps build trust with task givers.\n" .
                "വ്യക്തമായ ഫോട്ടോ വിശ്വാസം വർദ്ധിപ്പിക്കും.\n\n" .
                "📎 → Camera/Gallery ടാപ്പ് ചെയ്യുക\n\n" .
                "_ഫോട്ടോ ഇല്ലെങ്കിൽ Skip ചെയ്യാം_",
            'buttons' => [
                ['id' => 'skip_worker_photo', 'title' => '⏭️ ഒഴിവാക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 4. Ask worker location.
     */
    public static function askWorkerLocation(): array
    {
        return [
            'type' => 'buttons',
            'header' => '📍 ലൊക്കേഷൻ',
            'body' => "*Step 3/7* 📝\n\n" .
                "📍 *നിങ്ങളുടെ ലൊക്കേഷൻ*\n\n" .
                "Share your location so we can find jobs near you.\n" .
                "അടുത്തുള്ള ജോലികൾ കണ്ടെത്താൻ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക.\n\n" .
                "📎 → *Location* ടാപ്പ് ചെയ്യുക",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 5. Ask vehicle type.
     */
    public static function askVehicleType(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🚗 വാഹനം',
            'body' => "*Step 4/7* 📝\n\n" .
                "🚗 *വാഹനം ഉണ്ടോ?*\n\n" .
                "Do you have a vehicle for transportation?\n" .
                "യാത്രയ്ക്ക് വാഹനം ഉണ്ടോ?\n\n" .
                "_ഡെലിവറി ജോലികൾക്ക് വാഹനം വേണം_",
            'buttons' => [
                ['id' => 'vehicle_none', 'title' => '🚶 നടപ്പ് മാത്രം'],
                ['id' => 'vehicle_two_wheeler', 'title' => '🛵 ഇരുചക്രവാഹനം'],
                ['id' => 'vehicle_four_wheeler', 'title' => '🚗 നാലുചക്ര വാഹനം'],
            ],
        ];
    }

    /**
     * 6. Ask job types (categories worker can do).
     */
    public static function askJobTypes(): array
    {
        $categories = JobCategory::active()
            ->orderBy('tier')
            ->orderBy('sort_order')
            ->get();

        $tier1Rows = $categories->where('tier', 1)->take(5)->map(function($cat) {
            return [
                'id' => 'jobtype_' . $cat->id,
                'title' => self::safeTitle($cat->icon . ' ' . $cat->name_en),
                'description' => $cat->name_ml . ' • ' . $cat->pay_range,
            ];
        })->toArray();

        $tier2Rows = $categories->where('tier', 2)->take(4)->map(function($cat) {
            return [
                'id' => 'jobtype_' . $cat->id,
                'title' => self::safeTitle($cat->icon . ' ' . $cat->name_en),
                'description' => $cat->name_ml . ' • ' . $cat->pay_range,
            ];
        })->toArray();

        $tier1Rows[] = ['id' => 'jobtype_done', 'title' => '✅ Done', 'description' => 'Finish selection'];

        return [
            'type' => 'list',
            'header' => '💼 ജോലി തരങ്ങൾ',
            'body' => "*Step 5/7* 📝\n\n" .
                "💼 *ഏത് ജോലികൾ ചെയ്യാം?*\n\n" .
                "Select job types you can do.\n" .
                "നിങ്ങൾക്ക് ചെയ്യാൻ കഴിയുന്ന ജോലികൾ തിരഞ്ഞെടുക്കുക.\n\n" .
                "_ഒന്നിലധികം തിരഞ്ഞെടുക്കാം. Done അമർത്തുക._",
            'button' => 'ജോലി തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => '🟢 Zero Skills',
                    'rows' => $tier1Rows,
                ],
                [
                    'title' => '🔵 Basic Skills',
                    'rows' => $tier2Rows,
                ],
            ],
        ];
    }

    /**
     * 7. Ask availability.
     */
    public static function askAvailability(): array
    {
        return [
            'type' => 'list',
            'header' => '🕐 ലഭ്യത',
            'body' => "*Step 6/7* 📝\n\n" .
                "🕐 *എപ്പോൾ ലഭ്യമാണ്?*\n\n" .
                "When are you available for work?\n" .
                "ജോലിക്ക് എപ്പോൾ ലഭ്യമാണ്?",
            'button' => 'സമയം തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'ലഭ്യമായ സമയം',
                    'rows' => [
                        ['id' => 'avail_morning', 'title' => '🌅 രാവിലെ', 'description' => 'Morning - 6:00 AM - 12:00 PM'],
                        ['id' => 'avail_afternoon', 'title' => '☀️ ഉച്ചയ്ക്ക്', 'description' => 'Afternoon - 12:00 PM - 6:00 PM'],
                        ['id' => 'avail_evening', 'title' => '🌆 വൈകുന്നേരം', 'description' => 'Evening - 6:00 PM - 10:00 PM'],
                        ['id' => 'avail_flexible', 'title' => '🔄 എപ്പോഴും', 'description' => 'Flexible - Any time'],
                        ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * 8. Confirm worker registration.
     */
    public static function confirmWorkerRegistration(array $workerData): array
    {
        $name = $workerData['name'] ?? 'Unknown';
        $vehicle = $workerData['vehicle_type'] ?? 'none';
        $vehicleDisplay = match($vehicle) {
            'two_wheeler' => '🛵 ഇരുചക്രവാഹനം',
            'four_wheeler' => '🚗 നാലുചക്ര വാഹനം',
            default => '🚶 നടപ്പ് മാത്രം',
        };
        $jobCount = count($workerData['job_types'] ?? []);
        $hasPhoto = !empty($workerData['photo_url']) ? '✅' : '❌';

        return [
            'type' => 'buttons',
            'header' => '✅ സ്ഥിരീകരിക്കുക',
            'body' => "*Step 7/7* 📝\n\n" .
                "📋 *രജിസ്ട്രേഷൻ വിവരങ്ങൾ*\n\n" .
                "👤 പേര്: *{$name}*\n" .
                "📸 ഫോട്ടോ: {$hasPhoto}\n" .
                "📍 ലൊക്കേഷൻ: ✅\n" .
                "🚗 വാഹനം: {$vehicleDisplay}\n" .
                "💼 ജോലികൾ: {$jobCount} types\n\n" .
                "എല്ലാം ശരിയാണോ?",
            'buttons' => [
                ['id' => 'confirm_worker_reg', 'title' => '✅ സ്ഥിരീകരിക്കുക'],
                ['id' => 'edit_worker_reg', 'title' => '✏️ എഡിറ്റ്'],
                ['id' => 'cancel_worker_reg', 'title' => '❌ റദ്ദാക്കുക'],
            ],
        ];
    }

    /**
     * 9. Worker registration success.
     */
    public static function workerRegistrationSuccess(JobWorker $worker): array
    {
        return [
            'type' => 'buttons',
            'header' => '🎉 രജിസ്ട്രേഷൻ പൂർത്തി!',
            'body' => "🎉 *സ്വാഗതം, {$worker->name}!*\n\n" .
                "നിങ്ങൾ ഇപ്പോൾ ഒരു പണിക്കാരനായി രജിസ്റ്റർ ചെയ്തു!\n\n" .
                "✅ അടുത്തുള്ള ജോലികൾക്ക് അലേർട്ട് ലഭിക്കും\n" .
                "✅ നിങ്ങൾക്ക് ഇഷ്ടമുള്ള ജോലിക്ക് അപേക്ഷിക്കാം\n" .
                "✅ പണി കഴിഞ്ഞാൽ ഉടൻ പേയ്മെന്റ്\n\n" .
                "ഇപ്പോൾ ലഭ്യമായ ജോലികൾ കാണാം! 💼",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 ജോലികൾ കാണുക'],
                ['id' => 'worker_profile', 'title' => '👤 എന്റെ പ്രൊഫൈൽ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | JOB POSTING MESSAGES
    |--------------------------------------------------------------------------
    */

    /**
     * 10. Post job welcome.
     */
    public static function postJobWelcome(): array
    {
        return [
            'type' => 'buttons',
            'header' => '📋 ടാസ്ക് പോസ്റ്റ്',
            'body' => "📋 *Post a Task - ജോലി പോസ്റ്റ് ചെയ്യുക*\n\n" .
                "Need help with something?\n" .
                "Post a task and nearby workers will apply!\n\n" .
                "എന്തെങ്കിലും സഹായം വേണോ?\n" .
                "ഒരു ജോലി പോസ്റ്റ് ചെയ്യൂ, അടുത്തുള്ള പണിക്കാർ അപേക്ഷിക്കും!\n\n" .
                "നമുക്ക് തുടങ്ങാം! 🚀",
            'buttons' => [
                ['id' => 'start_job_posting', 'title' => '📋 ജോലി പോസ്റ്റ് ചെയ്യുക'],
                ['id' => 'my_posted_jobs', 'title' => '📂 എന്റെ ജോലികൾ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 11. Select job category.
     */
    public static function selectJobCategory(): array
    {
        $categories = JobCategory::active()
            ->orderBy('tier')
            ->orderBy('is_popular', 'desc')
            ->orderBy('sort_order')
            ->get();

        $tier1Rows = $categories->where('tier', 1)->take(5)->map(function($cat) {
            return [
                'id' => 'post_cat_' . $cat->id,
                'title' => self::safeTitle($cat->icon . ' ' . $cat->name_en),
                'description' => $cat->name_ml . ' • ' . $cat->pay_range,
            ];
        })->toArray();

        $tier2Rows = $categories->where('tier', 2)->take(4)->map(function($cat) {
            return [
                'id' => 'post_cat_' . $cat->id,
                'title' => self::safeTitle($cat->icon . ' ' . $cat->name_en),
                'description' => $cat->name_ml . ' • ' . $cat->pay_range,
            ];
        })->toArray();

        $tier2Rows[] = ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '📂 ജോലി തരം',
            'body' => "*Step 1/10* 📝\n\n" .
                "📂 *എന്ത് ജോലിയാണ്?*\n\n" .
                "Select the type of task you need help with.\n" .
                "എന്ത് തരം സഹായമാണ് വേണ്ടത്?",
            'button' => 'ജോലി തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => '🟢 Zero Skills Required',
                    'rows' => $tier1Rows,
                ],
                [
                    'title' => '🔵 Basic Skills Required',
                    'rows' => $tier2Rows,
                ],
            ],
        ];
    }

    /**
     * 12. Ask job title.
     */
    public static function askJobTitle(JobCategory $category): array
    {
        $example = match($category->slug) {
            'queue_standing' => 'ഉദാ: "RTO-യിൽ RC-ക്ക് ക്യൂ നിൽക്കുക"',
            'parcel_delivery' => 'ഉദാ: "കറിയറിൽ നിന്ന് പാഴ്സൽ എടുക്കുക"',
            'grocery_shopping' => 'ഉദാ: "സൂപ്പർ മാർക്കറ്റിൽ നിന്ന് സാധനം വാങ്ങുക"',
            default => 'ഉദാ: "ചെയ്യേണ്ട കാര്യത്തിന്റെ ചുരുക്കം"',
        };

        return [
            'type' => 'buttons',
            'header' => '✏️ ജോലി ടൈറ്റിൽ',
            'body' => "*Step 2/10* 📝\n\n" .
                "{$category->icon} *{$category->name_ml}*\n\n" .
                "Give your task a short title.\n" .
                "ജോലിക്ക് ഒരു ചെറിയ ടൈറ്റിൽ നൽകുക.\n\n" .
                "{$example}",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 13. Ask job location (text).
     */
    public static function askJobLocation(): array
    {
        return [
            'type' => 'buttons',
            'header' => '📍 സ്ഥലം',
            'body' => "*Step 3/10* 📝\n\n" .
                "📍 *ജോലി സ്ഥലം*\n\n" .
                "Where should the worker come?\n" .
                "പണിക്കാരൻ എവിടെ വരണം?\n\n" .
                "_ഉദാ: കളക്ടറേറ്റ്, എറണാകുളം_",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 14. Request location coordinates.
     */
    public static function requestJobLocationCoords(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🗺️ ലൊക്കേഷൻ',
            'body' => "*Step 4/10* 📝\n\n" .
                "🗺️ *കൃത്യമായ ലൊക്കേഷൻ*\n\n" .
                "Share the exact location for the task.\n" .
                "ജോലി സ്ഥലത്തിന്റെ കൃത്യമായ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക.\n\n" .
                "📎 → *Location* ടാപ്പ് ചെയ്യുക\n\n" .
                "_ഇത് ഒഴിവാക്കാം, പക്ഷേ workers-ന് ദിശ കാണാൻ സഹായിക്കും_",
            'buttons' => [
                ['id' => 'skip_job_coords', 'title' => '⏭️ ഒഴിവാക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 15. Ask job date.
     */
    public static function askJobDate(): array
    {
        $tomorrow = now()->addDay()->format('D, M j');
        $dayAfter = now()->addDays(2)->format('D, M j');

        return [
            'type' => 'buttons',
            'header' => '📅 തീയതി',
            'body' => "*Step 5/10* 📝\n\n" .
                "📅 *എന്ന് വേണം?*\n\n" .
                "When do you need this done?\n" .
                "ഏത് ദിവസം ചെയ്യണം?",
            'buttons' => [
                ['id' => 'job_date_today', 'title' => '📅 ഇന്ന്'],
                ['id' => 'job_date_tomorrow', 'title' => '📅 നാളെ'],
                ['id' => 'job_date_pick', 'title' => '📅 മറ്റൊരു ദിവസം'],
            ],
        ];
    }

    /**
     * 16. Ask job time.
     */
    public static function askJobTime(): array
    {
        return [
            'type' => 'buttons',
            'header' => '⏰ സമയം',
            'body' => "*Step 6/10* 📝\n\n" .
                "⏰ *എത്ര മണിക്ക്?*\n\n" .
                "What time should the worker arrive?\n" .
                "പണിക്കാരൻ എത്ര മണിക്ക് എത്തണം?\n\n" .
                "_ഉദാ: 9:00 AM അല്ലെങ്കിൽ 2:30 PM_",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 17. Ask job duration.
     */
    public static function askJobDuration(): array
    {
        return [
            'type' => 'list',
            'header' => '⏱️ സമയദൈർഘ്യം',
            'body' => "*Step 7/10* 📝\n\n" .
                "⏱️ *എത്ര സമയം എടുക്കും?*\n\n" .
                "How long will this task take approximately?\n" .
                "ഏകദേശം എത്ര സമയം എടുക്കും?",
            'button' => 'സമയം തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'സമയദൈർഘ്യം',
                    'rows' => [
                        ['id' => 'duration_30min', 'title' => '⏱️ 30 മിനിറ്റ്', 'description' => 'Quick task'],
                        ['id' => 'duration_1hr', 'title' => '⏱️ 1 മണിക്കൂർ', 'description' => 'Short task'],
                        ['id' => 'duration_2hr', 'title' => '⏱️ 2 മണിക്കൂർ', 'description' => 'Medium task'],
                        ['id' => 'duration_3hr', 'title' => '⏱️ 3 മണിക്കൂർ', 'description' => 'Longer task'],
                        ['id' => 'duration_4hr_plus', 'title' => '⏱️ 4+ മണിക്കൂർ', 'description' => 'Half day or more'],
                        ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * 18. Suggest pay amount.
     */
    public static function suggestPay(JobCategory $category, float $durationHours): array
    {
        $payRange = $category->getSuggestedPayRange();
        $minPay = $payRange['min'];
        $maxPay = $payRange['max'];

        // Adjust based on duration
        $multiplier = max(1, $durationHours / $category->typical_duration_hours);
        $suggestedMin = round($minPay * $multiplier, -1);
        $suggestedMax = round($maxPay * $multiplier, -1);

        return [
            'type' => 'buttons',
            'header' => '💰 പേയ്മെന്റ്',
            'body' => "*Step 8/10* 📝\n\n" .
                "💰 *എത്ര കൊടുക്കും?*\n\n" .
                "{$category->icon} *{$category->name_ml}*\n" .
                "⏱️ {$durationHours} hrs\n\n" .
                "Suggested pay: *₹{$suggestedMin} - ₹{$suggestedMax}*\n" .
                "സാധാരണ വില: *₹{$suggestedMin} - ₹{$suggestedMax}*\n\n" .
                "Use suggested amount or enter your own?",
            'buttons' => [
                ['id' => 'pay_suggested_min', 'title' => "💰 ₹{$suggestedMin}"],
                ['id' => 'pay_suggested_max', 'title' => "💰 ₹{$suggestedMax}"],
                ['id' => 'pay_custom', 'title' => '✏️ മറ്റൊരു തുക'],
            ],
        ];
    }

    /**
     * 19. Ask special instructions.
     */
    public static function askSpecialInstructions(): array
    {
        return [
            'type' => 'buttons',
            'header' => '📌 നിർദ്ദേശങ്ങൾ',
            'body' => "*Step 9/10* 📝\n\n" .
                "📌 *പ്രത്യേക നിർദ്ദേശങ്ങൾ*\n\n" .
                "Any special instructions for the worker?\n" .
                "പണിക്കാരന് പ്രത്യേക നിർദ്ദേശങ്ങൾ ഉണ്ടോ?\n\n" .
                "_ഉദാ: ഗേറ്റിൽ കാത്തിരിക്കുക, Token നമ്പർ 123_\n\n" .
                "_ഇല്ലെങ്കിൽ Skip ചെയ്യാം_",
            'buttons' => [
                ['id' => 'skip_instructions', 'title' => '⏭️ ഒഴിവാക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 20. Confirm job post.
     */
    public static function confirmJobPost(array $jobData, JobCategory $category): array
    {
        $title = $jobData['title'] ?? 'Untitled';
        $location = $jobData['location_name'] ?? 'Not specified';
        $date = $jobData['job_date'] ?? 'Today';
        $time = $jobData['job_time'] ?? 'Flexible';
        $duration = $jobData['duration_hours'] ?? 1;
        $pay = number_format($jobData['pay_amount'] ?? 0);
        $instructions = $jobData['special_instructions'] ?? 'None';

        return [
            'type' => 'buttons',
            'header' => '✅ സ്ഥിരീകരിക്കുക',
            'body' => "*Step 10/10* 📝\n\n" .
                "📋 *ജോലി വിവരങ്ങൾ*\n\n" .
                "{$category->icon} *{$title}*\n\n" .
                "📍 സ്ഥലം: {$location}\n" .
                "📅 തീയതി: {$date}\n" .
                "⏰ സമയം: {$time}\n" .
                "⏱️ ദൈർഘ്യം: {$duration} hrs\n" .
                "💰 പേയ്മെന്റ്: *₹{$pay}*\n" .
                "📌 നിർദ്ദേശം: {$instructions}\n\n" .
                "പോസ്റ്റ് ചെയ്യണോ?",
            'buttons' => [
                ['id' => 'confirm_job_post', 'title' => '✅ പോസ്റ്റ് ചെയ്യുക'],
                ['id' => 'edit_job_post', 'title' => '✏️ എഡിറ്റ്'],
                ['id' => 'cancel_job_post', 'title' => '❌ റദ്ദാക്കുക'],
            ],
        ];
    }

    /**
     * 21. Job posted success.
     */
    public static function jobPostedSuccess(JobPost $job, int $workerCount): array
    {
        $notifyMsg = $workerCount > 0
            ? "📢 *{$workerCount} പണിക്കാർക്ക്* അറിയിപ്പ് അയച്ചു!"
            : "📢 അടുത്തുള്ള പണിക്കാരെ അന്വേഷിക്കുന്നു...";

        return [
            'type' => 'buttons',
            'header' => '🎉 പോസ്റ്റ് ചെയ്തു!',
            'body' => "✅ *ജോലി പോസ്റ്റ് ചെയ്തു!*\n\n" .
                "📋 Job #: *{$job->job_number}*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "📍 {$job->location_display}\n" .
                "💰 {$job->pay_display}\n\n" .
                "{$notifyMsg}\n\n" .
                "ആരെങ്കിലും അപേക്ഷിക്കുമ്പോൾ അറിയിക്കും! 🔔",
            'buttons' => [
                ['id' => 'view_job_' . $job->id, 'title' => '👁️ ജോലി കാണുക'],
                ['id' => 'post_another_job', 'title' => '➕ മറ്റൊന്ന് പോസ്റ്റ്'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | WORKER NOTIFICATION MESSAGES
    |--------------------------------------------------------------------------
    */

    /**
     * 22. New job notification for worker.
     */
    public static function newJobNotification(JobPost $job, float $distanceKm): array
    {
        $distance = $distanceKm < 1 
            ? round($distanceKm * 1000) . 'm' 
            : round($distanceKm, 1) . ' km';

        $applicationsText = $job->applications_count > 0
            ? "\n👥 *{$job->applications_count} പേർ* ഇതിനകം അപേക്ഷിച്ചു!"
            : "";

        $instructionsText = $job->special_instructions
            ? "\n\n📌 _{$job->special_instructions}_"
            : "";

        return [
            'type' => 'buttons',
            'header' => '👷 പുതിയ ജോലി!',
            'body' => "👷 *NEW TASK AVAILABLE!*\n" .
                "*പുതിയ ജോലി ലഭ്യമാണ്!*\n\n" .
                "{$job->category->icon} *{$job->title}*\n\n" .
                "📍 {$job->location_display} ({$distance} അകലെ)\n" .
                "📅 {$job->formatted_date_time}\n" .
                "⏱️ ദൈർഘ്യം: {$job->duration_display}\n" .
                "💰 പേയ്മെന്റ്: *{$job->pay_display}*\n" .
                "⭐ Task Giver: {$job->poster->display_name}" .
                $applicationsText .
                $instructionsText,
            'buttons' => [
                ['id' => 'apply_job_' . $job->id, 'title' => '✅ താൽപ്പര്യമുണ്ട്'],
                ['id' => 'view_job_detail_' . $job->id, 'title' => '👁️ വിശദാംശങ്ങൾ'],
                ['id' => 'skip_job_' . $job->id, 'title' => '❌ ഒഴിവാക്കുക'],
            ],
        ];
    }

    /**
     * 23. Application confirmed to worker.
     */
    public static function applicationConfirmed(JobPost $job, int $position): array
    {
        return [
            'type' => 'buttons',
            'header' => '✅ അപേക്ഷിച്ചു!',
            'body' => "✅ *അപേക്ഷ സ്വീകരിച്ചു!*\n\n" .
                "{$job->category->icon} {$job->title}\n\n" .
                "📍 നിങ്ങൾ *#{$position}* സ്ഥാനത്താണ്\n\n" .
                "Task giver നിങ്ങളെ തിരഞ്ഞെടുക്കുമ്പോൾ അറിയിക്കും! 🔔\n\n" .
                "_മറ്റ് ജോലികളും കാണാൻ മറക്കരുത്_",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 മറ്റ് ജോലികൾ'],
                ['id' => 'my_applications', 'title' => '📋 എന്റെ അപേക്ഷകൾ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 24. Position filled notification.
     */
    public static function positionFilled(JobPost $job): array
    {
        return [
            'type' => 'buttons',
            'header' => '📋 ജോലി നിറഞ്ഞു',
            'body' => "📋 *ജോലി മറ്റൊരാൾക്ക് ലഭിച്ചു*\n\n" .
                "{$job->category->icon} {$job->title}\n\n" .
                "ക്ഷമിക്കണം, ഈ ജോലി മറ്റൊരു പണിക്കാരന് നൽകി.\n\n" .
                "_വേറെ ജോലികൾ ഉടൻ വരും!_",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 മറ്റ് ജോലികൾ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | TASK GIVER SELECTION MESSAGES
    |--------------------------------------------------------------------------
    */

    /**
     * 25. New application notification to poster.
     */
    public static function newApplicationNotification(JobApplication $application): array
    {
        $worker = $application->worker;
        $job = $application->jobPost;

        $ratingText = $worker->rating_count > 0
            ? "⭐ {$worker->short_rating}"
            : "🆕 New Worker";

        $vehicleText = $worker->vehicle_type !== VehicleType::NONE
            ? "\n🚗 {$worker->vehicle_display}"
            : "";

        $messageText = $application->message
            ? "\n\n💬 \"{$application->message}\""
            : "";

        $proposedText = $application->proposed_amount
            ? "\n💵 Proposed: {$application->proposed_amount_display}"
            : "";

        return [
            'type' => 'buttons',
            'header' => '👤 പുതിയ അപേക്ഷ!',
            'body' => "👤 *New Application!*\n" .
                "*പുതിയ അപേക്ഷ ലഭിച്ചു!*\n\n" .
                "📋 For: {$job->title}\n\n" .
                "👤 *{$worker->name}*\n" .
                "{$ratingText}\n" .
                "✅ {$worker->jobs_completed} jobs done" .
                $vehicleText .
                $proposedText .
                $messageText,
            'buttons' => [
                ['id' => 'select_worker_' . $application->id, 'title' => '✅ തിരഞ്ഞെടുക്കുക'],
                ['id' => 'view_all_apps_' . $job->id, 'title' => '👥 എല്ലാവരും കാണുക'],
                ['id' => 'reject_app_' . $application->id, 'title' => '❌ നിരസിക്കുക'],
            ],
        ];
    }

    /**
     * 26. Show all applications list.
     */
    public static function showAllApplications(Collection $applications, JobPost $job): array
    {
        if ($applications->isEmpty()) {
            return [
                'type' => 'buttons',
                'header' => '📋 അപേക്ഷകൾ',
                'body' => "📋 *{$job->title}*\n\n" .
                    "ഇതുവരെ ആരും അപേക്ഷിച്ചിട്ടില്ല.\n" .
                    "No applications yet.\n\n" .
                    "_പണിക്കാർ ഉടൻ അപേക്ഷിക്കും!_",
                'buttons' => [
                    ['id' => 'view_job_' . $job->id, 'title' => '👁️ ജോലി കാണുക'],
                    ['id' => 'main_menu', 'title' => '🏠 മെനു'],
                ],
            ];
        }

        $rows = $applications->take(9)->map(function($app) {
            $worker = $app->worker;
            $rating = $worker->rating_count > 0 ? "⭐{$worker->rating}" : "🆕";
            return [
                'id' => 'select_worker_' . $app->id,
                'title' => self::safeTitle("👤 " . $worker->name),
                'description' => "{$rating} • {$worker->jobs_completed} jobs • {$app->time_since_applied}",
            ];
        })->toArray();

        $rows[] = ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '👥 അപേക്ഷകൾ',
            'body' => "📋 *{$job->title}*\n\n" .
                "👥 {$applications->count()} പേർ അപേക്ഷിച്ചു\n\n" .
                "Select a worker to assign the task:",
            'button' => 'പണിക്കാർ കാണുക',
            'sections' => [
                [
                    'title' => 'അപേക്ഷകർ',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * 27. Worker selected confirmation to poster.
     */
    public static function workerSelected(JobWorker $worker, JobPost $job): array
    {
        return [
            'type' => 'buttons',
            'header' => '✅ പണിക്കാരനെ തിരഞ്ഞെടുത്തു!',
            'body' => "✅ *Worker Selected!*\n" .
                "*പണിക്കാരനെ തിരഞ്ഞെടുത്തു!*\n\n" .
                "📋 {$job->title}\n\n" .
                "👤 *{$worker->name}*\n" .
                "📞 {$worker->user->formatted_phone}\n" .
                "{$worker->short_rating}\n\n" .
                "പണിക്കാരനെ അറിയിച്ചു! 🔔\n\n" .
                "_ജോലി ദിവസം arrival photo ചോദിക്കും_",
            'buttons' => [
                ['id' => 'call_worker_' . $worker->id, 'title' => '📞 വിളിക്കുക'],
                ['id' => 'view_job_' . $job->id, 'title' => '👁️ ജോലി കാണുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 28. You are selected notification to worker.
     */
    public static function youAreSelected(JobPost $job): array
    {
        $poster = $job->poster;

        return [
            'type' => 'buttons',
            'header' => '🎉 നിങ്ങളെ തിരഞ്ഞെടുത്തു!',
            'body' => "🎉 *YOU GOT THE TASK!*\n" .
                "*നിങ്ങൾക്ക് ജോലി ലഭിച്ചു!*\n\n" .
                "{$job->category->icon} *{$job->title}*\n\n" .
                "📍 {$job->location_display}\n" .
                "📅 {$job->formatted_date_time}\n" .
                "💰 *{$job->pay_display}*\n\n" .
                "📞 Task Giver: *{$poster->display_name}*\n" .
                "📱 {$poster->formatted_phone}\n\n" .
                "⏰ *5 മിനിറ്റ് നേരത്തെ എത്തുക!*\n" .
                "Please arrive 5 minutes early!",
            'buttons' => [
                ['id' => 'call_poster_' . $job->id, 'title' => '📞 വിളിക്കുക'],
                ['id' => 'get_directions_' . $job->id, 'title' => '📍 ദിശ കാണുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | JOB EXECUTION MESSAGES
    |--------------------------------------------------------------------------
    */

    /**
     * 29. Request arrival photo.
     */
    public static function requestArrivalPhoto(JobPost $job): array
    {
        return [
            'type' => 'buttons',
            'header' => '📸 Arrival Photo',
            'body' => "📸 *എത്തിയെന്ന് സ്ഥിരീകരിക്കുക*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "📍 {$job->location_display}\n\n" .
                "Please send a photo to confirm you've arrived at the location.\n" .
                "നിങ്ങൾ സ്ഥലത്ത് എത്തിയതിന്റെ ഫോട്ടോ അയക്കുക.\n\n" .
                "📎 → Camera ടാപ്പ് ചെയ്യുക",
            'buttons' => [
                ['id' => 'skip_arrival_photo_' . $job->id, 'title' => '⏭️ ഒഴിവാക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 30. Worker arrived notification to poster.
     */
    public static function workerArrived(JobVerification $verification): array
    {
        $job = $verification->jobPost;
        $worker = $verification->worker;
        $hasPhoto = $verification->arrival_photo_url ? '📸 [Photo attached]' : '';

        return [
            'type' => 'buttons',
            'header' => '📍 പണിക്കാരൻ എത്തി!',
            'body' => "📍 *Worker Arrived!*\n" .
                "*പണിക്കാരൻ സ്ഥലത്ത് എത്തി!*\n\n" .
                "{$job->category->icon} {$job->title}\n\n" .
                "👤 {$worker->name}\n" .
                "⏰ {$verification->arrival_verified_at->format('h:i A')}\n" .
                "{$hasPhoto}\n\n" .
                "_ജോലി പുരോഗമിക്കുന്നു..._",
            'buttons' => [
                ['id' => 'call_worker_' . $worker->id, 'title' => '📞 വിളിക്കുക'],
                ['id' => 'view_job_' . $job->id, 'title' => '👁️ ജോലി കാണുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 31. Request completion confirmation (to worker).
     */
    public static function requestCompletionConfirmation(JobPost $job): array
    {
        return [
            'type' => 'buttons',
            'header' => '✅ ജോലി കഴിഞ്ഞോ?',
            'body' => "✅ *ജോലി പൂർത്തിയായോ?*\n\n" .
                "{$job->category->icon} {$job->title}\n\n" .
                "Have you completed the task?\n" .
                "ജോലി പൂർത്തിയായോ?\n\n" .
                "_Completion photo അയയ്ക്കാം (optional)_",
            'buttons' => [
                ['id' => 'confirm_complete_' . $job->id, 'title' => '✅ പൂർത്തിയായി'],
                ['id' => 'send_completion_photo_' . $job->id, 'title' => '📸 ഫോട്ടോ അയക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 32. Request worker rating (to poster).
     */
    public static function requestWorkerRating(JobPost $job, JobWorker $worker): array
    {
        return [
            'type' => 'list',
            'header' => '⭐ റേറ്റിംഗ്',
            'body' => "⭐ *പണിക്കാരനെ റേറ്റ് ചെയ്യുക*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "👤 {$worker->name}\n\n" .
                "How was the worker?\n" .
                "പണിക്കാരൻ എങ്ങനെയായിരുന്നു?",
            'button' => 'റേറ്റിംഗ് തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'റേറ്റിംഗ്',
                    'rows' => [
                        ['id' => 'rate_5_' . $job->id, 'title' => '⭐⭐⭐⭐⭐ Excellent', 'description' => 'Outstanding work!'],
                        ['id' => 'rate_4_' . $job->id, 'title' => '⭐⭐⭐⭐ Very Good', 'description' => 'Great job'],
                        ['id' => 'rate_3_' . $job->id, 'title' => '⭐⭐⭐ Good', 'description' => 'Satisfactory'],
                        ['id' => 'rate_2_' . $job->id, 'title' => '⭐⭐ Fair', 'description' => 'Could be better'],
                        ['id' => 'rate_1_' . $job->id, 'title' => '⭐ Poor', 'description' => 'Not satisfied'],
                    ],
                ],
            ],
        ];
    }

    /**
     * 33. Request payment confirmation.
     */
    public static function requestPaymentConfirmation(JobPost $job): array
    {
        return [
            'type' => 'buttons',
            'header' => '💰 പേയ്മെന്റ്',
            'body' => "💰 *പേയ്മെന്റ് സ്ഥിരീകരിക്കുക*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "💵 Amount: *{$job->pay_display}*\n\n" .
                "How did you pay the worker?\n" .
                "പണിക്കാരന് എങ്ങനെ പണം കൊടുത്തു?",
            'buttons' => [
                ['id' => 'paid_cash_' . $job->id, 'title' => '💵 Cash കൊടുത്തു'],
                ['id' => 'paid_upi_' . $job->id, 'title' => '📱 UPI ചെയ്തു'],
                ['id' => 'paid_other_' . $job->id, 'title' => '💳 മറ്റ് വഴി'],
            ],
        ];
    }

    /**
     * 34. Job completed summary.
     */
    public static function jobCompleted(JobPost $job, bool $isWorker = false): array
    {
        $worker = $job->assignedWorker;
        $poster = $job->poster;
        $verification = $job->verification;

        if ($isWorker) {
            // Message for worker
            $ratingText = $verification?->rating 
                ? "\n⭐ Rating: " . str_repeat('⭐', $verification->rating)
                : "";

            return [
                'type' => 'buttons',
                'header' => '🎉 ജോലി പൂർത്തിയായി!',
                'body' => "🎉 *Task Completed!*\n" .
                    "*ജോലി വിജയകരമായി പൂർത്തിയായി!*\n\n" .
                    "{$job->category->icon} {$job->title}\n\n" .
                    "💰 Earned: *{$job->pay_display}*" .
                    $ratingText . "\n\n" .
                    "നന്ദി! 🙏\n" .
                    "_മറ്റ് ജോലികൾ കാണൂ!_",
                'buttons' => [
                    ['id' => 'browse_jobs', 'title' => '🔍 മറ്റ് ജോലികൾ'],
                    ['id' => 'my_earnings', 'title' => '💰 എന്റെ വരുമാനം'],
                    ['id' => 'main_menu', 'title' => '🏠 മെനു'],
                ],
            ];
        } else {
            // Message for poster
            return [
                'type' => 'buttons',
                'header' => '🎉 ജോലി പൂർത്തിയായി!',
                'body' => "🎉 *Task Completed!*\n" .
                    "*ജോലി വിജയകരമായി പൂർത്തിയായി!*\n\n" .
                    "{$job->category->icon} {$job->title}\n\n" .
                    "👤 Worker: {$worker->name}\n" .
                    "💰 Paid: *{$job->pay_display}*\n" .
                    "✅ Status: Completed\n\n" .
                    "നന്ദി NearBuy ഉപയോഗിച്ചതിന്! 🙏",
                'buttons' => [
                    ['id' => 'post_another_job', 'title' => '➕ മറ്റൊരു ജോലി'],
                    ['id' => 'my_posted_jobs', 'title' => '📋 എന്റെ ജോലികൾ'],
                    ['id' => 'main_menu', 'title' => '🏠 മെനു'],
                ],
            ];
        }
    }

    /**
     * 35. Worker earnings summary.
     */
    public static function workerEarningsSummary(JobWorker $worker, ?WorkerEarning $weekEarnings = null): array
    {
        $totalEarnings = $weekEarnings?->total_earnings ?? 0;
        $totalJobs = $weekEarnings?->total_jobs ?? 0;
        $avgPerJob = $totalJobs > 0 ? round($totalEarnings / $totalJobs) : 0;

        return [
            'type' => 'buttons',
            'header' => '💰 വരുമാന സംഗ്രഹം',
            'body' => "💰 *ഈ ആഴ്ച വരുമാനം*\n" .
                "*This Week's Earnings*\n\n" .
                "💵 Total: *₹" . number_format($totalEarnings) . "*\n" .
                "📋 Jobs: {$totalJobs}\n" .
                "📊 Average: ₹{$avgPerJob}/job\n\n" .
                "📈 *ആകെ വരുമാനം*\n" .
                "Total All-time: *{$worker->earnings_display}*\n" .
                "✅ Jobs Completed: {$worker->jobs_completed}\n" .
                "⭐ Rating: {$worker->short_rating}",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 ജോലികൾ കാണുക'],
                ['id' => 'my_badges', 'title' => '🏅 എന്റെ ബാഡ്ജുകൾ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ERROR AND INFO MESSAGES
    |--------------------------------------------------------------------------
    */

    /**
     * 36. No workers nearby.
     */
    public static function noWorkersNearby(): array
    {
        return [
            'type' => 'buttons',
            'header' => '😕 പണിക്കാർ ഇല്ല',
            'body' => "😕 *അടുത്ത് പണിക്കാർ ഇല്ല*\n\n" .
                "No workers available nearby right now.\n" .
                "ഇപ്പോൾ അടുത്ത് പണിക്കാർ ലഭ്യമല്ല.\n\n" .
                "_കുറച്ച് കഴിഞ്ഞ് വീണ്ടും ശ്രമിക്കുക_",
            'buttons' => [
                ['id' => 'retry_post_job', 'title' => '🔄 വീണ്ടും ശ്രമിക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 37. No jobs available.
     */
    public static function noJobsAvailable(): array
    {
        return [
            'type' => 'buttons',
            'header' => '😕 ജോലികൾ ഇല്ല',
            'body' => "😕 *ജോലികൾ ലഭ്യമല്ല*\n\n" .
                "No tasks available matching your preferences.\n" .
                "നിങ്ങളുടെ മുൻഗണനകൾക്ക് അനുയോജ്യമായ ജോലികൾ ഇല്ല.\n\n" .
                "_പുതിയ ജോലികൾ വരുമ്പോൾ അറിയിക്കും!_",
            'buttons' => [
                ['id' => 'refresh_jobs', 'title' => '🔄 പുതുക്കുക'],
                ['id' => 'edit_preferences', 'title' => '⚙️ മുൻഗണനകൾ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 38. Job expired.
     */
    public static function jobExpired(): array
    {
        return [
            'type' => 'buttons',
            'body' => "⏰ *ജോലി കാലഹരണപ്പെട്ടു*\n\n" .
                "This task has expired or been filled.\n" .
                "ഈ ജോലി കാലഹരണപ്പെട്ടു അല്ലെങ്കിൽ മറ്റൊരാൾക്ക് ലഭിച്ചു.\n\n" .
                "_മറ്റ് ജോലികൾ കാണുക_",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 മറ്റ് ജോലികൾ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 39. Already applied.
     */
    public static function alreadyApplied(): array
    {
        return [
            'type' => 'buttons',
            'body' => "ℹ️ *ഇതിനകം അപേക്ഷിച്ചു*\n\n" .
                "You've already applied for this task.\n" .
                "നിങ്ങൾ ഈ ജോലിക്ക് ഇതിനകം അപേക്ഷിച്ചു.\n\n" .
                "_Task giver-ന്റെ മറുപടി കാത്തിരിക്കുക_",
            'buttons' => [
                ['id' => 'my_applications', 'title' => '📋 എന്റെ അപേക്ഷകൾ'],
                ['id' => 'browse_jobs', 'title' => '🔍 മറ്റ് ജോലികൾ'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /**
     * 40. Worker busy (has active task).
     */
    public static function workerBusy(JobPost $activeJob): array
    {
        return [
            'type' => 'buttons',
            'header' => '⚠️ സജീവ ജോലി ഉണ്ട്',
            'body' => "⚠️ *സജീവ ജോലി ഉണ്ട്*\n\n" .
                "You currently have an active task.\n" .
                "നിങ്ങൾക്ക് ഇപ്പോൾ ഒരു സജീവ ജോലി ഉണ്ട്.\n\n" .
                "{$activeJob->category->icon} {$activeJob->title}\n" .
                "📍 {$activeJob->location_display}\n\n" .
                "_ആദ്യം ഇത് പൂർത്തിയാക്കുക_",
            'buttons' => [
                ['id' => 'view_active_job_' . $activeJob->id, 'title' => '👁️ സജീവ ജോലി'],
                ['id' => 'complete_job_' . $activeJob->id, 'title' => '✅ പൂർത്തിയാക്കുക'],
                ['id' => 'main_menu', 'title' => '🏠 മെനു'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MENU MESSAGES
    |--------------------------------------------------------------------------
    */

    /**
     * Worker main menu.
     */
    public static function workerMenu(JobWorker $worker): array
    {
        $activeJobsCount = $worker->activeJobs()->count();
        $pendingAppsCount = $worker->pendingApplications()->count();

        return [
            'type' => 'list',
            'header' => '👷 പണിക്കാർ മെനു',
            'body' => "സ്വാഗതം, *{$worker->name}*! 👋\n\n" .
                "⭐ റേറ്റിംഗ്: {$worker->short_rating}\n" .
                "✅ ജോലികൾ: {$worker->jobs_completed}\n" .
                "💰 വരുമാനം: {$worker->earnings_display}\n\n" .
                "📋 Active: {$activeJobsCount} | Pending: {$pendingAppsCount}",
            'button' => 'തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'ജോലി ഓപ്ഷനുകൾ',
                    'rows' => [
                        ['id' => 'browse_jobs', 'title' => '🔍 ജോലികൾ കാണുക', 'description' => 'Find available tasks nearby'],
                        ['id' => 'my_active_jobs', 'title' => '📋 സജീവ ജോലികൾ', 'description' => 'Your current assigned tasks'],
                        ['id' => 'my_applications', 'title' => '📝 എന്റെ അപേക്ഷകൾ', 'description' => 'Pending applications'],
                        ['id' => 'my_earnings', 'title' => '💰 വരുമാനം', 'description' => 'Earnings & statistics'],
                        ['id' => 'worker_profile', 'title' => '👤 പ്രൊഫൈൽ', 'description' => 'Edit your profile'],
                        ['id' => 'main_menu', 'title' => '🏠 മെയിൻ മെനു', 'description' => 'Main Menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Job poster menu.
     */
    public static function posterMenu(User $user): array
    {
        $activeJobsCount = $user->activeJobPosts()->count();

        return [
            'type' => 'list',
            'header' => '📋 ജോലി മെനു',
            'body' => "👋 *{$user->display_name}*\n\n" .
                "📋 Active Tasks: {$activeJobsCount}\n\n" .
                "എന്ത് ചെയ്യണം?",
            'button' => 'തിരഞ്ഞെടുക്കുക',
            'sections' => [
                [
                    'title' => 'ഓപ്ഷനുകൾ',
                    'rows' => [
                        ['id' => 'post_job', 'title' => '📋 ജോലി പോസ്റ്റ് ചെയ്യുക', 'description' => 'Post a new task'],
                        ['id' => 'my_posted_jobs', 'title' => '📂 എന്റെ ജോലികൾ', 'description' => 'View your posted tasks'],
                        ['id' => 'view_applications', 'title' => '👥 അപേക്ഷകൾ കാണുക', 'description' => 'Review worker applications'],
                        ['id' => 'main_menu', 'title' => '🏠 മെയിൻ മെനു', 'description' => 'Main Menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Browse jobs results.
     */
    public static function browseJobsResults(Collection $jobs, string $location = 'അടുത്ത്'): array
    {
        if ($jobs->isEmpty()) {
            return self::noJobsAvailable();
        }

        $rows = $jobs->take(9)->map(function($job) {
            $title = $job->category->icon . ' ' . $job->title;
            return [
                'id' => 'view_job_detail_' . $job->id,
                'title' => self::safeTitle($title),
                'description' => "{$job->pay_display} • {$job->formatted_date_time}",
            ];
        })->toArray();

        $rows[] = ['id' => 'main_menu', 'title' => '🏠 മെനു', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '💼 ലഭ്യമായ ജോലികൾ',
            'body' => "💼 *{$jobs->count()} ജോലികൾ* {$location}-ൽ ലഭ്യമാണ്\n\n" .
                "Select a task to view details and apply:",
            'button' => 'ജോലികൾ കാണുക',
            'sections' => [
                [
                    'title' => 'ലഭ്യമായ ജോലികൾ',
                    'rows' => $rows,
                ],
            ],
        ];
    }
}