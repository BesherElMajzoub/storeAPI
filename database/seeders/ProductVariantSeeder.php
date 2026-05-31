<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class ProductVariantSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Variants for Classic Black Abaya
        $abaya = Product::where('slug', 'classic-black-abaya')->first();
        if ($abaya && $abaya->variants()->count() === 0) {
            $abaya->variants()->createMany([
                ['name' => 'Black / S', 'sku' => 'AB-CLS-BLK-S', 'price' => 120.00, 'stock_qty' => 50, 'attributes' => ['Color' => 'Black', 'Size' => 'S']],
                ['name' => 'Black / M', 'sku' => 'AB-CLS-BLK-M', 'price' => 120.00, 'stock_qty' => 50, 'attributes' => ['Color' => 'Black', 'Size' => 'M']],
                ['name' => 'Black / L', 'sku' => 'AB-CLS-BLK-L', 'price' => 120.00, 'stock_qty' => 40, 'attributes' => ['Color' => 'Black', 'Size' => 'L']],
                ['name' => 'Black / XL', 'sku' => 'AB-CLS-BLK-XL', 'price' => 125.00, 'stock_qty' => 10, 'attributes' => ['Color' => 'Black', 'Size' => 'XL']], // Overridden price
                ['name' => 'Dark Gray / M', 'sku' => 'AB-CLS-GRY-M', 'price' => 120.00, 'stock_qty' => 0, 'attributes' => ['Color' => 'Dark Gray', 'Size' => 'M']], // Out of stock variant
                ['name' => 'Dark Gray / L', 'sku' => 'AB-CLS-GRY-L', 'price' => 120.00, 'stock_qty' => 2, 'attributes' => ['Color' => 'Dark Gray', 'Size' => 'L']],  // Low stock variant
            ]);
        }

        // 2) Variants for Elegant Evening Gown
        $gown = Product::where('slug', 'elegant-evening-gown')->first();
        if ($gown && $gown->variants()->count() === 0) {
            $gown->variants()->createMany([
                ['name' => 'Burgundy / S', 'sku' => 'DR-EVE-SAT-BUR-S', 'price' => 350.00, 'stock_qty' => 10, 'attributes' => ['Color' => 'Burgundy', 'Size' => 'S']],
                ['name' => 'Burgundy / M', 'sku' => 'DR-EVE-SAT-BUR-M', 'price' => 350.00, 'stock_qty' => 8, 'attributes' => ['Color' => 'Burgundy', 'Size' => 'M']],
                ['name' => 'Royal Blue / M', 'sku' => 'DR-EVE-SAT-RBL-M', 'price' => 350.00, 'stock_qty' => 5, 'attributes' => ['Color' => 'Royal Blue', 'Size' => 'M']],
                ['name' => 'Champagne / L', 'sku' => 'DR-EVE-SAT-CHA-L', 'price' => 370.00, 'stock_qty' => 2, 'attributes' => ['Color' => 'Champagne', 'Size' => 'L']], // Overridden price
            ]);
        }

        // 3) Variants for 18k Gold Plated Necklace
        $necklace = Product::where('slug', '18k-gold-plated-necklace')->first();
        if ($necklace && $necklace->variants()->count() === 0) {
            $necklace->variants()->createMany([
                ['name' => '16in Length', 'sku' => 'AC-NKL-GLD-16', 'price' => 45.00, 'stock_qty' => 40, 'attributes' => ['Length' => '16in']],
                ['name' => '18in Length', 'sku' => 'AC-NKL-GLD-18', 'price' => 45.00, 'stock_qty' => 50, 'attributes' => ['Length' => '18in']],
                ['name' => '20in Length', 'sku' => 'AC-NKL-GLD-20', 'price' => 55.00, 'stock_qty' => 30, 'attributes' => ['Length' => '20in']], // Overridden price
            ]);
        }

        // 4) Variants for Italian Leather Tote
        $tote = Product::where('slug', 'italian-leather-tote')->first();
        if ($tote && $tote->variants()->count() === 0) {
            $tote->variants()->createMany([
                ['name' => 'Cognac', 'sku' => 'BG-TOT-ITL-COG', 'price' => 290.00, 'stock_qty' => 5, 'attributes' => ['Color' => 'Cognac']],
                ['name' => 'Classic Black', 'sku' => 'BG-TOT-ITL-BLK', 'price' => 290.00, 'stock_qty' => 8, 'attributes' => ['Color' => 'Classic Black']],
                ['name' => 'Olive Green', 'sku' => 'BG-TOT-ITL-OLV', 'price' => 310.00, 'stock_qty' => 2, 'attributes' => ['Color' => 'Olive Green']], // Overridden price
            ]);
        }

        // 5) Variants for Royal Oud Perfume
        $perfume = Product::where('slug', 'royal-oud-perfume')->first();
        if ($perfume && $perfume->variants()->count() === 0) {
            $perfume->variants()->createMany([
                ['name' => '50ml', 'sku' => 'PF-OUD-RYL-50', 'price' => 120.00, 'stock_qty' => 40, 'attributes' => ['Size' => '50ml']], // Under parent price
                ['name' => '100ml', 'sku' => 'PF-OUD-RYL-100', 'price' => 180.00, 'stock_qty' => 60, 'attributes' => ['Size' => '100ml']],
            ]);
        }

        // 6) Variants for iPhone 15 Pro Max
        $iphone = Product::where('slug', 'iphone-15-pro-max')->first();
        if ($iphone && $iphone->variants()->count() === 0) {
            $iphone->variants()->createMany([
                ['name' => 'Natural Titanium / 256GB', 'sku' => 'EL-IPH-15PM-NT-256', 'price' => 1199.00, 'stock_qty' => 15, 'attributes' => ['Color' => 'Natural Titanium', 'Storage' => '256GB']],
                ['name' => 'Natural Titanium / 512GB', 'sku' => 'EL-IPH-15PM-NT-512', 'price' => 1399.00, 'stock_qty' => 5, 'attributes' => ['Color' => 'Natural Titanium', 'Storage' => '512GB']], // Overridden price
                ['name' => 'Black Titanium / 256GB', 'sku' => 'EL-IPH-15PM-BT-256', 'price' => 1199.00, 'stock_qty' => 10, 'attributes' => ['Color' => 'Black Titanium', 'Storage' => '256GB']],
            ]);
        }
    }
}
