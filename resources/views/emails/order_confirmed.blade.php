@extends('emails.layouts.brand')

@php
  $money = fn ($amount) => '$'.number_format((float) $amount, 2);
  // Show the cancel deadline in the shop's time zone, not the server's (UTC).
  $tz = config('mail.brand.timezone', 'America/Los_Angeles');
@endphp

@section('title', 'Order confirmed')
@section('preheader', 'We received your order '.$order->order_number.'. Total '.$money($order->total).'.')
@section('heading')
  Order <span style="color: #d4af37; font-style: italic;">confirmed</span>
@endsection
@section('subheading', 'Thank you for shopping with us.')

@section('content')
  <p style="margin: 0 0 20px 0;">
    Thank you{{ !empty($address['name']) ? ', '.$address['name'] : '' }}! We received your order
    <strong>{{ $order->order_number }}</strong>.
  </p>

  @if ($order->payment_status === 'authorized')
    <p style="margin: 0 0 20px 0; color: #5b6170;">
      Your card has been authorized for {{ $money($order->total) }}. It is charged when we prepare your order for shipping.
    </p>
  @endif

  @if ($cancelUntil)
    <div style="border-radius: 8px; border: 1px solid #E8E2D9; background-color: #f9f5f0; padding: 16px 20px; margin: 0 0 26px 0;">
      Changed your mind? You can cancel free of charge until
      <strong>{{ \Illuminate\Support\Carbon::parse($cancelUntil)->setTimezone($tz)->format('F j, Y \a\t g:i A T') }}</strong> from your order page.
    </div>
  @endif

  <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 0 0 24px 0;">
    @foreach ($order->items as $item)
      <tr>
        <td style="padding: 10px 0; border-bottom: 1px solid #E8E2D9;">
          {{ $item->product_name }}@if ($item->variant_name) <span style="color: #5b6170;">({{ $item->variant_name }})</span>@endif
          <br /><span style="color: #5b6170; font-size: 13px;">Qty {{ $item->quantity }} × {{ $money($item->price) }}</span>
        </td>
        <td align="right" valign="top" style="padding: 10px 0; border-bottom: 1px solid #E8E2D9; white-space: nowrap;">{{ $money($item->total) }}</td>
      </tr>
    @endforeach
    <tr><td style="padding: 12px 0 2px 0; color: #5b6170;">Subtotal</td><td align="right" style="padding: 12px 0 2px 0;">{{ $money($order->subtotal) }}</td></tr>
    @if ((float) $order->discount > 0)
      <tr><td style="padding: 2px 0; color: #5b6170;">Discount{{ $order->coupon_code ? ' ('.$order->coupon_code.')' : '' }}</td><td align="right" style="padding: 2px 0;">−{{ $money($order->discount) }}</td></tr>
    @endif
    <tr><td style="padding: 2px 0; color: #5b6170;">Shipping</td><td align="right" style="padding: 2px 0;">{{ (float) $order->shipping_cost > 0 ? $money($order->shipping_cost) : 'Free' }}</td></tr>
    @if ((float) $order->tax > 0)
      <tr><td style="padding: 2px 0; color: #5b6170;">Tax</td><td align="right" style="padding: 2px 0;">{{ $money($order->tax) }}</td></tr>
    @endif
    <tr><td style="padding: 12px 0 0 0; font-weight: 600; border-top: 1px solid #E8E2D9;">Total</td><td align="right" style="padding: 12px 0 0 0; font-weight: 600; border-top: 1px solid #E8E2D9; white-space: nowrap;">{{ $money($order->total) }} USD</td></tr>
  </table>

  @if (!empty($address))
    <p style="margin: 0 0 4px 0; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; color: #5b6170;">Shipping to</p>
    <p style="margin: 0 0 28px 0;">
      {{ $address['name'] ?? '' }}<br />
      {{ $address['line1'] ?? '' }}@if (!empty($address['line2'])), {{ $address['line2'] }}@endif<br />
      {{ $address['city'] ?? '' }}, {{ $address['state'] ?? '' }} {{ $address['postal_code'] ?? '' }}
    </p>
  @endif

  @include('emails.partials.button', ['url' => $orderUrl, 'label' => 'View your order'])

  <p style="margin: 24px 0 0 0; text-align: center; font-size: 13px; color: #5b6170;">We will email you again when your order ships.</p>
@endsection
