@extends('emails.layouts.brand')

@section('title', 'We replied to your message')
@section('preheader', 'The Otantik Queen team has replied to your message.')
@section('heading')
  We <span style="color: #d4af37; font-style: italic;">replied</span>
@endsection
@section('subheading', 'Thank you for getting in touch.')

@section('content')
  <p style="margin: 0 0 16px 0;">Hello {{ $original->name }},</p>
  <p style="margin: 0 0 14px 0;">Here is our reply to your message:</p>

  <div style="border-left: 3px solid #d4af37; background-color: #f9f5f0; padding: 16px 20px; margin: 0 0 26px 0; white-space: pre-line;">{{ $reply->body }}</div>

  <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; color: #5b6170;">Your message</p>
  <p style="margin: 0 0 30px 0; color: #5b6170; white-space: pre-line;">{{ $original->message }}</p>

  @include('emails.partials.button', ['url' => $messagesUrl, 'label' => 'View the conversation'])
@endsection
