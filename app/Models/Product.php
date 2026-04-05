<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'price', 'discount_price', 'sku',
        'stock_qty', 'status', 'category_id', 'options', 'in_stock', 'is_featured',
        'meta_title', 'meta_description', 'rating', 'reviews_count'
    ];

    protected $casts = [
        'options' => 'array', // flexible attributes setup
        'in_stock' => 'boolean',
        'is_featured' => 'boolean',
        'price' => 'decimal:2',
        'discount_price' => 'decimal:2',
        'rating' => 'decimal:2',
    ];

    // Relations
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Users who have this product in their wishlist.
     */
    public function wishlistedByUsers()
    {
        return $this->belongsToMany(User::class, 'wishlist_items')->withTimestamps();
    }

    // Accessors & Scopes
    public function getFinalPriceAttribute()
    {
        return $this->discount_price && $this->discount_price < $this->price 
            ? $this->discount_price 
            : $this->price;
    }

    public function scopePublished(Builder $query)
    {
        return $query->where('status', 'published');
    }

    public function scopeFilter(Builder $query, array $filters)
    {
        $finalPriceExpr = self::finalPriceExpression();

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        $query->when($filters['category'] ?? null, function ($q, $slug) {
            if (is_numeric($slug)) {
                $q->where('category_id', (int) $slug);
                return;
            }
            $q->whereHas('category', fn($c) => $c->where('slug', $slug));
        });

        $query->when($filters['price_min'] ?? null, function ($q, $v) use ($finalPriceExpr) {
            $q->whereRaw("{$finalPriceExpr} >= ?", [(float) $v]);
        });

        $query->when($filters['price_max'] ?? null, function ($q, $v) use ($finalPriceExpr) {
            $q->whereRaw("{$finalPriceExpr} <= ?", [(float) $v]);
        });

        $query->when($filters['rating'] ?? null, fn($q, $v) => $q->where('rating', '>=', $v));

        $query->when(isset($filters['in_stock']), function ($q) use ($filters) {
            $flag = filter_var($filters['in_stock'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($flag === true) {
                $q->where('stock_qty', '>', 0);
            } elseif ($flag === false) {
                $q->where('stock_qty', '<=', 0);
            }
        });
    }
    
    public function scopeSort(Builder $query, $sort)
    {
        $finalPriceExpr = self::finalPriceExpression();

        switch ($sort) {
            case 'price_asc':
                $query->orderByRaw("{$finalPriceExpr} asc");
                break;
            case 'price_desc':
                $query->orderByRaw("{$finalPriceExpr} desc");
                break;
            case 'top_rated':
                $query->orderBy('rating', 'desc');
                break;
            case 'best_selling':
                // Assuming we track sales count somewhere, usually 'sold_count' column or via order_items relation
                 // For now, let's just sort by reviews_count as a proxy or add sold_count to schema next time. 
                 // I'll stick to created_at if not available, or id.
                 $query->orderBy('reviews_count', 'desc');
                break;
            case 'newest':
            default:
                $query->latest();
                break;
        }
    }

    protected static function finalPriceExpression(): string
    {
        return 'CASE WHEN products.discount_price IS NOT NULL AND products.discount_price < products.price THEN products.discount_price ELSE products.price END';
    }
}
