<?php

require_once __DIR__.'/../../vendor/autoload.php';

\Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

header('Content-Type: application/json');

$YOUR_DOMAIN = 'http://localhost:8000';

$checkout_session = \Stripe\Checkout\Session::create([
    'line_items' => [[
        'price' => '30', // حط Price ID الحقيقي من Stripe
        'quantity' => 1,
    ]],
    'mode' => 'payment',
    'success_url' => $YOUR_DOMAIN.'/stripe/success',
    'cancel_url' => $YOUR_DOMAIN.'/stripe/cancel',
]);

header('HTTP/1.1 303 See Other');
header('Location: '.$checkout_session->url);
exit;
