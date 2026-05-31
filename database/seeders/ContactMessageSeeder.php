<?php

namespace Database\Seeders;

use App\Models\ContactMessage;
use Illuminate\Database\Seeder;

class ContactMessageSeeder extends Seeder
{
    public function run(): void
    {
        $messages = [
            [
                'name'    => 'Ali Al-Malki',
                'email'   => 'ali@example.com',
                'phone'   => '+966509998887',
                'subject' => 'Inquiry about wedding abaya sizing / استفسار عن مقاسات عبايات الأعراس',
                'message' => 'Hello, I want to order a custom embroidered abaya for my sister, but I am not sure which size is best. Do you have a detailed size guide? السلام عليكم، أود الاستفسار عن تفاصيل مقاسات العبايات الملكية لتفصيل طلب خاص لأختي.',
                'status'  => 'new',
                'notes'   => null,
            ],
            [
                'name'    => 'Emily Johnson',
                'email'   => 'emily@example.com',
                'phone'   => '+12025550199',
                'subject' => 'International shipping rates to the United States',
                'message' => 'Hello, I live in New York and I love your linen dress collections! Do you ship to the US? If yes, how much is the delivery cost and what is the typical shipping duration?',
                'status'  => 'read',
                'notes'   => 'Customer viewed the shipping details page. Handled over email.',
            ],
            [
                'name'    => 'Yaser Mansoor',
                'email'   => 'yaser@example.com',
                'phone'   => '+966504445556',
                'subject' => 'Partnership Proposal / عرض شراكة وتسويق بالعمولة',
                'message' => 'We are a leading local digital marketing agency. We want to collaborate with Otantik Queen for influencer marketing and commission-based sales.',
                'status'  => 'replied',
                'notes'   => 'Replied on 2026-05-30. Sent them our affiliate program brochure.',
            ],
            [
                'name'    => 'Anonymous Spammer',
                'email'   => 'spam@badbot.com',
                'phone'   => '+0000000000',
                'subject' => 'CHEAP SEO SERVICES NOW!!! RANK #1 IN GOOGLE GUARANTEED!!! CLICK HERE TO GET 90% DISCOUNT RIGHT AWAY!!!',
                'message' => 'This is a very long subject line spam message designed to test text truncation, layout responsiveness, and overflow styling in the admin mailbox list view.',
                'status'  => 'new',
                'notes'   => null,
            ]
        ];

        foreach ($messages as $msg) {
            ContactMessage::create($msg);
        }
    }
}
