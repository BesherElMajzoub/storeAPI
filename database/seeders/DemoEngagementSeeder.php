<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\InspiredLead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Support\Facades\DB;

class DemoEngagementSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->guardAgainstProduction();

        $this->seedReviews();
        $this->seedWishlists();
        $this->seedInboxAndLeads();
        $this->seedAuditLogsAndNotifications();

        $this->command?->info('Demo engagement: reviews, wishlist history, inbox states, leads, notifications, and audit logs.');
    }

    private function seedReviews(): void
    {
        $customers = User::whereHas('roles', fn ($query) => $query->where('name', 'User'))
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
        $products = Product::published()->orderBy('id')->get();
        $comments = [
            'Excellent quality and careful packaging. The product matched the photos and the selected variant was correct.',
            'Beautiful item, but the color is slightly warmer than it appeared on my screen.',
            'Fast delivery and a good fit. I would buy this item again.',
            'The material feels premium. وصف المنتج واضح والتغليف ممتاز.',
            'This intentionally long review checks wrapping in narrow cards and admin moderation tables. It includes enough detail to span several lines on mobile screens without breaking the action buttons or star rating layout.',
            null,
        ];

        foreach ($customers as $userIndex => $user) {
            for ($position = 0; $position < 5; $position++) {
                $product = $products[(($userIndex * 7) + $position) % $products->count()];
                $status = ($userIndex + $position) % 11 === 0
                    ? 'rejected'
                    : ((($userIndex + $position) % 7 === 0) ? 'pending' : 'approved');
                $matchingOrder = Order::where('user_id', $user->id)
                    ->where('status', 'delivered')
                    ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
                    ->first();
                $createdAt = now()->subDays(($userIndex * 2 + $position) % 45);

                $review = Review::withoutEvents(fn () => Review::updateOrCreate(
                    ['user_id' => $user->id, 'product_id' => $product->id],
                    [
                        'order_id' => $matchingOrder?->id,
                        'rating' => (($userIndex + $position) % 5) + 1,
                        'comment' => $comments[($userIndex + $position) % count($comments)],
                        'status' => $status,
                        'is_verified_purchase' => $matchingOrder !== null,
                        'admin_note' => $status === 'rejected' ? 'Rejected demo review: moderation policy example.' : null,
                        'ip_address' => '198.51.100.'.(($userIndex * 5 + $position) % 200 + 1),
                    ]
                ));
                DB::table('reviews')->where('id', $review->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
            }
        }

        Product::query()->each(function (Product $product) {
            $approved = $product->reviews()->approved();
            $product->updateQuietly([
                'reviews_count' => (clone $approved)->count(),
                'rating' => round((float) ((clone $approved)->avg('rating') ?? 0), 2),
            ]);
        });
    }

    private function seedWishlists(): void
    {
        $customers = User::whereHas('roles', fn ($query) => $query->where('name', 'User'))
            ->where('email', '!=', 'customer.empty@demo.test')
            ->orderBy('id')
            ->get();
        $products = Product::published()->orderBy('id')->get();

        foreach ($customers as $userIndex => $user) {
            $itemCount = $user->email === 'customer.wishlist@demo.test' ? 24 : (3 + ($userIndex % 8));
            for ($position = 0; $position < $itemCount; $position++) {
                $product = $products[(($userIndex * 11) + $position) % $products->count()];
                $item = WishlistItem::firstOrCreate(['user_id' => $user->id, 'product_id' => $product->id]);
                $createdAt = now()->subDays(($position + $userIndex) % 28)->subMinutes($position);
                DB::table('wishlist_items')->where('id', $item->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

                DB::table('wishlist_events')->updateOrInsert(
                    [
                        'user_id' => $user->id,
                        'product_id' => $product->id,
                        'action' => 'added',
                        'created_at' => $createdAt,
                    ],
                    []
                );
                if ($position % 5 === 0) {
                    DB::table('wishlist_events')->updateOrInsert(
                        [
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                            'action' => 'removed',
                            'created_at' => $createdAt->copy()->addHours(2),
                        ],
                        []
                    );
                }
            }
        }
    }

    private function seedInboxAndLeads(): void
    {
        $messageStatuses = ['new', 'read', 'replied', 'archived'];
        for ($index = 1; $index <= 32; $index++) {
            ContactMessage::updateOrCreate(
                ['email' => sprintf('message%02d@demo.test', $index), 'subject' => sprintf('[Demo] Customer message %02d', $index)],
                [
                    'name' => $index % 6 === 0 ? 'اسم عميل عربي طويل لاختبار العرض' : sprintf('Demo Sender %02d', $index),
                    'phone' => $index % 5 === 0 ? null : sprintf('+12025557%03d', $index),
                    'message' => $index % 7 === 0
                        ? str_repeat('This is a long customer support message intended to test line wrapping, scrolling, detail panes, and reply controls. ', 8)
                        : 'A deterministic customer support message covering order, sizing, returns, shipping, or product questions.',
                    'status' => $messageStatuses[($index - 1) % count($messageStatuses)],
                    'notes' => $index % 4 === 0 ? 'Internal note: follow up during business hours.' : null,
                ]
            );
        }

        $leadStatuses = ['new', 'contacted', 'converted', 'closed'];
        $sources = ['stay_inspired', 'footer', 'campaign', 'checkout'];
        for ($index = 1; $index <= 28; $index++) {
            InspiredLead::updateOrCreate(
                ['phone' => sprintf('+12025558%03d', $index)],
                [
                    'name' => $index % 5 === 0 ? null : sprintf('Demo Lead %02d', $index),
                    'source' => $sources[($index - 1) % count($sources)],
                    'status' => $leadStatuses[($index - 1) % count($leadStatuses)],
                    'notes' => $index % 6 === 0 ? 'Requested contact in Arabic / يفضل التواصل باللغة العربية.' : null,
                ]
            );
        }
    }

    private function seedAuditLogsAndNotifications(): void
    {
        $admin = User::where('email', 'admin@demo.test')->firstOrFail();
        $actions = ['create_product', 'update_product', 'bulk_update_products', 'moderate_review', 'update_order_status', 'purchase_label', 'refund_order', 'reply_contact_message'];

        for ($index = 1; $index <= 32; $index++) {
            $action = $actions[($index - 1) % count($actions)];
            $description = sprintf('[Demo %02d] %s action for audit table pagination.', $index, str($action)->replace('_', ' '));
            $log = AuditLog::firstOrCreate(
                ['action' => $action, 'description' => $description],
                [
                    'causer_id' => $admin->id,
                    'causer_type' => User::class,
                    'ip_address' => '203.0.113.'.(($index % 200) + 1),
                    'changes' => ['before' => ['status' => 'old'], 'after' => ['status' => 'new'], 'demo' => true],
                ]
            );
            DB::table('audit_logs')->where('id', $log->id)->update([
                'created_at' => now()->subHours($index * 3),
                'updated_at' => now()->subHours($index * 3),
            ]);
        }

        $recipients = User::where('email', 'like', '%@demo.test')->orderBy('id')->take(15)->get();
        foreach ($recipients as $index => $recipient) {
            foreach (['order_status', 'promotion'] as $typeIndex => $type) {
                $id = sprintf('20000000-0000-4000-8000-%012d', (($index + 1) * 10) + $typeIndex);
                DB::table('notifications')->updateOrInsert(
                    ['id' => $id],
                    [
                        'type' => 'App\\Notifications\\Demo'.str($type)->studly().'Notification',
                        'notifiable_type' => User::class,
                        'notifiable_id' => $recipient->id,
                        'data' => json_encode([
                            'title' => $type === 'order_status' ? 'Demo order updated' : 'Demo promotion available',
                            'message' => 'A seeded notification used to verify unread badges and notification lists.',
                            'url' => $type === 'order_status' ? '/orders' : '/products',
                        ]),
                        'read_at' => ($index + $typeIndex) % 3 === 0 ? now()->subHour() : null,
                        'created_at' => now()->subHours(($index * 2) + $typeIndex),
                        'updated_at' => now()->subHours(($index * 2) + $typeIndex),
                    ]
                );
            }
        }
    }
}
