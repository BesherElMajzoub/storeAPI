@extends('emails.layouts.brand')

@section('title', 'Order confirmed')
@section('heading', 'Order confirmed')

@section('content')
  @php($money = fn ($amount) => '$'.number_format((float) $amount, 2))

  <p style="margin: 0 0 20px 0;">
    Thank you{{ !empty($address['name']) ? ', '.$address['name'] : '' }}! We received your order
    <strong>#{{ $order->order_number }}</strong>.
  </p>

  @if ($order->payment_status === 'authorized')
    <p style="margin: 0 0 20px 0; color: #6B7280;">
      Your card has been authorized for {{ $money($order->total) }}. It is charged when we prepare your order for shipping.
    </p>
  @endif

  @if ($cancelUntil)
    <div style="border-radius: 8px; border: 1px solid #E8E2D9; background-color: #f9f5f0; padding: 14px 18px; margin: 0 0 24px 0;">
      Changed your mind? You can cancel free of charge until
      <strong>{{ $cancelUntil->format('F j, Y \a\t g:i A T') }}</strong> from your order page.
    </div>
  @endif

  <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 0 0 20px 0;">
    @foreach ($order->items as $item)
      <tr>
        <td style="padding: 8px 0; border-bottom: 1px solid #E8E2D9;">
          {{ $item->product_name }}@if ($item->variant_name) <span style="color: #6B7280;">({{ $item->variant_name }})</span>@endif
          <br /><span style="color: #6B7280; font-size: 13px;">Qty {{ $item->quantity }} × {{ $money($item->price) }}</span>
        </td>
        <td align="right" style="padding: 8px 0; border-bottom: 1px solid #E8E2D9; white-space: nowrap;">{{ $money($item->total) }}</td>
      </tr>
    @endforeach
    <tr><td style="padding: 8px 0 2px 0; color: #6B7280;">Subtotal</td><td align="right" style="padding: 8px 0 2px 0;">{{ $money($order->subtotal) }}</td></tr>
    @if ((float) $order->discount > 0)
      <tr><td style="padding: 2px 0; color: #6B7280;">Discount{{ $order->coupon_code ? ' ('.$order->coupon_code.')' : '' }}</td><td align="right" style="padding: 2px 0;">−{{ $money($order->discount) }}</td></tr>
    @endif
    <tr><td style="padding: 2px 0; color: #6B7280;">Shipping</td><td align="right" style="padding: 2px 0;">{{ (float) $order->shipping_cost > 0 ? $money($order->shipping_cost) : 'Free' }}</td></tr>
    @if ((float) $order->tax > 0)
      <tr><td style="padding: 2px 0; color: #6B7280;">Tax</td><td align="right" style="padding: 2px 0;">{{ $money($order->tax) }}</td></tr>
    @endif
    <tr><td style="padding: 8px 0; font-weight: 600;">Total</td><td align="right" style="padding: 8px 0; font-weight: 600;">{{ $money($order->total) }} USD</td></tr>
  </table>

  @if (!empty($address))
    <p style="margin: 0 0 4px 0; font-weight: 600;">Shipping to</p>
    <p style="margin: 0 0 24px 0; color: #6B7280;">
      {{ $address['name'] ?? '' }}<br />
      {{ $address['line1'] ?? '' }}@if (!empty($address['line2'])), {{ $address['line2'] }}@endif<br />
      {{ $address['city'] ?? '' }}, {{ $address['state'] ?? '' }} {{ $address['postal_code'] ?? '' }}
    </p>
  @endif

  <p style="margin: 0 0 24px 0; text-align: center;">
    <a href="{{ $orderUrl }}" style="display: inline-block; background-color: #262320; color: #ffffff; padding: 12px 26px; border-radius: 6px; text-decoration: none;">View your order</a>
  </p>

  <p style="margin: 0; color: #6B7280;">We will email you again when your order ships.</p>
@endsection
