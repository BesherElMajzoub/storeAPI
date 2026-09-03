<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment confirmed</title>
</head>
<body style="font-family: Arial, sans-serif; color: #262320; line-height: 1.5">
    <h1>Thank you for your order</h1>
    <p>Your payment for order <strong>#{{ $order->order_number }}</strong> was confirmed.</p>
    <p>Total: <strong>${{ number_format((float) $order->total, 2) }} USD</strong></p>
    <p>We will email you again when your order ships.</p>
</body>
</html>
