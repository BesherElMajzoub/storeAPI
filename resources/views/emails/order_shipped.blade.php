<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Your order has shipped</title>
</head>
<body style="font-family: Arial, sans-serif; color: #262320; line-height: 1.5">
    <h1>Your order is on its way</h1>
    <p>Order <strong>#{{ $order->order_number }}</strong> has shipped.</p>
    <p>
        Tracking number: <strong>{{ $order->tracking_number }}</strong>
        @if ($order->shipping_carrier)
            via {{ $order->shipping_carrier }}{{ $order->shipping_service ? ' '.$order->shipping_service : '' }}
        @endif
    </p>
    @if ($order->estimated_delivery)
        <p>Estimated delivery: <strong>{{ $order->estimated_delivery->format('F j, Y') }}</strong></p>
    @endif
    @if ($order->tracking_url)
        <p><a href="{{ $order->tracking_url }}">Track your shipment</a></p>
    @endif
</body>
</html>
