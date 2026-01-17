<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Shop;
use App\Models\Offer;
use App\Models\ProductRequest;
use App\Models\ProductResponse;
use App\Models\Agreement;
use App\Models\ConversationSession;
use App\Enums\UserType;
use App\Enums\ShopCategory;
use App\Enums\NotificationFrequency;
use App\Enums\OfferValidity;
use App\Enums\RequestStatus;
use App\Enums\AgreementStatus;
use App\Enums\AgreementPurpose;
use App\Enums\AgreementDirection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Clear existing data (in correct order for foreign keys)
        $this->clearData();

        // Create test users
        $this->createUsers();

        // Create shops
        $this->createShops();

        // Create offers
        $this->createOffers();

        // Create product requests and responses
        $this->createProductRequests();

        // Create agreements
        $this->createAgreements();

        // Create conversation sessions
        $this->createConversationSessions();

        $this->command->info('Database seeding completed!');
    }

    /**
     * Clear existing data.
     */
    private function clearData(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        // Delete in reverse order of dependencies
        ConversationSession::query()->delete();
        ProductResponse::query()->delete();
        ProductRequest::query()->delete();
        Offer::query()->delete();
        Agreement::query()->delete();
        Shop::query()->delete();
        User::query()->delete();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Create test users.
     */
    private function createUsers(): void
    {
        // Sample locations in Kerala, India (Kottayam area)
        $locations = [
            ['lat' => 9.5916, 'lng' => 76.5222, 'address' => 'Kottayam Town'],
            ['lat' => 9.5833, 'lng' => 76.5167, 'address' => 'Nagampadam, Kottayam'],
            ['lat' => 9.6000, 'lng' => 76.5300, 'address' => 'Kumaranalloor, Kottayam'],
            ['lat' => 9.5750, 'lng' => 76.5100, 'address' => 'Changanassery'],
            ['lat' => 9.6100, 'lng' => 76.5400, 'address' => 'Pala'],
        ];

        // Create customers
        $customers = [
            ['phone' => '919876543210', 'name' => 'Rahul Kumar'],
            ['phone' => '919876543211', 'name' => 'Priya Menon'],
            ['phone' => '919876543212', 'name' => 'Arun Nair'],
            ['phone' => '919876543213', 'name' => 'Lakshmi Devi'],
            ['phone' => '919876543214', 'name' => 'Mohammed Ali'],
        ];

        foreach ($customers as $index => $customer) {
            $location = $locations[$index % count($locations)];

            User::create([
                'phone' => $customer['phone'],
                'name' => $customer['name'],
                'type' => UserType::CUSTOMER,
                'latitude' => $location['lat'],
                'longitude' => $location['lng'],
                'address' => $location['address'],
                'language' => 'en',
                'registered_at' => now()->subDays(rand(1, 30)),
            ]);
        }

        // Create shop owners
        $shopOwners = [
            ['phone' => '919876543220', 'name' => 'Suresh Grocery'],
            ['phone' => '919876543221', 'name' => 'Tech World'],
            ['phone' => '919876543222', 'name' => 'Fashion Hub'],
            ['phone' => '919876543223', 'name' => 'MedPlus Pharmacy'],
            ['phone' => '919876543224', 'name' => 'Home Hardware'],
            ['phone' => '919876543225', 'name' => 'Tasty Bites'],
            ['phone' => '919876543226', 'name' => 'Golden Bakery'],
            ['phone' => '919876543227', 'name' => 'Book Palace'],
        ];

        foreach ($shopOwners as $index => $owner) {
            $location = $locations[$index % count($locations)];

            User::create([
                'phone' => $owner['phone'],
                'name' => $owner['name'],
                'type' => UserType::SHOP,
                'latitude' => $location['lat'] + (rand(-100, 100) / 10000),
                'longitude' => $location['lng'] + (rand(-100, 100) / 10000),
                'address' => $location['address'],
                'language' => 'en',
                'registered_at' => now()->subDays(rand(30, 60)),
            ]);
        }

        $this->command->info('Created ' . User::count() . ' users');
    }

    /**
     * Create shops for shop owners.
     */
    private function createShops(): void
    {
        $shopOwners = User::where('type', UserType::SHOP)->get();

        $shopDetails = [
            ['name' => 'Suresh Supermarket', 'category' => ShopCategory::GROCERY],
            ['name' => 'Tech World Electronics', 'category' => ShopCategory::ELECTRONICS],
            ['name' => 'Fashion Hub Boutique', 'category' => ShopCategory::CLOTHES],
            ['name' => 'MedPlus Pharmacy', 'category' => ShopCategory::MEDICAL],
            ['name' => 'Home Hardware Store', 'category' => ShopCategory::HARDWARE],
            ['name' => 'Tasty Bites Restaurant', 'category' => ShopCategory::RESTAURANT],
            ['name' => 'Golden Bakery', 'category' => ShopCategory::BAKERY],
            ['name' => 'Book Palace', 'category' => ShopCategory::STATIONERY],
        ];

        $frequencies = NotificationFrequency::cases();

        foreach ($shopOwners as $index => $owner) {
            $details = $shopDetails[$index % count($shopDetails)];

            Shop::create([
                'user_id' => $owner->id,
                'shop_name' => $details['name'],
                'category' => $details['category'],
                'latitude' => $owner->latitude,
                'longitude' => $owner->longitude,
                'address' => $owner->address,
                'notification_frequency' => $frequencies[array_rand($frequencies)],
                'verified' => rand(0, 1) === 1,
                'is_active' => true,
            ]);
        }

        $this->command->info('Created ' . Shop::count() . ' shops');
    }

    /**
     * Create offers for shops.
     */
    private function createOffers(): void
    {
        $shops = Shop::all();
        $validities = OfferValidity::cases();

        $captions = [
            'Special weekend offer! 20% off on all items.',
            'Fresh arrivals - Limited stock!',
            'Buy 2 Get 1 Free - Today only!',
            'Clearance sale - Up to 50% off',
            'New collection just arrived!',
            'Festival special discounts',
        ];

        foreach ($shops as $shop) {
            $numOffers = rand(1, 3);

            for ($i = 0; $i < $numOffers; $i++) {
                $validity = $validities[array_rand($validities)];

                Offer::create([
                    'shop_id' => $shop->id,
                    'media_url' => 'https://example.com/offers/offer-' . rand(1000, 9999) . '.jpg',
                    'media_type' => rand(0, 1) ? 'image' : 'pdf',
                    'caption' => $captions[array_rand($captions)],
                    'validity_type' => $validity,
                    'expires_at' => $validity->expiresAt(),
                    'view_count' => rand(0, 100),
                    'location_tap_count' => rand(0, 30),
                    'is_active' => rand(0, 5) > 0, // 83% active
                ]);
            }
        }

        $this->command->info('Created ' . Offer::count() . ' offers');
    }

    /**
     * Create product requests and responses.
     */
    private function createProductRequests(): void
    {
        $customers = User::where('type', UserType::CUSTOMER)->get();
        $shops = Shop::all();
        $categories = ShopCategory::cases();

        $productDescriptions = [
            'Looking for Samsung Galaxy S24 Ultra 256GB',
            'Need fresh organic vegetables for home delivery',
            'Searching for wooden dining table set 6 seater',
            'Looking for branded formal shirts size L',
            'Need Paracetamol 500mg tablets',
            'Searching for power drill machine',
            'Need birthday cake for 20 people - chocolate flavor',
            'Looking for NCERT textbooks class 10',
        ];

        foreach ($customers as $customer) {
            $numRequests = rand(1, 3);

            for ($i = 0; $i < $numRequests; $i++) {
                $request = ProductRequest::create([
                    'user_id' => $customer->id,
                    'request_number' => ProductRequest::generateRequestNumber(),
                    'category' => rand(0, 1) ? $categories[array_rand($categories)] : null,
                    'description' => $productDescriptions[array_rand($productDescriptions)],
                    'latitude' => $customer->latitude,
                    'longitude' => $customer->longitude,
                    'radius_km' => rand(2, 10),
                    'status' => RequestStatus::cases()[array_rand(RequestStatus::cases())],
                    'expires_at' => now()->addHours(rand(-24, 48)),
                    'shops_notified' => rand(3, 15),
                ]);

                // Create some responses
                $respondingShops = $shops->random(rand(1, 4));
                foreach ($respondingShops as $shop) {
                    ProductResponse::create([
                        'request_id' => $request->id,
                        'shop_id' => $shop->id,
                        'photo_url' => rand(0, 1) ? 'https://example.com/products/product-' . rand(1000, 9999) . '.jpg' : null,
                        'price' => rand(100, 10000) + (rand(0, 99) / 100),
                        'description' => 'Available in stock. ' . ['Good condition', 'Brand new', 'Limited pieces'][rand(0, 2)],
                        'is_available' => rand(0, 4) > 0,
                        'responded_at' => now()->subHours(rand(1, 24)),
                    ]);
                }

                // Update response count
                $request->update(['response_count' => $request->responses()->count()]);
            }
        }

        $this->command->info('Created ' . ProductRequest::count() . ' product requests');
        $this->command->info('Created ' . ProductResponse::count() . ' product responses');
    }

    /**
     * Create agreements.
     */
    private function createAgreements(): void
    {
        $users = User::all();
        $purposes = AgreementPurpose::cases();
        $directions = AgreementDirection::cases();
        $statuses = AgreementStatus::cases();

        for ($i = 0; $i < 10; $i++) {
            $fromUser = $users->random();
            $toUser = $users->where('id', '!=', $fromUser->id)->random();

            $amount = rand(1000, 50000);
            $status = $statuses[array_rand($statuses)];

            Agreement::create([
                'agreement_number' => Agreement::generateAgreementNumber(),
                'from_user_id' => $fromUser->id,
                'from_name' => $fromUser->name,
                'from_phone' => $fromUser->phone,
                'to_user_id' => $toUser->id,
                'to_name' => $toUser->name,
                'to_phone' => $toUser->phone,
                'direction' => $directions[array_rand($directions)],
                'amount' => $amount,
                'amount_in_words' => Agreement::amountToWords($amount),
                'purpose_type' => $purposes[array_rand($purposes)],
                'description' => 'Agreement for ' . ['personal expenses', 'business transaction', 'house rent deposit', 'work advance'][rand(0, 3)],
                'due_date' => rand(0, 1) ? now()->addDays(rand(7, 90)) : null,
                'status' => $status,
                'from_confirmed_at' => now()->subDays(rand(1, 30)),
                'to_confirmed_at' => in_array($status, [AgreementStatus::CONFIRMED, AgreementStatus::COMPLETED])
                    ? now()->subDays(rand(1, 25))
                    : null,
                'pdf_url' => in_array($status, [AgreementStatus::CONFIRMED, AgreementStatus::COMPLETED])
                    ? 'https://example.com/agreements/agreement-' . rand(1000, 9999) . '.pdf'
                    : null,
                'completed_at' => $status === AgreementStatus::COMPLETED ? now()->subDays(rand(1, 10)) : null,
            ]);
        }

        $this->command->info('Created ' . Agreement::count() . ' agreements');
    }

    /**
     * Create conversation sessions.
     */
    private function createConversationSessions(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            ConversationSession::create([
                'phone' => $user->phone,
                'user_id' => $user->id,
                'current_flow' => 'main_menu',
                'current_step' => 'idle',
                'temp_data' => null,
                'last_activity_at' => now()->subMinutes(rand(1, 120)),
            ]);
        }

        // Create some sessions for unregistered phones
        for ($i = 0; $i < 3; $i++) {
            ConversationSession::create([
                'phone' => '91987654' . str_pad($i + 100, 4, '0', STR_PAD_LEFT),
                'user_id' => null,
                'current_flow' => 'registration',
                'current_step' => ['reg_ask_name', 'reg_ask_role', 'reg_ask_location'][rand(0, 2)],
                'temp_data' => ['partial' => true],
                'last_activity_at' => now()->subMinutes(rand(5, 60)),
            ]);
        }

        $this->command->info('Created ' . ConversationSession::count() . ' conversation sessions');
    }
}