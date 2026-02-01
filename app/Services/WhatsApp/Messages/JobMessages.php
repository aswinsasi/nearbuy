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
 * IMPORTANT: WhatsApp Interactive Message Limits:
 * - List Item Title: 24 characters max
 * - List Button Text: 20 characters max
 * - Button Title: 20 characters max
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
            'header' => '👷 Njaanum Panikkar',
            'body' => "👷 *ഞാനും പണിക്കാർ - Njaanum Panikkar*\n\n" .
                "Got free time? Earn money doing simple tasks!\n" .
                "ഫ്രീ ടൈം ഉണ്ടോ? ലളിതമായ ജോലികൾ ചെയ്ത് പണം സമ്പാദിക്കൂ!\n\n" .
                "✅ No special skills needed\n" .
                "✅ Work when you want\n" .
                "✅ Get paid same day\n\n" .
                "നമുക്ക് തുടങ്ങാം! 💪",
            'buttons' => [
                ['id' => 'start_worker_registration', 'title' => '✅ Register'],
                ['id' => 'browse_jobs', 'title' => '🔍 Browse Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '👤 Name',
            'body' => "*Step 1/7* 📝\n\n" .
                "👤 *നിങ്ങളുടെ പേര്*\n\n" .
                "Please enter your full name\n" .
                "നിങ്ങളുടെ മുഴുവൻ പേര് എഴുതുക\n\n" .
                "_ഉദാ: രാജേഷ് കുമാർ_",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '📸 Photo',
            'body' => "*Step 2/7* 📝\n\n" .
                "📸 *പ്രൊഫൈൽ ഫോട്ടോ*\n\n" .
                "A clear photo helps build trust with task givers.\n" .
                "വ്യക്തമായ ഫോട്ടോ വിശ്വാസം വർദ്ധിപ്പിക്കും.\n\n" .
                "📎 → Camera/Gallery ടാപ്പ് ചെയ്യുക\n\n" .
                "_ഫോട്ടോ ഇല്ലെങ്കിൽ Skip ചെയ്യാം_",
            'buttons' => [
                ['id' => 'skip_worker_photo', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '📍 Location',
            'body' => "*Step 3/7* 📝\n\n" .
                "📍 *നിങ്ങളുടെ ലൊക്കേഷൻ*\n\n" .
                "Share your location so we can find jobs near you.\n" .
                "അടുത്തുള്ള ജോലികൾ കണ്ടെത്താൻ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക.\n\n" .
                "📎 → *Location* ടാപ്പ് ചെയ്യുക",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '🚗 Vehicle',
            'body' => "*Step 4/7* 📝\n\n" .
                "🚗 *വാഹനം ഉണ്ടോ?*\n\n" .
                "Do you have a vehicle for transportation?\n" .
                "യാത്രയ്ക്ക് വാഹനം ഉണ്ടോ?\n\n" .
                "_ഡെലിവറി ജോലികൾക്ക് വാഹനം വേണം_",
            'buttons' => [
                ['id' => 'vehicle_none', 'title' => '🚶 Walking Only'],
                ['id' => 'vehicle_two_wheeler', 'title' => '🛵 Two Wheeler'],
                ['id' => 'vehicle_four_wheeler', 'title' => '🚗 Four Wheeler'],
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
            'header' => '💼 Job Types',
            'body' => "*Step 5/7* 📝\n\n" .
                "💼 *ഏത് ജോലികൾ ചെയ്യാം?*\n\n" .
                "Select job types you can do.\n" .
                "നിങ്ങൾക്ക് ചെയ്യാൻ കഴിയുന്ന ജോലികൾ തിരഞ്ഞെടുക്കുക.\n\n" .
                "_ഒന്നിലധികം തിരഞ്ഞെടുക്കാം. Done അമർത്തുക._",
            'button' => 'Select Jobs',
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
            'header' => '🕐 Availability',
            'body' => "*Step 6/7* 📝\n\n" .
                "🕐 *എപ്പോൾ ലഭ്യമാണ്?*\n\n" .
                "When are you available for work?\n" .
                "ജോലിക്ക് എപ്പോൾ ലഭ്യമാണ്?",
            'button' => 'Select Time',
            'sections' => [
                [
                    'title' => 'Available Time',
                    'rows' => [
                        ['id' => 'avail_morning', 'title' => '🌅 Morning', 'description' => '6:00 AM - 12:00 PM'],
                        ['id' => 'avail_afternoon', 'title' => '☀️ Afternoon', 'description' => '12:00 PM - 6:00 PM'],
                        ['id' => 'avail_evening', 'title' => '🌆 Evening', 'description' => '6:00 PM - 10:00 PM'],
                        ['id' => 'avail_flexible', 'title' => '🔄 Flexible', 'description' => 'Any time'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu', 'description' => 'Main Menu'],
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
            'two_wheeler' => '🛵 Two Wheeler',
            'four_wheeler' => '🚗 Four Wheeler',
            default => '🚶 Walking Only',
        };
        $jobCount = count($workerData['job_types'] ?? []);
        $hasPhoto = !empty($workerData['photo_url']) ? '✅' : '❌';

        return [
            'type' => 'buttons',
            'header' => '✅ Confirm',
            'body' => "*Step 7/7* 📝\n\n" .
                "📋 *Registration Details*\n\n" .
                "👤 Name: *{$name}*\n" .
                "📸 Photo: {$hasPhoto}\n" .
                "📍 Location: ✅\n" .
                "🚗 Vehicle: {$vehicleDisplay}\n" .
                "💼 Jobs: {$jobCount} types\n\n" .
                "All correct?",
            'buttons' => [
                ['id' => 'confirm_worker_reg', 'title' => '✅ Confirm'],
                ['id' => 'edit_worker_reg', 'title' => '✏️ Edit'],
                ['id' => 'cancel_worker_reg', 'title' => '❌ Cancel'],
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
            'header' => '🎉 Registered!',
            'body' => "🎉 *Welcome, {$worker->name}!*\n\n" .
                "You are now registered as a worker!\n" .
                "നിങ്ങൾ ഇപ്പോൾ ഒരു പണിക്കാരനായി രജിസ്റ്റർ ചെയ്തു!\n\n" .
                "✅ Get alerts for nearby jobs\n" .
                "✅ Apply to jobs you like\n" .
                "✅ Get paid after completion\n\n" .
                "Browse available jobs now! 💼",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 Browse Jobs'],
                ['id' => 'worker_profile', 'title' => '👤 My Profile'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '📋 Post Task',
            'body' => "📋 *Post a Task*\n\n" .
                "Need help with something?\n" .
                "Post a task and nearby workers will apply!\n\n" .
                "എന്തെങ്കിലും സഹായം വേണോ?\n" .
                "ഒരു ജോലി പോസ്റ്റ് ചെയ്യൂ!\n\n" .
                "Let's start! 🚀",
            'buttons' => [
                ['id' => 'start_job_posting', 'title' => '📋 Post Task'],
                ['id' => 'my_posted_jobs', 'title' => '📂 My Tasks'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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

        $tier2Rows[] = ['id' => 'main_menu', 'title' => '🏠 Menu', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '📂 Job Type',
            'body' => "*Step 1/10* 📝\n\n" .
                "📂 *What type of task?*\n\n" .
                "Select the type of task you need help with.\n" .
                "എന്ത് തരം സഹായമാണ് വേണ്ടത്?",
            'button' => 'Select Job',
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
            'queue_standing' => 'Ex: "Stand in queue at RTO"',
            'parcel_delivery' => 'Ex: "Pick up parcel"',
            'grocery_shopping' => 'Ex: "Buy groceries"',
            default => 'Ex: "Brief task description"',
        };

        return [
            'type' => 'buttons',
            'header' => '✏️ Job Title',
            'body' => "*Step 2/10* 📝\n\n" .
                "{$category->icon} *{$category->name_ml}*\n\n" .
                "Give your task a short title.\n" .
                "ജോലിക്ക് ഒരു ചെറിയ ടൈറ്റിൽ നൽകുക.\n\n" .
                "{$example}",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '📍 Location',
            'body' => "*Step 3/10* 📝\n\n" .
                "📍 *Job Location*\n\n" .
                "Where should the worker come?\n" .
                "പണിക്കാരൻ എവിടെ വരണം?\n\n" .
                "_Ex: Collectorate, Ernakulam_",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '🗺️ Location',
            'body' => "*Step 4/10* 📝\n\n" .
                "🗺️ *Exact Location*\n\n" .
                "Share the exact location for the task.\n" .
                "ജോലി സ്ഥലത്തിന്റെ കൃത്യമായ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക.\n\n" .
                "📎 → *Location* tap\n\n" .
                "_Optional but helps workers find the place_",
            'buttons' => [
                ['id' => 'skip_job_coords', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '📅 Date',
            'body' => "*Step 5/10* 📝\n\n" .
                "📅 *When needed?*\n\n" .
                "When do you need this done?\n" .
                "ഏത് ദിവസം ചെയ്യണം?",
            'buttons' => [
                ['id' => 'job_date_today', 'title' => '📅 Today'],
                ['id' => 'job_date_tomorrow', 'title' => '📅 Tomorrow'],
                ['id' => 'job_date_pick', 'title' => '📅 Other Day'],
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
            'header' => '⏰ Time',
            'body' => "*Step 6/10* 📝\n\n" .
                "⏰ *What time?*\n\n" .
                "What time should the worker arrive?\n" .
                "പണിക്കാരൻ എത്ര മണിക്ക് എത്തണം?\n\n" .
                "_Ex: 9:00 AM or 2:30 PM_",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '⏱️ Duration',
            'body' => "*Step 7/10* 📝\n\n" .
                "⏱️ *How long?*\n\n" .
                "How long will this task take approximately?\n" .
                "ഏകദേശം എത്ര സമയം എടുക്കും?",
            'button' => 'Select Duration',
            'sections' => [
                [
                    'title' => 'Duration',
                    'rows' => [
                        ['id' => 'duration_30min', 'title' => '⏱️ 30 minutes', 'description' => 'Quick task'],
                        ['id' => 'duration_1hr', 'title' => '⏱️ 1 hour', 'description' => 'Short task'],
                        ['id' => 'duration_2hr', 'title' => '⏱️ 2 hours', 'description' => 'Medium task'],
                        ['id' => 'duration_3hr', 'title' => '⏱️ 3 hours', 'description' => 'Longer task'],
                        ['id' => 'duration_4hr_plus', 'title' => '⏱️ 4+ hours', 'description' => 'Half day or more'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu', 'description' => 'Main Menu'],
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
            'header' => '💰 Payment',
            'body' => "*Step 8/10* 📝\n\n" .
                "💰 *How much to pay?*\n\n" .
                "{$category->icon} *{$category->name_ml}*\n" .
                "⏱️ {$durationHours} hrs\n\n" .
                "Suggested: *₹{$suggestedMin} - ₹{$suggestedMax}*\n\n" .
                "Use suggested or enter your own?",
            'buttons' => [
                ['id' => 'pay_suggested_min', 'title' => "💰 ₹{$suggestedMin}"],
                ['id' => 'pay_suggested_max', 'title' => "💰 ₹{$suggestedMax}"],
                ['id' => 'pay_custom', 'title' => '✏️ Other Amount'],
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
            'header' => '📌 Instructions',
            'body' => "*Step 9/10* 📝\n\n" .
                "📌 *Special Instructions*\n\n" .
                "Any special instructions for the worker?\n" .
                "പണിക്കാരന് പ്രത്യേക നിർദ്ദേശങ്ങൾ ഉണ്ടോ?\n\n" .
                "_Ex: Wait at gate, Token #123_\n\n" .
                "_Skip if none_",
            'buttons' => [
                ['id' => 'skip_instructions', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '✅ Confirm',
            'body' => "*Step 10/10* 📝\n\n" .
                "📋 *Job Details*\n\n" .
                "{$category->icon} *{$title}*\n\n" .
                "📍 Location: {$location}\n" .
                "📅 Date: {$date}\n" .
                "⏰ Time: {$time}\n" .
                "⏱️ Duration: {$duration} hrs\n" .
                "💰 Payment: *₹{$pay}*\n" .
                "📌 Instructions: {$instructions}\n\n" .
                "Post this job?",
            'buttons' => [
                ['id' => 'confirm_job_post', 'title' => '✅ Post Job'],
                ['id' => 'edit_job_post', 'title' => '✏️ Edit'],
                ['id' => 'cancel_job_post', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * 21. Job posted success.
     */
    public static function jobPostedSuccess(JobPost $job, int $workerCount): array
    {
        $notifyMsg = $workerCount > 0
            ? "📢 *{$workerCount} workers* notified!"
            : "📢 Finding nearby workers...";

        return [
            'type' => 'buttons',
            'header' => '🎉 Posted!',
            'body' => "✅ *Job Posted!*\n\n" .
                "📋 Job #: *{$job->job_number}*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "📍 {$job->location_display}\n" .
                "💰 {$job->pay_display}\n\n" .
                "{$notifyMsg}\n\n" .
                "We'll notify you when someone applies! 🔔",
            'buttons' => [
                ['id' => 'view_job_' . $job->id, 'title' => '👁️ View Job'],
                ['id' => 'post_another_job', 'title' => '➕ Post Another'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            ? "\n👥 *{$job->applications_count}* already applied!"
            : "";

        $instructionsText = $job->special_instructions
            ? "\n\n📌 _{$job->special_instructions}_"
            : "";

        return [
            'type' => 'buttons',
            'header' => '👷 New Job!',
            'body' => "👷 *NEW TASK AVAILABLE!*\n\n" .
                "{$job->category->icon} *{$job->title}*\n\n" .
                "📍 {$job->location_display} ({$distance} away)\n" .
                "📅 {$job->formatted_date_time}\n" .
                "⏱️ Duration: {$job->duration_display}\n" .
                "💰 Payment: *{$job->pay_display}*\n" .
                "⭐ Task Giver: {$job->poster->display_name}" .
                $applicationsText .
                $instructionsText,
            'buttons' => [
                ['id' => 'apply_job_' . $job->id, 'title' => '✅ Interested'],
                ['id' => 'view_job_detail_' . $job->id, 'title' => '👁️ Details'],
                ['id' => 'skip_job_' . $job->id, 'title' => '❌ Skip'],
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
            'header' => '✅ Applied!',
            'body' => "✅ *Application Received!*\n\n" .
                "{$job->category->icon} {$job->title}\n\n" .
                "📍 You are *#{$position}* in queue\n\n" .
                "We'll notify you when selected! 🔔\n\n" .
                "_Check out other jobs too_",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                ['id' => 'my_applications', 'title' => '📋 My Applications'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '📋 Job Filled',
            'body' => "📋 *Job Given to Another Worker*\n\n" .
                "{$job->category->icon} {$job->title}\n\n" .
                "Sorry, this job was given to another worker.\n\n" .
                "_More jobs coming soon!_",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '👤 New Application!',
            'body' => "👤 *New Application!*\n\n" .
                "📋 For: {$job->title}\n\n" .
                "👤 *{$worker->name}*\n" .
                "{$ratingText}\n" .
                "✅ {$worker->jobs_completed} jobs done" .
                $vehicleText .
                $proposedText .
                $messageText,
            'buttons' => [
                ['id' => 'select_worker_' . $application->id, 'title' => '✅ Select'],
                ['id' => 'view_all_apps_' . $job->id, 'title' => '👥 View All'],
                ['id' => 'reject_app_' . $application->id, 'title' => '❌ Reject'],
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
                'header' => '📋 Applications',
                'body' => "📋 *{$job->title}*\n\n" .
                    "No applications yet.\n\n" .
                    "_Workers will apply soon!_",
                'buttons' => [
                    ['id' => 'view_job_' . $job->id, 'title' => '👁️ View Job'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
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

        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Menu', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '👥 Applications',
            'body' => "📋 *{$job->title}*\n\n" .
                "👥 {$applications->count()} applied\n\n" .
                "Select a worker to assign the task:",
            'button' => 'View Workers',
            'sections' => [
                [
                    'title' => 'Applicants',
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
            'header' => '✅ Worker Selected!',
            'body' => "✅ *Worker Selected!*\n\n" .
                "📋 {$job->title}\n\n" .
                "👤 *{$worker->name}*\n" .
                "📞 {$worker->user->formatted_phone}\n" .
                "{$worker->short_rating}\n\n" .
                "Worker notified! 🔔\n\n" .
                "_Arrival photo will be requested on job day_",
            'buttons' => [
                ['id' => 'call_worker_' . $worker->id, 'title' => '📞 Call'],
                ['id' => 'view_job_' . $job->id, 'title' => '👁️ View Job'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '🎉 Selected!',
            'body' => "🎉 *YOU GOT THE TASK!*\n\n" .
                "{$job->category->icon} *{$job->title}*\n\n" .
                "📍 {$job->location_display}\n" .
                "📅 {$job->formatted_date_time}\n" .
                "💰 *{$job->pay_display}*\n\n" .
                "📞 Task Giver: *{$poster->display_name}*\n" .
                "📱 {$poster->formatted_phone}\n\n" .
                "⏰ *Arrive 5 minutes early!*",
            'buttons' => [
                ['id' => 'call_poster_' . $job->id, 'title' => '📞 Call'],
                ['id' => 'get_directions_' . $job->id, 'title' => '📍 Directions'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'body' => "📸 *Confirm Arrival*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "📍 {$job->location_display}\n\n" .
                "Please send a photo to confirm you've arrived.\n\n" .
                "📎 → Camera tap",
            'buttons' => [
                ['id' => 'skip_arrival_photo_' . $job->id, 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '📍 Worker Arrived!',
            'body' => "📍 *Worker Arrived!*\n\n" .
                "{$job->category->icon} {$job->title}\n\n" .
                "👤 {$worker->name}\n" .
                "⏰ {$verification->arrival_verified_at->format('h:i A')}\n" .
                "{$hasPhoto}\n\n" .
                "_Task in progress..._",
            'buttons' => [
                ['id' => 'call_worker_' . $worker->id, 'title' => '📞 Call'],
                ['id' => 'view_job_' . $job->id, 'title' => '👁️ View Job'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '✅ Task Done?',
            'body' => "✅ *Task Completed?*\n\n" .
                "{$job->category->icon} {$job->title}\n\n" .
                "Have you completed the task?\n\n" .
                "_Completion photo optional_",
            'buttons' => [
                ['id' => 'confirm_complete_' . $job->id, 'title' => '✅ Completed'],
                ['id' => 'send_completion_photo_' . $job->id, 'title' => '📸 Send Photo'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '⭐ Rating',
            'body' => "⭐ *Rate the Worker*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "👤 {$worker->name}\n\n" .
                "How was the worker?",
            'button' => 'Select Rating',
            'sections' => [
                [
                    'title' => 'Rating',
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
            'header' => '💰 Payment',
            'body' => "💰 *Confirm Payment*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "💵 Amount: *{$job->pay_display}*\n\n" .
                "How did you pay the worker?",
            'buttons' => [
                ['id' => 'paid_cash_' . $job->id, 'title' => '💵 Cash'],
                ['id' => 'paid_upi_' . $job->id, 'title' => '📱 UPI'],
                ['id' => 'paid_other_' . $job->id, 'title' => '💳 Other'],
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
                'header' => '🎉 Completed!',
                'body' => "🎉 *Task Completed!*\n\n" .
                    "{$job->category->icon} {$job->title}\n\n" .
                    "💰 Earned: *{$job->pay_display}*" .
                    $ratingText . "\n\n" .
                    "Thank you! 🙏\n" .
                    "_Check out more jobs!_",
                'buttons' => [
                    ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                    ['id' => 'my_earnings', 'title' => '💰 My Earnings'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
            ];
        } else {
            // Message for poster
            return [
                'type' => 'buttons',
                'header' => '🎉 Completed!',
                'body' => "🎉 *Task Completed!*\n\n" .
                    "{$job->category->icon} {$job->title}\n\n" .
                    "👤 Worker: {$worker->name}\n" .
                    "💰 Paid: *{$job->pay_display}*\n" .
                    "✅ Status: Completed\n\n" .
                    "Thank you for using NearBuy! 🙏",
                'buttons' => [
                    ['id' => 'post_another_job', 'title' => '➕ Post Another'],
                    ['id' => 'my_posted_jobs', 'title' => '📋 My Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '💰 Earnings',
            'body' => "💰 *This Week's Earnings*\n\n" .
                "💵 Total: *₹" . number_format($totalEarnings) . "*\n" .
                "📋 Jobs: {$totalJobs}\n" .
                "📊 Average: ₹{$avgPerJob}/job\n\n" .
                "📈 *All-time Earnings*\n" .
                "Total: *{$worker->earnings_display}*\n" .
                "✅ Jobs Completed: {$worker->jobs_completed}\n" .
                "⭐ Rating: {$worker->short_rating}",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 Browse Jobs'],
                ['id' => 'my_badges', 'title' => '🏅 My Badges'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '😕 No Workers',
            'body' => "😕 *No Workers Nearby*\n\n" .
                "No workers available nearby right now.\n\n" .
                "_Try again later_",
            'buttons' => [
                ['id' => 'retry_post_job', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '😕 No Jobs',
            'body' => "😕 *No Jobs Available*\n\n" .
                "No tasks available matching your preferences.\n\n" .
                "_We'll notify you when new jobs come!_",
            'buttons' => [
                ['id' => 'refresh_jobs', 'title' => '🔄 Refresh'],
                ['id' => 'edit_preferences', 'title' => '⚙️ Preferences'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'body' => "⏰ *Job Expired*\n\n" .
                "This task has expired or been filled.\n\n" .
                "_Check other jobs_",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'body' => "ℹ️ *Already Applied*\n\n" .
                "You've already applied for this task.\n\n" .
                "_Wait for task giver's response_",
            'buttons' => [
                ['id' => 'my_applications', 'title' => '📋 My Applications'],
                ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '⚠️ Active Job',
            'body' => "⚠️ *You Have an Active Job*\n\n" .
                "You currently have an active task.\n\n" .
                "{$activeJob->category->icon} {$activeJob->title}\n" .
                "📍 {$activeJob->location_display}\n\n" .
                "_Complete this first_",
            'buttons' => [
                ['id' => 'view_active_job_' . $activeJob->id, 'title' => '👁️ View Job'],
                ['id' => 'complete_job_' . $activeJob->id, 'title' => '✅ Complete'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
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
            'header' => '👷 Worker Menu',
            'body' => "Welcome, *{$worker->name}*! 👋\n\n" .
                "⭐ Rating: {$worker->short_rating}\n" .
                "✅ Jobs: {$worker->jobs_completed}\n" .
                "💰 Earnings: {$worker->earnings_display}\n\n" .
                "📋 Active: {$activeJobsCount} | Pending: {$pendingAppsCount}",
            'button' => 'Select',
            'sections' => [
                [
                    'title' => 'Job Options',
                    'rows' => [
                        ['id' => 'browse_jobs', 'title' => '🔍 Browse Jobs', 'description' => 'Find available tasks nearby'],
                        ['id' => 'my_active_jobs', 'title' => '📋 Active Jobs', 'description' => 'Your current assigned tasks'],
                        ['id' => 'my_applications', 'title' => '📝 My Applications', 'description' => 'Pending applications'],
                        ['id' => 'my_earnings', 'title' => '💰 Earnings', 'description' => 'Earnings & statistics'],
                        ['id' => 'worker_profile', 'title' => '👤 Profile', 'description' => 'Edit your profile'],
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Main Menu'],
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
            'header' => '📋 Jobs Menu',
            'body' => "👋 *{$user->display_name}*\n\n" .
                "📋 Active Tasks: {$activeJobsCount}\n\n" .
                "What would you like to do?",
            'button' => 'Select',
            'sections' => [
                [
                    'title' => 'Options',
                    'rows' => [
                        ['id' => 'post_job', 'title' => '📋 Post a Task', 'description' => 'Post a new task'],
                        ['id' => 'my_posted_jobs', 'title' => '📂 My Tasks', 'description' => 'View your posted tasks'],
                        ['id' => 'view_applications', 'title' => '👥 Applications', 'description' => 'Review worker applications'],
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Main Menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Browse jobs results.
     */
    public static function browseJobsResults(Collection $jobs, string $location = 'nearby'): array
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

        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Menu', 'description' => 'Main Menu'];

        return [
            'type' => 'list',
            'header' => '💼 Available Jobs',
            'body' => "💼 *{$jobs->count()} jobs* available {$location}\n\n" .
                "Select a task to view details and apply:",
            'button' => 'View Jobs',
            'sections' => [
                [
                    'title' => 'Available Jobs',
                    'rows' => $rows,
                ],
            ],
        ];
    }
}