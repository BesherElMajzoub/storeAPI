@extends('emails.layouts.brand')

@php
  $accepted = $decision === 'accepted';
  // Prefer a link built by the mailable from the order id (the site's order pages are /orders/{id}).
  $link = $orderUrl ?? rtrim(config('app.frontend_url', 'https://otantikqueen.com'), '/').'/orders/'.$orderNumber;
@endphp

@section('title', $accepted ? 'Cancellation request approved' : 'Cancellation request declined')
@section('preheader', $accepted ? 'Your order '.$orderNumber.' has been cancelled.' : 'Your order '.$orderNumber.' will continue as planned.')
@section('heading')
  Request <span style="color: #d4af37; font-style: italic;">{{ $accepted ? 'approved' : 'declined' }}</span>
@endsection
@section('subheading', 'We have reviewed your cancellation request.')
@section('footer_note', 'You are receiving this email about order '.$orderNumber.'.')

@section('content')
  <p style="margin: 0 0 16px 0;">Hello,</p>

  @if ($accepted)
    <p style="margin: 0 0 22px 0;">Your order <strong>{{ $orderNumber }}</strong> has been cancelled.</p>
    <div style="border-radius: 8px; border: 1px solid #E8E2D9; background-color: #f9f5f0; padding: 16px 20px; margin: 0 0 26px 0;">
      @if ($moneyOutcome === 'refund')
        <strong>Your refund is on its way.</strong> It usually takes 5 to 10 business days to appear on your card.
      @else
        <strong>You were not charged.</strong> If your bank shows a pending hold, it disappears within a few days.
      @endif
    </div>
  @else
    <p style="margin: 0 0 26px 0;">Your order <strong>{{ $orderNumber }}</strong> will continue as planned. We will email you the tracking details as soon as it ships.</p>
  @endif

  @if ($adminNote)
    <div style="border-left: 3px solid #d4af37; background-color: #f9f5f0; padding: 14px 18px; margin: 0 0 28px 0;">
      <p style="margin: 0 0 4px 0; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; color: #5b6170;">A note from our team</p>
      <p style="margin: 0; font-style: italic; white-space: pre-line;">{{ $adminNote }}</p>
    </div>
  @endif

  @include('emails.partials.button', ['url' => $link, 'label' => 'View your order'])
@endsection
