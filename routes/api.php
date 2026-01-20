<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\OrderController;
// Admin Imports
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;

Route::prefix('v1')->group(function () {
    
    // --- AUTH ---
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('google', [AuthController::class, 'googleLogin']);
        Route::post('phone/send-otp', [AuthController::class, 'sendOtp']);
        Route::post('phone/verify-otp', [AuthController::class, 'verifyOtp']);
        
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    // --- PUBLIC STORE ---
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
        
        // Profile, Address, Notifications, etc. (Not implemented in this turn but routes placeholders)
        // Route::get('profile', [ProfileController::class, 'show']);
    });

    // --- ADMIN ---
    Route::prefix('admin')->middleware(['auth:sanctum', 'can:admin-access'])->group(function () { // 'can:admin-access' needs Gate definition or custom middleware
        Route::get('dashboard', [DashboardController::class, 'index']);
        
        // Products
        Route::apiResource('products', AdminProductController::class);
        
        // Categories
        Route::apiResource('categories', AdminCategoryController::class);
        Route::post('categories/reorder', [AdminCategoryController::class, 'reorder']);
        
        // Orders
        Route::get('orders', [AdminOrderController::class, 'index']);
        Route::get('orders/{id}', [AdminOrderController::class, 'show']);
        Route::post('orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
        
        // Users, Reviews, Coupons (Placeholders for now)
    });
});
