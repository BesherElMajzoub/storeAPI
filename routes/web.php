<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Stripe\Stripe;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/checkout', function () {
    return view('checkout');
});

Route::post('/stripe/checkout', function (Request $request) {

    Stripe::setApiKey(env('STRIPE_SECRET'));

    $YOUR_DOMAIN = url('/');

    $session = \Stripe\Checkout\Session::create([
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd', // أو 'usd'
                'unit_amount' => 3000, // 30.00 => لازم بالسنت
                'product_data' => [
                    'name' => 'Test Payment',
                ],
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'success_url' => $YOUR_DOMAIN.'/stripe/success',
        'cancel_url' => $YOUR_DOMAIN.'/stripe/cancel',
    ]);

    return redirect()->away($session->url);
})->name('stripe.checkout');

Route::get('/stripe/success', fn () => 'تم الدفع بنجاح ✅');
Route::get('/stripe/cancel', fn () => 'تم إلغاء الدفع ❌');

Route::get('/google-test', function () {
    return view('google-auth');
});

