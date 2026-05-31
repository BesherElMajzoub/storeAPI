<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Seeder;

class AddressSeeder extends Seeder
{
    public function run(): void
    {
        $userWithAddress = User::where('email', 'user_with_address@store.com')->first();
        if ($userWithAddress) {
            // 1. Home address (default)
            Address::create([
                'user_id'     => $userWithAddress->id,
                'type'        => 'shipping',
                'name'        => 'Home',
                'line1'       => 'Olaya District, King Fahd Rd, Building 45',
                'line2'       => 'Floor 3, Apt 12',
                'state'       => 'Riyadh Region',
                'label'       => 'home',
                'full_name'   => 'Mohammad Al-Saudi',
                'phone'       => '+966501234567',
                'country'     => 'SA',
                'city'        => 'Riyadh',
                'area'        => 'Olaya',
                'street'      => 'King Fahd Rd',
                'building'    => '45',
                'floor'       => '3',
                'apartment'   => '12',
                'postal_code' => '12211',
                'notes'       => 'Call before delivery.',
                'is_default'  => true,
            ]);

            // 2. Work address
            Address::create([
                'user_id'     => $userWithAddress->id,
                'type'        => 'billing',
                'name'        => 'Work Office',
                'line1'       => 'Digital City, Building MU04',
                'line2'       => 'Level 2, Office 201',
                'state'       => 'Riyadh Region',
                'label'       => 'work',
                'full_name'   => 'Mohammad Al-Saudi (Office)',
                'phone'       => '+966501234567',
                'country'     => 'SA',
                'city'        => 'Riyadh',
                'area'        => 'Nakhil',
                'street'      => 'Digital City St',
                'building'    => 'MU04',
                'floor'       => '2',
                'apartment'   => '201',
                'postal_code' => '12382',
                'notes'       => 'Deliver only during office hours (9 AM - 5 PM).',
                'is_default'  => false,
            ]);
        }

        // Set up default addresses for other test accounts
        $emails = [
            'customer@store.com'            => 'Sarah Smith',
            'user_with_orders@store.com'    => 'Ahmad Mansour',
            'user_cancelled_orders@store.com' => 'Khaled Omar',
            'user_many_orders@store.com'    => 'Fatima Al-Harbi',
            'user_wishlist@store.com'       => 'Yousef Ali',
        ];

        foreach ($emails as $email => $fullName) {
            $user = User::where('email', $email)->first();
            if ($user) {
                Address::create([
                    'user_id'     => $user->id,
                    'type'        => 'shipping',
                    'name'        => 'Default Address',
                    'line1'       => 'King Abdulaziz Rd, Al-Huda District',
                    'line2'       => 'Villa 14',
                    'state'       => 'Makkah Region',
                    'label'       => 'home',
                    'full_name'   => $fullName,
                    'phone'       => '+966507654321',
                    'country'     => 'SA',
                    'city'        => 'Jeddah',
                    'area'        => 'Al-Huda',
                    'street'      => 'King Abdulaziz Rd',
                    'building'    => '14',
                    'floor'       => null,
                    'apartment'   => null,
                    'postal_code' => '23321',
                    'notes'       => null,
                    'is_default'  => true,
                ]);
            }
        }
    }
}
