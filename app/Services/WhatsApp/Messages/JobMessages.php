<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\JobCategory;
use App\Models\JobApplication;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Message templates for Jobs (Njaanum Panikkar) module.
 *
 * Bilingual Manglish/Malayalam messages for:
 * - Job posting flow
 * - Worker registration
 * - Job browsing & applications
 * - Job execution & completion
 *
 * @srs-ref Section 3 - Jobs Marketplace Module
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobMessages
{
    /*
    |--------------------------------------------------------------------------
    | Job Posting Messages (NP-006 to NP-014)
    |--------------------------------------------------------------------------
    */

    /**
     * Category selection message.
     */
    public static function categorySelection(): string
    {
        return "👷 *Entha pani?*\n" .
            "എന്ത് പണിക്കാണ് ആളെ വേണ്ടത്?\n\n" .
            "Select the type of job:";
    }

    /**
     * Custom category prompt.
     */
    public static function customCategoryPrompt(): string
    {
        return "✏️ *Custom job type*\n" .
            "മറ്റ് ജോലി തരം\n\n" .
            "Type cheyyuka (eg: Coconut climber, Electrician, Plumber):";
    }

    /**
     * Location prompt.
     */
    public static function locationPrompt(string $categoryName): string
    {
        return "📋 *{$categoryName}*\n\n" .
            "📍 *Location evide?*\n" .
            "പണിക്കാരൻ എവിടെ വരണം?\n\n" .
            "Type cheyyuka (eg: RTO Kakkanad, Collectorate):";
    }

    /**
     * Date prompt.
     */
    public static function datePrompt(): string
    {
        return "📅 *Eppozha vende?*\n" .
            "ഏത് ദിവസം വേണം?";
    }

    /**
     * Time prompt.
     */
    public static function timePrompt(string $dateDisplay): string
    {
        return "📅 *{$dateDisplay}*\n\n" .
            "⏰ *Time ethra manikku?*\n" .
            "എത്ര മണിക്ക് എത്തണം?\n\n" .
            "Type cheyyuka (eg: 7 AM, 9:30 AM):";
    }

    /**
     * Duration prompt.
     */
    public static function durationPrompt(): string
    {
        return "⏱️ *Ethra samayam edukkum?*\n" .
            "ഏകദേശം എത്ര സമയം എടുക്കും?";
    }

    /**
     * Pay prompt with suggestion.
     */
    public static function payPrompt(string $categoryName, string $durationDisplay, int $suggestedMin, int $suggestedMax): string
    {
        return "💰 *Ethra kodukkum?*\n" .
            "എത്ര രൂപ കൊടുക്കും?\n\n" .
            "📋 *{$categoryName}* | ⏱️ {$durationDisplay}\n" .
            "💡 Suggested: ₹{$suggestedMin} - ₹{$suggestedMax}\n\n" .
            "Amount type cheyyuka (in ₹):";
    }

    /**
     * Instructions prompt.
     */
    public static function instructionsPrompt(int $payAmount): string
    {
        return "💰 *₹{$payAmount}*\n\n" .
            "📝 *Special instructions?*\n" .
            "പ്രത്യേക നിർദ്ദേശങ്ങൾ ഉണ്ടോ?\n\n" .
            "Type cheyyuka OR skip:";
    }

    /**
     * Job review/confirmation message.
     */
    public static function jobReview(array $data): string
    {
        $catIcon = $data['category_icon'] ?? '📋';
        $catName = $data['category_name'] ?? 'Job';
        $location = $data['location_name'] ?? '';
        $hasCoords = !empty($data['latitude']) ? '✅' : '❌';
        $dateDisplay = $data['job_date_display'] ?? '';
        $timeDisplay = $data['job_time_display'] ?? '';
        $durDisplay = $data['duration_display'] ?? '';
        $payAmount = $data['pay_amount'] ?? 0;
        $instructions = $data['instructions'] ?? '';

        $instLine = $instructions ? "\n📝 {$instructions}" : '';

        return "👷 *Job Review*\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$catIcon} *{$catName}*\n" .
            "📍 {$location} ({$hasCoords} GPS)\n" .
            "📅 {$dateDisplay} ⏰ {$timeDisplay}\n" .
            "⏱️ {$durDisplay}\n" .
            "💰 *₹{$payAmount}*" .
            $instLine . "\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "Ready to post? ✅";
    }

    /**
     * Job posted success message.
     */
    public static function jobPosted(JobPost $job, int $workersNotified = 0): string
    {
        $jobNumber = $job->job_number ?? 'JOB-' . $job->id;

        return "🎉 *Job Posted!*\n" .
            "ജോലി പോസ്റ്റ് ചെയ്തു!\n\n" .
            "🆔 *{$jobNumber}*\n\n" .
            "👷 *{$workersNotified}* workers nearby notified! 🔔\n" .
            "അടുത്തുള്ള പണിക്കാർക്ക് അറിയിപ്പ് അയച്ചു\n\n" .
            "Applicants varunna neram ariyikkaam! 📲";
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Notification Messages
    |--------------------------------------------------------------------------
    */

    /**
     * New job notification for workers.
     */
    public static function newJobNotification(JobPost $job, float $distanceKm = 0): string
    {
        $catIcon = self::getCategoryIcon($job);
        $catName = self::getCategoryName($job);
        $distanceDisplay = $distanceKm > 0 ? round($distanceKm, 1) . ' km away' : 'Nearby';
        $dateDisplay = $job->job_date ? Carbon::parse($job->job_date)->format('d M') : 'TBD';
        $timeDisplay = self::formatTime($job->job_time);
        $payDisplay = '₹' . number_format($job->pay_amount ?? 0);

        return "🔔 *New Job Alert!*\n" .
            "*പുതിയ ജോലി!*\n\n" .
            "{$catIcon} *{$catName}*\n" .
            "📍 {$job->location_name}\n" .
            "🚶 {$distanceDisplay}\n" .
            "📅 {$dateDisplay} ⏰ {$timeDisplay}\n" .
            "💰 *{$payDisplay}*\n\n" .
            "Interested? Apply now! 👇";
    }

    /*
    |--------------------------------------------------------------------------
    | Job Detail Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Job detail for poster.
     */
    public static function jobDetailForPoster(JobPost $job): string
    {
        $catIcon = self::getCategoryIcon($job);
        $catName = self::getCategoryName($job);
        $statusIcon = self::getStatusIcon($job->status);
        $statusText = self::getStatusText($job->status);
        $dateDisplay = $job->job_date ? Carbon::parse($job->job_date)->format('d M Y') : 'Not set';
        $timeDisplay = self::formatTime($job->job_time);
        $payDisplay = '₹' . number_format($job->pay_amount ?? 0);
        $applicationsCount = $job->applications_count ?? $job->applications()->count();

        $message = "📋 *Job Details*\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "{$catIcon} *{$catName}*\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$statusIcon} *Status:* {$statusText}\n" .
            "📍 *Location:* {$job->location_name}\n" .
            "📅 *Date:* {$dateDisplay}\n" .
            "⏰ *Time:* {$timeDisplay}\n" .
            "💰 *Pay:* {$payDisplay}\n\n" .
            "📝 *Applications:* {$applicationsCount}\n" .
            "🆔 Job ID: " . ($job->job_number ?? 'N/A');

        if ($job->assignedWorker) {
            $message .= "\n\n👷 *Assigned:* {$job->assignedWorker->name}";
        }

        return $message;
    }

    /**
     * Job detail for worker.
     */
    public static function jobDetailForWorker(JobPost $job, float $distanceKm = 0): string
    {
        $catIcon = self::getCategoryIcon($job);
        $catName = self::getCategoryName($job);
        $distanceDisplay = $distanceKm > 0 ? round($distanceKm, 1) . ' km' : 'Nearby';
        $dateDisplay = $job->job_date ? Carbon::parse($job->job_date)->format('d M Y') : 'Not set';
        $timeDisplay = self::formatTime($job->job_time);
        $payDisplay = '₹' . number_format($job->pay_amount ?? 0);

        $message = "📋 *Job Details*\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "{$catIcon} *{$catName}*\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "📍 *Location:* {$job->location_name}\n" .
            "🚶 *Distance:* {$distanceDisplay}\n" .
            "📅 *Date:* {$dateDisplay}\n" .
            "⏰ *Time:* {$timeDisplay}\n" .
            "💰 *Pay:* {$payDisplay}";

        if ($job->description) {
            $message .= "\n\n📝 *Description:*\n{$job->description}";
        }

        if ($job->special_instructions) {
            $message .= "\n\n📌 *Instructions:*\n{$job->special_instructions}";
        }

        // Poster info
        if ($job->poster) {
            $message .= "\n\n👤 *Posted by:* {$job->poster->name}";
        }

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | Application Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Application confirmed message for worker.
     */
    public static function applicationConfirmed(JobPost $job, int $position = 1): string
    {
        $positionText = $position === 1
            ? "🎯 You're the *first* to apply!"
            : "📊 Position: *#{$position}* in queue";

        $dateDisplay = $job->job_date ? Carbon::parse($job->job_date)->format('d M Y') : 'TBD';
        $payDisplay = '₹' . number_format($job->pay_amount ?? 0);

        return "✅ *Application Sent!*\n" .
            "*അപേക്ഷ അയച്ചു!*\n\n" .
            "Your application for *{$job->title}* has been submitted.\n\n" .
            "{$positionText}\n\n" .
            "📍 {$job->location_name}\n" .
            "📅 {$dateDisplay}\n" .
            "💰 {$payDisplay}\n\n" .
            "Task giver will review and respond soon.\n" .
            "ടാസ്ക് ഗൈവർ ഉടൻ respond ചെയ്യും.";
    }

    /**
     * New application notification for poster.
     */
    public static function newApplicationNotification(JobApplication $application): string
    {
        $worker = $application->worker;
        $job = $application->jobPost;

        $ratingText = $worker->rating
            ? "⭐ {$worker->rating}/5 ({$worker->rating_count} reviews)"
            : "🆕 New worker";

        $completedText = $worker->jobs_completed > 0
            ? "✅ {$worker->jobs_completed} jobs completed"
            : "🆕 First job";

        $catIcon = self::getCategoryIcon($job);

        return "🔔 *New Application!*\n" .
            "*പുതിയ അപേക്ഷ!*\n\n" .
            "Job: {$catIcon} *{$job->title}*\n\n" .
            "👷 *{$worker->name}*\n" .
            "{$ratingText}\n" .
            "{$completedText}\n\n" .
            "Review and accept/reject this applicant.";
    }

    /**
     * Already applied message.
     */
    public static function alreadyApplied(): string
    {
        return "ℹ️ *Already Applied*\n" .
            "*ഇതിനകം അപേക്ഷിച്ചു*\n\n" .
            "You have already applied for this job.\n" .
            "Please wait for the poster's response.\n\n" .
            "നിങ്ങൾ ഇതിനകം ഈ ജോലിക്ക് അപേക്ഷിച്ചിട്ടുണ്ട്.";
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Menu Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Worker menu header.
     */
    public static function workerMenuHeader(JobWorker $worker): string
    {
        $availIcon = $worker->is_available ? '🟢' : '🔴';
        $availText = $worker->is_available ? 'Available' : 'Unavailable';
        $rating = $worker->rating ? "⭐ {$worker->rating}/5" : 'No ratings yet';
        $completedJobs = $worker->jobs_completed ?? 0;
        $totalEarnings = $worker->total_earnings ?? 0;

        return "👷 *Worker Dashboard*\n" .
            "*പണിക്കാരൻ ഡാഷ്ബോർഡ്*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "*{$worker->name}*\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$availIcon} *Status:* {$availText}\n" .
            "📊 *Rating:* {$rating}\n" .
            "✅ *Completed:* {$completedJobs} jobs\n" .
            "💰 *Earnings:* ₹{$totalEarnings}";
    }

    /**
     * Worker profile view.
     */
    public static function workerProfileView(JobWorker $worker): string
    {
        $availIcon = $worker->is_available ? '🟢' : '🔴';
        $availText = $worker->is_available ? 'Available' : 'Unavailable';
        $rating = $worker->rating ? "⭐ {$worker->rating}/5" : 'No ratings yet';

        $vehicleText = match ($worker->vehicle_type ?? 'none') {
            'none' => '🚶 Walking Only',
            'two_wheeler' => '🛵 Two Wheeler',
            'four_wheeler' => '🚗 Four Wheeler',
            default => 'Not specified',
        };

        return "👷 *My Profile*\n" .
            "*എന്റെ പ്രൊഫൈൽ*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "👤 *Name:* {$worker->name}\n" .
            "📍 *Location:* " . ($worker->address ?? 'Not set') . "\n" .
            "🚗 *Vehicle:* {$vehicleText}\n" .
            "{$availIcon} *Status:* {$availText}\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "*Stats:*\n" .
            "📊 Rating: {$rating}\n" .
            "✅ Completed: " . ($worker->jobs_completed ?? 0) . " jobs\n" .
            "💰 Earnings: ₹" . ($worker->total_earnings ?? 0);
    }

    /**
     * Availability toggled message.
     */
    public static function availabilityToggled(bool $isAvailable): string
    {
        if ($isAvailable) {
            return "🟢 *You are now Available*\n\n" .
                "Job notifications on aaki!\n" .
                "നിങ്ങൾ ഇപ്പോൾ ലഭ്യമാണ്.";
        }

        return "🔴 *You are now Unavailable*\n\n" .
            "Job notifications off aaki.\n" .
            "നിങ്ങൾ ഇപ്പോൾ ലഭ്യമല്ല.";
    }

    /*
    |--------------------------------------------------------------------------
    | Poster Menu Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Poster menu header.
     */
    public static function posterMenuHeader(int $activeJobs, int $completedJobs, int $totalApplications): string
    {
        return "📋 *My Posted Jobs*\n" .
            "*എന്റെ ജോലികൾ*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "🟢 Active: *{$activeJobs}*\n" .
            "✅ Completed: *{$completedJobs}*\n" .
            "📝 Applications: *{$totalApplications}*\n" .
            "━━━━━━━━━━━━━━━━";
    }

    /**
     * No jobs posted message.
     */
    public static function noJobsPosted(): string
    {
        return "📭 *No jobs found*\n\n" .
            "You haven't posted any jobs yet.\n" .
            "ഇതുവരെ ജോലികൾ ഒന്നും പോസ്റ്റ് ചെയ്തിട്ടില്ല.\n\n" .
            "Post your first job now!";
    }

    /*
    |--------------------------------------------------------------------------
    | Status & Error Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Job not found message.
     */
    public static function jobNotFound(): string
    {
        return "❌ *Job Not Found*\n\n" .
            "This job no longer exists.\n" .
            "ഈ ജോലി നിലവിലില്ല.";
    }

    /**
     * Job expired message.
     */
    public static function jobExpired(): string
    {
        return "⏰ *Job Expired*\n\n" .
            "This job is no longer available.\n" .
            "ഈ ജോലി ഇനി ലഭ്യമല്ല.";
    }

    /**
     * Job cancelled message.
     */
    public static function jobCancelled(): string
    {
        return "❌ *Job Cancelled*\n\n" .
            "This job has been cancelled.\n" .
            "ഈ ജോലി റദ്ദാക്കി.";
    }

    /**
     * Cannot apply to own job.
     */
    public static function cannotApplyOwnJob(): string
    {
        return "⚠️ *Cannot Apply*\n\n" .
            "You cannot apply to your own job.\n" .
            "സ്വന്തം ജോലിക്ക് apply cheyyaan kazhiyilla.";
    }

    /*
    |--------------------------------------------------------------------------
    | Job Execution Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Worker arrived notification.
     */
    public static function workerArrived(JobPost $job, JobWorker $worker): string
    {
        $catIcon = self::getCategoryIcon($job);

        return "📍 *Worker Arrived!*\n" .
            "*പണിക്കാരൻ എത്തി!*\n\n" .
            "{$catIcon} *{$job->title}*\n" .
            "👷 {$worker->name}\n" .
            "⭐ {$worker->rating}/5\n\n" .
            "Worker is ready to start.";
    }

    /**
     * Job completed message.
     */
    public static function jobCompleted(JobPost $job, bool $isWorker = true): string
    {
        $catIcon = self::getCategoryIcon($job);
        $payDisplay = '₹' . number_format($job->pay_amount ?? 0);

        if ($isWorker) {
            return "🎉 *Job Complete!*\n" .
                "*ജോലി പൂർത്തിയായി!*\n\n" .
                "{$catIcon} *{$job->title}*\n" .
                "💰 Earned: *{$payDisplay}*\n\n" .
                "Great work! 💪";
        }

        return "✅ *Job Complete!*\n" .
            "*ജോലി പൂർത്തിയായി!*\n\n" .
            "{$catIcon} *{$job->title}*\n" .
            "💰 Paid: *{$payDisplay}*\n\n" .
            "Thank you for using NearBuy! 🙏";
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Registration Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Worker registration success.
     */
    public static function workerRegistrationSuccess(JobWorker $worker): string
    {
        return "🎉 *Registration Complete!*\n" .
            "*രജിസ്ട്രേഷൻ പൂർത്തിയായി!*\n\n" .
            "Welcome, *{$worker->name}*! 👷\n\n" .
            "You are now registered as a worker.\n" .
            "പണിക്കാരനായി രജിസ്റ്റർ ചെയ്തു.\n\n" .
            "⭐ Rating: New\n" .
            "✅ Jobs: 0\n" .
            "🟢 Status: Available\n\n" .
            "Start browsing jobs now! 🔍";
    }

    /**
     * Worker already registered.
     */
    public static function workerAlreadyRegistered(): string
    {
        return "ℹ️ *Already Registered*\n\n" .
            "You are already a worker.\n" .
            "ഇതിനകം രജിസ്റ്റർ ചെയ്തിട്ടുണ്ട്.";
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get category icon.
     */
    protected static function getCategoryIcon(JobPost $job): string
    {
        if ($job->category) {
            return $job->category->icon ?? '📋';
        }
        return '📋';
    }

    /**
     * Get category name.
     */
    protected static function getCategoryName(JobPost $job): string
    {
        if ($job->custom_category_text) {
            return $job->custom_category_text;
        }
        if ($job->category) {
            return $job->category->name_en ?? $job->category->name ?? 'Job';
        }
        return $job->title ?? 'Job';
    }

    /**
     * Get status icon.
     */
    public static function getStatusIcon(string $status): string
    {
        return match ($status) {
            'open' => '🟢',
            'assigned' => '🔵',
            'in_progress' => '🟡',
            'completed' => '✅',
            'cancelled' => '❌',
            'expired' => '⏱️',
            'draft' => '📝',
            default => '⚪',
        };
    }

    /**
     * Get status text.
     */
    public static function getStatusText(string $status): string
    {
        return match ($status) {
            'open' => 'Open for applications',
            'assigned' => 'Worker assigned',
            'in_progress' => 'In progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'expired' => 'Expired',
            'draft' => 'Draft',
            default => 'Unknown',
        };
    }

    /**
     * Format MySQL time to display format.
     */
    protected static function formatTime(?string $mysqlTime): string
    {
        if (!$mysqlTime) {
            return 'TBD';
        }

        try {
            return Carbon::createFromFormat('H:i:s', $mysqlTime)->format('g:i A');
        } catch (\Exception $e) {
            return $mysqlTime;
        }
    }
}