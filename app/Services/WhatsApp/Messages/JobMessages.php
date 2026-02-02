<?php

namespace App\Services\WhatsApp\Messages;

use App\Enums\JobStatus;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\JobCategory;
use App\Models\JobApplication;
use App\Models\JobVerification;
use Illuminate\Support\Collection;

/**
 * Message templates for Jobs (Njaanum Panikkar) module.
 *
 * @srs-ref Section 3 - Jobs Marketplace Module
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 * 
 * UPDATED: Added templates for:
 * - Poster menu and job management
 * - Worker profile view and edit
 * - "Other" category with custom text
 */
class JobMessages
{
    /*
    |--------------------------------------------------------------------------
    | Job Category Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Get category selection message with "Other" option.
     */
    public static function categorySelection(): string
    {
        return "📋 *Post a Job*\n*ജോലി പോസ്റ്റ് ചെയ്യുക*\n\n" .
            "Step 1: Select the type of job you need help with\n\n" .
            "എന്ത് തരം പണിയാണ് വേണ്ടത്?\n\n" .
            "_Select 'Other' if your job type is not listed_";
    }

    /**
     * Get custom category prompt (for "Other" option).
     */
    public static function customCategoryPrompt(): string
    {
        return "✏️ *Custom Job Type*\n*മറ്റ് ജോലി തരം*\n\n" .
            "You selected 'Other'. Please describe the type of work you need:\n\n" .
            "നിങ്ങൾ 'മറ്റുള്ളവ' തിരഞ്ഞെടുത്തു. എന്ത് തരം പണിയാണ് വേണ്ടതെന്ന് വിവരിക്കുക:\n\n" .
            "*Examples:*\n" .
            "• Coconut climber (തെങ്ങ് കയറ്റം)\n" .
            "• Wood cutter (മരം മുറിക്കൽ)\n" .
            "• Electrician (ഇലക്ട്രീഷ്യൻ)\n" .
            "• Plumber (പ്ലംബർ)\n\n" .
            "_Type the job type (max 100 characters)_";
    }

    /**
     * Validate custom category text.
     */
    public static function customCategoryInvalid(): string
    {
        return "❌ *Invalid job type*\n\n" .
            "Please enter a valid job type description:\n" .
            "• Maximum 100 characters\n" .
            "• No special characters\n\n" .
            "ദയവായി സാധുവായ ഒരു ജോലി തരം നൽകുക";
    }

    /**
     * Custom category confirmed.
     */
    public static function customCategoryConfirmed(string $customType): string
    {
        return "✅ Job type set to: *{$customType}*\n\n" .
            "ജോലി തരം: *{$customType}*";
    }

    /*
    |--------------------------------------------------------------------------
    | Job Poster Menu Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Get poster menu header with stats.
     */
    public static function posterMenuHeader(int $activeJobs, int $completedJobs, int $totalApplications): string
    {
        return "📋 *My Posted Jobs*\n*എന്റെ പോസ്റ്റ് ചെയ്ത ജോലികൾ*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "🟢 Active Jobs: *{$activeJobs}*\n" .
            "✅ Completed: *{$completedJobs}*\n" .
            "📝 Total Applications: *{$totalApplications}*\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "Select an option to manage your jobs:";
    }

    /**
     * Get posted jobs list message.
     */
    public static function myPostedJobsList(Collection $jobs, string $filterLabel = 'All'): string
    {
        if ($jobs->isEmpty()) {
            return self::noJobsPosted($filterLabel);
        }

        $message = "📋 *{$filterLabel} Jobs*\n*{$filterLabel} ജോലികൾ*\n\n";

        foreach ($jobs as $index => $job) {
            $statusIcon = self::getStatusIcon($job->status);
            $categoryName = $job->custom_category_text ?? ($job->category ? self::getCategoryName($job->category) : 'Other');
            $applicationsCount = $job->applications()->count();
            
            $message .= ($index + 1) . ". {$statusIcon} *{$job->title}*\n" .
                "   📁 {$categoryName} | 💰 ₹{$job->pay_amount}\n" .
                "   📅 " . $job->job_date->format('d M') . " | 📝 {$applicationsCount} apps\n\n";
        }

        $message .= "_Select a job to view details_";

        return $message;
    }

    /**
     * Get job detail message for poster.
     */
    public static function jobDetailForPoster(JobPost $job): string
    {
        $statusIcon = self::getStatusIcon($job->status);
        $statusText = self::getStatusText($job->status);
        $categoryName = $job->custom_category_text ?? ($job->category ? self::getCategoryName($job->category) : 'Other');
        
        // Safe access to relationships
        $applicationsCount = 0;
        if ($job->relationLoaded('applications')) {
            $applicationsCount = $job->applications->count();
        } else {
            try {
                $applicationsCount = $job->applications()->count();
            } catch (\Exception $e) {
                $applicationsCount = 0;
            }
        }
        
        $assignedWorker = $job->assignedWorker;
        
        // Safe date formatting
        $dateStr = 'Not set';
        if ($job->job_date) {
            try {
                $dateStr = $job->job_date->format('d M Y');
            } catch (\Exception $e) {
                $dateStr = (string) $job->job_date;
            }
        }

        $message = "📋 *Job Details*\n*ജോലി വിവരങ്ങൾ*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "*{$job->title}*\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$statusIcon} *Status:* {$statusText}\n" .
            "📁 *Category:* {$categoryName}\n" .
            "💰 *Pay:* ₹" . ($job->pay_amount ?? 0) . "\n" .
            "📍 *Location:* " . ($job->location_name ?? 'Not specified') . "\n" .
            "📅 *Date:* {$dateStr}\n" .
            "⏰ *Time:* " . self::formatMySQLTime($job->job_time) . "\n" .
            "⏱️ *Duration:* " . ($job->formatted_duration ?? 'Not specified') . "\n\n";

        if ($job->description) {
            $message .= "*Description:*\n{$job->description}\n\n";
        }

        $message .= "━━━━━━━━━━━━━━━━\n" .
            "📝 *Applications:* {$applicationsCount}\n";

        if ($assignedWorker) {
            $workerName = $assignedWorker->user?->name ?? 'Unknown';
            $workerPhone = $assignedWorker->user?->phone ?? 'Not available';
            $message .= "👷 *Assigned:* {$workerName}\n" .
                "📞 *Contact:* {$workerPhone}\n";
        }

        $message .= "━━━━━━━━━━━━━━━━\n" .
            "🆔 Job ID: " . ($job->job_number ?? 'N/A');

        return $message;
    }

    /**
     * Get empty jobs message.
     */
    public static function noJobsPosted(string $filter = ''): string
    {
        $filterText = $filter ? " {$filter}" : '';
        
        return "📭 *No{$filterText} jobs found*\n\n" .
            "You haven't posted any{$filterText} jobs yet.\n\n" .
            "നിങ്ങൾ ഇതുവരെ{$filterText} ജോലികൾ ഒന്നും പോസ്റ്റ് ചെയ്തിട്ടില്ല.\n\n" .
            "Would you like to post a new job?";
    }

    /**
     * Get job cancelled confirmation.
     */
    public static function jobCancelled(): array
    {
        return [
            'type' => 'buttons',
            'body' => "✅ *Job Cancelled*\n\n" .
                "Your job has been cancelled successfully.\n\n" .
                "നിങ്ങളുടെ ജോലി റദ്ദാക്കി.",
            'buttons' => [
                ['id' => 'job_poster_menu', 'title' => '📋 My Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Get job expired message.
     */
    public static function jobExpired(): array
    {
        return [
            'type' => 'buttons',
            'body' => "⏰ *Job Expired*\n*ജോലി കാലഹരണപ്പെട്ടു*\n\n" .
                "This job is no longer available.\n" .
                "The job date has passed or it was cancelled.\n\n" .
                "ഈ ജോലി ഇനി ലഭ്യമല്ല.",
            'buttons' => [
                ['id' => 'job_browse', 'title' => '🔍 Find Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Get job not found message.
     */
    public static function jobNotFound(): array
    {
        return [
            'type' => 'buttons',
            'body' => "❌ *Job Not Found*\n*ജോലി കണ്ടെത്തിയില്ല*\n\n" .
                "This job no longer exists or has been removed.\n\n" .
                "ഈ ജോലി നിലവിലില്ല അല്ലെങ്കിൽ നീക്കം ചെയ്തു.",
            'buttons' => [
                ['id' => 'job_browse', 'title' => '🔍 Find Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Get job already assigned message.
     */
    public static function jobAlreadyAssigned(): array
    {
        return [
            'type' => 'buttons',
            'body' => "👷 *Job Already Assigned*\n*ജോലി ഇതിനകം നൽകിയിരിക്കുന്നു*\n\n" .
                "This job has already been assigned to another worker.\n\n" .
                "ഈ ജോലി മറ്റൊരു പണിക്കാരന് നൽകിയിരിക്കുന്നു.",
            'buttons' => [
                ['id' => 'job_browse', 'title' => '🔍 Find Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Get job closed message.
     */
    public static function jobClosed(): array
    {
        return [
            'type' => 'buttons',
            'body' => "🔒 *Job Closed*\n*ജോലി അവസാനിച്ചു*\n\n" .
                "This job is no longer accepting applications.\n\n" .
                "ഈ ജോലി ഇനി അപേക്ഷകൾ സ്വീകരിക്കുന്നില്ല.",
            'buttons' => [
                ['id' => 'job_browse', 'title' => '🔍 Find Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Get already applied message.
     */
    public static function alreadyApplied(): array
    {
        return [
            'type' => 'buttons',
            'body' => "ℹ️ *Already Applied*\n*ഇതിനകം അപേക്ഷിച്ചു*\n\n" .
                "You have already applied for this job.\n" .
                "Please wait for the poster's response.\n\n" .
                "നിങ്ങൾ ഇതിനകം ഈ ജോലിക്ക് അപേക്ഷിച്ചിട്ടുണ്ട്.",
            'buttons' => [
                ['id' => 'job_worker_menu', 'title' => '👷 Worker Menu'],
                ['id' => 'job_browse', 'title' => '🔍 Find Jobs'],
            ],
        ];
    }

    /**
     * Get cannot apply to own job message.
     */
    public static function cannotApplyOwnJob(): array
    {
        return [
            'type' => 'buttons',
            'body' => "⚠️ *Cannot Apply*\n*അപേക്ഷിക്കാൻ കഴിയില്ല*\n\n" .
                "You cannot apply to your own job posting.\n\n" .
                "നിങ്ങളുടെ സ്വന്തം ജോലിക്ക് അപേക്ഷിക്കാൻ കഴിയില്ല.",
            'buttons' => [
                ['id' => 'job_poster_menu', 'title' => '📋 My Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Get worker busy message (has conflicting job).
     */
    public static function workerBusy(JobPost $activeJob): array
    {
        return [
            'type' => 'buttons',
            'body' => "⚠️ *Schedule Conflict*\n*സമയ വൈരുദ്ധ്യം*\n\n" .
                "You have another job scheduled at this time:\n\n" .
                "📋 *{$activeJob->title}*\n" .
                "📅 {$activeJob->job_date->format('d M Y')}\n" .
                "⏰ {$activeJob->job_time}\n\n" .
                "Complete or cancel your current job first.\n\n" .
                "നിങ്ങൾക്ക് ഈ സമയത്ത് മറ്റൊരു ജോലി ഷെഡ്യൂൾ ചെയ്തിട്ടുണ്ട്.",
            'buttons' => [
                ['id' => 'job_worker_menu', 'title' => '👷 My Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Get application confirmed message for worker.
     */
    public static function applicationConfirmed(JobPost $job, int $position = 1): array
    {
        $positionText = $position === 1 
            ? "🎯 You're the *first* to apply!" 
            : "📊 Position: *#{$position}* in queue";

        return [
            'type' => 'buttons',
            'body' => "✅ *Application Sent!*\n*അപേക്ഷ അയച്ചു!*\n\n" .
                "Your application for *{$job->title}* has been submitted.\n\n" .
                "{$positionText}\n\n" .
                "📍 {$job->location_name}\n" .
                "📅 {$job->job_date->format('d M Y')}\n" .
                "💰 {$job->pay_display}\n\n" .
                "The task giver will review and respond soon.\n" .
                "ടാസ്ക് ഗൈവർ ഉടൻ പ്രതികരിക്കും.",
            'buttons' => [
                ['id' => 'job_browse', 'title' => '🔍 Find More Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Get new application notification for job poster.
     */
    public static function newApplicationNotification(JobApplication $application): array
    {
        $worker = $application->worker;
        $job = $application->jobPost;
        
        $ratingText = $worker->rating 
            ? "⭐ {$worker->rating}/5 ({$worker->rating_count} reviews)" 
            : "🆕 New worker";
        
        $completedText = $worker->jobs_completed > 0 
            ? "✅ {$worker->jobs_completed} jobs completed" 
            : "🆕 First job";

        $proposedAmount = $application->proposed_amount 
            ? "\n💰 *Proposed:* ₹" . number_format($application->proposed_amount)
            : "";

        $messageText = $application->message 
            ? "\n\n✉️ *Message:*\n_{$application->message}_"
            : "";

        return [
            'type' => 'buttons',
            'body' => "🔔 *New Application!*\n*പുതിയ അപേക്ഷ!*\n\n" .
                "Someone applied to your job:\n" .
                "📋 *{$job->title}*\n\n" .
                "👷 *{$worker->name}*\n" .
                "{$ratingText}\n" .
                "{$completedText}" .
                $proposedAmount .
                $messageText . "\n\n" .
                "Review and accept/reject this applicant.",
            'buttons' => [
                ['id' => 'view_applicant_' . $application->id, 'title' => '👤 View Applicant'],
                ['id' => 'view_all_apps_' . $job->id, 'title' => '👥 All Applicants'],
            ],
        ];
    }

    /**
     * Get job reposted confirmation.
     */
    public static function jobReposted(string $newJobNumber): string
    {
        return "✅ *Job Reposted!*\n\n" .
            "Your job has been reposted successfully.\n\n" .
            "*New Job ID:* {$newJobNumber}\n\n" .
            "Workers can now apply for this job.\n\n" .
            "ജോലി വീണ്ടും പോസ്റ്റ് ചെയ്തു!";
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Profile Menu Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Get worker menu header with stats.
     */
    public static function workerMenuHeader(JobWorker $worker): string
    {
        $availabilityIcon = $worker->is_available ? '🟢' : '🔴';
        $availabilityText = $worker->is_available ? 'Available' : 'Unavailable';
        $rating = $worker->rating ? "⭐ {$worker->rating}/5" : 'No ratings yet';
        
        // Name is stored in job_workers table
        $workerName = $worker->name ?? 'Worker';
        $completedJobs = $worker->jobs_completed ?? 0;
        $totalEarnings = $worker->total_earnings ?? 0;

        return "👷 *Worker Dashboard*\n*പണിക്കാരൻ ഡാഷ്ബോർഡ്*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "*{$workerName}*\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$availabilityIcon} *Status:* {$availabilityText}\n" .
            "📊 *Rating:* {$rating}\n" .
            "✅ *Jobs Completed:* {$completedJobs}\n" .
            "💰 *Total Earnings:* ₹{$totalEarnings}\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "Select an option:";
    }

    /**
     * Get worker profile view message.
     */
    public static function workerProfileView(JobWorker $worker): string
    {
        $availabilityIcon = $worker->is_available ? '🟢' : '🔴';
        $availabilityText = $worker->is_available ? 'Available for work' : 'Currently unavailable';
        $vehicleText = match(true) {
            $worker->vehicle_type === null => 'Not specified',
            is_object($worker->vehicle_type) && method_exists($worker->vehicle_type, 'label') => $worker->vehicle_type->label(),
            $worker->vehicle_type === 'none' => '🚶 Walking Only',
            $worker->vehicle_type === 'two_wheeler' => '🛵 Two Wheeler',
            $worker->vehicle_type === 'four_wheeler' => '🚗 Four Wheeler',
            default => (string) $worker->vehicle_type,
        };
        $rating = $worker->rating ? "⭐ {$worker->rating}/5 ({$worker->rating_count} reviews)" : 'No ratings yet';

        // Get job types from job_types array field
        $jobTypes = 'Not specified';
        if (!empty($worker->job_types) && is_array($worker->job_types)) {
            try {
                // job_types is an array of category IDs, get full records and extract names
                $categories = \App\Models\JobCategory::whereIn('id', $worker->job_types)->get();
                if ($categories->count() > 0) {
                    $jobTypes = $categories->map(fn($cat) => self::getCategoryName($cat))->implode(', ');
                }
            } catch (\Exception $e) {
                // Fallback to showing IDs
                $jobTypes = implode(', ', $worker->job_types);
            }
        }

        // Name is in job_workers table, phone is in users table
        $workerName = $worker->name ?? 'Unknown';
        $userPhone = $worker->user?->phone ?? 'Not set';
        $locationName = $worker->address ?? 'Not specified';
        $completedJobs = $worker->jobs_completed ?? 0;
        $totalEarnings = $worker->total_earnings ?? 0;

        $message = "👷 *My Worker Profile*\n*എന്റെ പണിക്കാരൻ പ്രൊഫൈൽ*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "👤 *Name:* {$workerName}\n" .
            "📞 *Phone:* {$userPhone}\n" .
            "📍 *Location:* {$locationName}\n" .
            "🚗 *Vehicle:* {$vehicleText}\n" .
            "📋 *Job Types:* {$jobTypes}\n" .
            "{$availabilityIcon} *Availability:* {$availabilityText}\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "*Stats:*\n" .
            "📊 Rating: {$rating}\n" .
            "✅ Completed: {$completedJobs} jobs\n" .
            "💰 Earnings: ₹{$totalEarnings}\n" .
            "━━━━━━━━━━━━━━━━";

        return $message;
    }

    /**
     * Get edit profile field selection message.
     */
    public static function editProfileSelect(): string
    {
        return "✏️ *Edit Profile*\n*പ്രൊഫൈൽ എഡിറ്റ് ചെയ്യുക*\n\n" .
            "Select which field you want to update:\n\n" .
            "ഏത് വിവരമാണ് മാറ്റേണ്ടത്?";
    }

    /**
     * Get edit name prompt.
     */
    public static function editNamePrompt(string $currentName): string
    {
        return "👤 *Edit Name*\n*പേര് മാറ്റുക*\n\n" .
            "Current name: *{$currentName}*\n\n" .
            "Enter your new name:\n\n" .
            "നിങ്ങളുടെ പുതിയ പേര് നൽകുക:";
    }

    /**
     * Get edit photo prompt.
     */
    public static function editPhotoPrompt(): string
    {
        return "📷 *Edit Photo*\n*ഫോട്ടോ മാറ്റുക*\n\n" .
            "Send a new profile photo:\n\n" .
            "പുതിയ പ്രൊഫൈൽ ഫോട്ടോ അയയ്ക്കുക:\n\n" .
            "_Photo should clearly show your face_";
    }

    /**
     * Get edit location prompt.
     */
    public static function editLocationPrompt(string $currentLocation): string
    {
        return "📍 *Edit Location*\n*സ്ഥലം മാറ്റുക*\n\n" .
            "Current location: *{$currentLocation}*\n\n" .
            "Share your new location or type the address:\n\n" .
            "നിങ്ങളുടെ പുതിയ സ്ഥലം ഷെയർ ചെയ്യുക:";
    }

    /**
     * Get edit vehicle prompt.
     */
    public static function editVehiclePrompt(?string $currentVehicle): string
    {
        $current = $currentVehicle ?? 'Not specified';
        
        return "🚗 *Edit Vehicle Type*\n*വാഹന തരം മാറ്റുക*\n\n" .
            "Current: *{$current}*\n\n" .
            "Select your vehicle type:\n\n" .
            "നിങ്ങളുടെ വാഹന തരം തിരഞ്ഞെടുക്കുക:";
    }

    /**
     * Get edit job types prompt.
     */
    public static function editJobTypesPrompt(Collection $currentTypes): string
    {
        $typesList = $currentTypes->map(fn($cat) => self::getCategoryName($cat))->implode(', ') ?: 'None selected';
        
        return "📋 *Edit Job Types*\n*ജോലി തരങ്ങൾ മാറ്റുക*\n\n" .
            "Current types: *{$typesList}*\n\n" .
            "Select the job types you can do:\n\n" .
            "നിങ്ങൾക്ക് ചെയ്യാൻ കഴിയുന്ന ജോലി തരങ്ങൾ തിരഞ്ഞെടുക്കുക:";
    }

    /**
     * Get edit availability prompt.
     */
    public static function editAvailabilityPrompt(bool $currentAvailability): string
    {
        $currentText = $currentAvailability ? 'Available 🟢' : 'Unavailable 🔴';
        
        return "🔘 *Edit Availability*\n*ലഭ്യത മാറ്റുക*\n\n" .
            "Current status: *{$currentText}*\n\n" .
            "Select your availability:\n\n" .
            "നിങ്ങളുടെ ലഭ്യത തിരഞ്ഞെടുക്കുക:";
    }

    /**
     * Get profile update confirmation.
     */
    public static function profileUpdateConfirm(string $field, string $newValue): string
    {
        return "✏️ *Confirm Update*\n*മാറ്റം സ്ഥിരീകരിക്കുക*\n\n" .
            "Update *{$field}* to:\n*{$newValue}*\n\n" .
            "Confirm this change?";
    }

    /**
     * Get profile updated success message.
     */
    public static function profileUpdated(string $field): string
    {
        return "✅ *Profile Updated*\n\n" .
            "*{$field}* has been updated successfully.\n\n" .
            "*{$field}* വിജയകരമായി അപ്‌ഡേറ്റ് ചെയ്തു.";
    }

    /**
     * Get availability toggled message.
     */
    public static function availabilityToggled(bool $isAvailable): string
    {
        if ($isAvailable) {
            return "🟢 *You are now Available*\n\n" .
                "You will receive notifications for new jobs in your area.\n\n" .
                "നിങ്ങൾ ഇപ്പോൾ ലഭ്യമാണ്. പുതിയ ജോലികളെ കുറിച്ച് അറിയിപ്പുകൾ ലഭിക്കും.";
        }

        return "🔴 *You are now Unavailable*\n\n" .
            "You won't receive notifications for new jobs.\n\n" .
            "നിങ്ങൾ ഇപ്പോൾ ലഭ്യമല്ല. പുതിയ ജോലി അറിയിപ്പുകൾ ലഭിക്കില്ല.";
    }

    /*
    |--------------------------------------------------------------------------
    | Job Posting Flow Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Get job confirmation message with custom category support.
     */
    public static function jobPostConfirmation(array $jobData): string
    {
        // Get category name - use custom text if available
        $categoryName = $jobData['custom_category_text'] ?? 'Unknown';
        if (!$categoryName || $categoryName === 'Unknown') {
            $category = JobCategory::find($jobData['job_category_id'] ?? null);
            if ($category) {
                $categoryName = self::getCategoryName($category);
            }
        }

        // Use display time if available, otherwise format from MySQL time
        $timeDisplay = $jobData['job_time_display'] ?? self::formatMySQLTime($jobData['job_time'] ?? '');

        $message = "✅ *Confirm Job Post*\n*ജോലി പോസ്റ്റ് സ്ഥിരീകരിക്കുക*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "*{$jobData['title']}*\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "📁 *Category:* {$categoryName}\n" .
            "💰 *Pay:* ₹{$jobData['pay_amount']}\n" .
            "📍 *Location:* {$jobData['location_name']}\n" .
            "📅 *Date:* {$jobData['job_date']}\n" .
            "⏰ *Time:* {$timeDisplay}\n" .
            "⏱️ *Duration:* " . ($jobData['estimated_duration'] ?? 'Not set') . "\n";

        if (!empty($jobData['description'])) {
            $message .= "\n*Description:*\n{$jobData['description']}\n";
        }

        if (!empty($jobData['special_instructions'])) {
            $message .= "\n*Instructions:*\n{$jobData['special_instructions']}\n";
        }

        $message .= "\n━━━━━━━━━━━━━━━━\n" .
            "Is this correct? Confirm to post the job.";

        return $message;
    }
    
    /**
     * Format MySQL time (HH:MM:SS) to 12-hour format.
     */
    public static function formatMySQLTime(?string $mysqlTime): string
    {
        if (!$mysqlTime) {
            return 'Not set';
        }
        
        try {
            $time = \Carbon\Carbon::createFromFormat('H:i:s', $mysqlTime);
            return $time->format('g:i A');
        } catch (\Exception $e) {
            return $mysqlTime;
        }
    }

    /**
     * Get job posted success message.
     */
    public static function jobPosted(JobPost $job): string
    {
        $categoryName = $job->custom_category_text ?? 'Unknown';
        if (!$categoryName || $categoryName === 'Unknown') {
            $categoryName = $job->category ? self::getCategoryName($job->category) : 'Unknown';
        }

        return "🎉 *Job Posted Successfully!*\n*ജോലി വിജയകരമായി പോസ്റ്റ് ചെയ്തു!*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "*{$job->title}*\n" .
            "📁 {$categoryName}\n" .
            "💰 ₹{$job->pay_amount}\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "🆔 *Job ID:* {$job->job_number}\n\n" .
            "Workers in your area will be notified.\n" .
            "You'll receive a message when someone applies.\n\n" .
            "നിങ്ങളുടെ പ്രദേശത്തെ പണിക്കാർക്ക് അറിയിപ്പ് ലഭിക്കും.";
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get status icon for job status.
     */
    public static function getStatusIcon(string|JobStatus $status): string
    {
        // Convert enum to string if needed
        $statusStr = $status instanceof JobStatus ? $status->value : $status;
        
        return match ($statusStr) {
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
     * Get status text for job status.
     */
    public static function getStatusText(string|JobStatus $status): string
    {
        // Convert enum to string if needed
        $statusStr = $status instanceof JobStatus ? $status->value : $status;
        
        return match ($statusStr) {
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
     * Get status in Malayalam.
     */
    public static function getStatusMalayalam(string|JobStatus $status): string
    {
        // Convert enum to string if needed
        $statusStr = $status instanceof JobStatus ? $status->value : $status;
        
        return match ($statusStr) {
            'open' => 'അപേക്ഷകൾക്കായി തുറന്നിരിക്കുന്നു',
            'assigned' => 'പണിക്കാരനെ നിയമിച്ചു',
            'in_progress' => 'നടന്നുകൊണ്ടിരിക്കുന്നു',
            'completed' => 'പൂർത്തിയാക്കി',
            'cancelled' => 'റദ്ദാക്കി',
            'expired' => 'കാലഹരണപ്പെട്ടു',
            'draft' => 'ഡ്രാഫ്റ്റ്',
            default => 'അജ്ഞാതം',
        };
    }

    /**
     * Get worker earnings summary message.
     */
    public static function workerEarningsSummary(JobWorker $worker, $weekEarnings = null): string
    {
        $totalEarnings = $worker->total_earnings ?? 0;
        $completedJobs = $worker->jobs_completed ?? 0;
        $weeklyAmount = $weekEarnings?->amount ?? 0;

        return "💰 *My Earnings*\n*എന്റെ വരുമാനം*\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "📊 *This Week:* ₹" . number_format($weeklyAmount, 2) . "\n" .
            "💵 *Total Earnings:* ₹" . number_format($totalEarnings, 2) . "\n" .
            "✅ *Jobs Completed:* {$completedJobs}\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "_Complete more jobs to increase your earnings!_";
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Registration Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Get worker welcome/registration start message.
     */
    public static function workerWelcome(): array
    {
        return [
            'type' => 'text',
            'body' => "👷 *Become a Worker*\n*പണിക്കാരനായി രജിസ്റ്റർ ചെയ്യുക*\n\n" .
                "━━━━━━━━━━━━━━━━\n\n" .
                "Join our network of skilled workers and start earning!\n\n" .
                "ഞങ്ങളുടെ പണിക്കാരുടെ ശൃംഖലയിൽ ചേരൂ!\n\n" .
                "You'll be able to:\n" .
                "✅ Find jobs near you\n" .
                "✅ Set your own schedule\n" .
                "✅ Earn money on your terms\n\n" .
                "━━━━━━━━━━━━━━━━\n\n" .
                "Let's set up your worker profile.\n\n" .
                "*What is your name?*\n" .
                "നിങ്ങളുടെ പേര് എന്താണ്?",
        ];
    }

    /**
     * Get ask worker name message.
     */
    public static function askWorkerName(): array
    {
        return [
            'type' => 'text',
            'body' => "👤 *Your Name*\n*നിങ്ങളുടെ പേര്*\n\n" .
                "Please enter your full name:\n" .
                "നിങ്ങളുടെ മുഴുവൻ പേര് നൽകുക:",
        ];
    }

    /**
     * Get ask worker photo message.
     */
    public static function askWorkerPhoto(): array
    {
        return [
            'type' => 'buttons',
            'body' => "📸 *Profile Photo*\n*പ്രൊഫൈൽ ഫോട്ടോ*\n\n" .
                "Please send a clear photo of yourself.\n" .
                "This helps job posters recognize you.\n\n" .
                "നിങ്ങളുടെ വ്യക്തമായ ഒരു ഫോട്ടോ അയയ്ക്കുക.\n\n" .
                "_You can also skip this step for now._",
            'buttons' => [
                ['id' => 'skip_worker_photo', 'title' => '⏭️ Skip'],
            ],
            'header' => '📸 Photo',
        ];
    }

    /**
     * Get ask worker location message.
     */
    public static function askWorkerLocation(): array
    {
        return [
            'type' => 'text',
            'body' => "📍 *Your Location*\n*നിങ്ങളുടെ ലൊക്കേഷൻ*\n\n" .
                "Share your location so we can find jobs near you.\n\n" .
                "അടുത്തുള്ള ജോലികൾ കണ്ടെത്താൻ നിങ്ങളുടെ ലൊക്കേഷൻ പങ്കിടുക.\n\n" .
                "Tap the 📎 attachment button and select 'Location'.",
        ];
    }

    /**
     * Get ask worker vehicle type message.
     */
    public static function askVehicleType(): array
    {
        return [
            'type' => 'buttons',
            'body' => "🚗 *Vehicle Type*\n*വാഹന തരം*\n\n" .
                "Do you have a vehicle?\n" .
                "This helps us match you with suitable jobs.\n\n" .
                "നിങ്ങൾക്ക് വാഹനം ഉണ്ടോ?",
            'buttons' => [
                ['id' => 'vehicle_none', 'title' => '🚶 Walking Only'],
                ['id' => 'vehicle_two_wheeler', 'title' => '🛵 Two Wheeler'],
                ['id' => 'vehicle_four_wheeler', 'title' => '🚗 Four Wheeler'],
            ],
            'header' => '🚗 Vehicle',
        ];
    }

    /**
     * Get ask worker job types message.
     */
    public static function askJobTypes(): array
    {
        $categories = JobCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->limit(9)
            ->get();

        $rows = $categories->map(fn($cat) => [
            'id' => 'jobtype_' . $cat->id,
            'title' => ($cat->icon ?? '📋') . ' ' . substr(self::getCategoryName($cat), 0, 20),
            'description' => substr($cat->description ?? 'Select this job type', 0, 70),
        ])->toArray();

        // Add "Done" option
        $rows[] = [
            'id' => 'jobtype_done',
            'title' => '✅ Done Selecting',
            'description' => 'Finish selecting job types',
        ];

        return [
            'type' => 'list',
            'body' => "💼 *Job Types*\n*ജോലി തരങ്ങൾ*\n\n" .
                "What types of jobs can you do?\n" .
                "Select all that apply, then tap 'Done':\n\n" .
                "നിങ്ങൾക്ക് ഏത് തരം ജോലികൾ ചെയ്യാൻ കഴിയും?",
            'button' => 'Select',
            'sections' => [[
                'title' => 'Job Types',
                'rows' => $rows,
            ]],
            'header' => '💼 Job Types',
        ];
    }
    
    /**
     * Get category name from JobCategory model.
     * 
     * Uses name_en (English) as primary, name_ml (Malayalam) as fallback.
     * Based on job_categories table: name_en, name_ml columns.
     */
    protected static function getCategoryName($category): string
    {
        // Primary: English name
        if (!empty($category->name_en)) {
            return $category->name_en;
        }
        // Fallback: Malayalam name
        if (!empty($category->name_ml)) {
            return $category->name_ml;
        }
        
        return 'Category #' . ($category->id ?? 'Unknown');
    }

    /**
     * Get ask worker availability message.
     */
    public static function askAvailability(): array
    {
        return [
            'type' => 'list',
            'body' => "🕐 *Availability*\n*ലഭ്യത*\n\n" .
                "When are you usually available for work?\n\n" .
                "നിങ്ങൾ സാധാരണയായി എപ്പോഴാണ് ജോലിക്ക് ലഭ്യം?",
            'button' => 'Select',
            'sections' => [[
                'title' => 'Availability',
                'rows' => [
                    ['id' => 'avail_morning', 'title' => '🌅 Morning', 'description' => '6 AM - 12 PM'],
                    ['id' => 'avail_afternoon', 'title' => '☀️ Afternoon', 'description' => '12 PM - 5 PM'],
                    ['id' => 'avail_evening', 'title' => '🌆 Evening', 'description' => '5 PM - 9 PM'],
                    ['id' => 'avail_flexible', 'title' => '🔄 Flexible', 'description' => 'Available anytime'],
                ],
            ]],
            'header' => '🕐 Availability',
        ];
    }

    /**
     * Get worker registration confirmation message.
     */
    public static function confirmWorkerRegistration(array $data): array
    {
        $name = $data['name'] ?? 'Not set';
        $hasPhoto = !empty($data['photo_url']) ? '✅ Uploaded' : '❌ Not uploaded';
        $vehicle = match($data['vehicle_type'] ?? 'none') {
            'none' => '🚶 Walking Only',
            'two_wheeler' => '🛵 Two Wheeler',
            'four_wheeler' => '🚗 Four Wheeler',
            default => 'Not set',
        };

        // Get job type names
        $jobTypeNames = 'Not selected';
        $jobTypes = $data['job_types'] ?? [];
        if (!empty($jobTypes)) {
            $categories = JobCategory::whereIn('id', $jobTypes)->get();
            if ($categories->count() > 0) {
                $jobTypeNames = $categories->map(fn($cat) => self::getCategoryName($cat))->implode(', ');
            }
        }

        // Get availability display
        $availabilityDisplay = 'Flexible';
        $availability = $data['availability'] ?? [];
        if (!empty($availability)) {
            $labels = [
                'morning' => '🌅 Morning',
                'afternoon' => '☀️ Afternoon',
                'evening' => '🌆 Evening',
                'flexible' => '🔄 Flexible',
            ];
            $availabilityDisplay = collect($availability)
                ->map(fn($a) => $labels[$a] ?? $a)
                ->implode(', ');
        }

        return [
            'type' => 'buttons',
            'body' => "✅ *Confirm Registration*\n*രജിസ്ട്രേഷൻ സ്ഥിരീകരിക്കുക*\n\n" .
                "━━━━━━━━━━━━━━━━\n" .
                "👤 *Name:* {$name}\n" .
                "📸 *Photo:* {$hasPhoto}\n" .
                "🚗 *Vehicle:* {$vehicle}\n" .
                "💼 *Job Types:* {$jobTypeNames}\n" .
                "🕐 *Availability:* {$availabilityDisplay}\n" .
                "━━━━━━━━━━━━━━━━\n\n" .
                "Is this information correct?",
            'buttons' => [
                ['id' => 'confirm_worker_reg', 'title' => '✅ Confirm'],
                ['id' => 'edit_worker_reg', 'title' => '✏️ Edit'],
                ['id' => 'cancel_worker_reg', 'title' => '❌ Cancel'],
            ],
            'header' => '✅ Confirm',
        ];
    }

    /**
     * Get worker registration success message.
     */
    public static function workerRegistrationSuccess($worker): array
    {
        $name = is_object($worker) ? $worker->name : ($worker['name'] ?? 'Worker');

        return [
            'type' => 'buttons',
            'body' => "🎉 *Registration Complete!*\n*രജിസ്ട്രേഷൻ പൂർത്തിയായി!*\n\n" .
                "━━━━━━━━━━━━━━━━\n\n" .
                "Welcome, *{$name}*! 👷\n\n" .
                "You are now registered as a worker.\n" .
                "നിങ്ങൾ ഇപ്പോൾ ഒരു പണിക്കാരനായി രജിസ്റ്റർ ചെയ്തിരിക്കുന്നു.\n\n" .
                "━━━━━━━━━━━━━━━━\n\n" .
                "You can now:\n" .
                "✅ Browse available jobs\n" .
                "✅ Apply to jobs near you\n" .
                "✅ Receive job notifications\n\n" .
                "_Start exploring jobs now!_",
            'buttons' => [
                ['id' => 'browse_jobs', 'title' => '🔍 Browse Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
            'header' => '🎉 Success',
        ];
    }

    /**
     * Get worker already registered message.
     */
    public static function workerAlreadyRegistered(): string
    {
        return "ℹ️ *Already Registered*\n\n" .
            "You are already registered as a worker.\n" .
            "നിങ്ങൾ ഇതിനകം ഒരു പണിക്കാരനായി രജിസ്റ്റർ ചെയ്തിട്ടുണ്ട്.\n\n" .
            "Go to the Worker Menu to view your profile and find jobs.";
    }

/*
|--------------------------------------------------------------------------
| Job Execution Flow Messages
|--------------------------------------------------------------------------
*/

    /**
     * Request arrival photo from worker.
     */
    public static function requestArrivalPhoto(JobPost $job): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        
        return [
            'type' => 'text',
            'text' => "📸 *Arrival Verification*\n" .
                "*എത്തിച്ചേർന്നു എന്ന് സ്ഥിരീകരിക്കുക*\n\n" .
                "{$categoryIcon} *{$job->title}*\n" .
                "📍 {$job->location_display}\n\n" .
                "Please send a photo to confirm you've arrived at the job location.\n\n" .
                "ജോലി സ്ഥലത്ത് എത്തിയതിന്റെ ഫോട്ടോ അയക്കുക.\n\n" .
                "_📷 Take a clear photo showing the location._",
        ];
    }

    /**
     * Notify poster that worker has arrived.
     */
    public static function workerArrived(JobPost $job, JobWorker $worker): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        
        return [
            'type' => 'buttons',
            'body' => "📍 *Worker Has Arrived!*\n" .
                "*പണിക്കാരൻ എത്തി!*\n\n" .
                "{$categoryIcon} *{$job->title}*\n" .
                "👷 {$worker->name}\n" .
                "⭐ {$worker->rating_display}\n\n" .
                "The worker has arrived at the job location and is ready to start.\n\n" .
                "പണിക്കാരൻ ജോലി സ്ഥലത്ത് എത്തി, ജോലി ആരംഭിക്കാൻ തയ്യാറാണ്.",
            'buttons' => [
                ['id' => 'contact_worker_' . $job->id, 'title' => '📞 Contact Worker'],
                ['id' => 'view_job_' . $job->id, 'title' => '📋 View Job'],
            ],
            'header' => '📍 Worker Arrived',
        ];
    }

    /**
     * Arrival confirmed message.
     */
    public static function arrivalConfirmed(JobPost $job): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        
        return [
            'type' => 'buttons',
            'body' => "✅ *Arrival Confirmed!*\n" .
                "*എത്തിച്ചേർന്നു!*\n\n" .
                "{$categoryIcon} *{$job->title}*\n\n" .
                "Great! Your arrival has been recorded.\n" .
                "നിങ്ങളുടെ വരവ് രേഖപ്പെടുത്തി.\n\n" .
                "Start working on the task. When done, tap 'Mark Complete'.",
            'buttons' => [
                ['id' => 'mark_complete', 'title' => '✅ Mark Complete'],
                ['id' => 'report_issue', 'title' => '⚠️ Report Issue'],
            ],
            'header' => '✅ Arrived',
        ];
    }

    /**
     * Request worker to confirm job completion.
     */
    public static function requestCompletionConfirmation(JobPost $job): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        
        return [
            'type' => 'buttons',
            'body' => "📸 *Photo Received!*\n" .
                "*ഫോട്ടോ ലഭിച്ചു!*\n\n" .
                "{$categoryIcon} *{$job->title}*\n\n" .
                "Please confirm that you have completed this job.\n\n" .
                "ജോലി പൂർത്തിയായി എന്ന് സ്ഥിരീകരിക്കുക.",
            'buttons' => [
                ['id' => 'confirm_complete', 'title' => '✅ Yes, Completed'],
                ['id' => 'not_complete', 'title' => '❌ Not Yet'],
                ['id' => 'report_issue', 'title' => '⚠️ Report Issue'],
            ],
            'header' => '✅ Confirm Completion',
        ];
    }

    /**
     * Request completion photo from worker.
     */
    public static function requestCompletionPhoto(JobPost $job): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        
        return [
            'type' => 'text',
            'text' => "📸 *Completion Verification*\n" .
                "*ജോലി പൂർത്തിയായി*\n\n" .
                "{$categoryIcon} *{$job->title}*\n\n" .
                "Please send a photo showing the completed work.\n\n" .
                "പൂർത്തിയാക്കിയ ജോലിയുടെ ഫോട്ടോ അയക്കുക.\n\n" .
                "_📷 Take a clear photo of the finished work._",
        ];
    }

    /**
     * Job completed - awaiting poster confirmation.
     */
    public static function completionSubmitted(JobPost $job): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        
        return [
            'type' => 'buttons',
            'body' => "✅ *Work Marked Complete!*\n" .
                "*ജോലി പൂർത്തിയാക്കി!*\n\n" .
                "{$categoryIcon} *{$job->title}*\n\n" .
                "The task giver has been notified.\n" .
                "ടാസ്ക് ഗൈവറെ അറിയിച്ചു.\n\n" .
                "Please wait for them to confirm and process payment.\n\n" .
                "💰 *Payment:* {$job->pay_display}",
            'buttons' => [
                ['id' => 'contact_poster', 'title' => '📞 Contact Poster'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'header' => '✅ Complete',
        ];
    }

    /**
     * Notify poster that worker completed the job.
     */
    public static function notifyPosterJobCompleted(JobPost $job, JobWorker $worker): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        
        return [
            'type' => 'buttons',
            'body' => "✅ *Job Completed!*\n" .
                "*ജോലി പൂർത്തിയായി!*\n\n" .
                "{$categoryIcon} *{$job->title}*\n" .
                "👷 Worker: {$worker->name}\n\n" .
                "The worker has marked this job as complete.\n\n" .
                "Please verify the work and confirm to release payment.\n\n" .
                "💰 *Amount:* {$job->pay_display}",
            'buttons' => [
                ['id' => 'confirm_completion_' . $job->id, 'title' => '✅ Confirm & Pay'],
                ['id' => 'report_issue_' . $job->id, 'title' => '⚠️ Report Issue'],
                ['id' => 'view_job_' . $job->id, 'title' => '📋 View Details'],
            ],
            'header' => '✅ Job Completed',
        ];
    }

    /**
     * Payment confirmation request.
     */
    public static function requestPaymentConfirmation(JobPost $job): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        
        return [
            'type' => 'buttons',
            'body' => "💰 *Confirm Payment*\n" .
                "*പേയ്മെന്റ് സ്ഥിരീകരിക്കുക*\n\n" .
                "{$categoryIcon} *{$job->title}*\n" .
                "💰 Amount: *{$job->pay_display}*\n\n" .
                "How will you pay the worker?\n" .
                "പണിക്കാരന് എങ്ങനെ പണം നൽകും?",
            'buttons' => [
                ['id' => 'pay_cash', 'title' => '💵 Cash'],
                ['id' => 'pay_upi', 'title' => '📱 UPI'],
                ['id' => 'pay_other', 'title' => '💳 Other'],
            ],
            'header' => '💰 Payment',
        ];
    }

    /**
     * Worker in-progress job status.
     */
    public static function workerActiveJobStatus(JobPost $job, ?JobVerification $verification = null): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        
        $status = 'Not started';
        $nextAction = 'arrival_photo';
        
        if ($verification) {
            if ($verification->poster_confirmed_at) {
                $status = '✅ Completed & Paid';
                $nextAction = 'completed';
            } elseif ($verification->worker_confirmed_at) {
                $status = '⏳ Awaiting payment';
                $nextAction = 'awaiting_payment';
            } elseif ($verification->arrival_verified_at) {
                $status = '🔨 In Progress';
                $nextAction = 'mark_complete';
            } else {
                $status = '📍 Arrive at location';
                $nextAction = 'arrival_photo';
            }
        }
        
        $buttons = match($nextAction) {
            'arrival_photo' => [
                ['id' => 'submit_arrival', 'title' => '📸 I\'ve Arrived'],
                ['id' => 'get_directions', 'title' => '📍 Directions'],
            ],
            'mark_complete' => [
                ['id' => 'mark_complete', 'title' => '✅ Mark Complete'],
                ['id' => 'report_issue', 'title' => '⚠️ Report Issue'],
            ],
            'awaiting_payment' => [
                ['id' => 'contact_poster', 'title' => '📞 Contact Poster'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            default => [
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        };
        
        return [
            'type' => 'buttons',
            'body' => "📋 *Your Active Job*\n" .
                "*നിങ്ങളുടെ സജീവ ജോലി*\n\n" .
                "{$categoryIcon} *{$job->title}*\n" .
                "📍 {$job->location_display}\n" .
                "📅 {$job->formatted_date_time}\n" .
                "💰 {$job->pay_display}\n\n" .
                "Status: *{$status}*",
            'buttons' => $buttons,
            'header' => '📋 Active Job',
        ];
    }

    /**
     * No active job for worker.
     */
    public static function noActiveJob(): array
    {
        return [
            'type' => 'buttons',
            'body' => "📭 *No Active Jobs*\n" .
                "*സജീവ ജോലികൾ ഇല്ല*\n\n" .
                "You don't have any active jobs right now.\n" .
                "ഇപ്പോൾ നിങ്ങൾക്ക് ജോലികൾ ഇല്ല.\n\n" .
                "Browse available jobs nearby!",
            'buttons' => [
                ['id' => 'job_browse', 'title' => '🔍 Find Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'header' => '📭 No Jobs',
        ];
    }

    /**
     * Request worker rating from poster.
     */
    public static function requestWorkerRating(JobPost $job, ?JobWorker $worker): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        $workerName = $worker?->name ?? 'Worker';

        return [
            'type' => 'list',
            'body' => "⭐ *Rate the Worker*\n" .
                "*പണിക്കാരനെ റേറ്റ് ചെയ്യുക*\n\n" .
                "{$categoryIcon} *{$job->title}*\n" .
                "👷 {$workerName}\n\n" .
                "How was the work quality?\n" .
                "പണിയുടെ നിലവാരം എങ്ങനെയായിരുന്നു?",
            'button' => '⭐ Rate',
            'sections' => [
                [
                    'title' => 'Rating',
                    'rows' => [
                        ['id' => 'rate_5', 'title' => '⭐⭐⭐⭐⭐ Excellent', 'description' => 'Outstanding work!'],
                        ['id' => 'rate_4', 'title' => '⭐⭐⭐⭐ Very Good', 'description' => 'Great job'],
                        ['id' => 'rate_3', 'title' => '⭐⭐⭐ Good', 'description' => 'Satisfactory'],
                        ['id' => 'rate_2', 'title' => '⭐⭐ Fair', 'description' => 'Could be better'],
                        ['id' => 'rate_1', 'title' => '⭐ Poor', 'description' => 'Not satisfied'],
                        ['id' => 'skip_rating', 'title' => '⏭️ Skip', 'description' => 'Skip rating'],
                    ],
                ],
            ],
            'header' => '⭐ Rate Worker',
        ];
    }

    /**
     * Job completed summary message.
     */
    public static function jobCompleted(JobPost $job, bool $isWorker = true): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        $payAmount = $job->pay_display ?? '₹' . number_format((float) ($job->pay_amount ?? 0));

        if ($isWorker) {
            // Worker completion message
            return [
                'type' => 'buttons',
                'body' => "🎉 *Job Complete!*\n" .
                    "*ജോലി പൂർത്തിയായി!*\n\n" .
                    "{$categoryIcon} *{$job->title}*\n" .
                    "💰 Earned: *{$payAmount}*\n\n" .
                    "Great work! Your earnings have been updated.\n" .
                    "നല്ല ജോലി! നിങ്ങളുടെ വരുമാനം അപ്‌ഡേറ്റ് ചെയ്തു.\n\n" .
                    "Keep up the great work! 💪",
                'buttons' => [
                    ['id' => 'find_jobs', 'title' => '🔍 Find More Jobs'],
                    ['id' => 'my_jobs', 'title' => '📋 My Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                'header' => '🎉 Complete!',
            ];
        } else {
            // Poster completion message
            return [
                'type' => 'buttons',
                'body' => "✅ *Job Complete!*\n" .
                    "*ജോലി പൂർത്തിയായി!*\n\n" .
                    "{$categoryIcon} *{$job->title}*\n" .
                    "💰 Paid: *{$payAmount}*\n\n" .
                    "Thank you for using JobTap!\n" .
                    "JobTap ഉപയോഗിച്ചതിന് നന്ദി!\n\n" .
                    "Need more help? Post another job!",
                'buttons' => [
                    ['id' => 'post_job', 'title' => '➕ Post New Job'],
                    ['id' => 'my_posted_jobs', 'title' => '📋 My Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                'header' => '✅ Complete!',
            ];
        }
    }

    /**
     * Payment confirmed, now ask for rating.
     */
    public static function paymentConfirmed(JobPost $job, string $paymentMethod): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        $payAmount = $job->pay_display ?? '₹' . number_format((float) ($job->pay_amount ?? 0));
        
        $methodDisplay = match($paymentMethod) {
            'cash' => '💵 Cash',
            'upi' => '📱 UPI',
            'other' => '💳 Other',
            default => '💰 ' . ucfirst($paymentMethod),
        };

        return [
            'type' => 'list',
            'body' => "💰 *Payment Recorded!*\n" .
                "*പേയ്മെന്റ് രേഖപ്പെടുത്തി!*\n\n" .
                "{$categoryIcon} *{$job->title}*\n" .
                "💵 Amount: *{$payAmount}*\n" .
                "💳 Method: {$methodDisplay}\n\n" .
                "Now please rate the worker:\n" .
                "ഇപ്പോൾ പണിക്കാരനെ റേറ്റ് ചെയ്യുക:",
            'button' => '⭐ Rate Worker',
            'sections' => [
                [
                    'title' => 'Rating',
                    'rows' => [
                        ['id' => 'rate_5', 'title' => '⭐⭐⭐⭐⭐ Excellent', 'description' => 'Outstanding work!'],
                        ['id' => 'rate_4', 'title' => '⭐⭐⭐⭐ Very Good', 'description' => 'Great job'],
                        ['id' => 'rate_3', 'title' => '⭐⭐⭐ Good', 'description' => 'Satisfactory'],
                        ['id' => 'rate_2', 'title' => '⭐⭐ Fair', 'description' => 'Could be better'],
                        ['id' => 'rate_1', 'title' => '⭐ Poor', 'description' => 'Not satisfied'],
                        ['id' => 'skip_rating', 'title' => '⏭️ Skip', 'description' => 'Skip rating'],
                    ],
                ],
            ],
            'header' => '💰 Payment Confirmed',
        ];
    }

    /**
     * Job fully completed with rating.
     */
    public static function jobFullyCompleted(JobPost $job, int $rating): array
    {
        $categoryIcon = $job->category?->icon ?? '📋';
        $payAmount = $job->pay_display ?? '₹' . number_format((float) ($job->pay_amount ?? 0));
        $stars = str_repeat('⭐', $rating);
        $workerName = $job->assignedWorker?->name ?? 'Worker';

        return [
            'type' => 'buttons',
            'body' => "🎉 *All Done!*\n" .
                "*എല്ലാം പൂർത്തിയായി!*\n\n" .
                "{$categoryIcon} *{$job->title}*\n" .
                "👷 {$workerName}\n" .
                "💰 Paid: *{$payAmount}*\n" .
                "Rating: {$stars}\n\n" .
                "Thank you for using JobTap!\n" .
                "JobTap ഉപയോഗിച്ചതിന് നന്ദി!\n\n" .
                "Need more help? Post another job!",
            'buttons' => [
                ['id' => 'post_job', 'title' => '➕ Post New Job'],
                ['id' => 'my_posted_jobs', 'title' => '📋 My Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'header' => '🎉 Complete!',
        ];
    }
}