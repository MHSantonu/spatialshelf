<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoShopSeeder extends Seeder
{
    /**
     * Seed the demo shop and merchant user.
     *
     * No products are created here: the catalog comes from scans.
     */
    public function run(): void
    {
        $email = (string) env('DEMO_EMAIL');
        $password = (string) env('DEMO_PASSWORD');

        if ($email === '' || $password === '') {
            throw new RuntimeException('Set DEMO_EMAIL and DEMO_PASSWORD in app/.env before seeding.');
        }

        Shop::updateOrCreate(
            ['name' => 'Mahadi Store, Tangail'],
            [
                'depth_defaults' => [
                    'snacks' => 3,
                    'biscuits' => 3,
                    'oil' => 4,
                    'drinks' => 4,
                    'spices' => 6,
                    'rice_dal' => 2,
                    'soap_detergent' => 6,
                    'personal_care' => 4,
                    'other' => 3,
                ],
            ],
        );

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Merchant',
                // The 'hashed' cast on User::class hashes this on save.
                'password' => $password,
            ],
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }
}
