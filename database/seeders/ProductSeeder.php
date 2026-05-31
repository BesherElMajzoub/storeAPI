<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $abayaCategory = Category::where('slug', 'abayas')->first();
        $dressCategory = Category::where('slug', 'dresses')->first();
        $clothingCategory = Category::where('slug', 'clothing')->first();
        $accessoriesCategory = Category::where('slug', 'accessories')->first();
        $bagsCategory = Category::where('slug', 'bags')->first();
        $shoesCategory = Category::where('slug', 'shoes')->first();
        $perfumesCategory = Category::where('slug', 'perfumes')->first();
        $electronicsCategory = Category::where('slug', 'electronics')->first();
        $inactiveCategory = Category::where('slug', 'inactive-category')->first();

        // 45 Products to cover all cases
        $products = [
            // --- ABAYAS (Clothing Subcategory) ---
            [
                'name' => 'Classic Black Abaya / عباءة كلاسيكية سوداء',
                'slug' => 'classic-black-abaya',
                'description' => 'An elegant, lightweight classic black abaya made from high-quality crepe fabric. Perfect for everyday wear or semi-formal gatherings. عباءة سوداء كلاسيكية أنيقة وخفيفة الوزن مصنوعة من قماش الكريب الفاخر.',
                'price' => 120.00,
                'discount_price' => 99.00,
                'sku' => 'AB-CLS-BLK',
                'stock_qty' => 150,
                'status' => 'published',
                'category_id' => $abayaCategory?->id,
                'is_featured' => true,
                'options' => ['Color' => ['Black', 'Dark Gray'], 'Size' => ['S', 'M', 'L', 'XL']],
            ],
            [
                'name' => 'Royal Embroidered Abaya / عباءة ملكية مطرزة',
                'slug' => 'royal-embroidered-abaya',
                'description' => 'Exquisite abaya featuring handcrafted silver embroidery on the sleeves and hemline. Made from premium Nidha fabric.',
                'price' => 250.00,
                'discount_price' => null,
                'sku' => 'AB-RYL-EMB',
                'stock_qty' => 30,
                'status' => 'published',
                'category_id' => $abayaCategory?->id,
                'is_featured' => true,
                'options' => ['Color' => ['Navy Blue', 'Emerald Green'], 'Size' => ['M', 'L', 'XL']],
            ],
            [
                'name' => 'Modern Casual Linen Abaya / عباءة كتان كاجوال',
                'slug' => 'modern-casual-linen-abaya',
                'description' => 'Minimalist linen abaya with a modern loose fit, ideal for summer and everyday look.',
                'price' => 85.00,
                'discount_price' => 75.00,
                'sku' => 'AB-MOD-LIN',
                'stock_qty' => 2, // Low stock case
                'status' => 'published',
                'category_id' => $abayaCategory?->id,
                'is_featured' => false,
                'options' => ['Color' => ['Beige', 'Sage Green'], 'Size' => ['S', 'M', 'L']],
            ],
            [
                'name' => 'Luxury Silk Abaya / عباءة حريرية فاخرة',
                'slug' => 'luxury-silk-abaya',
                'description' => 'A flowing luxury abaya made from 100% natural silk, featuring subtle sheen and premium drape.',
                'price' => 380.00,
                'discount_price' => null,
                'sku' => 'AB-LUX-SLK',
                'stock_qty' => 12,
                'status' => 'published',
                'category_id' => $abayaCategory?->id,
                'is_featured' => false,
                'options' => null, // Simple product without variants
            ],
            [
                'name' => 'Out of Stock Abaya / عباءة غير متوفرة',
                'slug' => 'out-of-stock-abaya',
                'description' => 'This is a demo product designed to test the out-of-stock badge on the frontend catalog grid and product detail screens.',
                'price' => 110.00,
                'discount_price' => null,
                'sku' => 'AB-OOS-DEMO',
                'stock_qty' => 0, // Out of stock case
                'status' => 'published',
                'category_id' => $abayaCategory?->id,
                'is_featured' => false,
                'options' => null,
            ],

            // --- DRESSES (Clothing Subcategory) ---
            [
                'name' => 'Elegant Evening Gown / فستان سهرة أنيق',
                'slug' => 'elegant-evening-gown',
                'description' => 'Stunning satin evening dress with a pleated bodice, off-shoulder sleeves, and a graceful flowy skirt. فستان سهرة من الستان الفاخر مناسب للمناسبات الرسمية.',
                'price' => 350.00,
                'discount_price' => 280.00,
                'sku' => 'DR-EVE-SAT',
                'stock_qty' => 25,
                'status' => 'published',
                'category_id' => $dressCategory?->id,
                'is_featured' => true,
                'options' => ['Color' => ['Burgundy', 'Royal Blue', 'Champagne'], 'Size' => ['S', 'M', 'L']],
            ],
            [
                'name' => 'Floral Summer Midi Dress / فستان صيفي مشجر',
                'slug' => 'floral-summer-midi-dress',
                'description' => 'Light and breezy casual floral midi dress with a smocked back and adjustable straps, perfect for warm days.',
                'price' => 65.00,
                'discount_price' => null,
                'sku' => 'DR-SUM-FLR',
                'stock_qty' => 80,
                'status' => 'published',
                'category_id' => $dressCategory?->id,
                'is_featured' => false,
                'options' => ['Color' => ['Yellow Floral', 'Pink Floral'], 'Size' => ['XS', 'S', 'M', 'L']],
            ],
            [
                'name' => 'Classic Red Velvet Dress / فستان مخملي أحمر',
                'slug' => 'classic-red-velvet-dress',
                'description' => 'Sophisticated wrap dress crafted from premium red velvet, featuring a flattering waist tie and long sleeves.',
                'price' => 190.00,
                'discount_price' => 140.00,
                'sku' => 'DR-VEL-RED',
                'stock_qty' => 15,
                'status' => 'published',
                'category_id' => $dressCategory?->id,
                'is_featured' => false,
                'options' => null,
            ],
            [
                'name' => 'Emerald Pleated Dress / فستان زمردي مكسر',
                'slug' => 'emerald-pleated-dress',
                'description' => 'A chic emerald green pleated dress with metallic accents and matching belt.',
                'price' => 110.00,
                'discount_price' => null,
                'sku' => 'DR-PLE-EMR',
                'stock_qty' => 0, // Out of stock case
                'status' => 'published',
                'category_id' => $dressCategory?->id,
                'is_featured' => false,
                'options' => null,
            ],

            // --- GENERAL CLOTHING ---
            [
                'name' => 'Premium Cotton Scarf / وشاح قطني فاخر',
                'slug' => 'premium-cotton-scarf',
                'description' => 'Soft, comfortable and warm organic cotton scarf. Hand-loomed with beautiful frayed fringes.',
                'price' => 25.00,
                'discount_price' => 18.00,
                'sku' => 'CL-SCF-PRM',
                'stock_qty' => 300,
                'status' => 'published',
                'category_id' => $clothingCategory?->id,
                'is_featured' => false,
                'options' => ['Color' => ['Pink', 'Beige', 'Navy'], 'Size' => ['One Size']],
            ],
            [
                'name' => 'Oversized Denim Jacket / جاكيت جينز فضفاض',
                'slug' => 'oversized-denim-jacket',
                'description' => 'Classic vintage-washed light blue denim jacket with double chest pockets and distressed details.',
                'price' => 79.00,
                'discount_price' => null,
                'sku' => 'CL-JKT-DNM',
                'stock_qty' => 45,
                'status' => 'published',
                'category_id' => $clothingCategory?->id,
                'is_featured' => false,
                'options' => ['Size' => ['S', 'M', 'L']],
            ],
            [
                'name' => 'Draft Product / منتج تجريبي مسودة',
                'slug' => 'draft-product-demo',
                'description' => 'A product in draft state. It is saved in the database but should not appear on the client-facing store catalog.',
                'price' => 100.00,
                'discount_price' => null,
                'sku' => 'CL-DRFT-DEMO',
                'stock_qty' => 1,
                'status' => 'draft', // Draft state
                'category_id' => $clothingCategory?->id,
                'is_featured' => false,
                'options' => null,
            ],

            // --- ACCESSORIES ---
            [
                'name' => '18k Gold Plated Necklace / قلادة مطلية بالذهب',
                'slug' => '18k-gold-plated-necklace',
                'description' => 'Dainty necklace plated in 18k yellow gold featuring a minimalist coin pendant. Hypoallergenic and water-resistant. قلادة أنيقة مطلية بذهب عيار 18.',
                'price' => 45.00,
                'discount_price' => 35.00,
                'sku' => 'AC-NKL-GLD',
                'stock_qty' => 120,
                'status' => 'published',
                'category_id' => $accessoriesCategory?->id,
                'is_featured' => true,
                'options' => ['Length' => ['16in', '18in', '20in']],
            ],
            [
                'name' => 'Sterling Silver Solitaire Ring / خاتم فضة سوليتير',
                'slug' => 'sterling-silver-solitaire-ring',
                'description' => 'Classic solitaire engagement ring made from genuine 925 sterling silver, featuring a brilliant cut cubic zirconia.',
                'price' => 60.00,
                'discount_price' => null,
                'sku' => 'AC-RNG-SLV',
                'stock_qty' => 50,
                'status' => 'published',
                'category_id' => $accessoriesCategory?->id,
                'is_featured' => false,
                'options' => ['Size' => ['6', '7', '8']],
            ],
            [
                'name' => 'Elegant Pearl Earrings / أقراط اللؤلؤ الأنيقة',
                'slug' => 'elegant-pearl-earrings',
                'description' => 'Pair of stud earrings featuring natural freshwater white pearls set on sterling silver posts.',
                'price' => 30.00,
                'discount_price' => 25.00,
                'sku' => 'AC-EAR-PRL',
                'stock_qty' => 4, // Low stock case
                'status' => 'published',
                'category_id' => $accessoriesCategory?->id,
                'is_featured' => false,
                'options' => null,
            ],

            // --- BAGS ---
            [
                'name' => 'Italian Leather Tote / حقيبة كتف إيطالية جلدية',
                'slug' => 'italian-leather-tote',
                'description' => 'Spacious everyday tote handcrafted in Italy from genuine pebble-grain leather, featuring a zipped interior compartment and gold hardware. حقيبة يد كبيرة مصنوعة من الجلد الإيطالي الفاخر.',
                'price' => 290.00,
                'discount_price' => null,
                'sku' => 'BG-TOT-ITL',
                'stock_qty' => 15,
                'status' => 'published',
                'category_id' => $bagsCategory?->id,
                'is_featured' => true,
                'options' => ['Color' => ['Cognac', 'Classic Black', 'Olive Green']],
            ],
            [
                'name' => 'Minimalist Crossbody Bag / حقيبة كروس ميني',
                'slug' => 'minimalist-crossbody-bag',
                'description' => 'Compact crossbody bag with a structured silhouette, adjustable strap, and secure magnetic clasp. Perfect for your mobile, cards, and keys.',
                'price' => 95.00,
                'discount_price' => 75.00,
                'sku' => 'BG-CRB-MIN',
                'stock_qty' => 80,
                'status' => 'published',
                'category_id' => $bagsCategory?->id,
                'is_featured' => false,
                'options' => ['Color' => ['Tan', 'Dusty Rose', 'Taupe']],
            ],
            [
                'name' => 'Velvet Evening Clutch / حقيبة سهرة مخملية',
                'slug' => 'velvet-evening-clutch',
                'description' => 'Elegant dark green velvet clutch bag with a detachable gold chain strap, ideal for dinner parties and weddings.',
                'price' => 50.00,
                'discount_price' => null,
                'sku' => 'BG-CLU-VEL',
                'stock_qty' => 20,
                'status' => 'published',
                'category_id' => $bagsCategory?->id,
                'is_featured' => false,
                'options' => null,
            ],

            // --- SHOES ---
            [
                'name' => 'Premium Suede Loafers / حذاء لوفر جلود فاخر',
                'slug' => 'premium-suede-loafers',
                'description' => 'Slip-on loafers in soft calfskin suede. Finished with hand-stitched detailing and memory foam leather insoles. حذاء جلد شمواه ناعم ومريح.',
                'price' => 110.00,
                'discount_price' => 89.00,
                'sku' => 'SH-LOF-SDE',
                'stock_qty' => 35,
                'status' => 'published',
                'category_id' => $shoesCategory?->id,
                'is_featured' => false,
                'options' => ['Color' => ['Beige', 'Navy Blue'], 'Size' => ['38', '39', '40', '41']],
            ],
            [
                'name' => 'Leather Strappy Sandals / صندل جلدي كلاسيك',
                'slug' => 'leather-strappy-sandals',
                'description' => 'Stylish summer sandals with leather crossover straps, buckle closure at the ankle, and a durable rubber outsole.',
                'price' => 45.00,
                'discount_price' => null,
                'sku' => 'SH-SND-LTR',
                'stock_qty' => 95,
                'status' => 'published',
                'category_id' => $shoesCategory?->id,
                'is_featured' => false,
                'options' => ['Color' => ['Tan', 'Black'], 'Size' => ['37', '38', '39', '40']],
            ],

            // --- PERFUMES ---
            [
                'name' => 'Royal Oud Perfume / عطر العود الملكي فاخر',
                'slug' => 'royal-oud-perfume',
                'description' => 'A deeply mysterious fragrance opening with spicy amber, rich incense, blended with dark woody notes and authentic Cambodian oud. عطر عود شرقي ملكي يدوم طويلاً.',
                'price' => 180.00,
                'discount_price' => 150.00,
                'sku' => 'PF-OUD-RYL',
                'stock_qty' => 100,
                'status' => 'published',
                'category_id' => $perfumesCategory?->id,
                'is_featured' => true,
                'options' => ['Size' => ['50ml', '100ml']],
            ],
            [
                'name' => 'Pure Musk & Rose EDP / عطر المسك والورد',
                'slug' => 'pure-musk-rose-edp',
                'description' => 'A clean, soft, romantic fragrance highlighting white musk blended with delicate Damask rose water.',
                'price' => 90.00,
                'discount_price' => null,
                'sku' => 'PF-MSK-RSE',
                'stock_qty' => 40,
                'status' => 'published',
                'category_id' => $perfumesCategory?->id,
                'is_featured' => false,
                'options' => null,
            ],

            // --- ELECTRONICS ---
            [
                'name' => 'MacBook Pro M3 Max / لابتوب ماك بوك برو',
                'slug' => 'macbook-pro-m3-max',
                'description' => 'The ultimate notebook for professionals. Equipped with Apple M3 Max chip, 36GB unified memory, and 1TB SSD. لابتوب ابل ماك بوك برو للمحترفين.',
                'price' => 3199.00,
                'discount_price' => 2999.00,
                'sku' => 'EL-MBP-M3MX',
                'stock_qty' => 10,
                'status' => 'published',
                'category_id' => $electronicsCategory?->id,
                'is_featured' => true,
                'options' => ['Color' => ['Space Black', 'Silver']],
            ],
            [
                'name' => 'iPhone 15 Pro Max / جوال ايفون 15 برو ماكس',
                'slug' => 'iphone-15-pro-max',
                'description' => 'Featuring titanium design, A17 Pro chip, custom Action button, and powerful 5x optical zoom camera system.',
                'price' => 1199.00,
                'discount_price' => null,
                'sku' => 'EL-IPH-15PM',
                'stock_qty' => 25,
                'status' => 'published',
                'category_id' => $electronicsCategory?->id,
                'is_featured' => true,
                'options' => ['Color' => ['Natural Titanium', 'Black Titanium'], 'Storage' => ['256GB', '512GB']],
            ],

            // --- INACTIVE CATEGORY ---
            [
                'name' => 'Hidden Inactive Product / منتج مخفي فئة غير نشطة',
                'slug' => 'hidden-inactive-product',
                'description' => 'This product is in an inactive category, which is used to test listing behaviors where products in disabled categories must not show up.',
                'price' => 15.00,
                'discount_price' => null,
                'sku' => 'CL-HID-INAC',
                'stock_qty' => 10,
                'status' => 'published',
                'category_id' => $inactiveCategory?->id,
                'is_featured' => false,
                'options' => null,
            ],
        ];

        // Seed 20 additional simple products to reach a high count (40+)
        for ($i = 1; $i <= 20; $i++) {
            $products[] = [
                'name' => "Extra Demo Product {$i} / منتج تجريبي إضافي {$i}",
                'slug' => "extra-demo-product-{$i}",
                'description' => "This is extra demo product number {$i} designed to populate the catalog with a large volume of products, helping the frontend verify pagination, sorting, filters, and lazy loading behaviors.",
                'price' => round(15.00 + ($i * 3.5), 2),
                'discount_price' => $i % 4 === 0 ? round(10.00 + ($i * 2.5), 2) : null,
                'sku' => 'ELEC-DEMO-EX' . $i,
                'stock_qty' => $i % 5 === 0 ? 0 : 50 + ($i * 2), // Some OOS
                'status' => 'published',
                'category_id' => $clothingCategory?->id,
                'is_featured' => false,
                'options' => null,
            ];
        }

        foreach ($products as $prod) {
            $product = Product::updateOrCreate(
                ['slug' => $prod['slug']],
                [
                    'name'             => $prod['name'],
                    'description'      => $prod['description'],
                    'price'            => $prod['price'],
                    'discount_price'   => $prod['discount_price'],
                    'sku'              => $prod['sku'],
                    'stock_qty'        => $prod['stock_qty'],
                    'status'           => $prod['status'],
                    'category_id'      => $prod['category_id'],
                    'in_stock'         => $prod['stock_qty'] > 0,
                    'is_featured'      => $prod['is_featured'],
                    'meta_title'       => 'Buy ' . explode(' /', $prod['name'])[0] . ' Online',
                    'meta_description' => Str::limit(strip_tags($prod['description']), 150, ''),
                    'options'          => $prod['options'],
                    'rating'           => 0.00,
                    'reviews_count'    => 0,
                ]
            );

            // Add Spatie images (multiple images for some, single for others)
            $this->attachPlaceholderImage($product, 'product_images', explode(' /', $prod['name'])[0]);
            
            // Add secondary image if featured/discounted
            if ($prod['is_featured'] || $prod['discount_price'] !== null) {
                $this->attachPlaceholderImage($product, 'product_images', explode(' /', $prod['name'])[0] . ' - Side');
                $this->attachPlaceholderImage($product, 'product_images', explode(' /', $prod['name'])[0] . ' - Detail');
            }
        }
    }

    /**
     * Attach a placeholder image to the model's collection, falling back to local creation if offline.
     */
    private function attachPlaceholderImage(Product $model, string $collection, string $text): void
    {
        // Don't duplicate
        if ($model->getMedia($collection)->where('name', $text)->count() > 0) {
            return;
        }

        $url = "https://placehold.co/1400x1400/1e293b/ffffff.png?text=" . urlencode($text);
        try {
            $model->addMediaFromUrl($url)->toMediaCollection($collection);
        } catch (\Exception $e) {
            // Offline fallback: write 1x1 transparent PNG locally and attach
            $tempDir = storage_path('app/temp_media');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $filename = Str::slug($text) . '-' . Str::random(5) . '.png';
            $filePath = $tempDir . '/' . $filename;
            
            $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
            file_put_contents($filePath, $pngContent);
            
            $model->addMedia($filePath)->toMediaCollection($collection);
        }
    }
}
