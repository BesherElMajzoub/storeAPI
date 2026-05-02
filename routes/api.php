<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\WishlistController;
use App\Http\Controllers\Api\V1\ContactMessageController;
use App\Http\Controllers\Api\V1\InspiredLeadController;
// Admin Imports
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Admin\WishlistAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\ContactMessageController as AdminContactMessageController;
use App\Http\Controllers\Api\V1\Admin\InspiredLeadController as AdminInspiredLeadController;
use App\Http\Controllers\Api\V1\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\TelescopeApiController;
use App\Http\Controllers\Api\V1\Admin\MediaController;

Route::prefix('v1')->group(function () {
    
    // --- AUTH ---
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
        Route::post('otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:otp');
        Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp');
        Route::post('google', [AuthController::class, 'googleLogin'])->middleware('throttle:login');
        
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::put('me', [AuthController::class, 'updateProfile']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
        });
    });

    // --- PUBLIC STORE ---
    Route::post('contact-messages', [ContactMessageController::class, 'store']);
    Route::post('inspired-leads', [InspiredLeadController::class, 'store']);

    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::get('products/{id}/reviews', [ProductController::class, 'reviews']);

    // --- USER PROTECTED ---
    Route::middleware('auth:sanctum')->group(function () {
        // Orders
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::post('orders/{id}/cancel', [OrderController::class, 'cancel']);

        // Reviews (auth user CRUD)
        Route::post('products/{product}/reviews', [ReviewController::class, 'store']);
        Route::get('products/{product}/my-review', [ReviewController::class, 'myReview']);
        Route::put('products/{product}/reviews/{review}', [ReviewController::class, 'update']);
        Route::delete('products/{product}/reviews/{review}', [ReviewController::class, 'destroy']);

        // ----- My Addresses (Profile) -----
        Route::prefix('profile')->group(function () {
            Route::get('addresses', [AddressController::class, 'index']);
            Route::post('addresses', [AddressController::class, 'store']);
            Route::put('addresses/{id}', [AddressController::class, 'update']);
            Route::delete('addresses/{id}', [AddressController::class, 'destroy']);
            Route::post('addresses/{id}/default', [AddressController::class, 'setDefault']);
        });

        // ----- Wishlist -----
        Route::get('wishlist', [WishlistController::class, 'index']);
        Route::post('wishlist', [WishlistController::class, 'store']);
        Route::get('wishlist/count', [WishlistController::class, 'count']);
        Route::get('wishlist/check/{productId}', [WishlistController::class, 'check']);
        Route::delete('wishlist/{productId}', [WishlistController::class, 'destroy']);
    });

    // --- ADMIN ---
    Route::prefix('admin')->middleware(['auth:sanctum', 'can:admin-access'])->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);

        // Products
        Route::apiResource('products', AdminProductController::class);

        // Categories
        Route::apiResource('categories', AdminCategoryController::class);
        Route::post('categories/reorder', [AdminCategoryController::class, 'reorder']);

        // Media management
        Route::post('products/{product}/media', [MediaController::class, 'uploadProductImages']);
        Route::post('products/{product}/media/reorder', [MediaController::class, 'reorderProductGallery']);
        Route::post('categories/{category}/media', [MediaController::class, 'replaceCategoryImage']);
        Route::delete('media/{media}', [MediaController::class, 'destroy']);

        // Orders
        Route::get('orders', [AdminOrderController::class, 'index']);
        Route::get('orders/{id}', [AdminOrderController::class, 'show']);
        Route::post('orders/{id}/status', [AdminOrderController::class, 'updateStatus']);

        // Users (with Wishlist & Addresses tabs)
        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{id}', [AdminUserController::class, 'show']);
        Route::get('users/{id}/wishlist', [AdminUserController::class, 'wishlist']);
        Route::get('users/{id}/addresses', [AdminUserController::class, 'addresses']);

        // Wishlist Analytics
        Route::get('wishlist-analytics', [WishlistAnalyticsController::class, 'index']);
        Route::get('wishlist-analytics/summary', [WishlistAnalyticsController::class, 'summary']);
        Route::get('wishlist-analytics/trending', [WishlistAnalyticsController::class, 'trending']);
        Route::get('wishlist-analytics/conversions', [WishlistAnalyticsController::class, 'conversions']);

        // Contact Messages
        Route::apiResource('contact-messages', AdminContactMessageController::class)->except(['store']);
        Route::patch('contact-messages/{id}/status', [AdminContactMessageController::class, 'updateStatus']);

        // Inspired Leads
        Route::apiResource('inspired-leads', AdminInspiredLeadController::class)->except(['store']);

        // Reviews Moderation
        Route::get('reviews', [AdminReviewController::class, 'index']);
        Route::get('reviews/{review}', [AdminReviewController::class, 'show']);
        Route::patch('reviews/{review}/moderate', [AdminReviewController::class, 'moderate']);
        Route::delete('reviews/{review}', [AdminReviewController::class, 'destroy']);

        // ─── Telescope API (React Admin Panel) ─────────────────────────────────
        // متاح فقط إذا كان TELESCOPE_ENABLED=true
        if (config('telescope.enabled')) {
            Route::prefix('telescope')->group(function () {
                Route::get('summary',       [TelescopeApiController::class, 'summary']);
                Route::get('requests',      [TelescopeApiController::class, 'requests']);
                Route::get('queries',       [TelescopeApiController::class, 'queries']);
                Route::get('exceptions',    [TelescopeApiController::class, 'exceptions']);
                Route::get('jobs',          [TelescopeApiController::class, 'jobs']);
                Route::get('logs',          [TelescopeApiController::class, 'logs']);
                Route::get('events',        [TelescopeApiController::class, 'events']);
                Route::get('mail',          [TelescopeApiController::class, 'mail']);
                Route::get('notifications', [TelescopeApiController::class, 'notifications']);
                Route::get('cache',         [TelescopeApiController::class, 'cache']);
            });
        }
    });
});
