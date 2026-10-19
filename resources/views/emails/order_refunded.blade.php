@extends('emails.layouts.brand')

@section('title', 'Your refund')
@section('preheader', 'Your refund of $'.$amount.' for order '.$order->order_number.'.')
@section('heading')
  Your <span style="color: #d4af37; font-style: italic;">refund</span>
@endsection
@section('subheading', 'The money for your order is on its way back to you.')
@section('footer_note', 'You are receiving this email about order '.$order->order_number.'.')

@section('content')
  <p style="margin: 0 0 16px 0;">Hello,</p>

  <div style="border-radius: 8px; border: 1px solid #E8E2D9; background-color: #f9f5f0; padding: 16px 20px; margin: 0 0 26px 0;">
    @if ($via === 'manual')
      <strong>We sent your refund of ${{ $amount }} by {{ $manualMethod }}{{ $manualSentAt ? ' on '.$manualSentAt->format('F j, Y') : '' }}.</strong>
      Please allow a short time for it to show in your account.
    @elseif ($via === 'mixed')
      <strong>Your refund of ${{ $amount }} has been sent</strong>, partly to your card and partly by {{ $manualMethod }}.
    @else
      <strong>Your refund of ${{ $amount }} was sent to your card.</strong>
      It usually takes 5 to 10 business days to appear on your statement.
    @endif
  </div>

  <p style="margin: 0 0 26px 0;">This refund is for order <strong>{{ $order->order_number }}</strong>.</p>

  @include('emails.partials.button', ['url' => $orderUrl, 'label' => 'View your order'])
@endsection
