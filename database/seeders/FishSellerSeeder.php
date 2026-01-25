<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\FishSeller;
use App\Models\FishCatch;
use App\Models\FishType;
use App\Models\FishSubscription;
use App\Enums\UserType;
use App\Enums\FishSellerType;
use App\Enums\FishCatchStatus;
use App\Enums\FishQuantityRange;
use App\Enums\FishAlertFrequency;
use Illuminate\Database\Seeder;

/**
 * Seeder for fish sellers and related test data.
 *
 * @srs-ref Pacha Meen Module
 */
class FishSellerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding fish sellers and test data...');

        // Create fish seller users
        $sellers = $this->createFishSellers();
        $this->command->info('Created ' . count($sellers) . ' fish sellers');

        // Create some catches
        $catches = $this->createFishCatches($sellers);
        $this->command->info('Created ' . count($catches) . ' fish catches');

        // Create some customer subscriptions
        $subscriptions = $this->createSubscriptions();
        $this->command->info('Created ' . count($subscriptions) . ' fish subscriptions');
    }

    /**
     * Create fish sellers.
     */
    protected function createFishSellers(): array
    {
        $sellersData = [
            [
                'phone' => '919876543250',
                'name' => 'Raghavan Fisheries',
                'seller_type' => FishSellerType::FISHERMAN,
                'business_name' => 'Raghavan Fresh Catch',
                'market_name' => 'Fort Kochi Harbour',
                'lat' => 9.9633,
                'lng' => 76.2411,
            ],
            [
                'phone' => '919876543251',
                'name' => 'Suresh Fish Market',
                'seller_type' => FishSellerType::HARBOUR_VENDOR,
                'business_name' => 'Suresh Meen Kada',
                'market_name' => 'Vypeen Fish Market',
                'lat' => 9.9833,
                'lng' => 76.2167,
            ],
            [
                'phone' => '919876543252',
                'name' => 'Kochi Sea Foods',
                'seller_type' => FishSellerType::FISH_SHOP,
                'business_name' => 'Kochi Premium Sea Foods',
                'market_name' => 'Ernakulam Market',
                'lat' => 9.9816,
                'lng' => 76.2999,
            ],
            [
                'phone' => '919876543253',
                'name' => 'Munambam Traders',
                'seller_type' => FishSellerType::WHOLESALER,
                'business_name' => 'Munambam Fish Wholesale',
                'market_name' => 'Munambam Fishing Harbour',
                'lat' => 10.1833,
                'lng' => 76.1667,
            ],
            [
                'phone' => '919876543254',
                'name' => 'Azhikkal Fishermen',
                'seller_type' => FishSellerType::FISHERMAN,
                'business_name' => 'Azhikkal Fresh Fish',
                'market_name' => 'Azhikkal Beach',
                'lat' => 9.5916,
                'lng' => 76.3339,
            ],
        ];

        $sellers = [];

        foreach ($sellersData as $data) {
            // Create user
            $user = User::updateOrCreate(
                ['phone' => $data['phone']],
                [
                    'name' => $data['name'],
                    'type' => UserType::FISH_SELLER,
                    'latitude' => $data['lat'],
                    'longitude' => $data['lng'],
                    'language' => 'en',
                    'registered_at' => now()->subDays(rand(10, 60)),
                ]
            );

            // Create fish seller profile
            $seller = FishSeller::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'business_name' => $data['business_name'],
                    'seller_type' => $data['seller_type'],
                    'latitude' => $data['lat'],
                    'longitude' => $data['lng'],
                    'market_name' => $data['market_name'],
                    'address' => $data['market_name'] . ', Kerala',
                    'operating_hours' => [
                        'mon' => ['open' => '05:00', 'close' => '12:00'],
                        'tue' => ['open' => '05:00', 'close' => '12:00'],
                        'wed' => ['open' => '05:00', 'close' => '12:00'],
                        'thu' => ['open' => '05:00', 'close' => '12:00'],
                        'fri' => ['open' => '05:00', 'close' => '12:00'],
                        'sat' => ['open' => '05:00', 'close' => '12:00'],
                        'sun' => ['open' => '06:00', 'close' => '11:00'],
                    ],
                    'catch_days' => [1, 2, 3, 4, 5, 6], // Mon-Sat
                    'total_catches' => rand(10, 100),
                    'total_sales' => rand(5, 50),
                    'average_rating' => rand(35, 50) / 10,
                    'rating_count' => rand(5, 30),
                    'is_verified' => rand(0, 1) === 1,
                    'verified_at' => rand(0, 1) === 1 ? now()->subDays(rand(5, 30)) : null,
                    'is_active' => true,
                    'is_accepting_orders' => rand(0, 1) === 1,
                    'default_alert_radius_km' => $data['seller_type']->defaultNotificationRadius(),
                ]
            );

            $sellers[] = $seller;
        }

        return $sellers;
    }

    /**
     * Create fish catches for sellers.
     */
    protected function createFishCatches(array $sellers): array
    {
        $fishTypes = FishType::active()->get();

        if ($fishTypes->isEmpty()) {
            $this->command->warn('No fish types found. Run FishTypeSeeder first.');
            return [];
        }

        $catches = [];
        $quantityRanges = FishQuantityRange::cases();

        foreach ($sellers as $seller) {
            // Create 2-5 catches per seller
            $numCatches = rand(2, 5);

            for ($i = 0; $i < $numCatches; $i++) {
                $fishType = $fishTypes->random();
                $arrivedAt = now()->subHours(rand(0, 4));

                $catch = FishCatch::create([
                    'fish_seller_id' => $seller->id,
                    'fish_type_id' => $fishType->id,
                    'quantity_range' => $quantityRanges[array_rand($quantityRanges)],
                    'price_per_kg' => $this->generatePrice($fishType),
                    'latitude' => $seller->latitude + (rand(-50, 50) / 10000),
                    'longitude' => $seller->longitude + (rand(-50, 50) / 10000),
                    'location_name' => $seller->market_name,
                    'status' => $this->getRandomStatus(),
                    'arrived_at' => $arrivedAt,
                    'expires_at' => $arrivedAt->copy()->addHours(6),
                    'view_count' => rand(0, 50),
                    'alerts_sent' => rand(5, 30),
                    'coming_count' => rand(0, 10),
                    'message_count' => rand(0, 5),
                    'freshness_tag' => 'morning_catch',
                ]);

                $catches[] = $catch;
            }
        }

        return $catches;
    }

    /**
     * Create customer subscriptions.
     */
    protected function createSubscriptions(): array
    {
        // Get some customer users or create them
        $customers = User::customers()->limit(5)->get();

        if ($customers->isEmpty()) {
            // Create test customers
            for ($i = 0; $i < 5; $i++) {
                $customers[] = User::create([
                    'phone' => '91987654326' . $i,
                    'name' => 'Test Customer ' . ($i + 1),
                    'type' => UserType::CUSTOMER,
                    'latitude' => 9.9 + (rand(-100, 100) / 1000),
                    'longitude' => 76.2 + (rand(-100, 100) / 1000),
                    'language' => 'en',
                    'registered_at' => now()->subDays(rand(1, 30)),
                ]);
            }
        }

        $fishTypes = FishType::active()->popular()->limit(5)->pluck('id')->toArray();
        $subscriptions = [];
        $frequencies = FishAlertFrequency::cases();

        foreach ($customers as $customer) {
            // 70% chance of having a subscription
            if (rand(1, 10) > 3) {
                $subscription = FishSubscription::create([
                    'user_id' => $customer->id,
                    'name' => 'Home',
                    'latitude' => $customer->latitude,
                    'longitude' => $customer->longitude,
                    'location_label' => 'Near Home',
                    'radius_km' => rand(3, 10),
                    'fish_type_ids' => rand(0, 1) ? array_slice($fishTypes, 0, rand(2, 4)) : null,
                    'all_fish_types' => rand(0, 1) === 1,
                    'alert_frequency' => $frequencies[array_rand($frequencies)],
                    'is_active' => true,
                    'is_paused' => false,
                    'alerts_received' => rand(0, 20),
                    'alerts_clicked' => rand(0, 10),
                ]);

                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * Generate realistic price based on fish type.
     */
    protected function generatePrice(FishType $fishType): float
    {
        $min = $fishType->typical_price_min ?? 100;
        $max = $fishType->typical_price_max ?? 500;

        return rand((int) $min, (int) $max);
    }

    /**
     * Get random catch status (weighted towards available).
     */
    protected function getRandomStatus(): FishCatchStatus
    {
        $rand = rand(1, 10);

        return match (true) {
            $rand <= 6 => FishCatchStatus::AVAILABLE,
            $rand <= 8 => FishCatchStatus::LOW_STOCK,
            $rand <= 9 => FishCatchStatus::SOLD_OUT,
            default => FishCatchStatus::EXPIRED,
        };
    }
}
