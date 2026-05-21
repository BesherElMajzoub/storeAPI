<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

class SkuGeneratorService
{
    /**
     * Generate an SKU preview based on product/variant details.
     * Format: OTQ-{CAT3}-{SLUG4}-{VARINIT}-{SEQ4}
     */
    public function generate(array $data): string
    {
        $category = Category::findOrFail($data['category_id']);
        
        $cat3 = $this->getCategoryPart($category);
        $slug4 = $this->getSlugPart($data['product_name'], $data['product_slug'] ?? null);
        $varInit = $this->getVariantInitials($data['type'], $data['variant_name'] ?? null);
        
        $baseSku = strtoupper("OTQ-{$cat3}-{$slug4}-{$varInit}");
        
        return $this->findUniqueSku($baseSku);
    }
    
    private function getCategoryPart(Category $category): string
    {
        $str = $category->sku_code ?? $category->slug ?? $category->name;
        // Keep only alphanumeric
        $str = preg_replace('/[^a-zA-Z0-9]/', '', $str);
        
        if (empty($str)) {
            $str = 'CAT';
        }
        
        return str_pad(substr($str, 0, 3), 3, 'X');
    }
    
    private function getSlugPart(string $productName, ?string $productSlug): string
    {
        $str = $productSlug ?: Str::slug($productName);
        $str = preg_replace('/[^a-zA-Z0-9]/', '', $str);
        
        if (empty($str)) {
            $str = 'PROD';
        }
        
        return str_pad(substr($str, 0, 4), 4, 'X');
    }
    
    private function getVariantInitials(string $type, ?string $variantName): string
    {
        if ($type === 'product' || empty($variantName)) {
            return 'PR';
        }
        
        $words = preg_split('/[\s_-]+/', trim($variantName));
        $initials = '';
        foreach ($words as $word) {
            $word = preg_replace('/[^a-zA-Z0-9]/', '', $word);
            if (!empty($word)) {
                $initials .= substr($word, 0, 1);
            }
        }
        
        if (empty($initials)) {
            $initials = 'VR';
        }
        
        return substr($initials, 0, 2);
    }
    
    private function findUniqueSku(string $baseSku): string
    {
        $seq = 1;
        
        while (true) {
            $formattedSeq = str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            $sku = "{$baseSku}-{$formattedSeq}";
            
            $productExists = Product::where('sku', $sku)->exists();
            $variantExists = ProductVariant::where('sku', $sku)->exists();
            
            if (!$productExists && !$variantExists) {
                return $sku;
            }
            
            $seq++;
        }
    }
}
