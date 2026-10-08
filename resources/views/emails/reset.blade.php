@extends('emails.layouts.brand')

@section('title', 'Reset your password')
@section('heading', 'Reset your password')

@section('content')
  <p style="margin: 0 0 20px 0;">We received a request to reset the password for your account.</p>
  <p style="margin: 0 0 24px 0; text-align: center;">
    <a href="{{ $url }}" style="display: inline-block; background-color: #262320; color: #ffffff; padding: 12px 26px; border-radius: 6px; text-decoration: none;">Reset password</a>
  </p>
  <p style="margin: 0 0 20px 0; color: #6B7280;">If you did not ask for this, you can ignore this email; your password stays the same.</p>
  <p style="margin: 0; font-size: 12px; color: #9CA3AF;">
    Link not working? Copy this address into your browser:<br />
    <a href="{{ $url }}" style="color: #9CA3AF; word-break: break-all;">{{ $url }}</a>
  </p>
@endsection
