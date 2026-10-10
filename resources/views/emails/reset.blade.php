@extends('emails.layouts.brand')

@section('title', 'Reset your password')
@section('preheader', 'Use the link inside to choose a new password.')
@section('heading')
  Reset your <span style="color: #d4af37; font-style: italic;">password</span>
@endsection
@section('subheading', 'We received a request to reset the password for your account.')
@section('footer_note', 'This is an automated security email. Please do not reply to it.')

@section('content')
  <p style="margin: 0 0 28px 0; text-align: center;">Choose a new password with the button below.</p>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Reset password'])

  <p style="margin: 28px 0 20px 0; text-align: center; font-size: 13px; color: #5b6170;">Didn't ask for this? You can ignore this email; your password stays the same.</p>
  <p style="margin: 0; font-size: 12px; color: #5b6170; word-break: break-all;">
    Button not working? Copy this address into your browser:<br />
    <a href="{{ $url }}" style="color: #7a5f12;">{{ $url }}</a>
  </p>
@endsection
