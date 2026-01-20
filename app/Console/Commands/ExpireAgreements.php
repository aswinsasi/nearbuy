<?php

namespace App\Console\Commands;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Services\Agreements\AgreementService;
use Illuminate\Console\Command;

/**
 * Expire agreements that haven't been confirmed within the expiry period.
 *
 * @example
 * php artisan nearbuy:expire-agreements
 */
class ExpireAgreements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nearbuy:expire-agreements
                            {--dry-run : Show what would expire without expiring}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire pending agreements that have passed their confirmation deadline';

    /**
     * Execute the console command.
     */
    public function handle(AgreementService $agreementService): int
    {
        $this->info('Checking for expired agreements...');

        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        try {
            $expired = $agreementService->expirePendingAgreements();

            $this->info("✅ Expired {$expired} agreement(s).");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Show what would be expired.
     */
    protected function dryRun(): int
    {
        $agreements = Agreement::query()
            ->where('status', AgreementStatus::PENDING)
            ->where('expires_at', '<', now())
            ->with('creator')
            ->get();

        if ($agreements->isEmpty()) {
            $this->info('No agreements to expire.');
            return self::SUCCESS;
        }

        $this->info("Found {$agreements->count()} agreement(s) to expire:");

        $headers = ['ID', 'Number', 'Creator', 'Counterparty', 'Amount', 'Expired At'];
        $rows = [];

        foreach ($agreements as $agreement) {
            $rows[] = [
                $agreement->id,
                $agreement->agreement_number,
                $agreement->creator?->name ?? 'Unknown',
                $agreement->to_name,
                '₹' . number_format($agreement->amount),
                $agreement->expires_at->format('Y-m-d H:i'),
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}