@extends('emails.layouts.brand')

@section('title', 'Your order has shipped')
@section('heading', 'Your order is on its way')

@section('content')
  <p style="margin: 0 0 20px 0;">Order <strong>#{{ $order->order_number }}</strong> has shipped.</p>
  <p style="margin: 0 0 20px 0;">
    Tracking number: <strong>{{ $order->tracking_number }}</strong>
    @if ($order->shipping_carrier)
      via {{ $order->shipping_carrier }}{{ $order->shipping_service ? ' '.$order->shipping_service : '' }}
    @endif
  </p>
  @if ($order->estimated_delivery)
    <p style="margin: 0 0 20px 0;">Estimated delivery: <strong>{{ $order->estimated_delivery->format('F j, Y') }}</strong></p>
  @endif
  @if ($order->tracking_url)
    <p style="margin: 0; text-align: center;">
      <a href="{{ $order->tracking_url }}" style="display: inline-block; background-color: #262320; color: #ffffff; padding: 12px 26px; border-radius: 6px; text-decoration: none;">Track your shipment</a>
    </p>
  @endif
@endsection
