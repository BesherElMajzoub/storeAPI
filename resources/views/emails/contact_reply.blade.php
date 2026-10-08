@extends('emails.layouts.brand')

@section('title', 'We replied to your message')
@section('heading', 'We replied to your message')

@section('content')
  <p style="margin: 0 0 16px 0;">Hello {{ $original->name }},</p>
  <div style="border-left: 4px solid #d4af37; background-color: #f9f5f0; padding: 14px 18px; margin: 0 0 24px 0; white-space: pre-line;">{{ $reply->body }}</div>
  <p style="margin: 0 0 6px 0; font-size: 12px; color: #9CA3AF;">Your message</p>
  <p style="margin: 0 0 24px 0; color: #6B7280; white-space: pre-line;">{{ $original->message }}</p>
  <p style="margin: 0; text-align: center;">
    <a href="{{ $messagesUrl }}" style="display: inline-block; background-color: #262320; color: #ffffff; padding: 12px 26px; border-radius: 6px; text-decoration: none;">View the conversation</a>
  </p>
@endsection
