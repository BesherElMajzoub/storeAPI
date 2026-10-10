@extends('emails.layouts.brand')

@php
  // "GroundAdvantage" -> "Ground Advantage"
  $service = $order->shipping_service ? preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $order->shipping_service) : null;
  $orderLink = rtrim(config('app.frontend_url', 'https://otantikqueen.com'), '/').'/orders/'.$order->id;
@endphp

@section('title', 'Your order has shipped')
@section('preheader', 'Order '.$order->order_number.' is on its way to you.')
@section('heading')
  On its <span style="color: #d4af37; font-style: italic;">way</span>
@endsection
@section('subheading', 'Your order has shipped.')

@section('content')
  <p style="margin: 0 0 22px 0;">Good news! Order <strong>{{ $order->order_number }}</strong> has shipped and is on its way to you.</p>

  <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#f9f5f0" style="background-color: #f9f5f0; border: 1px solid #E8E2D9; border-radius: 8px; margin: 0 0 28px 0;">
    @if ($order->shipping_carrier)
      <tr>
        <td style="padding: 14px 20px 0 20px; color: #5b6170; font-size: 13px;">Carrier</td>
        <td align="right" style="padding: 14px 20px 0 20px;">{{ $order->shipping_carrier }}{{ $service ? ' · '.$service : '' }}</td>
      </tr>
    @endif
    @if ($order->tracking_number)
      <tr>
        <td style="padding: 10px 20px 0 20px; color: #5b6170; font-size: 13px;">Tracking number</td>
        <td align="right" style="padding: 10px 20px 0 20px; font-weight: 600; word-break: break-all;">{{ $order->tracking_number }}</td>
      </tr>
    @endif
    @if ($order->estimated_delivery)
      <tr>
        <td style="padding: 10px 20px 0 20px; color: #5b6170; font-size: 13px;">Estimated delivery</td>
        <td align="right" style="padding: 10px 20px 0 20px;">{{ $order->estimated_delivery->format('F j, Y') }}</td>
      </tr>
    @endif
    <tr><td colspan="2" style="height: 14px; line-height: 14px; font-size: 1px;">&nbsp;</td></tr>
  </table>

  @if ($order->tracking_url)
    @include('emails.partials.button', ['url' => $order->tracking_url, 'label' => 'Track your shipment'])
    <p style="margin: 18px 0 0 0; text-align: center; font-size: 13px;">
      <a href="{{ $orderLink }}" style="color: #7a5f12;">View your order</a>
    </p>
  @else
    @include('emails.partials.button', ['url' => $orderLink, 'label' => 'View your order'])
  @endif
@endsection
